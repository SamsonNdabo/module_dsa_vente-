<?php

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Models\StockSuccursale;
use App\Models\MouvementStock;
use App\Models\Produit;
use App\Models\Caisses;
use App\Models\ProduitImei; 
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Models\Succursale;
use App\Services\StockAlertService;
use App\Models\LotStock;
use App\Services\LotStockService;
use App\Services\ImeiService; 
use App\Services\EcritureService; 
use Illuminate\Support\Facades\Log;

class StockSuccursaleController extends Controller
{
    protected StockAlertService $stockAlertService;
    protected ImeiService $imeiService; 
    protected EcritureService $ecritureService; 

    public function __construct(
        StockAlertService $stockAlertService,
        ImeiService $imeiService,
        EcritureService $ecritureService 
    ) {
        $this->stockAlertService = $stockAlertService;
        $this->imeiService = $imeiService; 
        $this->ecritureService = $ecritureService; 
    }

    public function index(Request $request)
    {
        $user = auth()->user();
    
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non authentifié'
            ], 401);
        }
    
        $query = StockSuccursale::with([
            'succursale',
            'produit.categorie',
            'depotSource.succursale'
        ]);
    
        /**
         * 🔥 DETECTION CONTEXTE PREPARATION
         */
        $isPreparationContext =
            $request->filled('type_stock') ||
            $request->filled('depot_source_id');
    
        // =========================================================
        // 🔐 GESTION DES ROLES (AJOUT UNIQUEMENT)
        // =========================================================
    
        if ($user->isSuperAdmin() || $user->isChefOperation() || $user->isAuditeur()) {
            // Accès total - rien à filtrer
        }
    
        elseif ($user->isChefAgence()) {
    
            if (!$user->succursale_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune succursale associée'
                ], 403);
            }
    
            $query->where('succursale_id', $user->succursale_id);
        }
    
        /**
         * 🔎 FILTRES (INCHANGÉS)
         */
    
        if ($request->filled('type_stock')) {
            if ($request->type_stock === 'depot') {
                $query->where('is_depot', true);
            } elseif ($request->type_stock === 'point_vente') {
                $query->where('is_depot', false);
            }
        }
    
        if ($request->filled('depot_source_id')) {
            $query->where('depot_source_id', $request->depot_source_id);
        }
    
        // 🔐 correction obligatoire selon règle CHEF_AGENCE
        if ($request->filled('succursale_id')) {
    
            if ($user->isSuperAdmin() || $user->isChefOperation() || $user->isAuditeur()) {
                $query->where('succursale_id', $request->succursale_id);
            }
    
            // CHEF_AGENCE → ignoré automatiquement (sécurité)
        }
    
        if ($request->filled('produit_id')) {
            $query->where('produit_id', $request->produit_id);
        }
    
        if ($request->filled('categorie_id')) {
            $query->whereHas('produit', function ($q) use ($request) {
                $q->where('categorie_id', $request->categorie_id);
            });
        }
    
        if ($request->boolean('alerte')) {
            $query->whereIn('statut_alerte', ['CRITIQUE', 'RUPTURE']);
        }
    
        if ($request->boolean('rupture')) {
            $query->where('statut_alerte', 'RUPTURE');
        }
    
        if ($request->filled('recherche')) {
            $search = $request->recherche;
    
            $query->whereHas('produit', function ($q) use ($search) {
                $q->where('nom', 'like', "%{$search}%")
                  ->orWhere('code_barre', 'like', "%{$search}%");
            });
        }
    
        $stocks = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));
        $alertes = $this->stockAlertService->verifierLotsExpirants($user);
    
        return response()->json([
            'success' => true,
            'data' => $stocks,
            'alertes' => $alertes
        ]);
    }

    /**
     * Afficher un stock spécifique
     */
    public function show($id)
    {
        $user = auth()->user();
        
        $stock = StockSuccursale::with([
            'succursale', 
            'produit.categorie', 
            'depotSource.succursale',
            'pointsVente.succursale'
        ])->find($id);

        if (!$stock) {
            return response()->json([
                'success' => false,
                'message' => 'Stock non trouvé'
            ], 404);
        }

        // Vérification des permissions
        if (!$user->hasPermission('manage-stock-agence') && $stock->succursale_id != $user->succursale_id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas accès au stock de cette succursale'
            ], 403);
        }

        $stats = [
            'mouvements_recents' => $stock->produit->mouvements()
                ->where('succursale_id', $stock->succursale_id)
                ->orderBy('date_mouvement', 'desc')
                ->limit(10)
                ->get(),
            'total_entrees' => $stock->produit->mouvements()
                ->where('succursale_id', $stock->succursale_id)
                ->whereIn('type_mouvement', ['ENTREE', 'RETOUR'])
                ->sum('quantite'),
            'total_sorties' => $stock->produit->mouvements()
                ->where('succursale_id', $stock->succursale_id)
                ->whereIn('type_mouvement', ['SORTIE', 'TRANSFERT'])
                ->sum('quantite'),
            'points_vente_rattaches' => $stock->pointsVente()->count(),
            'est_depot' => $stock->is_depot,
            'depot_source' => $stock->depotSource ? [
                'id' => $stock->depotSource->id,
                'succursale' => $stock->depotSource->succursale->nom,
                'emplacement' => $stock->depotSource->emplacement
            ] : null
        ];

        return response()->json([
            'success' => true,
            'data' => $stock,
            'stats' => $stats
        ]);
    }

    /**
     * Créer un nouveau stock (nécessite manage-stock-agence)
     * Crée automatiquement un lot FIFO avec date d'expiration si produit périssable
     */
    public function store(Request $request)
    {
        $user = auth()->user();
    
        // ============================================================
        // 1. VÉRIFICATION DES PERMISSIONS
        // ============================================================
    
        if (
            !$user->hasPermission('manage-stock-agence') &&
            !$user->hasPermission('create-stock')
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas la permission de créer un stock'
            ], 403);
        }
    
        // Un utilisateur avec uniquement create-stock
        // ne peut créer que dans sa propre succursale
        if (
            $user->hasPermission('create-stock') &&
            !$user->hasPermission('manage-stock-agence')
        ) {
            $request->merge([
                'succursale_id' => $user->succursale_id
            ]);
        }
    
        // ============================================================
        // 2. VALIDATION
        // ============================================================
    
        $validator = Validator::make($request->all(), [
            'succursale_id' => 'required|exists:succursales,id',
            'produit_id' => 'required|exists:produit,id',
    
            'quantite_disponible' => 'required|integer|min:0',
            'quantite_reserve' => 'nullable|integer|min:0',
            'quantite_commande' => 'nullable|integer|min:0',
            'quantite_maximale' => 'nullable|integer|min:0',
            'quantite_minimale' => 'nullable|integer|min:0',
    
            'emplacement' => 'nullable|string|max:100',
            'zone_stockage' => 'nullable|string|max:100',
    
            'is_depot' => 'sometimes|boolean',
            'depot_source_id' => 'nullable|exists:stock_succursale,id',
    
            'prix_achat' => 'nullable|numeric|min:0',
    
            'date_expiration' => 'nullable|date|after:today',
            'numero_lot' => 'nullable|string|max:100',
    
            'imeis' => 'nullable|array',
            'imeis.*' => 'required|string|regex:/^\d{15,17}$/',
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
    
        // ============================================================
        // 3. CONTRÔLE DE LA SUCCURSALE
        // ============================================================
    
        if (
            $user->hasPermission('create-stock') &&
            !$user->hasPermission('manage-stock-agence') &&
            $request->succursale_id != $user->succursale_id
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Vous ne pouvez créer du stock que dans votre succursale'
            ], 403);
        }
    
        // ============================================================
        // 4. VÉRIFIER SI LE STOCK EXISTE DÉJÀ
        // ============================================================
    
        $exists = StockSuccursale::where('succursale_id', $request->succursale_id)
            ->where('produit_id', $request->produit_id)
            ->exists();
    
        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Un stock pour ce produit existe déjà dans cette succursale'
            ], 409);
        }
    
        // ============================================================
        // 5. CONTRÔLE DÉPÔT / SOURCE
        // ============================================================
    
        if (
            $request->boolean('is_depot') &&
            $request->filled('depot_source_id')
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Un dépôt ne peut pas avoir de source'
            ], 422);
        }
    
        if (
            !$request->boolean('is_depot') &&
            $request->filled('depot_source_id')
        ) {
            $depotSource = StockSuccursale::find($request->depot_source_id);
    
            if (!$depotSource) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dépôt source non trouvé'
                ], 404);
            }
    
            if (!$depotSource->is_depot) {
                return response()->json([
                    'success' => false,
                    'message' => 'La source doit être un dépôt'
                ], 422);
            }
        }
    
        // ============================================================
        // 6. TRANSACTION GLOBALE
        // ============================================================
        //
        // TOUT ce qui est créé/modifié à partir d'ici appartient
        // à UNE SEULE transaction.
        //
        // Si une exception est levée à n'importe quelle étape :
        // Laravel annule automatiquement toute la transaction.
        //
        // ============================================================
    
        try {
    
            $stock = DB::transaction(function () use ($request, $user) {
    
                // ====================================================
                // 6.1 PRODUIT
                // ====================================================
    
                $produit = Produit::findOrFail($request->produit_id);
    
                $prixAchat = $request->filled('prix_achat')
                    ? (float) $request->prix_achat
                    : 0.0;
    
                // Synchronisation du prix d'achat
                if ($prixAchat > 0) {
                    $produit->synchroniserPrix($prixAchat);
                }
    
                // ====================================================
                // 6.2 PRODUIT PÉRISSABLE
                // ====================================================
    
                if (
                    $produit->est_perissable &&
                    !$request->filled('date_expiration')
                ) {
                    throw new \RuntimeException(
                        'La date d\'expiration est obligatoire pour ce produit périssable.'
                    );
                }
    
                // ====================================================
                // 6.3 DONNÉES IMEI
                // ====================================================
    
                $imeis = $request->imeis ?? [];
    
                $quantiteDisponible = (int) $request->quantite_disponible;
    
                // ----------------------------------------------------
                // Si des IMEI sont fournis
                // ----------------------------------------------------
    
                if (!empty($imeis)) {
    
                    // Le produit doit gérer les IMEI
                    if (!$produit->gere_imei) {
                        throw new \RuntimeException(
                            'Ce produit ne gère pas les IMEI. ' .
                            'Veuillez retirer les IMEI de la requête.'
                        );
                    }
    
                    // Nombre d'IMEI = quantité
                    if (count($imeis) !== $quantiteDisponible) {
                        throw new \RuntimeException(
                            "Le nombre d'IMEI (" .
                            count($imeis) .
                            ") ne correspond pas à la quantité du stock (" .
                            $quantiteDisponible .
                            ")."
                        );
                    }
    
                    // Pas de doublons
                    if (count($imeis) !== count(array_unique($imeis))) {
                        throw new \RuntimeException(
                            'Les IMEI ne peuvent pas être dupliqués.'
                        );
                    }
    
                    // Vérifier les IMEI déjà existants
                    $existants = ProduitImei::whereIn(
                        'numero_imei',
                        $imeis
                    )
                        ->pluck('numero_imei')
                        ->toArray();
    
                    if (!empty($existants)) {
                        throw new \RuntimeException(
                            'Les IMEI suivants existent déjà : ' .
                            implode(', ', $existants)
                        );
                    }
                }
    
                // ====================================================
                // 6.4 CRÉATION DU STOCK
                // ====================================================
    
                $stock = StockSuccursale::create([
                    'succursale_id' => $request->succursale_id,
                    'produit_id' => $request->produit_id,
    
                    'quantite_disponible' => $quantiteDisponible,
    
                    'quantite_reserve' =>
                        $request->quantite_reserve ?? 0,
    
                    'quantite_commande' =>
                        $request->quantite_commande ?? 0,
    
                    'quantite_maximale' =>
                        $request->quantite_maximale,
    
                    'quantite_minimale' =>
                        $request->quantite_minimale,
    
                    'emplacement' =>
                        $request->emplacement,
    
                    'zone_stockage' =>
                        $request->zone_stockage,
    
                    'is_depot' =>
                        $request->boolean('is_depot'),
    
                    'depot_source_id' =>
                        $request->depot_source_id,
                ]);
    
                // Vérification supplémentaire
                if (!$stock || !$stock->exists) {
                    throw new \RuntimeException(
                        'Impossible de créer le stock.'
                    );
                }
    
                // ====================================================
                // 6.5 CRÉATION DU LOT FIFO
                // ====================================================
    
                $mouvement = null;
    
                if ($stock->quantite_disponible > 0) {
    
                    $dateExpiration = null;

                    if ($produit->est_perissable) {
                    
                        if (!$request->filled('date_expiration')) {
                            throw new \RuntimeException(
                                'La date d\'expiration est obligatoire pour ce produit périssable.'
                            );
                        }
                    
                        $dateExpiration = $request->date_expiration;
                    }
    
                    $mouvement = $this->creerLotEntreeSansModifierStock(
                        $stock->produit_id,
                        $stock->id,
                        $stock->quantite_disponible,
                        $prixAchat,
                        [
                            'succursale_id' =>
                                $stock->succursale_id,
    
                            'date_lot' =>
                                now(),
    
                            'date_expiration' =>
                                $dateExpiration,
    
                            'responsable_id' =>
                                $user->id,
    
                            'raison' =>
                                'Création initiale du stock - Lot automatique',
    
                            'numero_lot_fournisseur' =>
                                $request->numero_lot
                                ?? ('LOT-' . now()->format('YmdHis')),
    
                            'metadata' => [
                                'type_creation' =>
                                    'initial',
    
                                'emplacement' =>
                                    $stock->emplacement,
    
                                'est_perissable' =>
                                    $produit->est_perissable,
    
                                'prix_achat' =>
                                    $prixAchat,
                            ],
                        ]
                    );
    
                    // ------------------------------------------------
                    // Le lot/mouvement est obligatoire si quantité > 0
                    // ------------------------------------------------
    
                    if (!$mouvement) {
                        throw new \RuntimeException(
                            'La création du lot FIFO a échoué.'
                        );
                    }
    
                    // =================================================
                    // 6.6 CRÉATION DES IMEI
                    // =================================================
    
                    if (!empty($imeis) && $produit->gere_imei) {
    
                        $lot = $mouvement->lotEntree;
    
                        if (!$lot) {
                            throw new \RuntimeException(
                                'Le lot FIFO créé est introuvable pour ' .
                                'l\'enregistrement des IMEI.'
                            );
                        }
    
                        $resultImeis =
                            $this->imeiService->creerImeisPourLot(
                                $lot,
                                $stock,
                                $imeis,
                                $mouvement->mouvement_id
                            );
    
                        /*
                         * Si le service retourne explicitement un résultat
                         * d'échec, on annule toute la transaction.
                         */
                        if (
                            is_array($resultImeis) &&
                            array_key_exists('success', $resultImeis) &&
                            $resultImeis['success'] !== true
                        ) {
                            throw new \RuntimeException(
                                $resultImeis['message']
                                ?? 'La création des IMEI a échoué.'
                            );
                        }
                    }
                }
                
                // ✅ DÉBITER LA CAISSE (si l'achat est payé au comptant)
                if ($prixAchat > 0 && $quantiteDisponible > 0) {
                    // ✅ Arrondir le montant TTC à 2 décimales (arrondi monétaire standard)
                    $tauxTVA = $produit->tva ?? 16;
                    $montantTTC = round($prixAchat * $quantiteDisponible * (1 + ($tauxTVA / 100)), 2);
                    
                    $this->debiterCaisse($stock, $montantTTC, $user);
                }
    
                // ====================================================
                // 6.7 ÉCRITURES COMPTABLES
                // ====================================================
    
                if (
                    $quantiteDisponible > 0 &&
                    $prixAchat > 0
                ) {
                    $this->creerEcrituresComptablesStock(
                        $stock,
                        $produit,
                        $quantiteDisponible,
                        $prixAchat,
                        $mouvement
                    );
                }
    
                // ====================================================
                // 6.8 TOUT EST OK
                // ====================================================
                //
                // Aucun commit manuel ici.
                //
                // DB::transaction() effectuera automatiquement :
                //
                //     COMMIT
                //
                // seulement si aucune exception n'a été levée.
                //
                // ====================================================
    
                return $stock;
            });
    
            // ========================================================
            // 7. SUCCÈS
            // ========================================================
    
            return response()->json([
                'success' => true,
    
                'message' =>
                    'Stock créé avec succès' .
                    (!empty($request->imeis)
                        ? ' avec ' . count($request->imeis) . ' IMEI'
                        : ''),
    
                'data' => $stock->load([
                    'succursale',
                    'produit',
                    'depotSource.succursale'
                ])
    
            ], 201);
    
        } catch (\Throwable $e) {
    
            // ========================================================
            // 8. ÉCHEC
            // ========================================================
            //
            // DB::transaction() a déjà effectué automatiquement
            // le ROLLBACK.
            //
            // IMPORTANT :
            // NE PAS faire DB::rollBack() ici, car la transaction
            // a déjà été gérée par DB::transaction().
            //
            // ========================================================
    
            Log::error('Erreur création stock - TRANSACTION ANNULÉE', [
                'user_id' => $user?->id,
                'succursale_id' => $request->succursale_id,
                'produit_id' => $request->produit_id,
    
                'message' => $e->getMessage(),
    
                'exception' => get_class($e),
    
                'trace' => $e->getTraceAsString(),
            ]);
    
            return response()->json([
                'success' => false,
    
                'message' =>
                    'La création du stock a été annulée. ' .
                    'Aucune modification partielle n\'a été conservée.',
    
                'error' => $e->getMessage(),
    
            ], 500);
        }
    }
    
    /**
     * ✅ DÉBITER LA CAISSE (diminuer l'argent) pour un achat
     */
        private function debiterCaisse(StockSuccursale $stock, float $montant, $user): void
    {
        // Récupérer la caisse de la succursale
        $caisse = Caisses::where('succursale_id', $stock->succursale_id)
            ->where('type_caisse', 'PHYSIQUE')
            ->where('categorie','COFFRE')
            ->where('statut', 'OUVERTE')
            ->first();
    
        if (!$caisse) {
            Log::warning('Aucune caisse physique ouverte trouvée pour débiter', [
                'succursale_id' => $stock->succursale_id
            ]);
            return;
        }
    
        // ✅ Cast en int
        $utilisateurId = (int) $user->id;
        $deviseId = (int) ($stock->produit->devise_id ?? 2);
    
        // Débiter la caisse
        $caisse->debiter(
            (float) $montant,
            $deviseId,
            'Achat de ' . $stock->produit->nom,
            'STK-' . $stock->id,
            $utilisateurId,
            'TRANSFERT_SORTANT',
            (int) $stock->id
        );
    
        Log::info('Caisse débitée pour achat de stock', [
            'stock_id' => $stock->id,
            'caisse_id' => $caisse->id,
            'montant' => $montant,
            'produit' => $stock->produit->nom
        ]);
    }


    /**
     * ✅ CRÉER LES ÉCRITURES COMPTABLES POUR LE STOCK (COMME DANS RECEPTIONFOURNISSEURSERVICE)
     */
    private function creerEcrituresComptablesStock(
    StockSuccursale $stock,
    Produit $produit,
    int $quantite,
    float $prixAchat,
    ?MouvementStock $mouvement = null
    ): void {
        // Récupérer l'entité
        $entite = $stock->succursale?->entite;
    
        if (!$entite) {
            throw new \RuntimeException(
                "Impossible de créer l'écriture comptable : aucune entité trouvée " .
                "pour la succursale {$stock->succursale_id}."
            );
        }
    
        // Calcul du montant HT
        $montantHT = round($prixAchat * $quantite, 2);
    
        if ($montantHT <= 0) {
            throw new \RuntimeException(
                "Impossible de créer l'écriture comptable : " .
                "le montant HT est nul ou négatif."
            );
        }
    
        // TVA
        $tauxTVA = $produit->tva ?? 16;
    
        // Prix d'achat TTC
        $prixAchatTTC = round(
            $prixAchat * (1 + ($tauxTVA / 100)),
            2
        );
    
        $montantTTC = round($prixAchatTTC * $quantite, 2);
    
        Log::info('Création écriture comptable - Stock initial', [
            'stock_id' => $stock->id,
            'produit' => $produit->nom,
            'quantite' => $quantite,
            'prix_achat_ht' => $prixAchat,
            'montant_ht' => $montantHT,
            'montant_ttc' => $montantTTC,
            'taux_tva' => $tauxTVA,
        ]);
    
        $result = $this->ecritureService->creerEcritureSimple([
            'montant' => $montantHT,
            'entite_id' => $entite->id,
            'succursale_id' => $stock->succursale_id,
            'code' => 'FIT',
            'operation_libelle' => 'Achat de marchandise au comptant',
            'libelle' => 'Achat de ' . $produit->nom . ' au comptant',
            'date_ecriture' => now()->format('Y-m-d'),
            'reference' => 'STK-' .
                $stock->id . '-' .
                $produit->id . '-' .
                now()->format('YmdHis'),
    
            'auto_overrides' => [
                [
                    'id_auto' => null,
                    'libelle' => 'Stockage Marchandise ' . $produit->nom,
                    'use_default' => false,
                    'valeur' => $montantHT,
                ],
            ],
        ]);
    
        /*
         * TRÈS IMPORTANT :
         * Si le service comptable retourne un échec,
         * on lève une exception afin que la transaction
         * principale fasse ROLLBACK.
         */
        if (!is_array($result) || ($result['success'] ?? false) !== true) {
    
            $message = $result['message']
                ?? 'Erreur inconnue lors de la création de l’écriture comptable.';
    
            Log::error('Échec écriture comptable pour création de stock', [
                'stock_id' => $stock->id,
                'produit' => $produit->nom,
                'message' => $message,
            ]);
    
            throw new \RuntimeException(
                'Échec de l’écriture comptable : ' . $message
            );
        }
    
        Log::info('Écriture comptable créée avec succès', [
            'stock_id' => $stock->id,
            'produit' => $produit->nom,
            'reference' => $result['reference']
                ?? 'STK-' . $stock->id . '-' . $produit->id,
        ]);
    }


    /**
     * Créer un lot d'entrée SANS modifier le stock (pour création initiale)
     */
    private function creerLotEntreeSansModifierStock(
        $produitId,
        $stockId,
        $quantite,
        $prixAchat,
        array $options = []
    ) {
        $stock = StockSuccursale::find($stockId);
    
        if (!$stock) {
            throw new \Exception('Stock non trouvé');
        }
    
        $prixAchat = $prixAchat ?? 0;
        $quantiteAvant = 0;
        $quantiteApres = $stock->quantite_disponible;
    
        $mouvement = new MouvementStock();
    
        $mouvement->reference_mouvement = MouvementStock::genererReference();
        $mouvement->succursale_id = $options['succursale_id'] ?? $stock->succursale_id;
        $mouvement->produit_id = $produitId;
        $mouvement->stock_destination_id = $stockId;
        $mouvement->demande_parent_id = $options['demande_parent_id'] ?? null;
        $mouvement->type_mouvement = 'ENTREE';
        $mouvement->statut = 'VALIDE';
        $mouvement->quantite = $quantite;
        $mouvement->quantite_avant = $quantiteAvant;
        $mouvement->quantite_apres = $quantiteApres;
        $mouvement->cout_unitaire = $prixAchat;
        $mouvement->prix_achat_lot = $prixAchat;
        $mouvement->cout_total = $quantite * $prixAchat;
        $mouvement->quantite_restante_lot = $quantite;
        $mouvement->est_lot_fifo = true;
        $mouvement->date_lot = $options['date_lot'] ?? now();
        $mouvement->date_expiration_lot = $options['date_expiration'] ?? null;
        $mouvement->date_mouvement = now();
        $mouvement->date_validation = now();
        $mouvement->responsable_id = $options['responsable_id'] ?? auth()->id();
        $mouvement->valide_par_id = auth()->id();
        $mouvement->raison = $options['raison'] ?? 'Création initiale du lot FIFO';
        $mouvement->reference_document = $options['reference_document'] ?? null;
    
        $metadata = $options['metadata'] ?? [];
        if (isset($options['numero_lot_fournisseur'])) {
            $metadata['numero_lot_fournisseur'] = $options['numero_lot_fournisseur'];
        }
        $mouvement->metadata = $metadata;
    
        $mouvement->save();
        $mouvement->mouvement_origine_id = $mouvement->mouvement_id;
        $mouvement->save();

        try {
            $lotService = app(LotStockService::class);
            $lotService->creerLotDepuisMouvement($mouvement);
        } catch (\Exception $e) {
            Log::error('Erreur création lot physique', [
                'mouvement_id' => $mouvement->mouvement_id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    
        return $mouvement;
    }

       public function update(Request $request, $id)
    {
        $user = auth()->user();
    
        // ============================================================
        // 1. AUTHENTIFICATION / PERMISSION
        // ============================================================
    
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non authentifié'
            ], 401);
        }
    
        if (!$user->hasPermission('manage-stock-agence')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas la permission de modifier un stock'
            ], 403);
        }
    
        // ============================================================
        // 2. RÉCUPÉRER LE STOCK
        // ============================================================
    
        $stock = StockSuccursale::find($id);
    
        if (!$stock) {
            return response()->json([
                'success' => false,
                'message' => 'Stock non trouvé'
            ], 404);
        }
    
        $produit = Produit::find($stock->produit_id);
    
        if (!$produit) {
            return response()->json([
                'success' => false,
                'message' => 'Produit associé non trouvé'
            ], 404);
        }
    
        // ============================================================
        // 3. VALIDATION
        // ============================================================
    
        $validator = Validator::make($request->all(), [
            'quantite_disponible' => 'sometimes|integer|min:0',
    
            'quantite_reserve' => 'nullable|integer|min:0',
            'quantite_commande' => 'nullable|integer|min:0',
            'quantite_maximale' => 'nullable|integer|min:0',
            'quantite_minimale' => 'nullable|integer|min:0',
    
            'emplacement' => 'nullable|string|max:100',
            'zone_stockage' => 'nullable|string|max:100',
    
            'is_depot' => 'sometimes|boolean',
            'depot_source_id' => 'nullable|exists:stock_succursale,id',
    
            'prix_achat' => 'nullable|numeric|min:0',
    
            'date_expiration' => 'nullable|date',
    
            'imeis' => 'nullable|array',
            'imeis.*' => 'required|string|regex:/^\d{15,17}$/',
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
    
        // ============================================================
        // 4. CONTRÔLE DÉPÔT / POINT DE VENTE
        // ============================================================
    
        if (
            $request->has('is_depot') &&
            $request->boolean('is_depot') != $stock->is_depot
        ) {
            if (
                $stock->is_depot &&
                $stock->pointsVente()->count() > 0
            ) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'Impossible de changer ce dépôt en point de vente car il a des points de vente rattachés'
                ], 422);
            }
        }
    
        if ($request->has('depot_source_id')) {
    
            if ($request->filled('depot_source_id')) {
    
                if ($stock->is_depot) {
                    return response()->json([
                        'success' => false,
                        'message' =>
                            'Impossible de rattacher un dépôt à un autre dépôt'
                    ], 422);
                }
    
                $depotSource = StockSuccursale::find(
                    $request->depot_source_id
                );
    
                if (!$depotSource) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Dépôt source non trouvé'
                    ], 404);
                }
    
                if (!$depotSource->is_depot) {
                    return response()->json([
                        'success' => false,
                        'message' => 'La source doit être un dépôt'
                    ], 422);
                }
            }
        }
    
        // ============================================================
        // 5. DONNÉES DE QUANTITÉ
        // ============================================================
    
        $quantiteDemandee = $request->has('quantite_disponible')
            ? (int) $request->quantite_disponible
            : (int) $stock->quantite_disponible;
    
        $imeis = $request->input('imeis', []);
    
        if (!is_array($imeis)) {
            $imeis = [];
        }
    
        // ============================================================
        // 6. TRANSACTION ATOMIQUE
        // ============================================================
    
        try {
    
            $result = DB::transaction(function () use (
                $request,
                $id,
                $user,
                $produit,
                $imeis,
                $quantiteDemandee
            ) {
    
                // ========================================================
                // 6.1 VERROUILLER LE STOCK
                // ========================================================
    
                $stock = StockSuccursale::where('id', $id)
                    ->lockForUpdate()
                    ->first();
    
                if (!$stock) {
                    throw new \RuntimeException(
                        'Stock non trouvé.'
                    );
                }
    
                // ========================================================
                // 6.2 RÉCUPÉRER TOUS LES LOTS PHYSIQUEMENT RESTANTS
                //
                // IMPORTANT :
                //
                // On NE filtre PAS sur statut = ACTIF.
                //
                // Tous les statuts sont pris en compte :
                // ACTIF
                // BLOQUE
                // QUARANTAINE
                // EXPIRE
                // etc.
                //
                // Seuls les lots avec une quantité restante > 0
                // représentent du stock physique.
                // ========================================================
    
                $lots = LotStock::where(
                        'stock_succursale_id',
                        $stock->id
                    )
                    ->where(
                        'produit_id',
                        $stock->produit_id
                    )
                    ->where('quantite_restante', '>', 0)
                    ->orderByRaw(
                        'CASE WHEN created_at IS NULL THEN 1 ELSE 0 END'
                    )
                    ->orderBy('created_at', 'asc')
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();
    
                // ========================================================
                // 6.3 CALCUL DU STOCK PHYSIQUE RÉEL
                //
                // Tous les statuts sont inclus.
                // ========================================================
    
                $quantiteLotsAvant = (int) $lots->sum(
                    fn ($lot) => (int) $lot->quantite_restante
                );
    
                $quantiteStockAvant = (int) $stock->quantite_disponible;
    
                // ========================================================
                // 6.4 VÉRIFICATION DE COHÉRENCE AVANT AJUSTEMENT
                // ========================================================
    
                if ($quantiteStockAvant !== $quantiteLotsAvant) {
    
                    throw new \RuntimeException(
                        "Incohérence détectée avant l'ajustement : " .
                        "stock disponible = {$quantiteStockAvant}, " .
                        "mais somme de tous les lots restants = {$quantiteLotsAvant}. " .
                        "L'ajustement a été annulé afin de ne pas " .
                        "aggraver l'incohérence."
                    );
                }
    
                $ancienneQuantite = $quantiteLotsAvant;
    
                $nouvelleQuantite = $quantiteDemandee;
    
                $ecart = $nouvelleQuantite - $ancienneQuantite;
    
                $estAugmentation = $ecart > 0;
                $estDiminution = $ecart < 0;
    
                $quantiteEcart = abs($ecart);
    
                // ========================================================
                // 6.5 VALIDATION IMEI
                // ========================================================
    
                if (!empty($imeis)) {
    
                    if (!$estAugmentation) {
    
                        throw new \RuntimeException(
                            'Les IMEI ne peuvent être ajoutés que lors ' .
                            'd\'une augmentation de stock.'
                        );
                    }
    
                    if (!$produit->gere_imei) {
    
                        throw new \RuntimeException(
                            'Ce produit ne gère pas les IMEI. ' .
                            'Veuillez retirer les IMEI de la requête.'
                        );
                    }
    
                    if (count($imeis) !== $quantiteEcart) {
    
                        throw new \RuntimeException(
                            'Le nombre d\'IMEI (' .
                            count($imeis) .
                            ') ne correspond pas à la quantité ajoutée (' .
                            $quantiteEcart .
                            ').'
                        );
                    }
    
                    if (
                        count($imeis) !==
                        count(array_unique($imeis))
                    ) {
                        throw new \RuntimeException(
                            'Les IMEI ne peuvent pas être dupliqués.'
                        );
                    }
    
                    // Vérifier les IMEI déjà existants
                    $existants = ProduitImei::whereIn(
                        'numero_imei',
                        $imeis
                    )
                        ->lockForUpdate()
                        ->pluck('numero_imei')
                        ->toArray();
    
                    if (!empty($existants)) {
    
                        throw new \RuntimeException(
                            'Les IMEI suivants existent déjà : ' .
                            implode(', ', $existants)
                        );
                    }
                }
    
                // ========================================================
                // 6.6 PRIX D'ACHAT
                // ========================================================
    
                $prixAchat = $request->filled('prix_achat')
                    ? (float) $request->prix_achat
                    : (float) ($produit->prix_achat ?? 0);
    
                // ========================================================
                // 6.7 SYNCHRONISATION DU PRIX PRODUIT
                // ========================================================
    
                if (
                    $request->filled('prix_achat') &&
                    $prixAchat > 0
                ) {
                    $produit->synchroniserPrix($prixAchat);
                }
    
                // ========================================================
                // 6.8 CONTRÔLE DU PRIX EN CAS D'AUGMENTATION
                // ========================================================
    
                if ($estAugmentation && $prixAchat <= 0) {
    
                    throw new \RuntimeException(
                        'Le coût unitaire est obligatoire lors ' .
                        'd\'une augmentation de stock.'
                    );
                }
    
                // ========================================================
                // 6.9 VARIABLES DE TRAÇABILITÉ
                // ========================================================
    
                $lotsEntree = [];
                $lotsSortie = [];
    
                $mouvementAjustement = null;
    
                // ========================================================
                // 6.10 AUCUN CHANGEMENT DE QUANTITÉ
                // ========================================================
    
                if ($ecart === 0) {
    
                    Log::info(
                        'Ajustement de quantité sans modification',
                        [
                            'stock_id' => $stock->id,
                            'quantite' => $nouvelleQuantite,
                        ]
                    );
    
                } else {
    
                    // ====================================================
                    // 6.11 CRÉATION DU MOUVEMENT D'AJUSTEMENT
                    // ====================================================
    
                    $mouvementAjustement = MouvementStock::create([
    
                        'reference_mouvement' =>
                            MouvementStock::genererReference(),
    
                        'succursale_id' =>
                            $stock->succursale_id,
    
                        'produit_id' =>
                            $stock->produit_id,
    
                        'stock_destination_id' =>
                            $stock->id,
    
                        'type_mouvement' =>
                            'AJUSTEMENT',
    
                        'statut' =>
                            'VALIDE',
    
                        'quantite' =>
                            $quantiteEcart,
    
                        'quantite_avant' =>
                            $ancienneQuantite,
    
                        'quantite_apres' =>
                            $nouvelleQuantite,
    
                        'responsable_id' =>
                            $user->id,
    
                        'raison' =>
                            'Ajustement manuel de stock',
    
                        'reference_document' =>
                            'AJUST-' . now()->format('YmdHis'),
    
                        'metadata' => [
    
                            'type_ajustement' =>
                                $estAugmentation
                                    ? 'AUGMENTATION'
                                    : 'DIMINUTION',
    
                            'ancienne_quantite' =>
                                $ancienneQuantite,
    
                            'nouvelle_quantite' =>
                                $nouvelleQuantite,
    
                            'quantite_ecart' =>
                                $quantiteEcart,
    
                            'lots_impactes' => [],
                        ],
    
                        'cout_unitaire' =>
                            $prixAchat,
    
                        'cout_total' =>
                            round(
                                $prixAchat * $quantiteEcart,
                                2
                            ),
    
                        'quantite_restante_lot' =>
                            $estAugmentation
                                ? $quantiteEcart
                                : 0,
    
                        'est_lot_fifo' =>
                            $estAugmentation ? 1 : 0,
    
                        'prix_achat_lot' =>
                            $estAugmentation
                                ? $prixAchat
                                : null,
    
                        'lots_sortie' =>
                            null,
    
                        'lots_entree' =>
                            null,
    
                        'date_mouvement' =>
                            now(),
    
                        'date_validation' =>
                            now(),
                    ]);
    
                    // ====================================================
                    // 6.12 AUGMENTATION
                    //
                    // Création d'un nouveau lot.
                    // ====================================================
    
                    if ($estAugmentation) {
    
                        // =============================================================
                        // DATE D'EXPIRATION
                        // =============================================================
                        //
                        // Produit périssable : obligatoire
                        // Produit non périssable : toujours NULL
                        // =============================================================
                        
                        $dateExpiration = null;
                        
                        if ($produit->est_perissable) {
                        
                            if (!$request->filled('date_expiration')) {
                                throw new \RuntimeException(
                                    'La date d\'expiration est obligatoire pour ce produit périssable.'
                                );
                            }
                        
                            $dateExpiration = $request->date_expiration;
                        
                            if (!$dateExpiration) {
                                throw new \RuntimeException(
                                    'La date d\'expiration est invalide.'
                                );
                            }
                        
                        } else {
                        
                            // Produit non périssable :
                            // on ignore complètement toute date envoyée.
                            $dateExpiration = null;
                        }
    
                        $mouvementAjustement->date_lot = now();
    
                        $mouvementAjustement->date_expiration_lot =
                            $dateExpiration;
    
                        $mouvementAjustement->quantite =
                            $quantiteEcart;
    
                        $mouvementAjustement->quantite_restante_lot =
                            $quantiteEcart;
    
                        $mouvementAjustement->cout_unitaire =
                            $prixAchat;
    
                        $mouvementAjustement->prix_achat_lot =
                            $prixAchat;
    
                        $mouvementAjustement->cout_total =
                            round(
                                $prixAchat * $quantiteEcart,
                                2
                            );
    
                        $metadata = is_array(
                            $mouvementAjustement->metadata
                        )
                            ? $mouvementAjustement->metadata
                            : [];
    
                        $metadata['date_expiration'] =
                            $dateExpiration;
    
                        $metadata['prix_achat'] =
                            $prixAchat;
    
                        $metadata['type_creation'] =
                            'ajustement_stock';
    
                        $mouvementAjustement->metadata =
                            $metadata;
    
                        $mouvementAjustement->save();
    
                        // =================================================
                        // CRÉER LE NOUVEAU LOT FIFO
                        // =================================================
    
                        $lotService =
                            app(LotStockService::class);
    
                        $lotService->creerLotDepuisMouvement(
                            $mouvementAjustement
                        );
    
                        $lot = $mouvementAjustement
                            ->fresh()
                            ->lotEntree;
    
                        if (!$lot) {
    
                            $lot = LotStock::where(
                                'stock_succursale_id',
                                $stock->id
                            )
                                ->where(
                                    'produit_id',
                                    $stock->produit_id
                                )
                                ->where(
                                    'quantite_restante',
                                    $quantiteEcart
                                )
                                ->orderByDesc('id')
                                ->first();
                        }
    
                        if (!$lot) {
    
                            throw new \RuntimeException(
                                'Le nouveau lot FIFO d\'ajustement ' .
                                'n\'a pas pu être créé.'
                            );
                        }
    
                        // =================================================
                        // TRAÇABILITÉ LOT
                        //
                        // Table réelle :
                        // mouvement_stock_lots
                        // =================================================
    
                        DB::table('mouvement_stock_lots')->insert([
    
                            'mouvement_stock_id' =>
                                $mouvementAjustement->mouvement_id,
    
                            'lot_stock_id' =>
                                $lot->id,
    
                            'quantite' =>
                                $quantiteEcart,
    
                            'cout_unitaire' =>
                                $prixAchat,
    
                            'created_at' =>
                                now(),
    
                            'updated_at' =>
                                now(),
                        ]);
    
                        $lotsEntree[] = [
    
                            'lot_id' =>
                                $lot->id,
    
                            'quantite' =>
                                $quantiteEcart,
    
                            'cout_unitaire' =>
                                $prixAchat,
                        ];
    
                        // =================================================
                        // IMEI
                        // =================================================
    
                        if (
                            !empty($imeis) &&
                            $produit->gere_imei
                        ) {
    
                            $resultImeis =
                                $this->imeiService
                                    ->creerImeisPourLot(
                                        $lot,
                                        $stock,
                                        $imeis,
                                        $mouvementAjustement
                                            ->mouvement_id
                                    );
    
                            if (
                                is_array($resultImeis) &&
                                array_key_exists(
                                    'success',
                                    $resultImeis
                                ) &&
                                $resultImeis['success'] !== true
                            ) {
    
                                throw new \RuntimeException(
                                    $resultImeis['message']
                                    ??
                                    'La création des IMEI a échoué.'
                                );
                            }
                        }
                    }
    
                    // ====================================================
                    // 6.13 DIMINUTION
                    //
                    // IMPORTANT :
                    //
                    // FIFO PHYSIQUE SUR TOUS LES LOTS RESTANTS,
                    // QUEL QUE SOIT LE STATUT.
                    // ====================================================
    
                    if ($estDiminution) {
    
                        $aRetirer = $quantiteEcart;
    
                        foreach ($lots as $lot) {
    
                            if ($aRetirer <= 0) {
                                break;
                            }
    
                            $quantiteDisponibleLot =
                                (int) $lot->quantite_restante;
    
                            if ($quantiteDisponibleLot <= 0) {
                                continue;
                            }
    
                            $quantiteRetiree =
                                min(
                                    $aRetirer,
                                    $quantiteDisponibleLot
                                );
    
                            $ancienneQuantiteLot =
                                $quantiteDisponibleLot;
    
                            $nouvelleQuantiteLot =
                                $ancienneQuantiteLot -
                                $quantiteRetiree;
    
                            // =============================================
                            // NE JAMAIS MODIFIER quantite_initiale
                            // =============================================
    
                            $lot->quantite_restante =
                                $nouvelleQuantiteLot;
    
                            if ($nouvelleQuantiteLot <= 0) {
    
                                $lot->quantite_restante = 0;
    
                                /*
                                 * Le lot est totalement consommé.
                                 */
                                $lot->statut = 'EPUISE';
                            }
    
                            $lot->save();
    
                            // =============================================
                            // COÛT DU LOT
                            // =============================================
    
                            $coutUnitaireLot = (float) (
                                $lot->cout_unitaire
                                ?? $lot->prix_achat_ttc_unitaire
                                ?? $prixAchat
                                ?? 0
                            );
    
                            // =============================================
                            // TRAÇABILITÉ DU LOT
                            // =============================================
    
                            DB::table('mouvement_stock_lots')->insert([
    
                                'mouvement_stock_id' =>
                                    $mouvementAjustement
                                        ->mouvement_id,
    
                                'lot_stock_id' =>
                                    $lot->id,
    
                                'quantite' =>
                                    $quantiteRetiree,
    
                                'cout_unitaire' =>
                                    $coutUnitaireLot,
    
                                'created_at' =>
                                    now(),
    
                                'updated_at' =>
                                    now(),
                            ]);
    
                            $lotsSortie[] = [
    
                                'lot_id' =>
                                    $lot->id,
    
                                'statut_avant' =>
                                    $lot->getOriginal('statut'),
    
                                'quantite_avant' =>
                                    $ancienneQuantiteLot,
    
                                'quantite_retiree' =>
                                    $quantiteRetiree,
    
                                'quantite_apres' =>
                                    $nouvelleQuantiteLot,
    
                                'cout_unitaire' =>
                                    $coutUnitaireLot,
                            ];
    
                            $aRetirer -= $quantiteRetiree;
                        }
    
                        // =================================================
                        // VÉRIFICATION
                        // =================================================
    
                        if ($aRetirer > 0) {
    
                            throw new \RuntimeException(
                                "Impossible de diminuer le stock de " .
                                "{$quantiteEcart} unité(s). " .
                                "Tous les lots physiquement restants " .
                                "ne contiennent que " .
                                ($quantiteEcart - $aRetirer) .
                                " unité(s)."
                            );
                        }
    
                        // =================================================
                        // TRAÇABILITÉ DU MOUVEMENT
                        // =================================================
    
                        $metadata =
                            is_array(
                                $mouvementAjustement->metadata
                            )
                                ? $mouvementAjustement->metadata
                                : [];
    
                        $metadata['lots_sortie'] =
                            $lotsSortie;
    
                        $metadata['lots_entree'] =
                            [];
    
                        $mouvementAjustement->metadata =
                            $metadata;
    
                        $mouvementAjustement->lots_sortie =
                            $lotsSortie;
    
                        $mouvementAjustement->save();
                    }
    
                    // ====================================================
                    // 6.14 TRAÇABILITÉ POUR AUGMENTATION
                    // ====================================================
    
                    if ($estAugmentation) {
    
                        $metadata =
                            is_array(
                                $mouvementAjustement->metadata
                            )
                                ? $mouvementAjustement->metadata
                                : [];
    
                        $metadata['lots_entree'] =
                            $lotsEntree;
    
                        $metadata['lots_sortie'] =
                            [];
    
                        $mouvementAjustement->metadata =
                            $metadata;
    
                        $mouvementAjustement->lots_entree =
                            $lotsEntree;
    
                        $mouvementAjustement->save();
                    }
                }
    
                // ========================================================
                // 6.15 MISE À JOUR DU STOCK GLOBAL
                // ========================================================
    
                $stock->quantite_disponible =
                    $nouvelleQuantite;
    
                // ========================================================
                // 6.16 AUTRES CHAMPS DU STOCK
                // ========================================================
    
                $updateData = $request->only([
                    'quantite_reserve',
                    'quantite_commande',
                    'quantite_maximale',
                    'quantite_minimale',
                    'emplacement',
                    'zone_stockage',
                    'is_depot',
                    'depot_source_id'
                ]);
    
                $stock->fill($updateData);
    
                $stock->quantite_disponible =
                    $nouvelleQuantite;
    
                $stock->save();
    
                // ========================================================
                // 6.17 CONTRÔLE FINAL
                //
                // IMPORTANT :
                //
                // Tous les lots avec quantite_restante > 0 sont comptés,
                // quel que soit leur statut.
                // ========================================================
    
                $quantiteLotsApres = (int) LotStock::where(
                        'stock_succursale_id',
                        $stock->id
                    )
                    ->where(
                        'produit_id',
                        $stock->produit_id
                    )
                    ->where('quantite_restante', '>', 0)
                    ->sum('quantite_restante');
    
                if ($quantiteLotsApres !== $nouvelleQuantite) {
    
                    throw new \RuntimeException(
                        "Incohérence après ajustement : " .
                        "stock = {$nouvelleQuantite}, " .
                        "somme de tous les lots restants = " .
                        "{$quantiteLotsApres}. " .
                        "L'opération a été annulée."
                    );
                }
    
                // ========================================================
                // 6.18 MISE À JOUR ALERTE
                // ========================================================
    
                $stock
                    ->mettreAJourStatutAlerte()
                    ->save();
    
                // ========================================================
                // 6.19 RAFRAÎCHISSEMENT
                // ========================================================
    
                $stock->refresh();
    
                return [
    
                    'stock' =>
                        $stock,
    
                    'ancienne_quantite' =>
                        $ancienneQuantite,
    
                    'nouvelle_quantite' =>
                        $nouvelleQuantite,
    
                    'ecart' =>
                        $ecart,
    
                    'lots_entree' =>
                        $lotsEntree,
    
                    'lots_sortie' =>
                        $lotsSortie,
    
                    'mouvement_id' =>
                        $mouvementAjustement?->mouvement_id,
    
                    'reference_mouvement' =>
                        $mouvementAjustement
                            ?->reference_mouvement,
                ];
            });
    
            // ============================================================
            // 7. MESSAGE
            // ============================================================
    
            $message =
                'Stock mis à jour avec succès';
    
            if ($result['ecart'] > 0) {
    
                $message =
                    'Stock augmenté de ' .
                    $result['ecart'] .
                    ' unité(s). Nouveau lot FIFO créé.';
    
                if (!empty($imeis)) {
    
                    $message .=
                        ' ' .
                        count($imeis) .
                        ' IMEI ajouté(s).';
                }
            }
    
            elseif ($result['ecart'] < 0) {
    
                $message =
                    'Stock diminué de ' .
                    abs($result['ecart']) .
                    ' unité(s) selon FIFO physique.';
            }
    
            // ============================================================
            // 8. RATTACHEMENT DÉPÔT
            // ============================================================
    
            $ancienDepotSourceId =
                $stock->getOriginal('depot_source_id');
    
            $nouveauDepotSourceId =
                $stock->depot_source_id;
    
            $rattachementChange =
                $ancienDepotSourceId !=
                $nouveauDepotSourceId;
    
            if ($rattachementChange) {
    
                $message =
                    $nouveauDepotSourceId
                        ? 'Point de vente rattaché au dépôt avec succès'
                        : 'Point de vente détaché du dépôt avec succès';
            }
    
            // ============================================================
            // 9. RÉPONSE
            // ============================================================
    
            return response()->json([
    
                'success' =>
                    true,
    
                'message' =>
                    $message,
    
                'data' =>
                    $stock->fresh([
                        'succursale',
                        'produit',
                        'depotSource.succursale'
                    ]),
    
                'ajustement' => [
    
                    'ancienne_quantite' =>
                        $result['ancienne_quantite'],
    
                    'nouvelle_quantite' =>
                        $result['nouvelle_quantite'],
    
                    'ecart' =>
                        $result['ecart'],
    
                    'type' =>
                        $result['ecart'] > 0
                            ? 'AUGMENTATION'
                            : (
                                $result['ecart'] < 0
                                    ? 'DIMINUTION'
                                    : 'AUCUN_CHANGEMENT'
                            ),
    
                    'mouvement_id' =>
                        $result['mouvement_id'],
    
                    'reference_mouvement' =>
                        $result['reference_mouvement'],
    
                    'lots_entree' =>
                        $result['lots_entree'],
    
                    'lots_sortie' =>
                        $result['lots_sortie'],
                ]
            ]);
    
        } catch (\Throwable $e) {
    
            // ============================================================
            // 10. ROLLBACK AUTOMATIQUE
            // ============================================================
    
            Log::error(
                'Erreur mise à jour stock - AJUSTEMENT FIFO ANNULÉ',
                [
                    'stock_id' =>
                        $id,
    
                    'user_id' =>
                        $user->id ?? null,
    
                    'produit_id' =>
                        $produit->id ?? null,
    
                    'message' =>
                        $e->getMessage(),
    
                    'exception' =>
                        get_class($e),
    
                    'trace' =>
                        $e->getTraceAsString(),
                ]
            );
    
            return response()->json([
    
                'success' =>
                    false,
    
                'message' =>
                    'La modification du stock a été annulée. ' .
                    'Aucune modification partielle n\'a été conservée.',
    
                'error' =>
                    $e->getMessage()
            ], 422);
        }
    }
    /**
     * Supprimer un stock (nécessite manage-stock-agence)
     */
    public function destroy($id)
    {
        $user = auth()->user();
        
        if (!$user->hasPermission('manage-stock-agence')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas la permission de supprimer un stock'
            ], 403);
        }

        $stock = StockSuccursale::find($id);

        if (!$stock) {
            return response()->json([
                'success' => false,
                'message' => 'Stock non trouvé'
            ], 404);
        }

        // Vérifications avant suppression
        if ($stock->mouvements()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer ce stock car il a des mouvements associés'
            ], 422);
        }

        if ($stock->is_depot && $stock->pointsVente()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer ce dépôt car il a des points de vente rattachés'
            ], 422);
        }

        if ($stock->quantite_reserve > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer ce stock car il a des réservations en cours'
            ], 422);
        }

        if ($stock->quantite_disponible > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer ce stock car il contient encore du stock disponible'
            ], 422);
        }

        try {
            DB::beginTransaction();

            if (!$stock->is_depot && $stock->depot_source_id) {
                $stock->depot_source_id = null;
                $stock->save();
            }

            $stock->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $stock->is_depot ? 'Dépôt supprimé avec succès' : 'Stock supprimé avec succès',
                'data' => [
                    'id' => $stock->id,
                    'produit' => $stock->produit->nom,
                    'succursale' => $stock->succursale->nom,
                    'is_depot' => $stock->is_depot
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Réserver du stock
     */
    public function reserver(Request $request, $id)
    {
        $user = auth()->user();
        
        if (!$user->hasPermission('manage-stock-agence')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas la permission de faire des réservations'
            ], 403);
        }

        $stock = StockSuccursale::find($id);

        if (!$stock) {
            return response()->json([
                'success' => false,
                'message' => 'Stock non trouvé'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'quantite' => 'required|integer|min:1'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $ancienneQuantiteDispo = $stock->quantite_disponible;
            $ancienneQuantiteReserve = $stock->quantite_reserve;

            $stock->reserver($request->quantite);

            // Enregistrer le mouvement de réservation
            MouvementStock::create([
                'reference_mouvement' => MouvementStock::genererReference(),
                'succursale_id' => $stock->succursale_id,
                'produit_id' => $stock->produit_id,
                'stock_source_id' => $stock->id,
                'type_mouvement' => 'RESERVATION',
                'statut' => 'VALIDE',
                'quantite' => $request->quantite,
                'quantite_avant' => $ancienneQuantiteDispo,
                'quantite_apres' => $stock->quantite_disponible,
                'raison' => 'Réservation de stock',
                'responsable_id' => auth()->id(),
                'metadata' => json_encode(['quantite_reservee' => $request->quantite, 'ancienne_reserve' => $ancienneQuantiteReserve]),
                'date_mouvement' => now()
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Réservation effectuée avec succès',
                'data' => $stock->fresh()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Libérer une réservation
     */
    public function libererReservation(Request $request, $id)
    {
        $user = auth()->user();
        
        if (!$user->hasPermission('manage-stock-agence')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas la permission de libérer des réservations'
            ], 403);
        }

        $stock = StockSuccursale::find($id);

        if (!$stock) {
            return response()->json([
                'success' => false,
                'message' => 'Stock non trouvé'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'quantite' => 'required|integer|min:1'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $ancienneQuantiteDispo = $stock->quantite_disponible;
            $ancienneQuantiteReserve = $stock->quantite_reserve;

            $stock->libererReservation($request->quantite);

            // Enregistrer le mouvement de libération
            MouvementStock::create([
                'reference_mouvement' => MouvementStock::genererReference(),
                'succursale_id' => $stock->succursale_id,
                'produit_id' => $stock->produit_id,
                'stock_destination_id' => $stock->id,
                'type_mouvement' => 'LIBERATION_RESERVATION',
                'statut' => 'VALIDE',
                'quantite' => $request->quantite,
                'quantite_avant' => $ancienneQuantiteDispo,
                'quantite_apres' => $stock->quantite_disponible,
                'raison' => 'Libération de réservation',
                'responsable_id' => auth()->id(),
                'metadata' => json_encode(['quantite_libere' => $request->quantite, 'ancienne_reserve' => $ancienneQuantiteReserve]),
                'date_mouvement' => now()
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Réservation libérée avec succès',
                'data' => $stock->fresh()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Liste des dépôts uniquement
     */
    public function depots(Request $request)
    {
        $user = auth()->user();
        
        $query = StockSuccursale::with(['succursale', 'produit.categorie'])
            ->where('is_depot', true);

        if ($user->hasPermission('view-stock-agence') && !$user->hasPermission('manage-stock-agence')) {
            $query->where('succursale_id', $user->succursale_id);
        }

        if ($request->filled('succursale_id') && $user->hasPermission('manage-stock-agence')) {
            $query->where('succursale_id', $request->succursale_id);
        }

        $depots = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $depots
        ]);
    }

    /**
     * Liste des points de vente uniquement
     */
    public function pointsVente(Request $request)
    {
        $user = auth()->user();
        
        $query = StockSuccursale::with(['succursale', 'produit.categorie', 'depotSource.succursale'])
            ->where('is_depot', false);

        if ($user->hasPermission('view-stock-agence') && !$user->hasPermission('manage-stock-agence')) {
            $query->where('succursale_id', $user->succursale_id);
        }

        if ($request->filled('succursale_id') && $user->hasPermission('manage-stock-agence')) {
            $query->where('succursale_id', $request->succursale_id);
        }

        if ($request->filled('depot_source_id')) {
            $query->where('depot_source_id', $request->depot_source_id);
        }

        $pointsVente = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $pointsVente
        ]);
    }

    /**
     * Liste des alertes stock
     */
    public function alertes()
    {
        try {
            $user = auth()->user();

            $query = StockSuccursale::select([
                'id', 'succursale_id', 'produit_id', 'quantite_disponible',
                'quantite_minimale', 'statut_alerte', 'emplacement'
            ])->with([
                'succursale' => function($q) {
                    $q->select('id', 'nom', 'code');
                },
                'produit' => function($q) {
                    $q->select('id', 'nom', 'code_barre');
                }
            ])->whereIn('statut_alerte', ['CRITIQUE', 'RUPTURE']);

            if ($user->hasPermission('view-stock-agence') && !$user->hasPermission('manage-stock-agence')) {
                $query->where('succursale_id', $user->succursale_id);
            }

            $stocks = $query->get();

            return response()->json([
                'success' => true,
                'data' => $stocks,
                'count' => $stocks->count()
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur alertes stock', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Statistiques globales - limitées selon les permissions
     */
    public function statistiquesGlobales()
    {
        try {
            $user = auth()->user();

            $query = StockSuccursale::query();

            if ($user->hasPermission('view-stock-agence') && !$user->hasPermission('manage-stock-agence')) {
                $query->where('succursale_id', $user->succursale_id);
            }

            // ✅ Statistiques en UNE SEULE REQUÊTE
            $baseStats = (clone $query)->selectRaw("
                COUNT(*) as total_produits,
                SUM(CASE WHEN quantite_disponible <= 0 THEN 1 ELSE 0 END) as ruptures,
                SUM(CASE WHEN quantite_disponible <= quantite_minimale AND quantite_minimale > 0 THEN 1 ELSE 0 END) as alertes,
                SUM(CASE WHEN is_depot = 1 THEN 1 ELSE 0 END) as total_depots,
                SUM(CASE WHEN is_depot = 0 THEN 1 ELSE 0 END) as total_points_vente
            ")->first();

            // ✅ Valeur du stock - UNE SEULE REQUÊTE avec jointure
            $valeurStock = (clone $query)
                ->join('produit', 'stock_succursale.produit_id', '=', 'produit.id')
                ->select(DB::raw('SUM(stock_succursale.quantite_disponible * produit.prix_achat) as total'))
                ->value('total') ?? 0;

            // ✅ Valeur par type - UNE SEULE REQUÊTE
            $valeurParType = (clone $query)
                ->join('produit', 'stock_succursale.produit_id', '=', 'produit.id')
                ->select(
                    'stock_succursale.is_depot',
                    DB::raw('SUM(stock_succursale.quantite_disponible * produit.prix_achat) as total')
                )
                ->groupBy('stock_succursale.is_depot')
                ->get()
                ->keyBy('is_depot');

            // ✅ Par succursale - UNE SEULE REQUÊTE
            $parSuccursale = (clone $query)
                ->select('succursale_id', DB::raw('count(*) as total'))
                ->with(['succursale' => function($q) {
                    $q->select('id', 'nom');
                }])
                ->groupBy('succursale_id')
                ->get();

            $stats = [
                'total_produits' => (int) $baseStats->total_produits,
                'valeur_stock' => round($valeurStock, 2),
                'ruptures' => (int) $baseStats->ruptures,
                'alertes' => (int) $baseStats->alertes,
                'par_succursale' => $parSuccursale,
                'depots' => [
                    'total' => (int) ($baseStats->total_depots ?? 0),
                    'valeur' => round($valeurParType[1]->total ?? 0, 2)
                ],
                'points_vente' => [
                    'total' => (int) ($baseStats->total_points_vente ?? 0),
                    'valeur' => round($valeurParType[0]->total ?? 0, 2)
                ]
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur statistiques globales', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }
}