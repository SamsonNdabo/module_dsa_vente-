<?php

namespace App\Http\Controllers\Produit;

use App\Http\Controllers\Controller;
use App\Models\ProduitImei;
use App\Models\Produit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ProduitImeiController extends Controller
{
    /**
     * Liste des IMEI avec filtres optimisés
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        $query = ProduitImei::with([
            'produit:id,nom,code_barre,prix_vente,gere_imei',
            'succursale:id,nom,adresse,telephone',
            'lotStock:id,date_expiration'
        ]);

        // Gestion des permissions (comme dans FactureController)
        if ($user->is_admin) {
            // Admin voit tout - pas de restriction
        } elseif ($user->is_chef_agence) {
            // Chef d'agence voit les IMEI de sa succursale
            if (!$user->succursale_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune succursale associée'
                ], 403);
            }
            $query->where('succursale_id', $user->succursale_id);
        } else {
            // Autres utilisateurs voient les IMEI de leur succursale
            $query->where('succursale_id', $user->succursale_id);
        }

        // Filtres
        if ($request->filled('produit_id')) {
            $query->where('produit_id', $request->produit_id);
        }

        if ($request->filled('succursale_id')) {
            if ($user->is_admin) {
                // Admin peut filtrer par n'importe quelle succursale
                $query->where('succursale_id', $request->succursale_id);
            } else {
                // Non-admin ne peut filtrer que sa succursale
                if ($request->succursale_id != $user->succursale_id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Vous ne pouvez pas filtrer sur une autre succursale'
                    ], 403);
                }
                // Le filtre est déjà appliqué par la restriction ci-dessus
            }
        }

        if ($request->filled('statut')) {
            $query->where('statut', $request->statut);
        }

        if ($request->filled('lot_stock_id')) {
            $query->where('lot_stock_id', $request->lot_stock_id);
        }

        if ($request->filled('recherche')) {
            $search = $request->recherche;
            $query->where(function($q) use ($search) {
                $q->where('numero_imei', 'like', "%{$search}%")
                  ->orWhere('numero_serie', 'like', "%{$search}%")
                  ->orWhereHas('produit', function($p) use ($search) {
                      $p->where('nom', 'like', "%{$search}%")
                        ->orWhere('code_barre', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->filled('date_entree_debut')) {
            $query->whereDate('date_entree', '>=', $request->date_entree_debut);
        }

        if ($request->filled('date_entree_fin')) {
            $query->whereDate('date_entree', '<=', $request->date_entree_fin);
        }

        if ($request->filled('date_vente_debut')) {
            $query->whereDate('date_vente', '>=', $request->date_vente_debut);
        }

        if ($request->filled('date_vente_fin')) {
            $query->whereDate('date_vente', '<=', $request->date_vente_fin);
        }

        if ($request->filled('est_garantie_active')) {
            $query->where('date_fin_garanti', '>', now());
        }

        if ($request->filled('date_fin_garanti_debut')) {
            $query->whereDate('date_fin_garanti', '>=', $request->date_fin_garanti_debut);
        }

        if ($request->filled('date_fin_garanti_fin')) {
            $query->whereDate('date_fin_garanti', '<=', $request->date_fin_garanti_fin);
        }

        // Tri sécurisé (comme dans FactureController)
        $sortField = $request->input('order_by', 'created_at');
        $sortDirection = $request->input('order_dir', 'desc');
        $allowedSorts = ['created_at', 'numero_imei', 'numero_serie', 'statut', 'date_entree', 'date_vente', 'date_fin_garanti'];
        
        if (in_array($sortField, $allowedSorts)) {
            $sortDirection = in_array(strtolower($sortDirection), ['asc', 'desc']) ? $sortDirection : 'desc';
            $query->orderBy($sortField, $sortDirection);
        }

        // Pagination sécurisée
        $perPage = $request->input('per_page', 15);
        $perPage = min(max((int) $perPage, 1), 100);

        $imeis = $query->paginate($perPage);

        // Statistiques (avec la même logique de permissions)
        $statsQuery = ProduitImei::query();
        if (!$user->is_admin) {
            $statsQuery->where('succursale_id', $user->succursale_id);
        }

        // Appliquer les mêmes filtres de date pour les statistiques
        if ($request->filled('date_entree_debut')) {
            $statsQuery->whereDate('date_entree', '>=', $request->date_entree_debut);
        }
        if ($request->filled('date_entree_fin')) {
            $statsQuery->whereDate('date_entree', '<=', $request->date_entree_fin);
        }

        $stats = [
            'total' => (clone $statsQuery)->count(),
            'en_stock' => (clone $statsQuery)->where('statut', ProduitImei::STATUT_EN_STOCK)->count(),
            'vendu' => (clone $statsQuery)->where('statut', ProduitImei::STATUT_VENDU)->count(),
            'reserve' => (clone $statsQuery)->where('statut', ProduitImei::STATUT_RESERVE)->count(),
            'transfere' => (clone $statsQuery)->where('statut', ProduitImei::STATUT_TRANSFERE)->count(),
            'retourne' => (clone $statsQuery)->where('statut', ProduitImei::STATUT_RETOURNE)->count(),
            'maintenance' => (clone $statsQuery)->where('statut', ProduitImei::STATUT_MAINTENANCE)->count(),
            'garantie_active' => (clone $statsQuery)->where('date_fin_garanti', '>', now())->count(),
            'garantie_expiree' => (clone $statsQuery)->where('date_fin_garanti', '<', now())->whereNotNull('date_fin_garanti')->count()
        ];

        return response()->json([
            'success' => true,
            'data' => $imeis,
            'stats' => $stats,
            'filters' => $request->all(), // Optionnel: pour le débogage
            'user_permissions' => [
                'is_admin' => $user->is_admin,
                'is_chef_agence' => $user->is_chef_agence,
                'succursale_id' => $user->succursale_id
            ] // Optionnel: pour le débogage
        ]);
    }


    /**
     * Afficher un IMEI spécifique
     */
    public function show($id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-stock-agence') && !$user->hasPermission('manage-stock-agence')) {
            return response()->json(['success' => false, 'message' => 'Permission refusée'], 403);
        }

        $imei = ProduitImei::with([
            'produit',
            'succursale',
            'lotStock',
            'mouvementEntree',
            'mouvementSortie',
            'facture',
            'commande',
            'client'
        ])->findOrFail($id);

        // Vérification d'accès
        if (!$user->hasPermission('manage-stock-agence')) {
            if ($imei->succursale_id != $user->succursale_id) {
                return response()->json(['success' => false, 'message' => 'Accès refusé'], 403);
            }
        }

        // Historique des statuts (si stocké dans metadata)
        $historique = $imei->metadata['historique_statuts'] ?? [];

        return response()->json([
            'success' => true,
            'data' => [
                'imei' => $imei,
                'historique' => $historique,
                'relations' => [
                    'produit' => $imei->produit,
                    'succursale' => $imei->succursale,
                    'lot' => $imei->lotStock,
                    'mouvement_entree' => $imei->mouvementEntree,
                    'mouvement_sortie' => $imei->mouvementSortie,
                    'facture' => $imei->facture,
                    'commande' => $imei->commande,
                    'client' => $imei->client,
                ]
            ]
        ]);
    }
    /**
     * Mettre à jour les informations administratives d'un IMEI
     *
     * IMPORTANT :
     * - Le statut n'est PAS modifiable ici.
     * - La succursale n'est PAS modifiable ici.
     * - Les changements de statut passent par les services métier.
     */
    public function update(Request $request, $id)
    {
        $user = auth()->user();
    
        if (!$user->hasPermission('manage-stock-agence')) {
            return response()->json([
                'success' => false,
                'message' => 'Permission refusée'
            ], 403);
        }
    
        DB::beginTransaction();
    
        try {
    
            /*
             * ============================================================
             * RÉCUPÉRATION VERROUILLÉE
             * ============================================================
             */
            $imei = ProduitImei::where('id', $id)
                ->lockForUpdate()
                ->firstOrFail();
    
            /*
             * ============================================================
             * VÉRIFICATION SUCCURSALE
             * ============================================================
             */
            if ($imei->succursale_id != $user->succursale_id) {
    
                DB::rollBack();
    
                return response()->json([
                    'success' => false,
                    'message' => 'Accès refusé'
                ], 403);
            }
    
            /*
             * ============================================================
             * VALIDATION
             *
             * Le statut et la succursale ne sont volontairement
             * PAS modifiables ici.
             * ============================================================
             */
            $validator = Validator::make($request->all(), [
    
                'numero_imei' => [
                    'sometimes',
                    'string',
                    'min:15',
                    'max:17',
                    Rule::unique('produit_imei', 'numero_imei')
                        ->ignore($imei->id)
                ],
    
                'numero_serie' => [
                    'nullable',
                    'string',
                    'max:100'
                ],
    
                'prix_achat' => [
                    'nullable',
                    'numeric',
                    'min:0'
                ],
    
                'prix_vente' => [
                    'nullable',
                    'numeric',
                    'min:0'
                ],
    
                'date_expiration' => [
                    'nullable',
                    'date'
                ],
    
                'date_fin_garanti' => [
                    'nullable',
                    'date'
                ],
    
                'garanti_jour' => [
                    'nullable',
                    'integer',
                    'min:0'
                ],
    
                'emplacement' => [
                    'nullable',
                    'string',
                    'max:150'
                ],
    
                'notes' => [
                    'nullable',
                    'string'
                ],
            ]);
    
            if ($validator->fails()) {
    
                DB::rollBack();
    
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }
    
            /*
             * ============================================================
             * IMEI DÉJÀ ENGAGÉ
             *
             * On évite de modifier son numéro après une opération
             * métier importante.
             * ============================================================
             */
            if (
                $request->has('numero_imei') &&
                $request->numero_imei !== $imei->numero_imei
            ) {
    
                if (in_array($imei->statut, [
                    ProduitImei::STATUT_VENDU,
                    ProduitImei::STATUT_TRANSFERE,
                    ProduitImei::STATUT_PERDU,
                    ProduitImei::STATUT_CASSE,
                ])) {
    
                    DB::rollBack();
    
                    return response()->json([
                        'success' => false,
                        'message' =>
                            "Le numéro IMEI ne peut plus être modifié " .
                            "car cet IMEI est dans l'état {$imei->statut}."
                    ], 400);
                }
            }
    
            /*
             * ============================================================
             * MISE À JOUR DES DONNÉES ADMINISTRATIVES
             * ============================================================
             */
            $imei->fill($request->only([
                'numero_imei',
                'numero_serie',
                'prix_achat',
                'prix_vente',
                'date_expiration',
                'date_fin_garanti',
                'garanti_jour',
                'emplacement',
                'notes',
            ]));
    
            /*
             * ============================================================
             * GARANTIE
             * ============================================================
             */
            if (
                $request->has('garanti_jour') &&
                (int) $request->garanti_jour > 0
            ) {
                $imei->activerGarantie(
                    (int) $request->garanti_jour
                );
            }
    
            $imei->save();
    
            DB::commit();
    
            return response()->json([
                'success' => true,
                'message' => 'IMEI mis à jour avec succès',
                'data' => $imei->fresh([
                    'produit',
                    'succursale'
                ])
            ]);
    
        } catch (\Throwable $e) {
    
            DB::rollBack();
    
            Log::error('Erreur mise à jour IMEI', [
                'imei_id' => $id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
    
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour de l\'IMEI'
            ], 500);
        }
    }

    /**
     * Supprimer un IMEI
     */
    public function destroy($id)
    {
        $user = auth()->user();
    
        if (!$user->hasPermission('manage-stock-agence')) {
            return response()->json([
                'success' => false,
                'message' => 'Permission refusée'
            ], 403);
        }
    
        $imei = ProduitImei::findOrFail($id);
    
        // Vérification d'accès
        if ($imei->succursale_id != $user->succursale_id) {
            return response()->json([
                'success' => false,
                'message' => 'Accès refusé'
            ], 403);
        }
    
        // Un IMEI vendu ne doit jamais être supprimé
        if ($imei->statut === ProduitImei::STATUT_VENDU) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer un IMEI déjà vendu'
            ], 400);
        }
    
        DB::beginTransaction();
    
        try {
    
            /*
             * IMPORTANT :
             *
             * La suppression d'un IMEI ne modifie PAS
             * la quantité physique du stock.
             *
             * StockSuccursale.quantite_disponible reste
             * la source de vérité du stock.
             */
    
            $imei->delete();
    
            DB::commit();
    
            return response()->json([
                'success' => true,
                'message' => 'IMEI supprimé avec succès'
            ]);
    
        } catch (\Throwable $e) {
    
            DB::rollBack();
    
            Log::error('Erreur suppression IMEI', [
                'imei_id' => $id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
    
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression de l\'IMEI'
            ], 500);
        }
    }


    /**
     * Suppression multiple d'IMEI
     */
    public function bulkDestroy(Request $request)
    {
        $user = auth()->user();
    
        if (!$user->hasPermission('manage-stock-agence')) {
            return response()->json([
                'success' => false,
                'message' => 'Permission refusée'
            ], 403);
        }
    
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array',
            'ids.*' => 'exists:produit_imei,id'
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
    
        $count = 0;
        $errors = [];
        $supprimes = [];
    
        DB::beginTransaction();
    
        try {
    
            $imeis = ProduitImei::whereIn('id', $request->ids)
                ->lockForUpdate()
                ->get();
    
            foreach ($imeis as $imei) {
    
                /*
                 * ----------------------------------------------------------
                 * IMEI VENDU
                 * ----------------------------------------------------------
                 */
                if ($imei->statut === ProduitImei::STATUT_VENDU) {
    
                    $errors[] =
                        "IMEI {$imei->numero_imei} déjà vendu";
    
                    continue;
                }
    
                /*
                 * ----------------------------------------------------------
                 * VÉRIFICATION SUCCURSALE
                 * ----------------------------------------------------------
                 */
                if ($imei->succursale_id != $user->succursale_id) {
    
                    $errors[] =
                        "IMEI {$imei->numero_imei} n'est pas dans votre succursale";
    
                    continue;
                }
    
                /*
                 * ----------------------------------------------------------
                 * SUPPRESSION
                 *
                 * IMPORTANT :
                 * AUCUNE MODIFICATION DU STOCK.
                 *
                 * La suppression d'un IMEI ne signifie pas
                 * qu'un produit physique est sorti du stock.
                 * ----------------------------------------------------------
                 */
    
                $supprimes[] = $imei->id;
                $count++;
            }
    
            /*
             * --------------------------------------------------------------
             * SUPPRESSION DES IMEI
             * --------------------------------------------------------------
             */
            if (!empty($supprimes)) {
    
                ProduitImei::whereIn('id', $supprimes)
                    ->delete();
            }
    
            DB::commit();
    
            return response()->json([
                'success' => true,
                'message' => "{$count} IMEI supprimé(s) avec succès",
                'supprimes' => $count,
                'total' => count($request->ids),
                'errors' => $errors
            ]);
    
        } catch (\Throwable $e) {
    
            DB::rollBack();
    
            Log::error('Erreur suppression multiple IMEI', [
                'user_id' => $user->id,
                'ids' => $request->ids,
                'error' => $e->getMessage(),
            ]);
    
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression des IMEI'
            ], 500);
        }
    }
}