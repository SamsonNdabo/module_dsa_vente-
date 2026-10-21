<?php
// app/Http/Controllers/Utilisateur/PermissionController.php

namespace App\Http\Controllers\Utilisateur;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PermissionController extends Controller
{
    public function __construct()
    {
    }

    /**
     * Liste des permissions
     */
    public function index(Request $request)
    {
        $query = Permission::withCount('roles');

        if ($request->has('module')) {
            $query->where('module', $request->module);
        }

        if ($request->has('categorie')) {
            $query->where('categorie', $request->categorie);
        }

        if ($request->has('recherche')) {
            $search = $request->recherche;
            $query->where(function($q) use ($search) {
                $q->where('permission_code', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('module', 'like', "%{$search}%")
                  ->orWhere('action', 'like', "%{$search}%");
            });
        }

        // Filtrer par système/non système
        if ($request->has('est_systeme')) {
            $query->where('est_systeme', $request->boolean('est_systeme'));
        }

        // Filtrer par assignation
        if ($request->has('est_assignee')) {
            if ($request->boolean('est_assignee')) {
                $query->has('roles');
            } else {
                $query->doesntHave('roles');
            }
        }

        // Filtrer par action
        if ($request->has('action')) {
            $query->where('action', $request->action);
        }

        $permissions = $query->orderBy('module')
        ->orderBy('categorie')
        ->orderBy('permission_code')
        ->get();

        return response()->json([
            'success' => true,
            'data' => $permissions
        ]);
    }

    /**
     * Afficher une permission
     */
    public function show($id)
    {
        $permission = Permission::with('roles')->findOrFail($id);

        // Détails des rôles qui utilisent cette permission
        $roles = $permission->roles->map(function($role) {
            return [
                'id' => $role->id,
                'code' => $role->code,
                'nom_role' => $role->nom_role,
                'niveau_acces' => $role->niveau_acces,
                'est_systeme' => $role->est_systeme,
                'date_attribution' => $role->pivot->date_attribution ?? null
            ];
        });

        $stats = [
            'nombre_roles' => $permission->roles()->count(),
            'est_systeme' => $permission->est_systeme,
            'est_modifiable' => !$permission->est_systeme,
            'peut_etre_supprime' => !$permission->est_systeme && $permission->roles()->count() == 0,
            'roles' => $roles,
            'module' => $permission->module,
            'action' => $permission->action,
            'categorie' => $permission->categorie ?: 'Général'
        ];

        return response()->json([
            'success' => true,
            'data' => $permission,
            'stats' => $stats
        ]);
    }

    /**
     * Mettre à jour une permission
     */
    public function update(Request $request, $id)
    {
        $permission = Permission::findOrFail($id);

        if ($permission->est_systeme) {
            return response()->json(['error' => 'Impossible de modifier une permission système'], 400);
        }

        $request->validate([
            'description' => 'nullable|string|max:200',
            'categorie' => 'nullable|string|max:50'
        ]);

        $permission->update($request->only(['description', 'categorie']));

        return response()->json([
            'success' => true,
            'message' => 'Permission mise à jour',
            'data' => $permission->fresh()
        ]);
    }

    /**
     * Supprimer une permission
     */
    public function destroy($id)
    {
        $permission = Permission::findOrFail($id);

        if ($permission->est_systeme) {
            return response()->json(['error' => 'Impossible de supprimer une permission système'], 400);
        }

        if ($permission->roles()->exists()) {
            return response()->json([
                'error' => 'Cette permission est assignée à des rôles. Retirez-la d\'abord.'
            ], 400);
        }

        $permission->delete();

        return response()->json([
            'success' => true,
            'message' => 'Permission supprimée'
        ]);
    }

    /**
     * Liste des modules
     */
    public function modules()
    {
        $modules = Permission::select('module')
            ->distinct()
            ->orderBy('module')
            ->pluck('module');

        // Ajouter le compteur de permissions par module
        $modulesWithCount = [];
        foreach ($modules as $module) {
            $modulesWithCount[] = [
                'nom' => $module,
                'total' => Permission::where('module', $module)->count()
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $modulesWithCount
        ]);
    }

    /**
     * Liste des catégories par module
     */
    public function categories(Request $request)
    {
        $query = Permission::select('categorie')
            ->distinct()
            ->whereNotNull('categorie');

        if ($request->has('module')) {
            $query->where('module', $request->module);
        }

        $categories = $query->orderBy('categorie')
            ->pluck('categorie');

        return response()->json([
            'success' => true,
            'data' => $categories
        ]);
    }

    /**
     * Synchroniser les permissions (pour admin)
     */
    public function sync(Request $request)
    {
        $request->validate([
            'permissions' => 'required|array',
            'permissions.*.permission_code' => 'required|string',
            'permissions.*.module' => 'required|string',
            'permissions.*.action' => 'required|string',
            'permissions.*.description' => 'nullable|string',
            'permissions.*.categorie' => 'nullable|string'
        ]);

        DB::beginTransaction();

        try {
            $results = [
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'details' => []
            ];

            foreach ($request->permissions as $permData) {
                $permission = Permission::where('permission_code', $permData['permission_code'])->first();

                if ($permission) {
                    // Permission existe déjà
                    if ($permission->est_systeme) {
                        $results['skipped']++;
                        $results['details'][] = [
                            'code' => $permData['permission_code'],
                            'status' => 'skipped',
                            'reason' => 'Permission système'
                        ];
                        continue;
                    }

                    // Mise à jour
                    $permission->update([
                        'description' => $permData['description'] ?? $permission->description,
                        'module' => $permData['module'],
                        'action' => $permData['action'],
                        'categorie' => $permData['categorie'] ?? $permission->categorie
                    ]);
                    
                    $results['updated']++;
                    $results['details'][] = [
                        'code' => $permData['permission_code'],
                        'status' => 'updated'
                    ];
                    
                } else {
                    // Nouvelle permission
                    Permission::create([
                        'name' => $permData['permission_code'],
                        'guard_name' => 'api',
                        'permission_code' => $permData['permission_code'],
                        'description' => $permData['description'] ?? null,
                        'module' => $permData['module'],
                        'action' => $permData['action'],
                        'categorie' => $permData['categorie'] ?? null,
                        'est_systeme' => false
                    ]);
                    
                    $results['created']++;
                    $results['details'][] = [
                        'code' => $permData['permission_code'],
                        'status' => 'created'
                    ];
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Permissions synchronisées',
                'results' => $results
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'error' => 'Erreur lors de la synchronisation: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtenir les statistiques globales des permissions
     */
    public function statistiques()
    {
        $stats = [
            'total_permissions' => Permission::count(),
            'permissions_systeme' => Permission::where('est_systeme', true)->count(),
            'permissions_non_systeme' => Permission::where('est_systeme', false)->count(),
            'permissions_assignees' => Permission::has('roles')->count(),
            'permissions_non_assignees' => Permission::doesntHave('roles')->count(),
            'par_module' => Permission::select('module', DB::raw('count(*) as total'))
                ->groupBy('module')
                ->orderBy('total', 'desc')
                ->get(),
            'par_action' => Permission::select('action', DB::raw('count(*) as total'))
                ->groupBy('action')
                ->orderBy('total', 'desc')
                ->get(),
            'par_categorie' => Permission::select('categorie', DB::raw('count(*) as total'))
                ->whereNotNull('categorie')
                ->groupBy('categorie')
                ->orderBy('total', 'desc')
                ->get(),
            'top_permissions' => Permission::withCount('roles')
                ->orderBy('roles_count', 'desc')
                ->limit(10)
                ->get(['permission_code', 'description', 'module', 'roles_count'])
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }
}