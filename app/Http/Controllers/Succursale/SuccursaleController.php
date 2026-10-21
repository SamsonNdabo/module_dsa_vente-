<?php
// app/Http/Controllers/Succursale/SuccursaleController.php

namespace App\Http\Controllers\Succursale;

use App\Http\Controllers\Controller;
use App\Models\Succursale;
use App\Models\Utilisateur;
use App\Models\Caisses;
use App\Models\Entite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SuccursaleController extends Controller
{
    // ✅ Rôles avec accès total
    private const ROLES_FULL_ACCESS = ['SUPER_ADMIN','CHEF_OPERATIONS'];
    private const ROLES_RESPONSABLE = ['CHEF_AGENCE', 'SUPER_ADMIN'];

    /**
     * Liste des succursales avec filtres - OPTIMISÉ
     * Permissions: view-agences
     */
        public function index(Request $request)
    {
        $user = auth()->user();
    
        if (!$user->hasPermission('view-agences') && !$user->hasPermission('view-all-agences')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour voir les succursales'
            ], 403);
        }
    
        $query = Succursale::select([
            'id', 'code', 'nom', 'type', 'telephone', 'email',
            'adresse', 'ville', 'province', 'pays', 'code_postal',
            'est_principale', 'est_active', 'statut', 'zone_geo',
            'responsable_id', 'entite_id',
            'date_activation', 'date_desactivation',
            'created_at', 'updated_at'
        ])->with([
            'responsable' => function($q) {
                $q->select(['id', 'nom_complet', 'email', 'telephone', 'matricule', 'role_id'])
                  ->with('role:id,code,nom_role');
            },
            'entite' => function($q) {
                $q->select(['id', 'code', 'nom', 'is_active']);
            }
        ]);
    
        // ✅ AJOUT : Ne pas filtrer sur est_active (garder TOUTES les succursales)
        // Le filtre sera appliqué UNIQUEMENT si l'utilisateur le demande explicitement
        if ($request->filled('est_active')) {
            $query->where('est_active', $request->boolean('est_active'));
        }
    
        // RESTRICTION BASÉE SUR LES PERMISSIONS
        if (!in_array($user->role?->code, self::ROLES_FULL_ACCESS) && !$user->hasPermission('view-all-agences')) {
            if ($user->succursale_id) {
                $query->where('id', $user->succursale_id);
            } else {
                return response()->json([
                    'success' => true,
                    'data' => ['data' => [], 'total' => 0],
                    'stats' => []
                ]);
            }
        }
    
        // Filtres
        if ($request->filled('recherche')) {
            $search = $request->recherche;
            $query->where(function($q) use ($search) {
                $q->where('nom', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('ville', 'like', "%{$search}%")
                  ->orWhere('province', 'like', "%{$search}%")
                  ->orWhereHas('responsable', function($r) use ($search) {
                      $r->where('nom_complet', 'like', "%{$search}%");
                  })
                  ->orWhereHas('entite', function($r) use ($search) {
                      $r->where('nom', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                  });
            });
        }
    
        if ($request->filled('entite_id')) {
            $query->where('entite_id', $request->entite_id);
        }
    
        if ($request->filled('ville')) {
            $query->where('ville', $request->ville);
        }
    
        if ($request->filled('province')) {
            $query->where('province', $request->province);
        }
    
        if ($request->filled('statut')) {
            $query->where('statut', $request->statut);
        }
    
        if ($request->filled('est_principale')) {
            $query->where('est_principale', $request->boolean('est_principale'));
        }
    
        if ($request->filled('zone_geo')) {
            $query->where('zone_geo', $request->zone_geo);
        }
    
        if ($request->filled('responsable_id')) {
            $query->where('responsable_id', $request->responsable_id);
        }
    
        if ($request->filled('has_responsable')) {
            $request->boolean('has_responsable') ? $query->whereNotNull('responsable_id') : $query->whereNull('responsable_id');
        }
    
        $orderBy = $request->get('order_by', 'created_at');
        $orderDir = $request->get('order_dir', 'desc');
        $query->orderBy($orderBy, $orderDir);
    
        $perPage = $request->get('per_page', 15);
        $succursales = $query->paginate($perPage);
    
        // Statistiques globales (uniquement pour les utilisateurs avec accès total)
        $stats = [];
        if (in_array($user->role?->code, self::ROLES_FULL_ACCESS) || $user->hasPermission('view-all-agences')) {
            $stats = $this->getGlobalStats();
        }
        
        $entites = Entite::where('is_active', true)
            ->orderBy('nom')
            ->select('id', 'code', 'nom')
            ->get();
    
        return response()->json([
            'success' => true,
            'data' => $succursales,
            'stats' => $stats,
            'entites' => $entites
        ]);
    }
    /**
     * Créer une nouvelle succursale
     * Permissions: create-agences
     */
    public function store(Request $request)
    {
        $user = auth()->user();

        if (!$user->hasPermission('create-agences')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour créer des succursales'
            ], 403);
        }

        $request->validate([
            'code' => 'nullable|string|max:100|unique:succursales',
            'nom' => 'required|string|max:150',
            'type' => 'nullable|string|max:150',
            'telephone' => 'required|string|max:50',
            'email' => 'required|email|max:150|unique:succursales,email',
            'adresse' => 'required|string',
            'ville' => 'required|string|max:100',
            'province' => 'nullable|string|max:100',
            'pays' => 'nullable|string|max:100',
            'code_postal' => 'nullable|string|max:20',
            'coordonnees_gps' => 'nullable|array',
            'heure_ouverture' => 'nullable|date_format:H:i:s',
            'heure_fermeture' => 'nullable|date_format:H:i:s|after:heure_ouverture',
            'est_principale' => 'sometimes|boolean',
            'jours_ouverture' => 'nullable|array',
            'capacite_maximale' => 'nullable|integer|min:1',
            'zone_geo' => 'nullable|string|max:100',
            'responsable_id' => 'nullable|exists:utilisateurs,id',
            'entite_id' => 'nullable|exists:entites,id',
            'configuration' => 'nullable|array'
        ]);

        if ($request->boolean('est_principale') && Succursale::where('est_principale', true)->exists()) {
            return response()->json([
                'success' => false,
                'error' => 'Une succursale principale existe déjà'
            ], 400);
        }

        if ($request->has('responsable_id') && $request->responsable_id) {
            $validation = $this->validerResponsable($request->responsable_id);
            if (!$validation['valide']) {
                return response()->json(['success' => false, 'error' => $validation['message']], 400);
            }
        }
        
        $entites = Entite::where('is_active', true)
        ->orderBy('nom')
        ->select('id', 'code', 'nom')  // Sélectionner uniquement les champs nécessaires
        ->get();

        if ($request->has('entite_id') && $request->entite_id) {
            $entite = Entite::find($request->entite_id);
            if (!$entite) {
                return response()->json([
                    'success' => false,
                    'error' => 'Entité non trouvée'
                ], 400);
            }
            if (!$entite->is_active) {
                return response()->json([
                    'success' => false,
                    'error' => 'L\'entité doit être active pour y associer une succursale'
                ], 400);
            }
        }

        DB::beginTransaction();

        try {
            $code = $request->code ?: Succursale::genererCode($request->nom);

            $succursale = Succursale::create([
                'code' => $code,
                'nom' => $request->nom,
                'type' => $request->type,
                'telephone' => $request->telephone,
                'email' => $request->email,
                'adresse' => $request->adresse,
                'ville' => $request->ville,
                'province' => $request->province,
                'pays' => $request->pays ?? 'RDC',
                'code_postal' => $request->code_postal,
                'coordonnees_gps' => $request->coordonnees_gps,
                'heure_ouverture' => $request->heure_ouverture,
                'heure_fermeture' => $request->heure_fermeture,
                'est_principale' => $request->boolean('est_principale', false),
                'jours_ouverture' => $request->jours_ouverture,
                'capacite_maximale' => $request->capacite_maximale,
                'zone_geo' => $request->zone_geo,
                'responsable_id' => $request->responsable_id,
                'entite_id' => $request->entite_id,
                'configuration' => $request->configuration ?? [
                    'devise_principale' => 'CDF',
                    'devises_acceptees' => ['CDF', 'USD'],
                    'seuil_alerte_stock' => 10,
                    'impression_automatique' => true
                ],
                'est_active' => true,
                'statut' => 'ACTIVE',
                'date_activation' => now()
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Succursale créée avec succès',
                'data' => $succursale->load([
                    'responsable' => function($q) {
                        $q->select(['id', 'nom_complet', 'email', 'role_id'])->with('role:id,code,nom_role');
                    },
                    'entite' => function($q) {
                        $q->select(['id', 'code', 'nom', 'is_active']);
                    }
                ])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'error' => 'Erreur lors de la création: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Afficher une succursale - OPTIMISÉ
     * Permissions: view-agences
     */
    public function show($id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-agences') && !$user->hasPermission('view-all-agences')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour voir les succursales'
            ], 403);
        }

        $succursale = Succursale::select([
            'id', 'code', 'nom', 'type', 'telephone', 'email',
            'adresse', 'ville', 'province', 'pays', 'code_postal',
            'coordonnees_gps', 'heure_ouverture', 'heure_fermeture',
            'est_principale', 'jours_ouverture', 'capacite_maximale',
            'zone_geo', 'responsable_id', 'entite_id',
            'configuration', 'est_active',
            'statut', 'date_activation', 'date_desactivation',
            'motif_desactivation', 'created_at', 'updated_at'
        ])->with([
            'responsable' => function($q) {
                $q->select(['id', 'nom_complet', 'email', 'telephone', 'matricule', 'role_id', 'photo_profil', 'statut'])
                  ->with('role:id,code,nom_role');
            },
            'entite' => function($q) {
                $q->select(['id', 'code', 'nom', 'is_active']);
            },
            'utilisateurs' => function($q) {
                $q->select(['id', 'nom_complet', 'email', 'role_id', 'statut', 'succursale_id'])
                  ->with('role:id,code,nom_role')
                  ->orderBy('nom_complet')
                  ->limit(10);
            }
        ])->findOrFail($id);

        if (!in_array($user->role?->code, self::ROLES_FULL_ACCESS) &&
            !$user->hasPermission('view-all-agences') &&
            $user->succursale_id != $succursale->id) {
            return response()->json([
                'success' => false,
                'error' => 'Non autorisé à voir cette agence'
            ], 403);
        }

        $stats = $this->getSuccursaleStats($succursale);

        return response()->json([
            'success' => true,
            'data' => $succursale,
            'stats' => $stats
        ]);
    }

    /**
     * Mettre à jour une succursale
     * Permissions: edit-agences
     */
    public function update(Request $request, $id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('edit-agences')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour modifier des succursales'
            ], 403);
        }

        $succursale = Succursale::findOrFail($id);

        if ($request->has('code')) {
            return response()->json([
                'success' => false,
                'error' => 'Le code de la succursale ne peut pas être modifié après la création'
            ], 400);
        }

        $request->validate([
            'nom' => 'sometimes|string|max:150',
            'type' => 'nullable|string|max:150',
            'telephone' => 'sometimes|string|max:50',
            'email' => ['sometimes', 'email', 'max:150', Rule::unique('succursales')->ignore($succursale->id)],
            'adresse' => 'sometimes|string',
            'ville' => 'sometimes|string|max:100',
            'province' => 'nullable|string|max:100',
            'pays' => 'nullable|string|max:100',
            'code_postal' => 'nullable|string|max:20',
            'coordonnees_gps' => 'nullable|array',
            'heure_ouverture' => 'nullable|date_format:H:i:s',
            'heure_fermeture' => 'nullable|date_format:H:i:s|after:heure_ouverture',
            'est_principale' => 'sometimes|boolean',
            'jours_ouverture' => 'nullable|array',
            'capacite_maximale' => 'nullable|integer|min:1',
            'zone_geo' => 'nullable|string|max:100',
            'statut' => 'sometimes|in:ACTIVE,INACTIVE,MAINTENANCE',
            'est_active' => 'sometimes|boolean',
            'configuration' => 'nullable|array',
            'responsable_id' => 'nullable|exists:utilisateurs,id',
            'entite_id' => 'required|exists:entites,id'
        ]);

        if ($request->has('est_principale') && $request->boolean('est_principale') && !$succursale->est_principale) {
            $principaleExistante = Succursale::where('est_principale', true)
                ->where('id', '!=', $succursale->id)
                ->exists();

            if ($principaleExistante) {
                return response()->json([
                    'success' => false,
                    'error' => 'Une autre succursale principale existe déjà'
                ], 400);
            }
        }

        if ($request->has('entite_id') && $request->entite_id) {
            $entite = Entite::find($request->entite_id);
            if (!$entite) {
                return response()->json([
                    'success' => false,
                    'error' => 'Entité non trouvée'
                ], 400);
            }
            if (!$entite->is_active) {
                return response()->json([
                    'success' => false,
                    'error' => 'L\'entité doit être active pour y associer une succursale'
                ], 400);
            }
        }

        if ($request->has('responsable_id') && $request->responsable_id != $succursale->responsable_id) {
            if ($request->responsable_id) {
                $validation = $this->validerResponsable($request->responsable_id, $succursale->id);
                if (!$validation['valide']) {
                    return response()->json(['success' => false, 'error' => $validation['message']], 400);
                }
            }
        }

        DB::beginTransaction();

        try {
            $data = $request->only([
                'nom', 'type', 'telephone', 'email', 'adresse', 'ville',
                'province', 'pays', 'code_postal', 'coordonnees_gps',
                'heure_ouverture', 'heure_fermeture', 'est_principale',
                'jours_ouverture', 'capacite_maximale', 'zone_geo',
                'statut', 'est_active', 'configuration', 'responsable_id',
                'entite_id'
            ]);

            $succursale->update($data);

            if ($request->has('est_active') && !$request->boolean('est_active') && $succursale->wasChanged('est_active')) {
                $succursale->utilisateurs()->update(['statut' => 'INACTIF']);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Succursale mise à jour avec succès',
                'data' => $succursale->fresh([
                    'responsable' => function($q) {
                        $q->select(['id', 'nom_complet', 'email', 'role_id'])->with('role:id,code,nom_role');
                    },
                    'entite' => function($q) {
                        $q->select(['id', 'code', 'nom', 'is_active']);
                    }
                ])
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'error' => 'Erreur lors de la mise à jour: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Supprimer une succursale
     * Permissions: delete-agences
     */
    public function destroy($id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('delete-agences')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour supprimer des succursales'
            ], 403);
        }

        $succursale = Succursale::findOrFail($id);

        if ($succursale->est_principale) {
            return response()->json([
                'success' => false,
                'error' => 'Impossible de supprimer la succursale principale'
            ], 400);
        }

        if ($succursale->utilisateurs()->count() > 0) {
            return response()->json([
                'success' => false,
                'error' => 'Impossible de supprimer une succursale avec des utilisateurs actifs'
            ], 400);
        }

        if ($succursale->caisses()->where('statut', 'OUVERTE')->count() > 0) {
            return response()->json([
                'success' => false,
                'error' => 'Fermez d\'abord toutes les caisses ouvertes'
            ], 400);
        }

        DB::beginTransaction();

        try {
            $succursale->delete();
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Succursale supprimée avec succès'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'error' => 'Erreur lors de la suppression: ' . $e->getMessage()
            ], 500);
        }
    }

    public function statistiquesGlobales()
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-agences') && !$user->hasPermission('view-all-agences')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour voir les statistiques'
            ], 403);
        }

        $stats = [
            'total' => Succursale::count(),
            'actives' => Succursale::where('est_active', true)->count(),
            'inactives' => Succursale::where('est_active', false)->count(),
            'principales' => Succursale::where('est_principale', true)->count(),
            'avec_responsable' => Succursale::whereNotNull('responsable_id')->count(),
            'sans_responsable' => Succursale::whereNull('responsable_id')->count(),
            'avec_entite' => Succursale::whereNotNull('entite_id')->count(),
            'sans_entite' => Succursale::whereNull('entite_id')->count(),
            'repartition_par_ville' => Succursale::select('ville', DB::raw('count(*) as total'))
                ->whereNotNull('ville')
                ->groupBy('ville')
                ->orderBy('total', 'desc')
                ->get()
                ->map(fn($item) => ['ville' => $item->ville, 'total' => $item->total]),
            'repartition_par_province' => Succursale::select('province', DB::raw('count(*) as total'))
                ->whereNotNull('province')
                ->groupBy('province')
                ->orderBy('total', 'desc')
                ->get()
                ->map(fn($item) => ['province' => $item->province, 'total' => $item->total]),
            'repartition_par_entite' => Succursale::select('entite_id', DB::raw('count(*) as total'))
                ->whereNotNull('entite_id')
                ->groupBy('entite_id')
                ->with('entite:id,code,nom')
                ->get()
                ->map(fn($item) => [
                    'entite_id' => $item->entite_id,
                    'entite_nom' => $item->entite?->nom,
                    'entite_code' => $item->entite?->code,
                    'total' => $item->total
                ])
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }
    
    // /**
    //  * Récupérer la liste des entités actives pour les listes déroulantes
    //  */
    // private function getEntitesList()
    // {
    //     return Entite::where('is_active', true)
    //         ->orderBy('nom')
    //         ->select('id', 'code', 'nom')
    //         ->get();
    // }

    /**
     * Statistiques d'une succursale - OPTIMISÉ
     * Permissions: view-agences
     */
    public function statistiques($id, Request $request)
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-agences') && !$user->hasPermission('view-all-agences')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour voir les statistiques'
            ], 403);
        }

        $succursale = Succursale::select(['id', 'nom', 'code', 'ville', 'province', 'est_active', 'est_principale', 'entite_id'])
            ->with([
                'responsable' => function($q) {
                    $q->select(['id', 'nom_complet', 'email']);
                },
                'entite' => function($q) {
                    $q->select(['id', 'code', 'nom']);
                }
            ])
            ->findOrFail($id);

        $debut = $request->get('debut', now()->startOfMonth()->toDateString());
        $fin = $request->get('fin', now()->toDateString());

        $stats = $this->getSuccursaleStatsPeriod($succursale, $debut, $fin);

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    // ============================================================
    // MÉTHODES PRIVÉES OPTIMISÉES
    // ============================================================

    /**
     * Statistiques globales - UNE SEULE REQUÊTE
     */
    private function getGlobalStats(): array
    {
        $stats = Succursale::selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN est_active = 1 THEN 1 ELSE 0 END) as actives,
            SUM(CASE WHEN est_active = 0 THEN 1 ELSE 0 END) as inactives,
            SUM(CASE WHEN est_principale = 1 THEN 1 ELSE 0 END) as principales,
            SUM(CASE WHEN responsable_id IS NOT NULL THEN 1 ELSE 0 END) as avec_responsable,
            SUM(CASE WHEN responsable_id IS NULL THEN 1 ELSE 0 END) as sans_responsable,
            SUM(CASE WHEN entite_id IS NOT NULL THEN 1 ELSE 0 END) as avec_entite,
            SUM(CASE WHEN entite_id IS NULL THEN 1 ELSE 0 END) as sans_entite
        ")->first();

        return [
            'total' => (int) $stats->total,
            'actives' => (int) $stats->actives,
            'inactives' => (int) $stats->inactives,
            'principales' => (int) $stats->principales,
            'avec_responsable' => (int) $stats->avec_responsable,
            'sans_responsable' => (int) $stats->sans_responsable,
            'avec_entite' => (int) $stats->avec_entite,
            'sans_entite' => (int) $stats->sans_entite
        ];
    }

    /**
     * Statistiques d'une succursale - OPTIMISÉ
     */
    private function getSuccursaleStats($succursale): array
    {
        $utilisateurs = $succursale->utilisateurs();

        $stats = [
            'utilisateurs' => [
                'total' => $utilisateurs->count(),
                'actifs' => $utilisateurs->where('statut', 'ACTIF')->count(),
                'verrouilles' => $utilisateurs->where('compte_verrouille', true)->count()
            ],
            'caisses' => [
                'total' => $succursale->caisses()->count(),
                'ouvertes' => $succursale->caisses()->where('statut', 'OUVERTE')->count(),
                'fermees' => $succursale->caisses()->where('statut', 'FERMEE')->count()
            ],
            'entite' => $succursale->entite ? [
                'id' => $succursale->entite->id,
                'code' => $succursale->entite->code,
                'nom' => $succursale->entite->nom,
                'is_active' => $succursale->entite->is_active
            ] : null
        ];

        return $stats;
    }

    /**
     * Statistiques d'une succursale par période
     */
    private function getSuccursaleStatsPeriod($succursale, $debut, $fin): array
    {
        return [
            'periode' => ['debut' => $debut, 'fin' => $fin],
            'informations' => [
                'nom' => $succursale->nom,
                'code' => $succursale->code,
                'ville' => $succursale->ville,
                'est_active' => $succursale->est_active,
                'est_principale' => $succursale->est_principale,
                'responsable' => $succursale->responsable ? [
                    'id' => $succursale->responsable->id,
                    'nom' => $succursale->responsable->nom_complet,
                    'email' => $succursale->responsable->email
                ] : null,
                'entite' => $succursale->entite ? [
                    'id' => $succursale->entite->id,
                    'code' => $succursale->entite->code,
                    'nom' => $succursale->entite->nom
                ] : null
            ],
            'utilisateurs' => [
                'total' => $succursale->utilisateurs()->count(),
                'actifs' => $succursale->utilisateurs()->where('statut', 'ACTIF')->count()
            ],
            'caisses' => [
                'total' => $succursale->caisses()->count(),
                'ouvertes' => $succursale->caisses()->where('statut', 'OUVERTE')->count()
            ]
        ];
    }

    /**
     * Valider un responsable
     */
    private function validerResponsable($responsableId, $succursaleId = null): array
    {
        $responsable = Utilisateur::with('role')->find($responsableId);

        if (!$responsable) {
            return ['valide' => false, 'message' => 'Utilisateur non trouvé'];
        }

        if (!in_array($responsable->role?->code, self::ROLES_RESPONSABLE)) {
            return ['valide' => false, 'message' => 'Le responsable doit avoir un rôle de chef d\'agence, superviseur ou super admin'];
        }

        $query = Succursale::where('responsable_id', $responsableId);
        if ($succursaleId) {
            $query->where('id', '!=', $succursaleId);
        }

        if ($query->exists()) {
            return ['valide' => false, 'message' => 'Cet utilisateur est déjà responsable d\'une autre succursale'];
        }

        return ['valide' => true, 'message' => 'OK'];
    }

    // ============================================================
    // MÉTHODES PUBLIQUES SUPPRÉSION MULTIPLE, ACTIVATION, ETC.
    // ============================================================

    /**
     * Suppression multiple
     */
    public function bulkDestroy(Request $request)
    {
        $user = auth()->user();

        if (!$user->hasPermission('delete-agences')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour supprimer des succursales'
            ], 403);
        }

        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:succursales,id'
        ]);

        $count = 0;
        $errors = [];

        DB::beginTransaction();

        foreach ($request->ids as $id) {
            try {
                $succursale = Succursale::find($id);

                if (!$succursale) {
                    $errors[] = "Succursale {$id} non trouvée";
                    continue;
                }

                if ($succursale->est_principale) {
                    $errors[] = "Impossible de supprimer la succursale principale {$succursale->nom}";
                    continue;
                }

                if ($succursale->utilisateurs()->count() > 0) {
                    $errors[] = "La succursale {$succursale->nom} a des utilisateurs actifs";
                    continue;
                }

                if ($succursale->caisses()->where('statut', 'OUVERTE')->count() > 0) {
                    $errors[] = "La succursale {$succursale->nom} a des caisses ouvertes";
                    continue;
                }

                $succursale->delete();
                $count++;

            } catch (\Exception $e) {
                $errors[] = "Erreur sur la succursale {$id}: " . $e->getMessage();
            }
        }

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => "{$count} succursale(s) supprimée(s) avec succès",
            'errors' => $errors
        ]);
    }

    /**
     * Activer une succursale
     */
    public function activer($id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('block-agences')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour activer des succursales'
            ], 403);
        }

        $succursale = Succursale::findOrFail($id);
        $succursale->activer();

        return response()->json([
            'success' => true,
            'message' => 'Succursale activée avec succès',
            'data' => $succursale->fresh([
                'responsable' => function($q) {
                    $q->select(['id', 'nom_complet', 'email']);
                },
                'entite' => function($q) {
                    $q->select(['id', 'code', 'nom']);
                }
            ])
        ]);
    }

    /**
     * Désactiver une succursale
     */
    public function desactiver(Request $request, $id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('block-agences')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour désactiver des succursales'
            ], 403);
        }
        
        // Vérifier s'il y a une caisse ouverte
        $caisseExists = Caisses::where('succursale_id', $id)
            ->where('statut', 'OUVERTE')
            ->exists();
        
        if ($caisseExists) {
            return response()->json([
                'message' => 'Veuillez fermer toutes les caisses de cette succursale avant de pouvoir la fermer'
            ], 409);
        }

        $succursale = Succursale::findOrFail($id);

        $request->validate([
            'motif' => 'nullable|string|max:255'
        ]);

        if ($succursale->est_principale) {
            return response()->json([
                'success' => false,
                'error' => 'Impossible de désactiver la succursale principale'
            ], 400);
        }

        $succursale->desactiver($request->motif);

        return response()->json([
            'success' => true,
            'message' => 'Succursale désactivée avec succès',
            'data' => $succursale->fresh()
        ]);
    }

    /**
     * Mettre en maintenance
     */
    public function maintenance(Request $request, $id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('block-agences')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour mettre en maintenance des succursales'
            ], 403);
        }

        $succursale = Succursale::findOrFail($id);

        $request->validate([
            'motif' => 'required|string|max:255'
        ]);

        if ($succursale->est_principale) {
            return response()->json([
                'success' => false,
                'error' => 'La succursale principale ne peut pas être mise en maintenance'
            ], 400);
        }

        $succursale->mettreEnMaintenance($request->motif);

        return response()->json([
            'success' => true,
            'message' => 'Succursale mise en maintenance avec succès',
            'data' => $succursale->fresh()
        ]);
    }

    // ============================================================
    // MÉTHODES D'EXPORT ET OPTIONS
    // ============================================================

    /**
     * Exporter les succursales
     */
    public function export(Request $request)
    {
        $user = auth()->user();

        if (!$user->hasPermission('export-agences')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour exporter les succursales'
            ], 403);
        }

        $query = Succursale::with(['responsable', 'entite']);

        if ($request->filled('statut')) {
            $query->where('statut', $request->statut);
        }

        if ($request->filled('province')) {
            $query->where('province', $request->province);
        }

        if ($request->filled('est_active')) {
            $query->where('est_active', $request->boolean('est_active'));
        }

        if ($request->filled('entite_id')) {
            $query->where('entite_id', $request->entite_id);
        }

        $succursales = $query->get();

        $csv = $this->genererCSV($succursales);

        return response($csv)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', 'attachment; filename="succursales_' . now()->format('Y-m-d_His') . '.csv"');
    }

    /**
     * Générer CSV
     */
    private function genererCSV($succursales)
    {
        $output = fopen('php://temp', 'r+');

        fputcsv($output, [
            'Code', 'Nom', 'Type', 'Ville', 'Province', 'Pays',
            'Téléphone', 'Email', 'Adresse', 'Responsable', 'Entité',
            'Statut', 'Active', 'Principale', 'Zone Géographique', 'Date Création'
        ]);

        foreach ($succursales as $s) {
            fputcsv($output, [
                $s->code,
                $s->nom,
                $s->type,
                $s->ville,
                $s->province,
                $s->pays,
                $s->telephone,
                $s->email,
                $s->adresse,
                $s->responsable?->nom_complet,
                $s->entite?->nom,
                $s->statut,
                $s->est_active ? 'Oui' : 'Non',
                $s->est_principale ? 'Oui' : 'Non',
                $s->zone_geo,
                $s->created_at->format('d/m/Y H:i')
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Obtenir les options pour les filtres
     */
    public function options()
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-agences') && !$user->hasPermission('view-all-agences')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour voir les options'
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'villes' => Succursale::select('ville')->distinct()->whereNotNull('ville')->orderBy('ville')->pluck('ville'),
                'provinces' => Succursale::select('province')->distinct()->whereNotNull('province')->orderBy('province')->pluck('province'),
                'zones_geo' => Succursale::select('zone_geo')->distinct()->whereNotNull('zone_geo')->orderBy('zone_geo')->pluck('zone_geo'),
                'statuts' => ['ACTIVE', 'INACTIVE', 'MAINTENANCE'],
                'entites' => Entite::select(['id', 'code', 'nom', 'is_active'])
                    ->where('is_active', true)
                    ->orderBy('nom')
                    ->get()
            ]
        ]);
    }

    // ============================================================
    // MÉTHODES DE GESTION DE RESPONSABLE
    // ============================================================

    /**
     * Assigner un responsable
     */
    public function assignResponsable(Request $request, $id)
    {
        $user = auth()->user();

        $succursale = Succursale::findOrFail($id);

        $request->validate([
            'responsable_id' => 'required|exists:utilisateurs,id'
        ]);

        $validation = $this->validerResponsable($request->responsable_id, $succursale->id);
        if (!$validation['valide']) {
            return response()->json(['success' => false, 'error' => $validation['message']], 400);
        }

        DB::beginTransaction();

        try {
            $succursale->responsable_id = $request->responsable_id;
            $succursale->save();

            $responsable = Utilisateur::find($request->responsable_id);
            if ($responsable && (!$responsable->succursale_id || $responsable->succursale_id != $succursale->id)) {
                $responsable->succursale_id = $succursale->id;
                $responsable->save();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Responsable assigné avec succès',
                'data' => $succursale->fresh([
                    'responsable' => function($q) {
                        $q->select(['id', 'nom_complet', 'email', 'role_id'])->with('role:id,code,nom_role');
                    },
                    'entite' => function($q) {
                        $q->select(['id', 'code', 'nom']);
                    }
                ])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'error' => 'Erreur lors de l\'assignation: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Retirer le responsable
     */
    public function removeResponsable($id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('manage-superviseurs')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour retirer un responsable'
            ], 403);
        }

        $succursale = Succursale::findOrFail($id);

        DB::beginTransaction();

        try {
            $succursale->responsable_id = null;
            $succursale->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Responsable retiré avec succès',
                'data' => $succursale->fresh()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'error' => 'Erreur lors du retrait: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Liste des responsables potentiels
     */
    public function responsablesPotentiels(Request $request)
    {
        $user = auth()->user();

        $responsablesExistants = Succursale::whereNotNull('responsable_id')->pluck('responsable_id')->toArray();

        $query = Utilisateur::whereHas('role', function($q) {
                $q->where('code', 'CHEF_AGENCE');
            })
            ->where('statut', 'ACTIF')
            ->whereNotIn('id', $responsablesExistants);

        if ($request->filled('recherche')) {
            $search = $request->recherche;
            $query->where(function($q) use ($search) {
                $q->where('nom_complet', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $responsables = $query->select(['id', 'nom_complet', 'email'])
            ->with('role:id,code,nom_role')
            ->orderBy('nom_complet', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $responsables
        ]);
    }

    /**
     * Mettre à jour la configuration
     */
    public function updateConfiguration(Request $request, $id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('edit-agences')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour modifier la configuration'
            ], 403);
        }

        $succursale = Succursale::findOrFail($id);

        $request->validate([
            'configuration' => 'required|array',
            'configuration.devise_principale' => 'sometimes|string|in:CDF,USD,EUR',
            'configuration.devises_acceptees' => 'sometimes|array',
            'configuration.seuil_alerte_stock' => 'sometimes|integer|min:0',
            'configuration.impression_automatique' => 'sometimes|boolean',
            'configuration.taux_tva_default' => 'sometimes|numeric|min:0|max:100'
        ]);

        $configuration = array_merge($succursale->configuration ?? [], $request->configuration);
        $succursale->configuration = $configuration;
        $succursale->save();

        return response()->json([
            'success' => true,
            'message' => 'Configuration mise à jour avec succès',
            'data' => $succursale->configuration
        ]);
    }

    // ============================================================
    // MÉTHODES DE VÉRIFICATION ET DUPLICATION
    // ============================================================

    public function checkCode(Request $request)
    {
        $user = auth()->user();

        if (!$user->hasPermission('create-agences')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour vérifier les codes'
            ], 403);
        }

        $request->validate([
            'code' => 'required|string|max:100',
            'ignore_id' => 'nullable|exists:succursales,id'
        ]);

        $query = Succursale::where('code', $request->code);
        if ($request->has('ignore_id')) {
            $query->where('id', '!=', $request->ignore_id);
        }

        $exists = $query->exists();

        return response()->json([
            'success' => true,
            'available' => !$exists,
            'message' => $exists ? 'Code déjà utilisé' : 'Code disponible'
        ]);
    }

    public function checkEmail(Request $request)
    {
        $user = auth()->user();

        if (!$user->hasPermission('create-agences')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour vérifier les emails'
            ], 403);
        }

        $request->validate([
            'email' => 'required|email|max:150',
            'ignore_id' => 'nullable|exists:succursales,id'
        ]);

        $query = Succursale::where('email', $request->email);
        if ($request->has('ignore_id')) {
            $query->where('id', '!=', $request->ignore_id);
        }

        $exists = $query->exists();

        return response()->json([
            'success' => true,
            'available' => !$exists,
            'message' => $exists ? 'Email déjà utilisé' : 'Email disponible'
        ]);
    }

    public function duplicate($id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('create-agences')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour dupliquer des succursales'
            ], 403);
        }

        $succursale = Succursale::findOrFail($id);

        DB::beginTransaction();

        try {
            $nouvelleSuccursale = $succursale->replicate();
            $nouvelleSuccursale->code = Succursale::genererCode($succursale->nom . ' (copie)');
            $nouvelleSuccursale->nom = $succursale->nom . ' (copie)';
            $nouvelleSuccursale->email = $this->genererEmailUnique($succursale->email);
            $nouvelleSuccursale->responsable_id = null;
            $nouvelleSuccursale->est_principale = false;
            $nouvelleSuccursale->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Succursale dupliquée avec succès',
                'data' => $nouvelleSuccursale->fresh([
                    'responsable' => function($q) {
                        $q->select(['id', 'nom_complet', 'email']);
                    },
                    'entite' => function($q) {
                        $q->select(['id', 'code', 'nom']);
                    }
                ])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'error' => 'Erreur lors de la duplication: ' . $e->getMessage()
            ], 500);
        }
    }

    private function genererEmailUnique($email)
    {
        $parts = explode('@', $email);
        $name = $parts[0];
        $domain = $parts[1];

        $newEmail = $name . '+copie@' . $domain;
        $counter = 1;

        while (Succursale::where('email', $newEmail)->exists()) {
            $newEmail = $name . '+copie' . $counter . '@' . $domain;
            $counter++;
        }

        return $newEmail;
    }

    // ============================================================
    // MÉTHODES D'HISTORIQUE ET STATISTIQUES GLOBALES
    // ============================================================

    public function historiqueResponsable($id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-agences') && !$user->hasPermission('view-all-agences')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour voir l\'historique'
            ], 403);
        }

        Succursale::findOrFail($id);

        $historique = DB::table('audit_systemes')
            ->where('table_type', 'succursales')
            ->where('table_id', $id)
            ->whereIn('action', ['MODIFICATION', 'ASSIGNATION_RESPONSABLE', 'RETRAIT_RESPONSABLE'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function($audit) {
                $oldValues = json_decode($audit->old_values, true);
                $newValues = json_decode($audit->new_values, true);

                $oldResponsableId = $oldValues['responsable_id'] ?? null;
                $newResponsableId = $newValues['responsable_id'] ?? null;

                $oldResponsable = $oldResponsableId ? Utilisateur::select(['id', 'nom_complet'])->find($oldResponsableId) : null;
                $newResponsable = $newResponsableId ? Utilisateur::select(['id', 'nom_complet'])->find($newResponsableId) : null;

                return [
                    'date' => $audit->created_at,
                    'action' => $audit->action,
                    'ancien_responsable' => $oldResponsable ? ['id' => $oldResponsable->id, 'nom' => $oldResponsable->nom_complet] : null,
                    'nouveau_responsable' => $newResponsable ? ['id' => $newResponsable->id, 'nom' => $newResponsable->nom_complet] : null,
                    'utilisateur' => $audit->user_nom ?? 'Système'
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $historique
        ]);
    }

    public function globalStats()
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-all-agences') && !in_array($user->role?->code, self::ROLES_FULL_ACCESS)) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour voir les statistiques globales'
            ], 403);
        }

        $stats = [
            'succursales' => $this->getGlobalStats(),
            'repartition_par_ville' => Succursale::select('ville', DB::raw('count(*) as total'))
                ->whereNotNull('ville')
                ->groupBy('ville')
                ->orderBy('total', 'desc')
                ->get(),
            'repartition_par_province' => Succursale::select('province', DB::raw('count(*) as total'))
                ->whereNotNull('province')
                ->groupBy('province')
                ->orderBy('total', 'desc')
                ->get(),
            'repartition_par_entite' => Succursale::select('entite_id', DB::raw('count(*) as total'))
                ->whereNotNull('entite_id')
                ->groupBy('entite_id')
                ->with('entite:id,code,nom')
                ->get()
                ->map(fn($item) => [
                    'entite_id' => $item->entite_id,
                    'entite_nom' => $item->entite?->nom,
                    'entite_code' => $item->entite?->code,
                    'total' => $item->total
                ]),
            'evolution_creation' => Succursale::select(
                    DB::raw('DATE_FORMAT(created_at, "%Y-%m") as periode'),
                    DB::raw('count(*) as total')
                )
                ->groupBy('periode')
                ->orderBy('periode')
                ->get()
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    // ============================================================
    // MÉTHODES DE LISTES (CAISSES, UTILISATEURS)
    // ============================================================

    public function utilisateurs($id, Request $request)
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-agences') && !$user->hasPermission('view-all-agences')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour voir les utilisateurs'
            ], 403);
        }

        Succursale::findOrFail($id);

        $query = Utilisateur::where('succursale_id', $id)
            ->select(['id', 'nom_complet', 'email', 'matricule', 'role_id', 'statut', 'compte_verrouille'])
            ->with('role:id,code,nom_role');

        if ($request->filled('recherche')) {
            $search = $request->recherche;
            $query->where(function($q) use ($search) {
                $q->where('nom_complet', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('matricule', 'like', "%{$search}%");
            });
        }

        if ($request->filled('role_id')) {
            $query->where('role_id', $request->role_id);
        }

        if ($request->filled('statut')) {
            $query->where('statut', $request->statut);
        }

        $orderBy = $request->get('order_by', 'nom_complet');
        $orderDir = $request->get('order_dir', 'asc');
        $query->orderBy($orderBy, $orderDir);

        $utilisateurs = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $utilisateurs
        ]);
    }

    public function caisses($id, Request $request)
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-agences') && !$user->hasPermission('view-caisses')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour voir les caisses'
            ], 403);
        }

        Succursale::findOrFail($id);

        $query = Caisse::where('succursale_id', $id)
            ->select(['id', 'nom', 'code', 'type_caisse', 'devise_id', 'statut', 'solde', 'caissier_id'])
            ->with([
                'devise:id,code,nom',
                'caissier:id,nom_complet,email'
            ]);

        if ($request->filled('statut')) {
            $query->where('statut', $request->statut);
        }

        if ($request->filled('type_caisse')) {
            $query->where('type_caisse', $request->type_caisse);
        }

        if ($request->filled('devise_id')) {
            $query->where('devise_id', $request->devise_id);
        }

        $orderBy = $request->get('order_by', 'nom');
        $orderDir = $request->get('order_dir', 'asc');
        $query->orderBy($orderBy, $orderDir);

        $caisses = $query->get();

        return response()->json([
            'success' => true,
            'data' => $caisses
        ]);
    }

    /**
     * Liste des entités pour une succursale
     */
    public function entitesDisponibles(Request $request)
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-agences') && !$user->hasPermission('view-all-agences')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour voir les entités'
            ], 403);
        }

        $query = Entite::select(['id', 'code', 'nom', 'is_active'])
            ->where('is_active', true)
            ->orderBy('nom');

        if ($request->filled('recherche')) {
            $search = $request->recherche;
            $query->where(function($q) use ($search) {
                $q->where('nom', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $entites = $query->get();

        return response()->json([
            'success' => true,
            'data' => $entites
        ]);
    }
}