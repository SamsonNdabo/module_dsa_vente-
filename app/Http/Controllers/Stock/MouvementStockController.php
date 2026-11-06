<?php

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Models\MouvementStock;
use App\Models\StockSuccursale;
use App\Models\LotStock;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MouvementStockController extends Controller
{
    protected StockService $stockService;

    public function __construct(StockService $stockService)
    {
        $this->stockService = $stockService;
    }

    /**
     * Afficher la liste des mouvements - OPTIMISÉ
     * Permissions: view-mouvements-stock
     */
    public function index(Request $request)
    {
        $user = auth()->user();
    
        if (!$user->hasPermission('view-mouvements-stock')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour voir les mouvements de stock'
            ], 403);
        }
    
        try {
            $query = MouvementStock::select([
                'mouvement_id', 'reference_mouvement', 'succursale_id', 'produit_id',
                'type_mouvement', 'quantite', 'quantite_avant', 'quantite_apres',
                'cout_unitaire', 'cout_total', 'reference_document', 'raison',
                'responsable_id', 'date_mouvement', 'created_at'
            ])->with([
                'succursale:id,nom,code',
                'produit:id,nom,code_barre,type',
                'responsable:id,nom_complet,nom_utilisateur'
            ]);
    
            /**
             * 🔐 GESTION DES RÔLES
             */
            if ($user->isSuperAdmin() || $user->isChefOperation() || $user->isAuditeur()) {
                // Accès total
            } elseif ($user->isChefAgence()) {
                if (!$user->succursale_id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Aucune succursale associée à cet utilisateur'
                    ], 403);
                }
                $query->where('succursale_id', $user->succursale_id);
            }
    
            /**
             * 🔎 FILTRES
             */
            if ($request->filled('succursale_id') && $user->isSuperAdmin()) {
                $query->where('succursale_id', $request->succursale_id);
            } elseif ($request->filled('succursale_id') && $user->isChefAgence()) {
                $query->where('succursale_id', $user->succursale_id);
            }
            if ($request->filled('produit_id')) {
                $query->where('produit_id', $request->produit_id);
            }
            if ($request->filled('type_mouvement')) {
                $query->where('type_mouvement', $request->type_mouvement);
            }
            if ($request->filled('date_debut')) {
                $query->whereDate('date_mouvement', '>=', $request->date_debut);
            }
            if ($request->filled('date_fin')) {
                $query->whereDate('date_mouvement', '<=', $request->date_fin);
            }
            if ($request->filled('recherche')) {
                $search = $request->recherche;
                $query->where(function($q) use ($search) {
                    $q->where('reference_mouvement', 'like', "%{$search}%")
                      ->orWhere('reference_document', 'like', "%{$search}%")
                      ->orWhere('raison', 'like', "%{$search}%");
                });
            }
    
            $perPage = $request->get('per_page', 15);
            $mouvements = $query->orderBy('date_mouvement', 'desc')->paginate($perPage);
    
            return response()->json([
                'success' => true,
                'data' => $mouvements
            ]);
    
        } catch (\Exception $e) {
            Log::error('Erreur liste mouvements stock', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Créer un mouvement de stock
     *  SYNCHRONISÉ AVEC LES LOTS
     * Permissions: create-mouvement-stock
     */
    public function store(Request $request)
    {
        $user = auth()->user();

        if (!$user->hasPermission('create-mouvement-stock') && !$user->hasPermission('manage-stock-agence')) {
            return response()->json(['success' => false, 'message' => 'Permission refusée'], 403);
        }

        $validator = Validator::make($request->all(), [
            'succursale_id' => 'required|exists:succursales,id',
            'produit_id' => 'required|exists:produit,id',
            'type_mouvement' => 'required|in:ENTREE,SORTIE,TRANSFERT,RETOUR,AJUSTEMENT,INVENTAIRE,ENTREE_SCAN',
            'quantite' => 'required|integer|min:1',
            'cout_unitaire' => 'nullable|numeric|min:0',
            'reference_document' => 'nullable|string|max:50',
            'raison' => 'nullable|string',
            'responsable_id' => 'nullable|exists:utilisateurs,id',
            'date_mouvement' => 'nullable|date',
            'date_expiration' => 'nullable|date', // pour ENTREE
            'numero_lot' => 'nullable|string|max:100', // pour ENTREE
            'nouvelle_quantite' => 'nullable|integer|min:0', // pour AJUSTEMENT
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        if (!$user->hasPermission('manage-stock-agence')) {
            if ($request->succursale_id != $user->succursale_id) {
                return response()->json(['success' => false, 'message' => 'Succursale non autorisée'], 403);
            }
        }

        try {
            DB::beginTransaction();

            $stock = StockSuccursale::where('succursale_id', $request->succursale_id)
                ->where('produit_id', $request->produit_id)
                ->first();

            if (!$stock) {
                throw new \Exception('Stock non trouvé pour cette succursale et ce produit');
            }

            $type = $request->type_mouvement;
            $mouvement = null;

            // ============================================================
            // REDIRECTION VERS StockService
            // ============================================================

            if (in_array($type, ['ENTREE', 'RETOUR'])) {
                $mouvement = $this->stockService->receptionner($stock, $request->quantite, [
                    'prix_achat' => $request->cout_unitaire,
                    'date_expiration' => $request->date_expiration,
                    'numero_lot' => $request->numero_lot,
                    'raison' => $request->raison ?? 'Réception manuelle',
                    'reference_document' => $request->reference_document,
                    'responsable_id' => $request->responsable_id ?? auth()->id(),
                ]);

            } elseif (in_array($type, ['SORTIE', 'TRANSFERT'])) {
                $mouvement = $this->stockService->expedier($stock, $request->quantite, [
                    'raison' => $request->raison ?? 'Sortie manuelle',
                    'reference_document' => $request->reference_document,
                    'responsable_id' => $request->responsable_id ?? auth()->id(),
                    'autoriser_expires' => $request->autoriser_expires ?? false,
                ]);

            } elseif ($type === 'AJUSTEMENT') {
                $nouvelleQuantite = $request->nouvelle_quantite ?? $stock->quantite_disponible;
                $mouvement = $this->stockService->ajuster($stock, $nouvelleQuantite, [
                    'prix_achat' => $request->cout_unitaire,
                    'date_expiration' => $request->date_expiration,
                    'raison' => $request->raison ?? 'Ajustement manuel',
                    'reference_document' => $request->reference_document,
                    'responsable_id' => $request->responsable_id ?? auth()->id(),
                ]);

            } else {
                // Autres types (INVENTAIRE, ENTREE_SCAN) – ils ne gèrent pas les lots
                // On les garde simples (sans lots) mais on les laisse passer.
                $estSortie = in_array($type, ['SORTIE', 'TRANSFERT']);
                if ($estSortie && $stock->quantite_disponible < $request->quantite) {
                    throw new \Exception('Stock insuffisant');
                }

                $quantiteAvant = $stock->quantite_disponible;
                $quantiteApres = $estSortie
                    ? $stock->quantite_disponible - $request->quantite
                    : $stock->quantite_disponible + $request->quantite;

                $mouvement = MouvementStock::create([
                    'reference_mouvement' => MouvementStock::genererReference(),
                    'succursale_id' => $request->succursale_id,
                    'produit_id' => $request->produit_id,
                    'type_mouvement' => $type,
                    'statut' => 'VALIDE',
                    'quantite' => $request->quantite,
                    'quantite_avant' => $quantiteAvant,
                    'quantite_apres' => $quantiteApres,
                    'cout_unitaire' => $request->cout_unitaire,
                    'cout_total' => $request->cout_unitaire ? $request->cout_unitaire * $request->quantite : null,
                    'reference_document' => $request->reference_document,
                    'raison' => $request->raison,
                    'responsable_id' => $request->responsable_id ?? auth()->id(),
                    'date_mouvement' => $request->date_mouvement ?? now(),
                    'quantite_restante_lot' => 0,
                    'est_lot_fifo' => 0,
                    'prix_achat_lot' => null,
                    'lots_sortie' => null,
                    'lots_entree' => null,
                ]);

                if ($estSortie) {
                    $stock->diminuerStock($request->quantite);
                } else {
                    $stock->augmenterStock($request->quantite);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Mouvement de stock enregistré avec succès',
                'data' => $mouvement->load([
                    'succursale:id,nom,code',
                    'produit:id,nom,code_barre',
                    'responsable:id,nom_complet'
                ])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur création mouvement stock', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Afficher un mouvement - OPTIMISÉ
     */
    public function show($id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-mouvements-stock')) {
            return response()->json(['success' => false, 'message' => 'Permission refusée'], 403);
        }

        try {
            $mouvement = MouvementStock::select([
                'mouvement_id', 'reference_mouvement', 'succursale_id', 'produit_id',
                'type_mouvement', 'statut', 'quantite', 'quantite_avant', 'quantite_apres',
                'cout_unitaire', 'cout_total', 'reference_document', 'raison',
                'responsable_id', 'date_mouvement', 'created_at', 'updated_at'
            ])->with([
                'succursale:id,nom,code,adresse,telephone',
                'produit:id,nom,code_barre,type,description',
                'responsable:id,nom_complet,nom_utilisateur,email'
            ])->find($id);

            if (!$mouvement) {
                return response()->json(['success' => false, 'message' => 'Mouvement non trouvé'], 404);
            }

            if (!$user->hasPermission('manage-stock-agence') && $mouvement->succursale_id != $user->succursale_id) {
                return response()->json(['success' => false, 'message' => 'Accès refusé'], 403);
            }

            return response()->json(['success' => true, 'data' => $mouvement]);

        } catch (\Exception $e) {
            Log::error('Erreur affichage mouvement stock', ['mouvement_id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Mettre à jour un mouvement (seulement métadonnées)
     * Permissions: edit-mouvement-stock
     */
    public function update(Request $request, $id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('edit-mouvement-stock') && !$user->hasPermission('manage-stock-agence')) {
            return response()->json(['success' => false, 'message' => 'Permission refusée'], 403);
        }

        try {
            $mouvement = MouvementStock::find($id);
            if (!$mouvement) {
                return response()->json(['success' => false, 'message' => 'Mouvement non trouvé'], 404);
            }

            if (!$user->hasPermission('manage-stock-agence') && $mouvement->succursale_id != $user->succursale_id) {
                return response()->json(['success' => false, 'message' => 'Accès refusé'], 403);
            }

            $validator = Validator::make($request->all(), [
                'reference_document' => 'nullable|string|max:50',
                'type_document' => 'nullable|string|max:50',
                'raison' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
            }

            $mouvement->update($request->only(['reference_document', 'type_document', 'raison']));

            return response()->json([
                'success' => true,
                'message' => 'Mouvement mis à jour avec succès',
                'data' => $mouvement->fresh(['succursale', 'produit'])
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur mise à jour mouvement stock', ['mouvement_id' => $id, 'user_id' => $user->id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Supprimer un mouvement (UNIQUEMENT SI BROUILLON ET SANS IMPACT SUR LES LOTS)
     * Permissions: delete-mouvement-stock
     */
    public function destroy($id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('delete-mouvement-stock') && !$user->hasPermission('manage-stock-agence')) {
            return response()->json(['success' => false, 'message' => 'Permission refusée'], 403);
        }

        try {
            $mouvement = MouvementStock::find($id);
            if (!$mouvement) {
                return response()->json(['success' => false, 'message' => 'Mouvement non trouvé'], 404);
            }

            if (!$user->hasPermission('manage-stock-agence') && $mouvement->succursale_id != $user->succursale_id) {
                return response()->json(['success' => false, 'message' => 'Accès refusé'], 403);
            }

            //  Vérification : seul un BROUILLON peut être supprimé
            if ($mouvement->statut !== 'BROUILLON') {
                return response()->json(['success' => false, 'message' => 'Impossible de supprimer un mouvement déjà validé ou annulé.'], 422);
            }

            //  Vérification : pas de lots associés
            if (in_array($mouvement->type_mouvement, ['ENTREE', 'RETOUR'])) {
                if (LotStock::where('mouvement_entree_id', $mouvement->mouvement_id)->exists()) {
                    return response()->json(['success' => false, 'message' => 'Ce mouvement a déjà créé un lot. Utilisez l\'annulation.'], 422);
                }
            } elseif (in_array($mouvement->type_mouvement, ['SORTIE', 'TRANSFERT'])) {
                if ($mouvement->lotsConsommes()->exists()) {
                    return response()->json(['success' => false, 'message' => 'Ce mouvement a déjà consommé des lots. Utilisez l\'annulation.'], 422);
                }
            }

            //  Suppression sans modification du stock (puisque le mouvement est en brouillon, le stock n'a pas été modifié)
            $mouvement->delete();

            return response()->json(['success' => true, 'message' => 'Mouvement supprimé avec succès']);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur suppression mouvement stock', ['mouvement_id' => $id, 'user_id' => $user->id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Annuler un mouvement (création d'un mouvement compensateur)
     * Permissions: annuler-mouvement-stock
     */
    public function annuler(Request $request, $id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('annuler-mouvement-stock') && !$user->hasPermission('manage-stock-agence')) {
            return response()->json(['success' => false, 'message' => 'Permission refusée'], 403);
        }

        $validator = Validator::make($request->all(), [
            'raison' => 'required|string|max:255'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $mouvement = MouvementStock::find($id);
            if (!$mouvement) {
                return response()->json(['success' => false, 'message' => 'Mouvement non trouvé'], 404);
            }

            if (!$user->hasPermission('manage-stock-agence') && $mouvement->succursale_id != $user->succursale_id) {
                return response()->json(['success' => false, 'message' => 'Accès refusé'], 403);
            }

            if ($mouvement->statut === 'ANNULE') {
                return response()->json(['success' => false, 'message' => 'Ce mouvement est déjà annulé.'], 422);
            }

            //  Utilisation de StockService::annuler()
            $mouvementAnnulation = $this->stockService->annuler($mouvement, $request->raison);

            return response()->json([
                'success' => true,
                'message' => 'Mouvement annulé avec succès',
                'data' => [
                    'mouvement_annule' => $mouvement->fresh(),
                    'mouvement_compensateur' => $mouvementAnnulation->fresh(['succursale', 'produit']),
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur annulation mouvement stock', ['mouvement_id' => $id, 'user_id' => $user->id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Statistiques des mouvements - OPTIMISÉ
     */
    public function statistiques(Request $request)
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-mouvements-stock')) {
            return response()->json(['success' => false, 'message' => 'Permission refusée'], 403);
        }

        try {
            $query = MouvementStock::query();

            if ($user->hasPermission('view-stock-agence') && !$user->hasPermission('manage-stock-agence')) {
                $query->where('succursale_id', $user->succursale_id);
            }

            if ($request->filled('date_debut')) {
                $query->whereDate('date_mouvement', '>=', $request->date_debut);
            }
            if ($request->filled('date_fin')) {
                $query->whereDate('date_mouvement', '<=', $request->date_fin);
            }

            $stats = [
                'total_entrees' => (clone $query)->whereIn('type_mouvement', ['ENTREE', 'RETOUR'])->sum('quantite'),
                'total_sorties' => (clone $query)->whereIn('type_mouvement', ['SORTIE', 'TRANSFERT'])->sum('quantite'),
                'valeur_entrees' => (clone $query)->whereIn('type_mouvement', ['ENTREE', 'RETOUR'])->sum('cout_total'),
                'valeur_sorties' => (clone $query)->whereIn('type_mouvement', ['SORTIE', 'TRANSFERT'])->sum('cout_total'),
                'par_type' => (clone $query)->select('type_mouvement', DB::raw('count(*) as total'))
                    ->groupBy('type_mouvement')
                    ->get(),
                'evolution_journaliere' => (clone $query)
                    ->select(DB::raw('DATE(date_mouvement) as date'), DB::raw('count(*) as total'))
                    ->groupBy('date')
                    ->orderBy('date')
                    ->get()
            ];

            return response()->json(['success' => true, 'data' => $stats]);

        } catch (\Exception $e) {
            Log::error('Erreur statistiques mouvements stock', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Filtrer les mouvements par type - MÉTHODE GÉNÉRIQUE
     */
    private function getMouvementsByType(Request $request, array $types)
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-mouvements-stock')) {
            return response()->json(['success' => false, 'message' => 'Permission refusée'], 403);
        }

        try {
            $query = MouvementStock::select([
                'mouvement_id', 'reference_mouvement', 'succursale_id', 'produit_id',
                'type_mouvement', 'quantite', 'quantite_avant', 'quantite_apres',
                'reference_document', 'raison', 'responsable_id', 'date_mouvement'
            ])->with([
                'succursale:id,nom,code',
                'produit:id,nom,code_barre,type',
                'responsable:id,nom_complet,nom_utilisateur'
            ])->whereIn('type_mouvement', $types);

            if ($user->hasPermission('view-stock-agence') && !$user->hasPermission('manage-stock-agence')) {
                $query->where('succursale_id', $user->succursale_id);
            }

            $perPage = $request->get('per_page', 15);
            $mouvements = $query->orderBy('date_mouvement', 'desc')->paginate($perPage);

            return response()->json(['success' => true, 'data' => $mouvements]);

        } catch (\Exception $e) {
            Log::error('Erreur filtrage mouvements par type', ['types' => $types, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()], 500);
        }
    }

    // ============================================================
    // MÉTHODES DE FILTRAGE (appellent getMouvementsByType)
    // ============================================================

    public function entrees(Request $request)
    {
        return $this->getMouvementsByType($request, ['ENTREE', 'RETOUR']);
    }

    public function sorties(Request $request)
    {
        return $this->getMouvementsByType($request, ['SORTIE', 'TRANSFERT']);
    }

    public function transferts(Request $request)
    {
        return $this->getMouvementsByType($request, ['TRANSFERT']);
    }

    public function ajustements(Request $request)
    {
        return $this->getMouvementsByType($request, ['AJUSTEMENT']);
    }

    public function inventaires(Request $request)
    {
        return $this->getMouvementsByType($request, ['INVENTAIRE']);
    }

    public function parProduit(Request $request, $produitId)
    {
        return $this->getMouvementsByType($request, array_keys(array_flip(MouvementStock::TYPES_MOUVEMENT)))
            ->where('produit_id', $produitId);
    }

    public function parSuccursale(Request $request, $succursaleId)
    {
        return $this->getMouvementsByType($request, array_keys(array_flip(MouvementStock::TYPES_MOUVEMENT)))
            ->where('succursale_id', $succursaleId);
    }

    public function parPeriode(Request $request, $debut, $fin)
    {
        return $this->getMouvementsByType($request, array_keys(array_flip(MouvementStock::TYPES_MOUVEMENT)))
            ->whereDate('date_mouvement', '>=', $debut)
            ->whereDate('date_mouvement', '<=', $fin);
    }

    public function parResponsable(Request $request, $responsableId)
    {
        return $this->getMouvementsByType($request, array_keys(array_flip(MouvementStock::TYPES_MOUVEMENT)))
            ->where('responsable_id', $responsableId);
    }

    /**
     * Rapport journalier - OPTIMISÉ
     */
    public function rapportJournalier(Request $request)
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-rapports-journaliers')) {
            return response()->json(['success' => false, 'message' => 'Permission refusée'], 403);
        }

        try {
            $date = $request->get('date', date('Y-m-d'));

            $query = MouvementStock::with([
                'succursale:id,nom,code',
                'produit:id,nom,code_barre',
                'responsable:id,nom_complet'
            ])->whereDate('date_mouvement', $date);

            if ($user->hasPermission('view-stock-agence') && !$user->hasPermission('manage-stock-agence')) {
                $query->where('succursale_id', $user->succursale_id);
            }

            $entrees = (clone $query)->whereIn('type_mouvement', ['ENTREE', 'RETOUR'])->get();
            $sorties = (clone $query)->whereIn('type_mouvement', ['SORTIE', 'TRANSFERT'])->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'date' => $date,
                    'entrees' => $entrees,
                    'sorties' => $sorties,
                    'total_entrees' => $entrees->sum('quantite'),
                    'total_sorties' => $sorties->sum('quantite'),
                    'solde_journalier' => $entrees->sum('quantite') - $sorties->sum('quantite')
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur rapport journalier', ['date' => $date ?? null, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Rapport mensuel - OPTIMISÉ
     */
    public function rapportMensuel(Request $request)
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-rapports-journaliers')) {
            return response()->json(['success' => false, 'message' => 'Permission refusée'], 403);
        }

        try {
            $mois = $request->get('mois', date('m'));
            $annee = $request->get('annee', date('Y'));

            $query = MouvementStock::whereYear('date_mouvement', $annee)
                ->whereMonth('date_mouvement', $mois);

            if ($user->hasPermission('view-stock-agence') && !$user->hasPermission('manage-stock-agence')) {
                $query->where('succursale_id', $user->succursale_id);
            }

            $entrees = (clone $query)->whereIn('type_mouvement', ['ENTREE', 'RETOUR'])->sum('quantite');
            $sorties = (clone $query)->whereIn('type_mouvement', ['SORTIE', 'TRANSFERT'])->sum('quantite');

            $evolution = (clone $query)
                ->select(
                    DB::raw('DATE(date_mouvement) as jour'),
                    DB::raw('SUM(CASE WHEN type_mouvement IN ("ENTREE", "RETOUR") THEN quantite ELSE 0 END) as entrees'),
                    DB::raw('SUM(CASE WHEN type_mouvement IN ("SORTIE", "TRANSFERT") THEN quantite ELSE 0 END) as sorties')
                )
                ->groupBy('jour')
                ->orderBy('jour')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'mois' => $mois,
                    'annee' => $annee,
                    'total_entrees' => $entrees,
                    'total_sorties' => $sorties,
                    'evolution' => $evolution
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur rapport mensuel', ['mois' => $mois ?? null, 'annee' => $annee ?? null, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Exporter en CSV
     */
    public function exportCsv(Request $request)
    {
        $user = auth()->user();

        if (!$user->hasPermission('export-stock-reports')) {
            return response()->json(['success' => false, 'message' => 'Permission refusée'], 403);
        }

        try {
            $query = MouvementStock::with([
                'succursale:id,nom,code',
                'produit:id,nom,code_barre',
                'responsable:id,nom_complet'
            ]);

            if ($user->hasPermission('view-stock-agence') && !$user->hasPermission('manage-stock-agence')) {
                $query->where('succursale_id', $user->succursale_id);
            }

            if ($request->filled('date_debut')) {
                $query->whereDate('date_mouvement', '>=', $request->date_debut);
            }
            if ($request->filled('date_fin')) {
                $query->whereDate('date_mouvement', '<=', $request->date_fin);
            }

            $mouvements = $query->orderBy('date_mouvement', 'desc')->get();

            $filename = 'mouvements_stock_' . date('Ymd_His') . '.csv';
            $handle = fopen('php://temp', 'w+');

            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($handle, [
                'Référence', 'Date', 'Succursale', 'Produit', 'Code Produit', 'Type',
                'Quantité', 'Quantité Avant', 'Quantité Après',
                'Réf Document', 'Raison', 'Responsable'
            ], ';');

            foreach ($mouvements as $m) {
                fputcsv($handle, [
                    $m->reference_mouvement,
                    $m->date_mouvement->format('d/m/Y H:i'),
                    $m->succursale?->nom,
                    $m->produit?->nom,
                    $m->produit?->code_barre,
                    $m->type_mouvement,
                    $m->quantite,
                    $m->quantite_avant,
                    $m->quantite_apres,
                    $m->reference_document,
                    $m->raison,
                    $m->responsable?->nom_complet
                ], ';');
            }

            rewind($handle);
            $content = stream_get_contents($handle);
            fclose($handle);

            return response($content, 200)
                ->header('Content-Type', 'text/csv; charset=utf-8')
                ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');

        } catch (\Exception $e) {
            Log::error('Erreur export CSV', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Exporter en PDF (à implémenter)
     */
    public function exportPdf(Request $request)
    {
        $user = auth()->user();

        if (!$user->hasPermission('export-stock-reports')) {
            return response()->json(['success' => false, 'message' => 'Permission refusée'], 403);
        }

        return response()->json([
            'success' => false,
            'message' => 'Export PDF à implémenter avec DomPDF ou autre package'
        ], 501);
    }
}