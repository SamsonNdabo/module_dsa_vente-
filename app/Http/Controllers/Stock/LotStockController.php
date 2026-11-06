<?php

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Models\LotStock;
use App\Models\ProduitImei;
use App\Models\StockSuccursale;
use App\Models\Produit;
use App\Models\MouvementStock;
use App\Models\Caisses;                       //  AJOUT
use App\Services\EcritureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class LotStockController extends Controller
{
    
    protected EcritureService $ecritureService;

    public function __construct(EcritureService $ecritureService)
    {
        $this->ecritureService = $ecritureService;
    }
    /**
     * Afficher la liste des lots
     * Permissions: view-stock-agence ou manage-stock-agence
     */
    public function index(Request $request)
    {
        $user = auth()->user();
    
        // ============================================================
        // 1. AUTHENTIFICATION
        // ============================================================
    
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non authentifié'
            ], 401);
        }
    
        // ============================================================
        // 2. PERMISSION
        // ============================================================
    
        // if (
        //     !$user->hasPermission('view-stock-agence') &&
        //     !$user->hasPermission('manage-stock-agence')
        // ) {
        //     return response()->json([
        //         'success' => false,
        //         'message' => 'Permission refusée'
        //     ], 403);
        // }
    
        // ============================================================
        // 3. SUCCURSALE DE L'UTILISATEUR CONNECTÉ
        // ============================================================
    
        if (!$user->succursale_id) {
            return response()->json([
                'success' => false,
                'message' => 'Aucune succursale n\'est associée à cet utilisateur.'
            ], 422);
        }
    
        $succursaleId = (int) $user->succursale_id;
    
        // ============================================================
        // 4. LISTE DES STOCKS (pour le dropdown "Stock de la succursale")
        // ============================================================
    
        $stocks = StockSuccursale::with('produit')
            ->where('succursale_id', $succursaleId)
            ->get();
    
        // ============================================================
        // 5. LISTE DES PRODUITS (pour le dropdown "Produit")
        // ============================================================
    
        $produits = $stocks
            ->filter(fn ($stock) => $stock->produit !== null)
            ->map(function ($stock) {
                return [
                    'id' => $stock->produit->id,
                    'nom' => $stock->produit->nom,
                    'code_barre' => $stock->produit->code_barre,
                    'type' => $stock->produit->type,
                    'gere_imei' => (bool) $stock->produit->gere_imei,
                    'est_perissable' => (bool) $stock->produit->est_perissable,
                    'stock_succursale_id' => $stock->id,
                    'quantite_disponible' => (int) $stock->quantite_disponible,
                    'quantite_reserve' => (int) $stock->quantite_reserve,
                ];
            })
            ->values();
    
        // ============================================================
        // 6. LISTE DES STOCKS POUR LE DROPDOWN (format attendu par le front)
        // ============================================================
    
        $stocksList = $stocks->map(function ($stock) {
            return [
                'id' => $stock->id,
                'produit_id' => $stock->produit_id,
                'produit_nom' => $stock->produit?->nom ?? 'Produit inconnu',
                'succursale_id' => $stock->succursale_id,
                'quantite_disponible' => (int) $stock->quantite_disponible,
                'quantite_reserve' => (int) $stock->quantite_reserve,
                'is_depot' => (bool) $stock->is_depot,
            ];
        })->values();
    
        // ============================================================
        // 7. REQUÊTE DES LOTS (PAGINATION)
        // ============================================================
    
        $query = LotStock::with([
            'produit',
            'stockSuccursale.succursale',
            'mouvementEntree'
        ])
            ->whereHas('stockSuccursale', function ($q) use ($succursaleId) {
                $q->where('succursale_id', $succursaleId);
            });
    
        // Filtres
        if ($request->filled('produit_id')) {
            $query->where('produit_id', $request->produit_id);
        }
    
        if ($request->filled('stock_succursale_id')) {
            $query->where('stock_succursale_id', $request->stock_succursale_id);
        }
    
        if ($request->filled('statut')) {
            $query->where('statut', $request->statut);
        }
    
        if ($request->filled('date_expiration_debut')) {
            $query->where('date_expiration', '>=', $request->date_expiration_debut);
        }
    
        if ($request->filled('date_expiration_fin')) {
            $query->where('date_expiration', '<=', $request->date_expiration_fin);
        }
    
        if ($request->filled('numero_lot')) {
            $query->where('numero_lot', 'LIKE', '%' . $request->numero_lot . '%');
        }
    
        if ($request->filled('recherche')) {
            $search = $request->recherche;
            $query->where(function ($q) use ($search) {
                $q->where('numero_lot', 'LIKE', "%{$search}%")
                    ->orWhereHas('produit', function ($pq) use ($search) {
                        $pq->where('nom', 'LIKE', "%{$search}%")
                            ->orWhere('code_barre', 'LIKE', "%{$search}%");
                    });
            });
        }
    
        $perPage = $request->get('per_page', 20);
        $lots = $query
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    
        // ============================================================
        // 8. RÉPONSE
        // ============================================================
    
        return response()->json([
            'success' => true,
            'succursale' => [
                'id' => $succursaleId,
            ],
            //  Pour le dropdown "Produit"
            'produits' => $produits,
            //  Pour le dropdown "Stock de la succursale"
            'stocks' => $stocksList,
            //  Liste des lots paginés
            'data' => $lots,
        ]);
    }

    public function store(Request $request)
    {
        $user = auth()->user();
    
        // 1. AUTHENTIFICATION
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non authentifié'
            ], 401);
        }
    
        // 2. PERMISSION
        if (!$user->hasPermission('manage-stock-agence')) {
            return response()->json([
                'success' => false,
                'message' => 'Permission refusée'
            ], 403);
        }
    
        // 3. VALIDATION
        $validator = Validator::make($request->all(), [
            'produit_id' => ['required','integer', Rule::exists(Produit::class, 'id')],
            'stock_succursale_id' => ['required','integer', Rule::exists(StockSuccursale::class, 'id')],
            'quantite' => ['required','integer','min:1'],
            'numero_lot' => ['nullable','string','max:100'],
            'date_fabrication' => ['nullable','date'],
            'date_expiration' => ['nullable','date'],
            'cout_unitaire' => ['required','numeric','min:0'],
            'cout_unitaire_ttc' => ['nullable','numeric','min:0'],
            'notes' => ['nullable','string','max:1000'],
            'imeis' => ['nullable','array'],
            'imeis.*' => ['required','string','regex:/^\d{15,17}$/'],
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
    
        // 4. NORMALISATION
        $produitId = (int) $request->produit_id;
        $stockId = (int) $request->stock_succursale_id;
        $quantiteAjoutee = (int) $request->quantite;
    
        $imeis = collect($request->input('imeis', []))
            ->map(fn ($imei) => trim((string) $imei))
            ->filter()
            ->values()
            ->all();
    
        // 5. TRANSACTION ATOMIQUE
        try {
            $result = DB::transaction(function () use (
                $request, $user, $produitId, $stockId, $quantiteAjoutee, $imeis
            ) {
                // 5.1 VERROUILLER LE STOCK
                $stock = StockSuccursale::where('id', $stockId)->lockForUpdate()->first();
                if (!$stock) throw new \RuntimeException('Stock de la succursale introuvable.');
    
                // 5.2 VÉRIFIER LE PRODUIT
                if ((int) $stock->produit_id !== $produitId) {
                    throw new \RuntimeException('Le produit sélectionné ne correspond pas au produit de ce stock.');
                }
    
                $produit = Produit::lockForUpdate()->find($produitId);
                if (!$produit) throw new \RuntimeException('Produit introuvable.');
    
                // 5.3 ACCÈS SUCCURSALE
                if (
                    !$user->hasPermission('manage-stock-agence') &&
                    (int) $stock->succursale_id !== (int) $user->succursale_id
                ) {
                    throw new \RuntimeException('Vous n\'êtes pas autorisé à modifier ce stock.');
                }
    
                // 5.4 COHÉRENCE AVANT AJOUT
                $quantiteLotsAvant = (int) LotStock::where('stock_succursale_id', $stock->id)
                    ->where('produit_id', $stock->produit_id)
                    ->where('quantite_restante', '>', 0)
                    ->sum('quantite_restante');
    
                $quantiteStockAvant = (int) ($stock->quantite_disponible ?? 0);
    
                if ($quantiteStockAvant !== $quantiteLotsAvant) {
                    throw new \RuntimeException(
                        "Incohérence détectée avant ajout du lot : " .
                        "stock disponible = {$quantiteStockAvant}, " .
                        "mais somme des lots restants = {$quantiteLotsAvant}. " .
                        "L'ajout du lot a été annulé."
                    );
                }
    
                // 5.5 DATE D'EXPIRATION
                $dateExpiration = null;
                if ($produit->est_perissable) {
                    if (!$request->filled('date_expiration')) {
                        throw new \RuntimeException(
                            'La date d\'expiration est obligatoire pour ce produit périssable.'
                        );
                    }
                    $dateExpiration = $request->date_expiration;
                    if (!$dateExpiration) {
                        throw new \RuntimeException('La date d\'expiration est invalide.');
                    }
                } else {
                    $dateExpiration = null;
                }
    
                // 5.6 IMEI
                if (!empty($imeis)) {
                    if (!$produit->gere_imei) {
                        throw new \RuntimeException(
                            'Ce produit ne gère pas les IMEI. Veuillez retirer les IMEI de la requête.'
                        );
                    }
                    if (count($imeis) !== $quantiteAjoutee) {
                        throw new \RuntimeException(
                            'Le nombre d\'IMEI (' . count($imeis) .
                            ') doit correspondre à la quantité ajoutée (' . $quantiteAjoutee . ').'
                        );
                    }
                    if (count($imeis) !== count(array_unique($imeis))) {
                        throw new \RuntimeException('Les IMEI ne peuvent pas être dupliqués.');
                    }
                    $imeisExistants = ProduitImei::whereIn('numero_imei', $imeis)
                        ->lockForUpdate()->pluck('numero_imei')->toArray();
                    if (!empty($imeisExistants)) {
                        throw new \RuntimeException(
                            'Les IMEI suivants existent déjà : ' . implode(', ', $imeisExistants)
                        );
                    }
                }
    
                // 5.7 COÛT D'ACHAT
                $coutUnitaire = (float) $request->cout_unitaire;
                if ($coutUnitaire < 0) {
                    throw new \RuntimeException('Le coût unitaire ne peut pas être négatif.');
                }
    
                // 5.8 COÛT TTC
                if ($request->filled('cout_unitaire_ttc')) {
                    $coutUnitaireTTC = (float) $request->cout_unitaire_ttc;
                } else {
                    $tauxTVA = (float) ($produit->tva ?? 16);
                    $coutUnitaireTTC = $coutUnitaire * (1 + ($tauxTVA / 100));
                }
    
                // 5.9 MONTANT TOTAL
                $coutTotal = round($coutUnitaire * $quantiteAjoutee, 2);
    
                // 5.10 QUANTITÉ AVANT / APRÈS
                $ancienneQuantite = $quantiteStockAvant;
                $nouvelleQuantite = $ancienneQuantite + $quantiteAjoutee;
    
                // 5.11 CRÉER LE MOUVEMENT D'ENTRÉE
                $mouvement = MouvementStock::create([
                    'reference_mouvement' => MouvementStock::genererReference(),
                    'succursale_id' => $stock->succursale_id,
                    'produit_id' => $stock->produit_id,
                    'stock_destination_id' => $stock->id,
                    'type_mouvement' => 'ENTREE',
                    'statut' => 'VALIDE',
                    'quantite' => $quantiteAjoutee,
                    'quantite_avant' => $ancienneQuantite,
                    'quantite_apres' => $nouvelleQuantite,
                    'responsable_id' => $user->id,
                    'raison' => 'Ajout manuel d\'un nouveau lot',
                    'reference_document' => 'LOT-' . now()->format('YmdHis'),
                    'metadata' => [
                        'type_creation' => 'ajout_manuel_lot',
                        'numero_lot' => $request->input('numero_lot'),
                        'date_fabrication' => $request->input('date_fabrication'),
                        'date_expiration' => $dateExpiration,
                        'quantite_ajoutee' => $quantiteAjoutee,
                        'ancienne_quantite' => $ancienneQuantite,
                        'nouvelle_quantite' => $nouvelleQuantite,
                        'cout_unitaire' => $coutUnitaire,
                        'cout_unitaire_ttc' => $coutUnitaireTTC,
                        'notes' => $request->input('notes'),
                        'lots_entree' => [],
                    ],
                    'cout_unitaire' => $coutUnitaire,
                    'cout_total' => $coutTotal,
                    'quantite_restante_lot' => $quantiteAjoutee,
                    'est_lot_fifo' => 1,
                    'prix_achat_lot' => $coutUnitaire,
                    'lots_sortie' => null,
                    'lots_entree' => null,
                    'date_mouvement' => now(),
                    'date_validation' => now(),
                ]);
    
                // 5.12 DATE DU LOT
                $mouvement->date_lot = $request->filled('date_fabrication')
                    ? $request->date_fabrication
                    : now();
                $mouvement->date_expiration_lot = $dateExpiration;
                $mouvement->save();
    
                // 5.13 CRÉER LE LOT FIFO
                $lotService = app(\App\Services\LotStockService::class);
                $lot = $lotService->creerLotDepuisMouvement($mouvement);
                if (!$lot) {
                    throw new \RuntimeException('Le nouveau lot FIFO n\'a pas pu être créé.');
                }
    
                // 5.14 VÉRIFICATIONS DU LOT
                if ((int) $lot->stock_succursale_id !== (int) $stock->id) {
                    throw new \RuntimeException('Le lot créé n\'est pas associé au bon stock.');
                }
                if ((int) $lot->produit_id !== (int) $produit->id) {
                    throw new \RuntimeException('Le lot créé n\'est pas associé au bon produit.');
                }
                if ((int) $lot->quantite_initiale !== $quantiteAjoutee) {
                    throw new \RuntimeException('La quantité initiale du lot est incorrecte.');
                }
                if ((int) $lot->quantite_restante !== $quantiteAjoutee) {
                    throw new \RuntimeException('La quantité restante du lot est incorrecte.');
                }
    
                // 5.15 TRAÇABILITÉ MOUVEMENT ↔ LOT
                DB::table('mouvement_stock_lots')->insert([
                    'mouvement_stock_id' => $mouvement->mouvement_id,
                    'lot_stock_id' => $lot->id,
                    'quantite' => $quantiteAjoutee,
                    'cout_unitaire' => $coutUnitaire,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
    
                // 5.16 TRAÇABILITÉ DU MOUVEMENT
                $metadata = is_array($mouvement->metadata) ? $mouvement->metadata : [];
                $metadata['lots_entree'] = [[
                    'lot_id' => $lot->id,
                    'numero_lot' => $lot->numero_lot,
                    'quantite' => $quantiteAjoutee,
                    'cout_unitaire' => $coutUnitaire,
                    'cout_unitaire_ttc' => $coutUnitaireTTC,
                    'date_expiration' => $dateExpiration,
                ]];
                $mouvement->metadata = $metadata;
                $mouvement->lots_entree = $metadata['lots_entree'];
                $mouvement->save();
    
                // 5.17 CRÉATION DES IMEI
                $imeisCrees = [];
                if (!empty($imeis)) {
                    $resultImeis = app(\App\Services\ImeiService::class)
                        ->creerImeisPourLot($lot, $stock, $imeis, $mouvement->mouvement_id);
                    if (
                        is_array($resultImeis) &&
                        array_key_exists('success', $resultImeis) &&
                        $resultImeis['success'] !== true
                    ) {
                        throw new \RuntimeException(
                            $resultImeis['message'] ?? 'La création des IMEI a échoué.'
                        );
                    }
                    $imeisCrees = $imeis;
                }
    
                // ========================================================
                //  AJOUT : MISE À JOUR DU STOCK GLOBAL
                // AVANT le débit caisse et l'écriture comptable
                // ========================================================
                $stock->quantite_disponible = $nouvelleQuantite;
                $stock->save();
    
                // ========================================================
                //  AJOUT #1 : DÉBITER LA CAISSE
                // Identique à StockSuccursaleController::store()
                // ========================================================
                if ($coutUnitaire > 0 && $quantiteAjoutee > 0) {
                    $tauxTVA = $produit->tva ?? 16;
                    $montantTTC = round(
                        $coutUnitaire * $quantiteAjoutee * (1 + ($tauxTVA / 100)),
                        2
                    );
                    $this->debiterCaisse($stock, $montantTTC, $user);
                }
    
                // ========================================================
                //  AJOUT #2 : ÉCRITURES COMPTABLES
                // Identique à StockSuccursaleController::store()
                // ========================================================
                // if ($quantiteAjoutee > 0 && $coutUnitaire > 0) {
                //     $this->creerEcrituresComptablesStock(
                //         $stock,
                //         $produit,
                //         $quantiteAjoutee,
                //         $coutUnitaire,
                //         $mouvement
                //     );
                // }
    
                // ========================================================
                // 5.19 CONTRÔLE FINAL DE COHÉRENCE
                // ========================================================
                $quantiteLotsApres = (int) LotStock::where('stock_succursale_id', $stock->id)
                    ->where('produit_id', $stock->produit_id)
                    ->where('quantite_restante', '>', 0)
                    ->sum('quantite_restante');
    
                if ($quantiteLotsApres !== $nouvelleQuantite) {
                    throw new \RuntimeException(
                        "Incohérence après ajout du lot : " .
                        "stock = {$nouvelleQuantite}, " .
                        "mais somme des lots restants = {$quantiteLotsApres}. " .
                        "L'opération a été annulée."
                    );
                }
    
                // 5.20 ALERTE
                $stock->mettreAJourStatutAlerte()->save();
    
                // 5.21 RAFRAÎCHIR
                $stock->refresh();
                $lot->refresh();
                $mouvement->refresh();
    
                return [
                    'stock' => $stock,
                    'lot' => $lot,
                    'mouvement' => $mouvement,
                    'ancienne_quantite' => $ancienneQuantite,
                    'quantite_ajoutee' => $quantiteAjoutee,
                    'nouvelle_quantite' => $nouvelleQuantite,
                    'imeis' => $imeisCrees,
                ];
            });
    
            // 6. RÉPONSE
            return response()->json([
                'success' => true,
                'message' => 'Nouveau lot ajouté avec succès.',
                'data' => [
                    'lot' => [
                        'id' => $result['lot']->id,
                        'numero_lot' => $result['lot']->numero_lot,
                        'produit_id' => $result['lot']->produit_id,
                        'stock_succursale_id' => $result['lot']->stock_succursale_id,
                        'quantite_initiale' => (int) $result['lot']->quantite_initiale,
                        'quantite_restante' => (int) $result['lot']->quantite_restante,
                        'statut' => $result['lot']->statut,
                        'cout_unitaire' => $result['lot']->cout_unitaire,
                        'cout_unitaire_ttc' => $result['lot']->cout_unitaire_ttc,
                        'date_fabrication' => $result['lot']->date_fabrication,
                        'date_expiration' => $result['lot']->date_expiration,
                    ],
                    'stock' => [
                        'ancienne_quantite' => $result['ancienne_quantite'],
                        'quantite_ajoutee' => $result['quantite_ajoutee'],
                        'nouvelle_quantite' => $result['nouvelle_quantite'],
                    ],
                    'mouvement' => [
                        'id' => $result['mouvement']->mouvement_id,
                        'reference' => $result['mouvement']->reference_mouvement,
                    ],
                    'imeis' => [
                        'nombre' => count($result['imeis']),
                        'liste' => $result['imeis'],
                    ],
                ]
            ], 201);
    
        } catch (\Throwable $e) {
            Log::error('Erreur ajout manuel nouveau lot', [
                'stock_succursale_id' => $stockId,
                'produit_id' => $produitId,
                'quantite' => $quantiteAjoutee,
                'user_id' => $user->id ?? null,
                'message' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
    
            return response()->json([
                'success' => false,
                'message' => 'L\'ajout du nouveau lot a été annulé. ' .
                    'Aucune modification partielle n\'a été conservée.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    // ================================================================
    //  MÉTHODES PRIVÉES COPIÉES DEPUIS StockSuccursaleController
    // ================================================================

    /**
     *  DÉBITER LA CAISSE (diminuer l'argent) pour un achat
     */
    private function debiterCaisse(StockSuccursale $stock, float $montant, $user): void
    {
        // Récupérer la caisse de la succursale
        $caisse = Caisses::where('succursale_id', $stock->succursale_id)
            ->where('type_caisse', 'PHYSIQUE')
            ->where('categorie', 'COFFRE')
            ->where('statut', 'OUVERTE')
            ->first();

        if (!$caisse) {
            Log::warning('Aucune caisse physique ouverte trouvée pour débiter', [
                'succursale_id' => $stock->succursale_id
            ]);
            return;
        }

        //  Cast en int
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
     *  CRÉER LES ÉCRITURES COMPTABLES POUR LE STOCK
     * (identique à StockSuccursaleController)
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
        $prixAchatTTC = round($prixAchat * (1 + ($tauxTVA / 100)), 2);
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

        if (!is_array($result) || ($result['success'] ?? false) !== true) {
            $message = $result['message']
                ?? 'Erreur inconnue lors de la création de l\'écriture comptable.';

            Log::error('Échec écriture comptable pour création de stock', [
                'stock_id' => $stock->id,
                'produit' => $produit->nom,
                'message' => $message,
            ]);

            throw new \RuntimeException(
                'Échec de l\'écriture comptable : ' . $message
            );
        }

        Log::info('Écriture comptable créée avec succès', [
            'stock_id' => $stock->id,
            'produit' => $produit->nom,
            'reference' => $result['reference']
                ?? 'STK-' . $stock->id . '-' . $produit->id,
        ]);
    }



    public function ajusterQuantite(Request $request, $id)
    {
        $user = auth()->user();
    
        // ============================================================
        // 1. AUTHENTIFICATION
        // ============================================================
    
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non authentifié.'
            ], 401);
        }
    
        // ============================================================
        // 2. PERMISSION
        // ============================================================
    
        if (!$user->hasPermission('manage-stock-agence')) {
            return response()->json([
                'success' => false,
                'message' => 'Permission refusée.'
            ], 403);
        }
    
        // ============================================================
        // 3. VALIDATION
        // ============================================================
    
        $validator = Validator::make($request->all(), [
    
            /*
             * Exemple :
             *
             * +5  => ajouter 5 unités au lot
             * -5  => retirer 5 unités du lot
             */
            'quantite' => [
                'required',
                'integer',
                'not_in:0'
            ],
    
            'motif' => [
                'required',
                'string',
                'max:500'
            ],
    
            /*
             * Facultatif lors d'une augmentation.
             *
             * Si fourni :
             * - le produit doit gérer les IMEI ;
             * - le nombre doit correspondre exactement à
             *   la quantité ajoutée.
             */
            'imeis' => [
                'nullable',
                'array'
            ],
    
            'imeis.*' => [
                'required',
                'string',
                'regex:/^\d{15,17}$/'
            ],
    
            'notes' => [
                'nullable',
                'string',
                'max:1000'
            ],
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
    
        $delta = (int) $request->input('quantite');
    
        // Normalisation des IMEI
        $imeis = collect(
            $request->input('imeis', [])
        )
            ->map(fn ($imei) => trim((string) $imei))
            ->filter()
            ->values()
            ->all();
    
        try {
    
            $result = DB::transaction(function () use (
                $request,
                $user,
                $id,
                $delta,
                $imeis
            ) {
    
                // ========================================================
                // 4. RÉCUPÉRER LE LOT AVEC VERROU
                // ========================================================
    
                $lot = LotStock::where('id', $id)
                    ->lockForUpdate()
                    ->first();
    
                if (!$lot) {
                    throw new \RuntimeException(
                        'Lot introuvable.'
                    );
                }
    
                // ========================================================
                // 5. RÉCUPÉRER LE STOCK AVEC VERROU
                // ========================================================
    
                $stock = StockSuccursale::where(
                    'id',
                    $lot->stock_succursale_id
                )
                    ->lockForUpdate()
                    ->first();
    
                if (!$stock) {
                    throw new \RuntimeException(
                        'Stock de la succursale introuvable.'
                    );
                }
    
                // ========================================================
                // 6. VÉRIFIER LA CORRESPONDANCE PRODUIT
                // ========================================================
    
                if (
                    (int) $lot->produit_id !==
                    (int) $stock->produit_id
                ) {
                    throw new \RuntimeException(
                        'Le produit du lot ne correspond pas au produit du stock.'
                    );
                }
    
                // ========================================================
                // 7. VÉRIFIER L'ACCÈS À LA SUCCURSALE
                // ========================================================
    
                if (
                    (int) $stock->succursale_id !==
                    (int) $user->succursale_id
                ) {
                    throw new \RuntimeException(
                        'Vous n\'êtes pas autorisé à modifier ce lot.'
                    );
                }
    
                // ========================================================
                // 8. VERROUILLER LE PRODUIT
                // ========================================================
    
                $produit = \App\Models\Produit::where(
                    'id',
                    $lot->produit_id
                )
                    ->lockForUpdate()
                    ->first();
    
                if (!$produit) {
                    throw new \RuntimeException(
                        'Produit introuvable.'
                    );
                }
    
                // ========================================================
                // 9. QUANTITÉS ACTUELLES
                // ========================================================
    
                $quantiteLotAvant = (int) (
                    $lot->quantite_restante ?? 0
                );
    
                $quantiteStockAvant = (int) (
                    $stock->quantite_disponible ?? 0
                );
    
                // ========================================================
                // 10. VÉRIFICATION DE COHÉRENCE AVANT AJUSTEMENT
                //
                // TOUS LES STATUTS SONT COMPTÉS :
                //
                // ACTIF
                // BLOQUE
                // QUARANTAINE
                // EXPIRE
                // etc.
                // ========================================================
    
                $quantiteLotsAvant = (int) LotStock::where(
                        'stock_succursale_id',
                        $stock->id
                    )
                    ->where(
                        'produit_id',
                        $stock->produit_id
                    )
                    ->where(
                        'quantite_restante',
                        '>',
                        0
                    )
                    ->sum('quantite_restante');
    
                if (
                    $quantiteLotsAvant !==
                    $quantiteStockAvant
                ) {
                    throw new \RuntimeException(
                        "Incohérence détectée avant ajustement : " .
                        "stock disponible = {$quantiteStockAvant}, " .
                        "mais somme des lots restants = {$quantiteLotsAvant}. " .
                        "L'ajustement a été annulé."
                    );
                }
    
                // ========================================================
                // 11. CALCUL DE LA NOUVELLE QUANTITÉ DU LOT
                // ========================================================
    
                $quantiteLotApres =
                    $quantiteLotAvant + $delta;
    
                // ========================================================
                // 12. EMPÊCHER UNE QUANTITÉ NÉGATIVE
                // ========================================================
    
                         if ($quantiteLotApres < 0) {
                    
                    throw new \RuntimeException(
                        "Impossible de retirer " . ($delta * -1) . " unité(s) " .
                        "du lot #{$lot->id}. " .
                        "Le lot ne contient que {$quantiteLotAvant} unité(s)."
                    );
                }
                // ========================================================
                // 13. CAS AUGMENTATION
                // ========================================================
    
                $estAugmentation = $delta > 0;
    
                if ($estAugmentation) {
    
                    /*
                     * Un lot EPUISE ne doit pas être réactivé automatiquement.
                     *
                     * Si on veut ajouter du stock après épuisement,
                     * on doit normalement créer un nouveau lot.
                     */
                    if ($lot->statut === 'EPUISE') {
    
                        throw new \RuntimeException(
                            'Impossible d\'augmenter directement un lot épuisé. ' .
                            'Créez un nouveau lot pour les nouvelles unités.'
                        );
                    }
    
                    // ----------------------------------------------------
                    // IMEI lors d'une augmentation
                    // ----------------------------------------------------
    
                    if (!empty($imeis)) {
    
                        if (!$produit->gere_imei) {
    
                            throw new \RuntimeException(
                                'Ce produit ne gère pas les IMEI.'
                            );
                        }
    
                        if (
                            count($imeis) !==
                            $delta
                        ) {
    
                            throw new \RuntimeException(
                                'Le nombre d\'IMEI (' .
                                count($imeis) .
                                ') doit correspondre exactement ' .
                                'à la quantité ajoutée (' .
                                $delta .
                                ').'
                            );
                        }
    
                        // Doublons dans la requête
                        if (
                            count($imeis) !==
                            count(array_unique($imeis))
                        ) {
    
                            throw new \RuntimeException(
                                'Les IMEI ne peuvent pas être dupliqués.'
                            );
                        }
    
                        // Vérifier les doublons en base
                        $imeisExistants = ProduitImei::whereIn(
                            'numero_imei',
                            $imeis
                        )
                            ->lockForUpdate()
                            ->pluck('numero_imei')
                            ->toArray();
    
                        if (!empty($imeisExistants)) {
    
                            throw new \RuntimeException(
                                'Les IMEI suivants existent déjà : ' .
                                implode(', ', $imeisExistants)
                            );
                        }
    
                    } else {
    
                        /*
                         * IMEI facultatifs.
                         *
                         * Même si le produit gère les IMEI,
                         * l'ajustement peut être effectué sans IMEI.
                         */
                    }
    
                } else {
    
                    // ====================================================
                    // 14. CAS DIMINUTION
                    // ====================================================
    
                    /*
                     * Les IMEI existants ne doivent jamais devenir
                     * incohérents avec la quantité physique du lot.
                     *
                     * On compte uniquement les IMEI encore EN_STOCK.
                     */
    
                    if ($produit->gere_imei) {
    
                        $nombreImeisEnStock = ProduitImei::where(
                            'lot_stock_id',
                            $lot->id
                        )
                            ->where(
                                'statut',
                                ProduitImei::STATUT_EN_STOCK
                            )
                            ->count();
    
                        if (
                            $nombreImeisEnStock >
                            $quantiteLotApres
                        ) {
    
                            throw new \RuntimeException(
                                "Impossible de réduire ce lot à {$quantiteLotApres} unité(s) : " .
                                "{$nombreImeisEnStock} IMEI sont encore enregistrés " .
                                "comme étant EN_STOCK dans ce lot. " .
                                "Veuillez d'abord régulariser les IMEI."
                            );
                        }
                    }
                }
    
                // ========================================================
                // 15. NOUVELLE QUANTITÉ DU STOCK GLOBAL
                // ========================================================
    
                $nouvelleQuantiteStock =
                    $quantiteStockAvant + $delta;
    
                if ($nouvelleQuantiteStock < 0) {
    
                    throw new \RuntimeException(
                        'La quantité disponible du stock ne peut pas devenir négative.'
                    );
                }
    
                // ========================================================
                // 16. CRÉER LE MOUVEMENT D'AJUSTEMENT
                // ========================================================
    
                $coutUnitaire = (float) (
                    $lot->cout_unitaire ??
                    $lot->cout_unitaire_ttc ??
                    $produit->prix_achat ??
                    0
                );
    
                $coutTotal = round(
                    abs($delta) * $coutUnitaire,
                    2
                );
    
                $mouvement = \App\Models\MouvementStock::create([
    
                    'reference_mouvement' =>
                        \App\Models\MouvementStock::genererReference(),
    
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
    
                    /*
                     * Le mouvement garde le delta réel :
                     *
                     * +5 = ajout
                     * -5 = retrait
                     */
                    'quantite' =>
                        $delta,
    
                    'quantite_avant' =>
                        $quantiteStockAvant,
    
                    'quantite_apres' =>
                        $nouvelleQuantiteStock,
    
                    'responsable_id' =>
                        $user->id,
    
                    'raison' =>
                        $request->input('motif'),
    
                    'reference_document' =>
                        'AJUST-LOT-' . $lot->id . '-' .
                        now()->format('YmdHis'),
    
                    'metadata' => [
    
                        'type_ajustement' =>
                            $estAugmentation
                                ? 'AUGMENTATION_LOT'
                                : 'DIMINUTION_LOT',
    
                        'lot_id' =>
                            $lot->id,
    
                        'numero_lot' =>
                            $lot->numero_lot,
    
                        'quantite_lot_avant' =>
                            $quantiteLotAvant,
    
                        'quantite_lot_apres' =>
                            $quantiteLotApres,
    
                        'delta_lot' =>
                            $delta,
    
                        'quantite_stock_avant' =>
                            $quantiteStockAvant,
    
                        'quantite_stock_apres' =>
                            $nouvelleQuantiteStock,
    
                        'cout_unitaire' =>
                            $coutUnitaire,
    
                        'cout_total' =>
                            $coutTotal,
    
                        'statut_lot_avant' =>
                            $lot->statut,
    
                        'motif' =>
                            $request->input('motif'),
    
                        'notes' =>
                            $request->input('notes'),
    
                        'imeis' =>
                            $imeis,
                    ],
    
                    'cout_unitaire' =>
                        $coutUnitaire,
    
                    'cout_total' =>
                        $coutTotal,
    
                    'quantite_restante_lot' =>
                        $quantiteLotApres,
    
                    'est_lot_fifo' =>
                        1,
    
                    'prix_achat_lot' =>
                        $coutUnitaire,
    
                    'lots_sortie' =>
                        null,
    
                    'lots_entree' =>
                        null,
    
                    'date_mouvement' =>
                        now(),
    
                    'date_validation' =>
                        now(),
                ]);
    
                // ========================================================
                // 17. INFORMATIONS DU LOT SUR LE MOUVEMENT
                // ========================================================
    
                $mouvement->date_lot =
                    $lot->date_fabrication ?? now();
    
                $mouvement->date_expiration_lot =
                    $lot->date_expiration;
    
                $mouvement->save();
    
                // ========================================================
                // 18. MODIFIER LE LOT SPÉCIFIQUE
                // ========================================================
    
                $lot->quantite_restante =
                    $quantiteLotApres;
    
                /*
                 * Si on ajoute physiquement des unités à ce lot,
                 * elles font désormais partie de sa quantité initiale.
                 *
                 * Exemple :
                 *
                 * initiale = 10
                 * restante = 4
                 *
                 * +5
                 *
                 * initiale = 15
                 * restante = 9
                 *
                 * Consommé reste = 6.
                 */
                if ($estAugmentation) {
    
                    $lot->quantite_initiale =
                        (int) $lot->quantite_initiale +
                        $delta;
                }
    
                /*
                 * Si le lot arrive à zéro, il devient EPUISE.
                 *
                 * On ne touche pas aux autres statuts tant que
                 * le lot possède encore du stock.
                 */
                if ($quantiteLotApres === 0) {
    
                    $lot->statut = 'EPUISE';
                }
    
                $lot->save();
    
                // ========================================================
                // 19. LIER MOUVEMENT ↔ LOT
                // ========================================================
                
                DB::table('mouvement_stock_lots')->insert([
                
                    'mouvement_stock_id' =>
                        $mouvement->mouvement_id,
                
                    'lot_stock_id' =>
                        $lot->id,
                
                    'quantite' =>
                        abs($delta),  //  Toujours positif
                
                    'cout_unitaire' =>
                        $coutUnitaire,
                
                    'created_at' =>
                        now(),
                
                    'updated_at' =>
                        now(),
                ]);
                    
                // ========================================================
                // 20. METTRE À JOUR LES LOTS DU MOUVEMENT
                // ========================================================
    
                $lotsEntree = [];
    
                if ($estAugmentation) {
    
                    $lotsEntree[] = [
    
                        'lot_id' =>
                            $lot->id,
    
                        'numero_lot' =>
                            $lot->numero_lot,
    
                        'quantite' =>
                            $delta,
    
                        'quantite_avant' =>
                            $quantiteLotAvant,
    
                        'quantite_apres' =>
                            $quantiteLotApres,
    
                        'cout_unitaire' =>
                            $coutUnitaire,
    
                        'date_expiration' =>
                            $lot->date_expiration,
                    ];
                }
    
                $lotsSortie = [];
    
                if (!$estAugmentation) {
    
                    $lotsSortie[] = [
    
                        'lot_id' =>
                            $lot->id,
    
                        'numero_lot' =>
                            $lot->numero_lot,
    
                        'quantite' =>
                            abs($delta),
    
                        'quantite_avant' =>
                            $quantiteLotAvant,
    
                        'quantite_apres' =>
                            $quantiteLotApres,
    
                        'cout_unitaire' =>
                            $coutUnitaire,
    
                        'date_expiration' =>
                            $lot->date_expiration,
                    ];
                }
    
                $metadata = is_array($mouvement->metadata)
                    ? $mouvement->metadata
                    : [];
    
                $metadata['lots_entree'] =
                    $lotsEntree;
    
                $metadata['lots_sortie'] =
                    $lotsSortie;
    
                $mouvement->metadata =
                    $metadata;
    
                $mouvement->lots_entree =
                    $lotsEntree ?: null;
    
                $mouvement->lots_sortie =
                    $lotsSortie ?: null;
    
                $mouvement->save();
    
                // ========================================================
                // 21. CRÉER LES IMEI EN CAS D'AUGMENTATION
                // ========================================================
    
                $imeisCrees = [];
    
                if (
                    $estAugmentation &&
                    !empty($imeis)
                ) {
    
                    $resultImeis =
                        app(\App\Services\ImeiService::class)
                            ->creerImeisPourLot(
                                $lot,
                                $stock,
                                $imeis,
                                $mouvement->mouvement_id
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
    
                    $imeisCrees = $imeis;
                }
    
                // ========================================================
                // 22. METTRE À JOUR LE STOCK GLOBAL
                // ========================================================
    
                $stock->quantite_disponible =
                    $nouvelleQuantiteStock;
    
                $stock->save();
    
                // ========================================================
                // 23. CONTRÔLE FINAL STOCK ↔ LOTS
                // ========================================================
    
                $quantiteLotsApres = (int) LotStock::where(
                        'stock_succursale_id',
                        $stock->id
                    )
                    ->where(
                        'produit_id',
                        $stock->produit_id
                    )
                    ->where(
                        'quantite_restante',
                        '>',
                        0
                    )
                    ->sum('quantite_restante');
    
                if (
                    $quantiteLotsApres !==
                    $nouvelleQuantiteStock
                ) {
    
                    throw new \RuntimeException(
                        "Incohérence après ajustement du lot #{$lot->id} : " .
                        "stock = {$nouvelleQuantiteStock}, " .
                        "somme des lots = {$quantiteLotsApres}."
                    );
                }
    
                // ========================================================
                // 24. MISE À JOUR DE L'ALERTE
                // ========================================================
    
                $stock
                    ->mettreAJourStatutAlerte()
                    ->save();
    
                // ========================================================
                // 25. RAFRAÎCHISSEMENT
                // ========================================================
    
                $stock->refresh();
                $lot->refresh();
                $mouvement->refresh();
    
                return [
    
                    'stock' =>
                        $stock,
    
                    'lot' =>
                        $lot,
    
                    'mouvement' =>
                        $mouvement,
    
                    'quantite_lot_avant' =>
                        $quantiteLotAvant,
    
                    'quantite_lot_apres' =>
                        $quantiteLotApres,
    
                    'delta' =>
                        $delta,
    
                    'quantite_stock_avant' =>
                        $quantiteStockAvant,
    
                    'quantite_stock_apres' =>
                        $nouvelleQuantiteStock,
    
                    'imeis' =>
                        $imeisCrees,
                ];
            });
    
            // ============================================================
            // 26. RÉPONSE
            // ============================================================
    
            return response()->json([
    
                'success' =>
                    true,
    
                'message' =>
                    $result['delta'] > 0
                        ? "Augmentation du lot #{$id} effectuée avec succès."
                        : "Diminution du lot #{$id} effectuée avec succès.",
    
                'data' => [
    
                    'lot' => [
    
                        'id' =>
                            $result['lot']->id,
    
                        'numero_lot' =>
                            $result['lot']->numero_lot,
    
                        'quantite_initiale' =>
                            (int) $result['lot']->quantite_initiale,
    
                        'quantite_restante' =>
                            (int) $result['lot']->quantite_restante,
    
                        'statut' =>
                            $result['lot']->statut,
    
                    ],
    
                    'ajustement' => [
    
                        'quantite_avant' =>
                            $result['quantite_lot_avant'],
    
                        'delta' =>
                            $result['delta'],
    
                        'quantite_apres' =>
                            $result['quantite_lot_apres'],
    
                    ],
    
                    'stock' => [
    
                        'quantite_avant' =>
                            $result['quantite_stock_avant'],
    
                        'quantite_apres' =>
                            $result['quantite_stock_apres'],
    
                    ],
    
                    'mouvement' => [
    
                        'id' =>
                            $result['mouvement']->mouvement_id,
    
                        'reference' =>
                            $result['mouvement']
                                ->reference_mouvement,
    
                        'type' =>
                            $result['mouvement']
                                ->type_mouvement,
    
                    ],
    
                    'imeis' => [
    
                        'nombre' =>
                            count($result['imeis']),
    
                        'liste' =>
                            $result['imeis'],
    
                    ],
                ],
    
            ], 200);
    
        } catch (\Throwable $e) {
    
            Log::error(
                'Erreur ajustement quantité lot',
                [
    
                    'lot_id' =>
                        $id,
    
                    'quantite' =>
                        $delta,
    
                    'user_id' =>
                        $user->id ?? null,
    
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
                    'L\'ajustement du lot a été annulé. ' .
                    'Aucune modification partielle n\'a été conservée.',
    
                'error' =>
                    $e->getMessage(),
    
            ], 422);
        }
    }
    /**
     * Afficher un lot spécifique avec ses relations
     */
    public function show($id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-stock-agence') && !$user->hasPermission('manage-stock-agence')) {
            return response()->json(['success' => false, 'message' => 'Permission refusée'], 403);
        }

        $lot = LotStock::with([
            'produit',
            'stockSuccursale.succursale',
            'mouvementEntree',
            'mouvementsSortie' => function ($q) {
                $q->with(['succursale', 'responsable']);
            }
        ])->findOrFail($id);

        // Vérification d'accès à la succursale
        if (!$user->hasPermission('manage-stock-agence')) {
            if ($lot->stockSuccursale->succursale_id != $user->succursale_id) {
                return response()->json(['success' => false, 'message' => 'Accès refusé'], 403);
            }
        }

        // Récupération des IMEI liés à ce lot
        $imeis = ProduitImei::where('lot_stock_id', $lot->id)
            ->select('id', 'numero_imei', 'numero_serie', 'statut', 'facture_id', 'commande_id', 'created_at')
            ->get();

        // Statistiques du lot
        $stats = [
            'quantite_consommee' => $lot->quantite_initiale - $lot->quantite_restante,
            'pourcentage_consomme' => $lot->quantite_initiale > 0
                ? round(($lot->quantite_initiale - $lot->quantite_restante) / $lot->quantite_initiale * 100, 2)
                : 0,
            'nb_mouvements_sortie' => $lot->mouvementsSortie->count(),
            'nb_imeis' => $imeis->count(),
            'est_expire' => $lot->date_expiration && $lot->date_expiration <= now()->startOfDay(),
            'jours_avant_expiration' => $lot->date_expiration
                ? now()->startOfDay()->diffInDays($lot->date_expiration, false)
                : null,
        ];

        return response()->json([
            'success' => true,
            'data' => $lot,
            'imeis' => $imeis,
            'stats' => $stats
        ]);
    }

    /**
     * RÉCUPÉRER LA QUANTITÉ D'UN LOT
     */
    public function getQuantite($id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-stock-agence') && !$user->hasPermission('manage-stock-agence')) {
            return response()->json(['success' => false, 'message' => 'Permission refusée'], 403);
        }

        $lot = LotStock::with(['produit', 'stockSuccursale.succursale'])->findOrFail($id);

        if (!$user->hasPermission('manage-stock-agence')) {
            if ($lot->stockSuccursale->succursale_id != $user->succursale_id) {
                return response()->json(['success' => false, 'message' => 'Accès refusé'], 403);
            }
        }

        $quantiteConsommee = $lot->quantite_initiale - $lot->quantite_restante;
        $pourcentageConsomme = $lot->quantite_initiale > 0
            ? round(($quantiteConsommee / $lot->quantite_initiale) * 100, 2)
            : 0;

        $quantiteReservee = DB::table('mouvements_stock_lots')
            ->where('lot_stock_id', $lot->id)
            ->sum('quantite');

        $quantiteDisponible = max(0, $lot->quantite_restante - $quantiteReservee);

        $imeis = [];
        $nbImeis = 0;
        if ($lot->produit && $lot->produit->gere_imei) {
            $imeis = ProduitImei::where('lot_stock_id', $lot->id)
                ->select('id', 'numero_imei', 'numero_serie', 'statut')
                ->get();
            $nbImeis = $imeis->count();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'lot' => [
                    'id' => $lot->id,
                    'numero_lot' => $lot->numero_lot,
                    'produit_id' => $lot->produit_id,
                    'produit_nom' => $lot->produit?->nom,
                    'statut' => $lot->statut,
                ],
                'quantites' => [
                    'quantite_initiale' => (int) $lot->quantite_initiale,
                    'quantite_restante' => (int) $lot->quantite_restante,
                    'quantite_consommee' => (int) $quantiteConsommee,
                    'pourcentage_consomme' => $pourcentageConsomme,
                    'quantite_reservee' => (int) $quantiteReservee,
                    'quantite_disponible' => (int) $quantiteDisponible,
                ],
                'succursale' => [
                    'id' => $lot->stockSuccursale?->succursale_id,
                    'nom' => $lot->stockSuccursale?->succursale?->nom,
                ],
                'imeis' => $lot->produit?->gere_imei ? [
                    'total' => $nbImeis,
                    'liste' => $imeis->take(20)->map(function ($imei) {
                        return [
                            'id' => $imei->id,
                            'numero_imei' => $imei->numero_imei,
                            'numero_serie' => $imei->numero_serie,
                            'statut' => $imei->statut,
                            'libelle_statut' => $imei->libelle_statut ?? $imei->statut,
                        ];
                    }),
                ] : null,
                'expiration' => [
                    'date_expiration' => $lot->date_expiration?->format('Y-m-d'),
                    'est_expire' => $lot->date_expiration && $lot->date_expiration <= now()->startOfDay(),
                    'jours_avant_expiration' => $lot->date_expiration
                        ? now()->startOfDay()->diffInDays($lot->date_expiration, false)
                        : null,
                ],
                'cout' => [
                    'cout_unitaire' => $lot->cout_unitaire,
                    'cout_unitaire_ttc' => $lot->cout_unitaire_ttc,
                    'valeur_totale_ht' => $lot->cout_unitaire * $lot->quantite_restante,
                    'valeur_totale_ttc' => ($lot->cout_unitaire_ttc ?? $lot->cout_unitaire) * $lot->quantite_restante,
                ]
            ]
        ]);
    }

    /**
     * AJOUTER DES IMEI À UN LOT (IDENTIFICATION DES UNITÉS PHYSIQUES)
     * 
     * Permet d'ajouter des IMEI à un lot existant pour identifier chaque unité.
     * 
     * Conditions :
     * - Le produit du lot doit gérer les IMEI (gere_imei = true)
     * - Le nombre d'IMEI ne doit pas dépasser la quantité restante du lot
     * - Les IMEI doivent être uniques (15-17 chiffres)
     * 
     * Permissions: manage-stock-agence
     */
        public function ajouterImeis(Request $request, $id)
    {
        $user = auth()->user();
    
        $validator = Validator::make($request->all(), [
            'imeis' => 'required|array|min:1',
            'imeis.*' => [
                'required',
                'string',
                'regex:/^\d{15,17}$/',
            ],
            'notes' => 'nullable|string|max:500',
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }
    
        try {
            DB::beginTransaction();
    
            // ============================================================
            // 1. RÉCUPÉRER LE LOT AVEC VERROU
            // ============================================================
    
            $lot = LotStock::with([
                'produit',
                'stockSuccursale',
            ])
            ->lockForUpdate()
            ->findOrFail($id);
    
            // ============================================================
            // 2. VÉRIFIER L'ACCÈS À LA SUCCURSALE
            // ============================================================
    
            if (
                !$lot->stockSuccursale ||
                $lot->stockSuccursale->succursale_id != $user->succursale_id
            ) {
                DB::rollBack();
    
                return response()->json([
                    'success' => false,
                    'message' => 'Accès refusé',
                ], 403);
            }
    
            // ============================================================
            // 3. VÉRIFIER QUE LE PRODUIT GÈRE LES IMEI
            // ============================================================
    
            if (!$lot->produit || !$lot->produit->gere_imei) {
                DB::rollBack();
    
                return response()->json([
                    'success' => false,
                    'message' => 'Ce lot est associé à un produit qui ne gère pas les IMEI.',
                ], 422);
            }
    
            // ============================================================
            // 4. NORMALISER LES IMEI
            // ============================================================
    
            $imeis = collect($request->input('imeis', []))
                ->map(fn ($imei) => trim((string) $imei))
                ->values()
                ->all();
    
            $notes = $request->input(
                'notes',
                "Ajout manuel d'IMEI au lot #{$lot->id}"
            );
    
            // ============================================================
            // 5. VÉRIFIER LES DOUBLONS DANS LA REQUÊTE
            // ============================================================
    
            if (count($imeis) !== count(array_unique($imeis))) {
                DB::rollBack();
    
                return response()->json([
                    'success' => false,
                    'message' => 'Les IMEI ne peuvent pas être dupliqués dans la même requête.',
                ], 422);
            }
    
            // ============================================================
            // 6. VÉRIFIER LA QUANTITÉ DISPONIBLE DU LOT
            // ============================================================
            //
            // IMPORTANT :
            // On ne modifie PAS quantite_restante ici.
            //
            // Ajouter un IMEI signifie identifier une unité déjà présente
            // dans le lot, pas créer une nouvelle unité de stock.
            //
            // On vérifie seulement qu'il y a suffisamment d'unités
            // disponibles pour lesquelles on peut enregistrer les IMEI.
            //
            // On compte les IMEI déjà associés à ce lot.
            // ============================================================
    
            $nombreImeisExistantsDansLot = ProduitImei::where(
                'lot_stock_id',
                $lot->id
            )->count();
    
            $quantiteLot = (int) ($lot->quantite_restante ?? 0);
    
            $capaciteImeiRestante = max(
                0,
                $quantiteLot - $nombreImeisExistantsDansLot
            );
    
            if (count($imeis) > $capaciteImeiRestante) {
                DB::rollBack();
    
                return response()->json([
                    'success' => false,
                    'message' => "Le lot ne peut recevoir que {$capaciteImeiRestante} IMEI supplémentaire(s).",
                    'details' => [
                        'quantite_lot' => $quantiteLot,
                        'imeis_deja_associes' => $nombreImeisExistantsDansLot,
                        'capacite_restante' => $capaciteImeiRestante,
                        'imeis_demandes' => count($imeis),
                    ],
                ], 422);
            }
    
            // ============================================================
            // 7. VÉRIFIER L'UNICITÉ DES IMEI EN BASE
            // ============================================================
    
            $existants = ProduitImei::whereIn('numero_imei', $imeis)
                ->pluck('numero_imei')
                ->toArray();
    
            if (!empty($existants)) {
                DB::rollBack();
    
                return response()->json([
                    'success' => false,
                    'message' => 'Les IMEI suivants existent déjà : ' .
                        implode(', ', $existants),
                    'imeis_existants' => $existants,
                ], 422);
            }
    
            // ============================================================
            // 8. CRÉER LES IMEI
            // ============================================================
    
            $created = [];
    
            foreach ($imeis as $numeroImei) {
                $imei = ProduitImei::create([
                    'produit_id' => $lot->produit_id,
                    'numero_imei' => $numeroImei,
                    'succursale_id' => $lot->stockSuccursale->succursale_id,
                    'lot_stock_id' => $lot->id,
                    'statut' => ProduitImei::STATUT_EN_STOCK,
                    'date_entree' => now(),
    
                    // Prix d'achat du lot
                    'prix_achat' => $lot->cout_unitaire ?? 0,
    
                    'notes' => $notes,
    
                    'metadata' => [
                        'ajout_manuel' => true,
                        'ajoute_par' => $user->id,
                        'ajoute_le' => now()->toDateTimeString(),
                    ],
                ]);
    
                $created[] = $imei;
            }
    
            // ============================================================
            // 9. NE PAS MODIFIER LE STOCK
            // ============================================================
            //
            // IMPORTANT :
            // Les unités existaient déjà dans le stock.
            //
            // Donc :
            // - pas de + quantite_disponible
            // - pas de - quantite_restante
            // - pas de nouveau mouvement de stock
            //
            // On ne fait qu'associer les IMEI au lot.
            // ============================================================
    
            DB::commit();
    
            // ============================================================
            // 10. LOG
            // ============================================================
    
            Log::info('IMEI ajoutés au lot', [
                'lot_id' => $lot->id,
                'user_id' => $user->id,
                'nb_imeis' => count($created),
                'succursale_id' => $lot->stockSuccursale->succursale_id,
            ]);
    
            // ============================================================
            // 11. PRÉPARER LA RÉPONSE
            // ============================================================
            //
            // $created est un tableau PHP.
            // On utilise array_map(), PAS ->map().
            // ============================================================
    
            $imeisAjoutes = array_map(
                function ($imei) {
                    return [
                        'id' => $imei->id,
                        'numero_imei' => $imei->numero_imei,
                        'statut' => $imei->statut,
                    ];
                },
                $created
            );
    
            return response()->json([
                'success' => true,
                'message' => count($created) .
                    " IMEI ajouté(s) avec succès au lot #{$lot->id}",
    
                'data' => [
                    'lot' => [
                        'id' => $lot->id,
                        'numero_lot' => $lot->numero_lot,
                        'quantite_restante' => $lot->quantite_restante,
                    ],
    
                    'imeis_ajoutes' => $imeisAjoutes,
    
                    'nombre_imeis_ajoutes' => count($created),
                ],
            ], 201);
    
        } catch (\Exception $e) {
    
            DB::rollBack();
    
            Log::error('Erreur ajout IMEI au lot', [
                'lot_id' => $id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
    
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'ajout des IMEI : ' .
                    $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mettre à jour les métadonnées d'un lot
     */
    public function update(Request $request, $id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('manage-stock-agence')) {
            return response()->json(['success' => false, 'message' => 'Permission refusée'], 403);
        }

        $lot = LotStock::with('stockSuccursale')->findOrFail($id);

        if ($lot->stockSuccursale->succursale_id != $user->succursale_id) {
            return response()->json(['success' => false, 'message' => 'Accès refusé'], 403);
        }

        $validator = Validator::make($request->all(), [
            'numero_lot' => 'nullable|string|max:100',
            'date_fabrication' => 'nullable|date',
            'date_expiration' => 'nullable|date',
            'cout_unitaire' => 'nullable|numeric|min:0',
            'cout_unitaire_ttc' => 'nullable|numeric|min:0',
            'motif_statut' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        if ($request->has('quantite_restante')) {
            return response()->json([
                'success' => false,
                'message' => 'La modification de la quantité restante est interdite.'
            ], 422);
        }

        if ($request->has('statut')) {
            return response()->json([
                'success' => false,
                'message' => 'La modification du statut se fait via /lots/{id}/statut.'
            ], 422);
        }

        try {
            $lot->update($request->only([
                'numero_lot',
                'date_fabrication',
                'date_expiration',
                'cout_unitaire',
                'cout_unitaire_ttc',
                'motif_statut',
                'notes'
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Métadonnées du lot mises à jour',
                'data' => $lot->fresh(['produit', 'stockSuccursale'])
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur mise à jour lot', [
                'lot_id' => $id,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Changer le statut administratif d'un lot
     */
    public function changerStatut(Request $request, $id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('manage-stock-agence')) {
            return response()->json(['success' => false, 'message' => 'Permission refusée'], 403);
        }

        $validator = Validator::make($request->all(), [
            'statut' => 'required|in:ACTIF,EPUISE,QUARANTAINE,BLOQUE,EXPIRE',
            'motif' => 'nullable|string|max:255'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $lot = LotStock::with('stockSuccursale')->findOrFail($id);

        if ($lot->stockSuccursale->succursale_id != $user->succursale_id) {
            return response()->json(['success' => false, 'message' => 'Accès refusé'], 403);
        }

        if ($lot->statut === 'EPUISE' && $request->statut === 'ACTIF') {
            return response()->json([
                'success' => false,
                'message' => 'Un lot épuisé ne peut pas être remis en ACTIF.'
            ], 422);
        }

        $ancienStatut = $lot->statut;
        $nouveauStatut = $request->statut;

        try {
            $lot->statut = $nouveauStatut;
            if ($request->filled('motif')) {
                $lot->motif_statut = $request->motif;
            }
            $lot->save();

            return response()->json([
                'success' => true,
                'message' => "Statut changé de {$ancienStatut} à {$nouveauStatut}",
                'data' => $lot->fresh()
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur changement statut', [
                'lot_id' => $id,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}