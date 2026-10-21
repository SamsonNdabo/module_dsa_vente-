<?php
// app/Http/Controllers/Utilisateur/RoleController.php

namespace App\Http\Controllers\Utilisateur;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    // ✅ Constantes pour les niveaux
    private const NIVEAU_STRATEGIQUE = 9;
    private const NIVEAU_GESTION = 7;

    /**
     * Convertir un nom de niveau en valeur numérique
     */
    private function resolveNiveau($niveau): ?int
    {
        if (is_numeric($niveau)) {
            return (int) $niveau;
        }

        return Role::convertNiveauNomToValue($niveau);
    }

    /**
     * Liste des rôles - OPTIMISÉ
     */
    public function index(Request $request)
    {
        $query = Role::select([
            'id', 'code', 'nom_role', 'description',
            'niveau_acces', 'est_systeme', 'est_defaut',
            'guard_name', 'created_at', 'updated_at'
        ])->withCount('utilisateurs');

        if ($request->filled('recherche')) {
            $search = $request->recherche;
            $query->where(function($q) use ($search) {
                $q->where('nom_role', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->has('est_systeme')) {
            $query->where('est_systeme', $request->boolean('est_systeme'));
        }

        if ($request->has('niveau_min')) {
            $query->where('niveau_acces', '>=', $request->niveau_min);
        }

        if ($request->has('niveau_max')) {
            $query->where('niveau_acces', '<=', $request->niveau_max);
        }

        if ($request->boolean('exclude_system')) {
            $query->where('est_systeme', false);
        }

        if ($request->filled('code')) {
            $query->where('code', $request->code);
        }

        $roles = $query->orderBy('niveau_acces', 'desc')
            ->orderBy('nom_role')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $roles
        ]);
    }

    /**
     * Créer un rôle - OPTIMISÉ
     */
    public function store(Request $request)
    {
        $request->validate([
            'code' => 'required|string|max:100|unique:roles',
            'nom_role' => 'required|string|max:100|unique:roles',
            'description' => 'nullable|string',
            'niveau_acces' => 'required',
            'permissions' => 'nullable|array',
            'permissions.*' => 'exists:permissions,id'
        ]);

        $niveau = $this->resolveNiveau($request->niveau_acces);

        if ($niveau === null) {
            return response()->json([
                'error' => 'Niveau d\'accès invalide. Utilisez un chiffre (1-10) ou un nom valide.',
                'niveaux_disponibles' => Role::NIVEAU_MAPPING
            ], 422);
        }

        // ✅ Vérification des droits
        $user = auth()->user();
        if ($niveau > 8 && !$user->isSuperAdmin()) {
            return response()->json([
                'error' => 'Seul un Super Admin peut créer des rôles de niveau supérieur à 8'
            ], 403);
        }

        DB::beginTransaction();

        try {
            $role = Role::create([
                'name' => $request->code,
                'code' => $request->code,
                'nom_role' => $request->nom_role,
                'description' => $request->description,
                'niveau_acces' => $niveau,
                'est_systeme' => false,
                'guard_name' => 'api'
            ]);

            if ($request->has('permissions')) {
                $role->permissions()->attach($request->permissions);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Rôle créé avec succès',
                'data' => $role->load(['permissions' => function($q) {
                    $q->select('permissions.id', 'permission_code', 'module', 'categorie');
                }])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Erreur lors de la création: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Afficher un rôle - OPTIMISÉ
     */
    public function show($id)
    {
        $role = Role::select([
            'id', 'code', 'nom_role', 'description',
            'niveau_acces', 'est_systeme', 'est_defaut',
            'guard_name', 'created_at', 'updated_at'
        ])->with([
            'permissions' => function($q) {
                $q->select('permissions.id', 'permission_code', 'module', 'categorie', 'description', 'action');
            },
            'utilisateurs' => function($q) {
                $q->select(['id', 'nom_complet', 'email', 'succursale_id', 'statut'])
                  ->with(['succursale' => function($sq) {
                      $sq->select('id', 'nom', 'code');
                  }])
                  ->limit(10);
            }
        ])->findOrFail($id);

        // ✅ Statistiques - UNE SEULE REQUÊTE
        $stats = $this->getRoleStats($role);

        return response()->json([
            'success' => true,
            'data' => $role,
            'stats' => $stats
        ]);
    }

    /**
     * Mettre à jour un rôle - OPTIMISÉ
     */
    public function update(Request $request, $id)
    {
        $role = Role::findOrFail($id);

        if ($role->est_systeme) {
            return response()->json(['error' => 'Impossible de modifier un rôle système'], 400);
        }

        $request->validate([
            'code' => ['sometimes', 'string', 'max:100', Rule::unique('roles')->ignore($role->id)],
            'nom_role' => ['sometimes', 'string', 'max:100', Rule::unique('roles')->ignore($role->id)],
            'description' => 'nullable|string',
            'niveau_acces' => 'sometimes'
        ]);

        $data = $request->only(['code', 'nom_role', 'description']);

        if ($request->has('niveau_acces')) {
            $niveau = $this->resolveNiveau($request->niveau_acces);

            if ($niveau === null) {
                return response()->json([
                    'error' => 'Niveau d\'accès invalide.'
                ], 422);
            }

            $user = auth()->user();
            if ($niveau > 8 && !$user->isSuperAdmin()) {
                return response()->json([
                    'error' => 'Seul un Super Admin peut définir un niveau d\'accès supérieur à 8'
                ], 403);
            }

            $data['niveau_acces'] = $niveau;
        }

        $role->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Rôle mis à jour',
            'data' => $role->fresh(['permissions' => function($q) {
                $q->select('permissions.id', 'permission_code', 'module');
            }])
        ]);
    }

    /**
     * Supprimer un rôle
     */
    public function destroy($id)
    {
        try {
            $role = Role::findOrFail($id);

            if ($role->est_systeme) {
                return response()->json([
                    'success' => false,
                    'error' => 'Impossible de supprimer un rôle système'
                ], 400);
            }

            $utilisateursCount = $role->utilisateurs()->count();
            if ($utilisateursCount > 0) {
                return response()->json([
                    'success' => false,
                    'error' => "Impossible de supprimer ce rôle, il est utilisé par {$utilisateursCount} utilisateur(s)"
                ], 400);
            }

            $role->delete();

            return response()->json([
                'success' => true,
                'message' => 'Rôle supprimé avec succès'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Assigner des permissions - OPTIMISÉ
     */
    public function assignPermissions(Request $request, $id)
    {
        $role = Role::findOrFail($id);

        if ($role->est_systeme) {
            return response()->json(['error' => 'Impossible de modifier les permissions d\'un rôle système'], 400);
        }

        $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'exists:permissions,id',
            'mode' => 'required|in:sync,add,remove'
        ]);

        DB::beginTransaction();

        try {
            switch ($request->mode) {
                case 'sync':
                    $role->permissions()->sync($request->permissions);
                    $message = 'Permissions synchronisées';
                    break;
                case 'add':
                    $role->permissions()->syncWithoutDetaching($request->permissions);
                    $message = 'Permissions ajoutées';
                    break;
                case 'remove':
                    $role->permissions()->detach($request->permissions);
                    $message = 'Permissions retirées';
                    break;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $role->load(['permissions' => function($q) {
                    $q->select('permissions.id', 'permission_code', 'module', 'categorie');
                }])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Erreur: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Obtenir les permissions d'un rôle (groupées) - OPTIMISÉ
     */
    public function permissions($id)
    {
        $role = Role::findOrFail($id);

        $permissions = $role->permissions()
            ->select(['id', 'permission_code', 'module', 'categorie', 'description', 'action', 'est_systeme'])
            ->orderBy('module')
            ->orderBy('categorie')
            ->orderBy('permission_code')
            ->get()
            ->groupBy(['module', 'categorie']);

        $result = [];
        foreach ($permissions as $module => $categories) {
            $moduleData = ['module' => $module, 'categories' => []];

            foreach ($categories as $categorie => $perms) {
                $moduleData['categories'][] = [
                    'nom' => $categorie ?: 'Général',
                    'permissions' => $perms->map(fn($p) => [
                        'id' => $p->id,
                        'code' => $p->permission_code,
                        'description' => $p->description,
                        'action' => $p->action,
                        'est_systeme' => $p->est_systeme
                    ])
                ];
            }

            $result[] = $moduleData;
        }

        return response()->json([
            'success' => true,
            'data' => $result
        ]);
    }

    /**
     * Dupliquer un rôle - OPTIMISÉ
     */
    public function duplicate(Request $request, $id)
    {
        $role = Role::findOrFail($id);

        $request->validate([
            'code' => 'required|string|max:100|unique:roles',
            'nom_role' => 'required|string|max:100|unique:roles'
        ]);

        DB::beginTransaction();

        try {
            $newRole = Role::create([
                'name' => $request->code,
                'code' => $request->code,
                'nom_role' => $request->nom_role,
                'description' => ($role->description ? $role->description . ' - ' : '') . 'Copie',
                'niveau_acces' => $role->niveau_acces,
                'est_systeme' => false,
                'guard_name' => 'api'
            ]);

            $permissions = $role->permissions()->pluck('permissions.id')->toArray();
            if (!empty($permissions)) {
                $newRole->permissions()->attach($permissions);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Rôle dupliqué avec succès',
                'data' => $newRole->load(['permissions' => function($q) {
                    $q->select('permissions.id', 'permission_code', 'module');
                }])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Erreur: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Liste des niveaux d'accès disponibles - OPTIMISÉ
     */
    public function niveauxListe()
    {
        $niveaux = [];

        foreach (Role::NIVEAU_MAPPING as $nom => $valeur) {
            if (!isset($niveaux[$valeur])) {
                $niveaux[$valeur] = [
                    'valeur' => $valeur,
                    'noms' => [],
                    'label' => $this->getNiveauLabel($valeur)
                ];
            }
            $niveaux[$valeur]['noms'][] = $nom;
        }

        ksort($niveaux);

        return response()->json([
            'success' => true,
            'data' => array_values($niveaux)
        ]);
    }

    /**
     * Obtenir les statistiques globales des rôles - OPTIMISÉ
     */
    public function statistiques()
    {
        // ✅ UNE SEULE REQUÊTE pour les stats
        $stats = $this->getGlobalRoleStats();

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    // ============================================================
    // MÉTHODES PRIVÉES
    // ============================================================

    /**
     * Statistiques d'un rôle - OPTIMISÉ
     */
    private function getRoleStats(Role $role): array
    {
        $totalUtilisateurs = $role->utilisateurs()->count();

        return [
            'nombre_utilisateurs' => $totalUtilisateurs,
            'utilisateurs_actifs' => $role->utilisateurs()->where('statut', 'ACTIF')->count(),
            'utilisateurs_inactifs' => $role->utilisateurs()->where('statut', 'INACTIF')->count(),
            'permissions_par_module' => $role->permissions
                ->groupBy('module')
                ->map(fn($items) => [
                    'total' => $items->count(),
                    'permissions' => $items->pluck('permission_code')
                ]),
            'total_permissions' => $role->permissions->count(),
            'est_modifiable' => !$role->est_systeme,
            'peut_etre_supprime' => !$role->est_systeme && $totalUtilisateurs == 0,
            'niveau_acces' => $role->niveau_acces,
            'est_defaut' => $role->est_defaut,
            'niveau_label' => $this->getNiveauLabel($role->niveau_acces)
        ];
    }

    /**
     * Statistiques globales des rôles - UNE SEULE REQUÊTE
     */
    private function getGlobalRoleStats(): array
    {
        $stats = Role::selectRaw("
            COUNT(*) as total_roles,
            SUM(CASE WHEN est_systeme = 1 THEN 1 ELSE 0 END) as roles_systeme,
            SUM(CASE WHEN est_systeme = 0 THEN 1 ELSE 0 END) as roles_non_systeme
        ")->first();

        $rolesAvecUtilisateurs = Role::has('utilisateurs')->count();
        $rolesSansUtilisateurs = Role::doesntHave('utilisateurs')->count();

        $niveauxAcces = Role::select('niveau_acces', DB::raw('count(*) as total'))
            ->groupBy('niveau_acces')
            ->orderBy('niveau_acces', 'desc')
            ->get();

        $topRoles = Role::withCount('utilisateurs')
            ->select(['id', 'code', 'nom_role'])
            ->orderBy('utilisateurs_count', 'desc')
            ->limit(5)
            ->get();

        return [
            'total_roles' => (int) $stats->total_roles,
            'roles_systeme' => (int) $stats->roles_systeme,
            'roles_non_systeme' => (int) $stats->roles_non_systeme,
            'roles_avec_utilisateurs' => $rolesAvecUtilisateurs,
            'roles_sans_utilisateurs' => $rolesSansUtilisateurs,
            'niveaux_acces' => $niveauxAcces->map(fn($item) => [
                'niveau' => $item->niveau_acces,
                'total' => $item->total,
                'label' => $this->getNiveauLabel($item->niveau_acces)
            ]),
            'top_roles' => $topRoles
        ];
    }

    /**
     * Obtenir le label d'un niveau
     */
    private function getNiveauLabel(int $niveau): string
    {
        if ($niveau <= 3) return 'Opérationnel';
        if ($niveau <= 6) return 'Supervision';
        if ($niveau <= 8) return 'Gestion';
        return 'Stratégique';
    }
}