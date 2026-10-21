<?php
// app/Services/Approvisionnement/ApprovisionnementService.php

namespace App\Services;

use App\Models\Approvisionnement;
use App\Models\ProduitImei;
use App\Models\StockSuccursale;
use App\Models\LotStock;
use App\Models\MouvementStock;
use App\Models\LigneApprovisionnement;
use App\Services\StockService;
use App\Services\ImeiService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ApprovisionnementService
{
    protected StockService $stockService;
    protected ImeiService $imeiService;

    public function __construct(StockService $stockService, ImeiService $imeiService)
    {
        $this->stockService = $stockService;
        $this->imeiService = $imeiService;
    }

    // ================================================================
    // PRÉPARER UN APPROVISIONNEMENT (AVEC SÉLECTION OPTIONNELLE DES IMEI)
    // ================================================================

    public function preparer(
    Approvisionnement $appro,
    ?array $imeiSelections = null
    ): array {
        $result = [
            'success' => true,
            'imei_manquants' => [],
            'imei_selectionnes' => [],
            'erreurs' => []
        ];
    
        foreach ($appro->lignes as $ligne) {
    
            if (!$ligne->produit || !$ligne->produit->gere_imei) {
                continue;
            }
    
            $quantite = (int) $ligne->quantite_demandee;
    
            if ($quantite <= 0) {
                continue;
            }
    
            // ============================================================
            // 1. RÉCUPÉRER LES IMEI FOURNIS
            // ============================================================
    
            $imeiIdsFournis = $imeiSelections[$ligne->id] ?? null;
    
            if ($imeiIdsFournis !== null && !is_array($imeiIdsFournis)) {
                $imeiIdsFournis = [$imeiIdsFournis];
            }
    
            $imeiIdsFournis = collect($imeiIdsFournis ?? [])
                ->map(fn ($value) => trim((string) $value))
                ->filter(fn ($value) => $value !== '')
                ->values()
                ->all();
    
            // ============================================================
            // 2. VERROUILLER LE STOCK SOURCE
            // ============================================================
    
            $stockSource = StockSuccursale::where(
                'succursale_id',
                $appro->source_decidee_id
            )
            ->where(
                'produit_id',
                $ligne->produit_id
            )
            ->lockForUpdate()
            ->first();
    
            if (!$stockSource) {
                $result['success'] = false;
    
                $result['erreurs'][] = [
                    'produit_id' => $ligne->produit_id,
                    'produit_nom' => $ligne->produit->nom,
                    'message' =>
                        "Stock source introuvable pour {$ligne->produit->nom}."
                ];
    
                continue;
            }
    
            // ============================================================
            // 3. VÉRIFIER LE STOCK PHYSIQUE
            // ============================================================
    
            $stockDisponible = $stockSource->stockDisponible();
    
            if ($stockDisponible < $quantite) {
                $result['success'] = false;
    
                $result['erreurs'][] = [
                    'produit_id' => $ligne->produit_id,
                    'produit_nom' => $ligne->produit->nom,
                    'message' =>
                        "Stock insuffisant pour {$ligne->produit->nom}. " .
                        "Demandé : {$quantite}, disponible : {$stockDisponible}."
                ];
    
                continue;
            }
    
            // ============================================================
            // 4. DÉTERMINER LES LOTS FIFO QUI SERONT CONSOMMÉS
            // ============================================================
            //
            // IMPORTANT :
            // On ne limite PAS le transfert à un seul lot.
            //
            // Exemple :
            //
            // Lot 10 → 2
            // Lot 15 → 3
            // Lot 21 → 5
            //
            // Quantité demandée = 7
            //
            // FIFO :
            // Lot 10 → 2
            // Lot 15 → 3
            // Lot 21 → 2
            //
            // ============================================================
    
            $stockSource = StockSuccursale::where(
                'succursale_id',
                $appro->source_decidee_id
            )
            ->where(
                'produit_id',
                $ligne->produit_id
            )
            ->lockForUpdate()
            ->first();
            
            if (!$stockSource) {
                $result['success'] = false;
            
                $result['erreurs'][] = [
                    'produit_id' => $ligne->produit_id,
                    'produit_nom' => $ligne->produit->nom,
                    'message' =>
                        "Stock source introuvable pour {$ligne->produit->nom}."
                ];
            
                continue;
            }
            
            $lots = LotStock::where(
                'produit_id',
                $ligne->produit_id
            )
            ->where(
                'stock_succursale_id',
                $stockSource->id
            )
            ->where(
                'quantite_restante',
                '>',
                0
            )
            ->where('statut', 'ACTIF') // ✅ AJOUTER CE FILTRE
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    
            $quantiteRestante = $quantite;
            $lotsFifo = [];
    
            foreach ($lots as $lot) {
    
                if ($quantiteRestante <= 0) {
                    break;
                }
    
                $quantiteDisponibleLot = (int) $lot->quantite_restante;
    
                if ($quantiteDisponibleLot <= 0) {
                    continue;
                }
    
                $quantiteDuLot = min(
                    $quantiteRestante,
                    $quantiteDisponibleLot
                );
    
                $lotsFifo[] = [
                    'lot_stock_id' => $lot->id,
                    'quantite' => $quantiteDuLot,
                ];
    
                $quantiteRestante -= $quantiteDuLot;
            }
    
            if ($quantiteRestante > 0) {
                $result['success'] = false;
    
                $result['erreurs'][] = [
                    'produit_id' => $ligne->produit_id,
                    'produit_nom' => $ligne->produit->nom,
                    'message' =>
                        "Impossible de répartir la quantité de {$ligne->produit->nom} " .
                        "selon le FIFO."
                ];
    
                continue;
            }
    
            // IDs des lots effectivement consommés par le FIFO
            $lotIdsFifo = collect($lotsFifo)
                ->pluck('lot_stock_id')
                ->filter()
                ->unique()
                ->values()
                ->all();
    
            // ============================================================
            // 5. MODE MANUEL
            // ============================================================
    
            if (!empty($imeiIdsFournis)) {
    
                // --------------------------------------------------------
                // Vérification des doublons
                // --------------------------------------------------------
    
                if (
                    count($imeiIdsFournis) !==
                    count(array_unique($imeiIdsFournis))
                ) {
                    $result['success'] = false;
    
                    $result['erreurs'][] = [
                        'produit_id' => $ligne->produit_id,
                        'produit_nom' => $ligne->produit->nom,
                        'message' =>
                            "Un même IMEI ne peut pas être sélectionné plusieurs fois."
                    ];
    
                    continue;
                }
    
                // --------------------------------------------------------
                // IMPORTANT :
                // On ne demande plus obligatoirement $quantite IMEI.
                //
                // Exemple :
                // quantité = 7
                // IMEI sélectionnés = 4
                //
                // → autorisé
                // --------------------------------------------------------
    
                if (count($imeiIdsFournis) > $quantite) {
    
                    $result['success'] = false;
    
                    $result['erreurs'][] = [
                        'produit_id' => $ligne->produit_id,
                        'produit_nom' => $ligne->produit->nom,
                        'message' =>
                            "Vous avez sélectionné " .
                            count($imeiIdsFournis) .
                            " IMEI alors que la quantité à transférer est de " .
                            $quantite . "."
                    ];
    
                    continue;
                }
    
                // --------------------------------------------------------
                // Récupérer les IMEI
                //
                // Ils doivent :
                // - appartenir au produit
                // - appartenir à la succursale source
                // - être EN_STOCK
                // - appartenir à un lot consommé par le FIFO
                // --------------------------------------------------------
    
                $imeis = ProduitImei::where(
                    'produit_id',
                    $ligne->produit_id
                )
                ->where(
                    'succursale_id',
                    $appro->source_decidee_id
                )
                ->where(
                    'statut',
                    ProduitImei::STATUT_EN_STOCK
                )
                ->whereIn(
                    'lot_stock_id',
                    $lotIdsFifo
                )
                ->where(function ($query) use ($imeiIdsFournis) {
    
                    $query
                        ->whereIn('id', $imeiIdsFournis)
                        ->orWhereIn('numero_imei', $imeiIdsFournis);
    
                })
                ->lockForUpdate()
                ->get();
    
                // --------------------------------------------------------
                // Vérifier que tous les IMEI demandés existent
                // --------------------------------------------------------
    
                if ($imeis->count() !== count($imeiIdsFournis)) {
    
                    $trouves = [];
    
                    foreach ($imeis as $imei) {
                        $trouves[] = (string) $imei->id;
                        $trouves[] = (string) $imei->numero_imei;
                    }
    
                    $manquants = array_values(
                        array_diff(
                            $imeiIdsFournis,
                            $trouves
                        )
                    );
    
                    $result['success'] = false;
    
                    $result['erreurs'][] = [
                        'produit_id' => $ligne->produit_id,
                        'produit_nom' => $ligne->produit->nom,
                        'message' =>
                            "Les IMEI suivants ne sont pas disponibles, " .
                            "ne correspondent pas au produit, ou appartiennent " .
                            "à un lot qui n'est pas consommé par le FIFO : " .
                            implode(', ', $manquants)
                    ];
    
                    continue;
                }
    
                // --------------------------------------------------------
                // Vérification unicité réelle
                // --------------------------------------------------------
    
                if (
                    $imeis->pluck('id')->unique()->count() !==
                    count($imeiIdsFournis)
                ) {
                    $result['success'] = false;
    
                    $result['erreurs'][] = [
                        'produit_id' => $ligne->produit_id,
                        'produit_nom' => $ligne->produit->nom,
                        'message' =>
                            "Certains IMEI ont été sélectionnés plusieurs fois."
                    ];
    
                    continue;
                }
    
                // --------------------------------------------------------
                // Vérification quantité IMEI par lot
                // --------------------------------------------------------
                //
                // Exemple FIFO :
                //
                // Lot 10 → 2
                // Lot 15 → 3
                // Lot 21 → 2
                //
                // Impossible de sélectionner :
                //
                // Lot 10 → 3 IMEI
                //
                // car le FIFO ne consomme que 2 unités de ce lot.
                //
                // --------------------------------------------------------
    
                $quantitesFifoParLot = collect($lotsFifo)
                    ->keyBy('lot_stock_id')
                    ->map(fn ($lot) => (int) $lot['quantite']);
    
                $imeisParLot = $imeis
                    ->groupBy('lot_stock_id')
                    ->map(fn ($collection) => $collection->count());
    
                foreach ($imeisParLot as $lotStockId => $nombreImeis) {
    
                    $quantiteFifoLot =
                        $quantitesFifoParLot[(int) $lotStockId] ?? 0;
    
                    if ($nombreImeis > $quantiteFifoLot) {
    
                        $result['success'] = false;
    
                        $result['erreurs'][] = [
                            'produit_id' => $ligne->produit_id,
                            'produit_nom' => $ligne->produit->nom,
                            'message' =>
                                "Le lot #{$lotStockId} ne peut fournir que " .
                                "{$quantiteFifoLot} unité(s) selon le FIFO, " .
                                "mais {$nombreImeis} IMEI ont été sélectionnés."
                        ];
    
                        continue 2;
                    }
                }
    
                $idsFinals = $imeis
                    ->pluck('id')
                    ->values()
                    ->toArray();
    
                // --------------------------------------------------------
                // Sauvegarder
                // --------------------------------------------------------
    
                $ligne->metadata = array_merge(
                    $ligne->metadata ?? [],
                    [
                        'imeis_selectionnes' => $idsFinals,
                        'selection_manuelle' => true,
                        'lots_fifo' => $lotsFifo,
                        'date_selection' =>
                            now()->toDateTimeString(),
                    ]
                );
    
                $ligne->save();
    
                $result['imei_selectionnes'][$ligne->id] = $idsFinals;
    
                continue;
            }
    
            // ============================================================
            // 6. MODE AUTOMATIQUE
            // ============================================================
            //
            // IMPORTANT :
            // On sélectionne automatiquement uniquement parmi les lots
            // que le FIFO va réellement consommer.
            //
            // Mais si moins d'IMEI sont disponibles que la quantité,
            // CE N'EST PAS UNE ERREUR.
            //
            // ============================================================
    
            $imeisDisponibles = ProduitImei::where(
                'produit_id',
                $ligne->produit_id
            )
            ->where(
                'succursale_id',
                $appro->source_decidee_id
            )
            ->where(
                'statut',
                ProduitImei::STATUT_EN_STOCK
            )
            ->whereIn(
                'lot_stock_id',
                $lotIdsFifo
            )
            ->orderByRaw(
                'FIELD(lot_stock_id, ' .
                implode(',', $lotIdsFifo) .
                ')'
            )
            ->orderBy('created_at')
            ->lockForUpdate()
            ->get();
    
            // ------------------------------------------------------------
            // On limite aux quantités FIFO réellement consommées par lot
            // ------------------------------------------------------------
    
            $imeisSelectionnes = collect();
    
            foreach ($lotsFifo as $lotFifo) {
    
                $lotId = $lotFifo['lot_stock_id'];
                $quantiteLot = (int) $lotFifo['quantite'];
    
                $imeisDuLot = $imeisDisponibles
                    ->where('lot_stock_id', $lotId)
                    ->take($quantiteLot);
    
                foreach ($imeisDuLot as $imei) {
                    $imeisSelectionnes->push($imei);
                }
            }
    
            $idsFinals = $imeisSelectionnes
                ->take($quantite)
                ->pluck('id')
                ->values()
                ->toArray();
    
            // ------------------------------------------------------------
            // Même si 0 IMEI ou moins que la quantité sont disponibles,
            // le transfert reste autorisé.
            // ------------------------------------------------------------
    
            $ligne->metadata = array_merge(
                $ligne->metadata ?? [],
                [
                    'imeis_selectionnes' => $idsFinals,
                    'selection_manuelle' => false,
                    'lots_fifo' => $lotsFifo,
                    'date_selection' =>
                        now()->toDateTimeString(),
                ]
            );
    
            $ligne->save();
    
            $result['imei_selectionnes'][$ligne->id] = $idsFinals;
    
            // ------------------------------------------------------------
            // Information uniquement : les unités sans IMEI ne bloquent pas
            // ------------------------------------------------------------
    
            if (count($idsFinals) < $quantite) {
    
                $result['imei_manquants'][] = [
                    'produit_id' => $ligne->produit_id,
                    'produit_nom' => $ligne->produit->nom,
                    'demande' => $quantite,
                    'disponibles' => count($idsFinals),
                    'manquants' => $quantite - count($idsFinals),
                ];
            }
        }
    
        return $result;
    }

    // ================================================================
    // EXPÉDIER UN APPROVISIONNEMENT (AVEC IMEI)
    // ================================================================

        public function expedier(
    Approvisionnement $appro,
    int $utilisateurId
): MouvementStock {

    DB::beginTransaction();

    try {

        // ============================================================
        // 1. VERROUILLER LA DEMANDE
        // ============================================================

        $appro = Approvisionnement::with([
            'lignes.produit',
            'sourceDecidee',
            'destinationSuccursale',
        ])
        ->lockForUpdate()
        ->findOrFail($appro->id);

        // ============================================================
        // 2. VÉRIFICATIONS GÉNÉRALES
        // ============================================================

        if ($appro->statut_operation === 'EXPEDIE') {
            throw new \Exception(
                'Cette demande a déjà été expédiée.'
            );
        }

        if (!$appro->source_decidee_id) {
            throw new \Exception(
                'La succursale source de cette demande est introuvable.'
            );
        }

        if (!$appro->destination_succursale_id) {
            throw new \Exception(
                'La succursale destination de cette demande est introuvable.'
            );
        }

        if ($appro->lignes->isEmpty()) {
            throw new \Exception(
                'Cette demande ne contient aucune ligne à expédier.'
            );
        }

        $dernierMouvement = null;

        // ============================================================
        // 3. TRAITER CHAQUE LIGNE
        // ============================================================

        foreach ($appro->lignes as $ligne) {

            $quantite =
                (int) $ligne->quantite_envoyee;

            if ($quantite <= 0) {
                throw new \Exception(
                    "La quantité à expédier est invalide pour " .
                    ($ligne->produit->nom ??
                        "le produit #{$ligne->produit_id}")
                );
            }

            if (!$ligne->produit) {
                throw new \Exception(
                    "Produit introuvable pour la ligne #{$ligne->id}."
                );
            }

            // ========================================================
            // 3.1 VERROUILLER LE STOCK SOURCE
            // ========================================================

            $stockSource = StockSuccursale::where(
                'succursale_id',
                $appro->source_decidee_id
            )
            ->where(
                'produit_id',
                $ligne->produit_id
            )
            ->lockForUpdate()
            ->first();

            if (!$stockSource) {
                throw new \Exception(
                    "Stock source introuvable pour " .
                    $ligne->produit->nom
                );
            }

            // ========================================================
            // 3.2 STOCK PHYSIQUE
            // ========================================================

            $stockDisponible =
                $stockSource->stockDisponible();

            if ($stockDisponible < $quantite) {
                throw new \Exception(
                    "Stock insuffisant pour " .
                    $ligne->produit->nom .
                    ". Demandé : {$quantite}, " .
                    "disponible : {$stockDisponible}."
                );
            }

            // ========================================================
            // 3.3 RÉCUPÉRER LA PRÉPARATION FIFO
            // ========================================================

            $metadataLigne =
                $ligne->metadata ?? [];

            if (is_string($metadataLigne)) {
                $metadataLigne =
                    json_decode(
                        $metadataLigne,
                        true
                    ) ?? [];
            }

            if (!is_array($metadataLigne)) {
                $metadataLigne = [];
            }

            $lotsFifo =
                $metadataLigne['lots_fifo'] ?? [];

            if (!is_array($lotsFifo) || empty($lotsFifo)) {
                throw new \Exception(
                    "Aucune répartition FIFO n'a été préparée pour " .
                    $ligne->produit->nom . "."
                );
            }

            // ========================================================
            // 3.4 VÉRIFIER LA QUANTITÉ FIFO
            // ========================================================

            $quantiteFifo =
                collect($lotsFifo)->sum(
                    fn ($lot) =>
                        (int) ($lot['quantite'] ?? 0)
                );

            if ($quantiteFifo !== $quantite) {
                throw new \Exception(
                    "La répartition FIFO de " .
                    $ligne->produit->nom .
                    " est incohérente. " .
                    "Demandé : {$quantite}, " .
                    "FIFO : {$quantiteFifo}."
                );
            }

            // ========================================================
            // 3.5 GESTION DES IMEI
            // ========================================================

            $reservedIds = [];

            if ($ligne->produit->gere_imei) {

                /*
                 * Les IMEI ont déjà été déterminés par preparer().
                 *
                 * Ils peuvent être :
                 *
                 * 0 → aucun IMEI
                 * 3 → 3 unités tracées
                 * 7 → toutes les unités tracées
                 */

                $selection =
                    $metadataLigne['imeis_selectionnes']
                    ?? [];

                if (!is_array($selection)) {
                    $selection = [$selection];
                }

                $selection = collect($selection)
                    ->map(
                        fn ($value) =>
                            trim((string) $value)
                    )
                    ->filter(
                        fn ($value) =>
                            $value !== ''
                    )
                    ->values()
                    ->all();

                /*
                 * Réserver les IMEI.
                 *
                 * IMPORTANT :
                 * on ne demande PAS $quantite IMEI obligatoirement.
                 */

                $reservedIds =
                    $this->imeiService
                        ->reserverImeisPourTransfert(
                            $ligne->produit_id,
                            $appro->source_decidee_id,
                            $quantite,
                            $appro->id,
                            !empty($selection)
                                ? $selection
                                : null
                        );

                /*
                 * Le nombre d'IMEI peut être inférieur à la quantité.
                 */

                if (count($reservedIds) > $quantite) {
                    throw new \Exception(
                        "Le nombre d'IMEI réservés (" .
                        count($reservedIds) .
                        ") dépasse la quantité à expédier (" .
                        $quantite .
                        ")."
                    );
                }

                /*
                 * Sauvegarder les réservations.
                 */

                $metadataLigne[
                    'imeis_reserves'
                ] = $reservedIds;

                $metadataLigne[
                    'transfert_imei'
                ] = count($reservedIds) > 0;

                $metadataLigne[
                    'nombre_imeis_reserves'
                ] = count($reservedIds);

                $metadataLigne[
                    'transfert_imei_date'
                ] = now()->toDateTimeString();

                $ligne->metadata =
                    $metadataLigne;

                $ligne->save();
            }

            // ========================================================
            // 3.6 SORTIE DE STOCK
            // ========================================================

            $mouvementSortie =
                $stockSource->expedier(
                    $quantite,
                    [
                        'demande_parent_id' =>
                            $appro->id,

                        'reference_document' =>
                            $appro->reference,

                        'raison' =>
                            'Expédition approvisionnement ' .
                            $appro->reference,

                        'type_expedition' =>
                            'APPROVISIONNEMENT',

                        'responsable_id' =>
                            $utilisateurId,

                        /*
                         * IMPORTANT :
                         *
                         * On transmet EXACTEMENT les lots déterminés
                         * pendant preparer().
                         */

                        'lots_fifo' =>
                            $lotsFifo,

                        'metadata' => [

                            'source' =>
                                'APPROVISIONNEMENT',

                            'approvisionnement_id' =>
                                $appro->id,

                            'ligne_appro_id' =>
                                $ligne->id,

                            'succursale_source_id' =>
                                $appro->source_decidee_id,

                            'succursale_destination_id' =>
                                $appro->destination_succursale_id,

                            'quantite' =>
                                $quantite,

                            'avec_imei' =>
                                $ligne->produit->gere_imei,

                            'nombre_imeis' =>
                                count($reservedIds),

                            'imeis' =>
                                $reservedIds,
                        ],
                    ]
                );

            if (!$mouvementSortie) {
                throw new \Exception(
                    "La sortie de stock n'a pas pu être créée pour " .
                    $ligne->produit->nom
                );
            }

            // ========================================================
            // 3.7 TRANSFERT DÉFINITIF DES IMEI
            // ========================================================

            if (
                $ligne->produit->gere_imei &&
                !empty($reservedIds)
            ) {

                /*
                 * Les IMEI réellement réservés sont transférés.
                 *
                 * Leur nombre peut être inférieur à $quantite.
                 */

                $this->imeiService
                    ->marquerImeisTransferes(
                        $reservedIds,
                        $mouvementSortie
                    );

                /*
                 * Traçabilité.
                 */

                $metadataLigne =
                    $ligne->metadata ?? [];

                if (is_string($metadataLigne)) {
                    $metadataLigne =
                        json_decode(
                            $metadataLigne,
                            true
                        ) ?? [];
                }

                if (!is_array($metadataLigne)) {
                    $metadataLigne = [];
                }

                $metadataLigne[
                    'imeis_expedies'
                ] = $reservedIds;

                $metadataLigne[
                    'nombre_imeis_expedies'
                ] = count($reservedIds);

                $metadataLigne[
                    'mouvement_sortie_id'
                ] =
                    $mouvementSortie->mouvement_id;

                $metadataLigne[
                    'date_expedition_imei'
                ] =
                    now()->toDateTimeString();

                $ligne->metadata =
                    $metadataLigne;

                $ligne->save();
            }

            $dernierMouvement =
                $mouvementSortie;
        }

        // ============================================================
        // 4. METTRE À JOUR L'APPROVISIONNEMENT
        // ============================================================

        $appro->statut_operation =
            'EXPEDIE';

        $appro->date_expedition =
            now();

        $metadata =
            $appro->metadata ?? [];

        if (is_string($metadata)) {
            $metadata =
                json_decode(
                    $metadata,
                    true
                ) ?? [];
        }

        if (!is_array($metadata)) {
            $metadata = [];
        }

        $metadata['expedition'] =
            array_merge(
                $metadata['expedition'] ?? [],
                [
                    'effectuee_par' =>
                        $utilisateurId,

                    'effectuee_le' =>
                        now()->toDateTimeString(),

                    'mouvement_id' =>
                        $dernierMouvement?->mouvement_id,
                ]
            );

        $appro->metadata =
            $metadata;

        $appro->save();

        // ============================================================
        // 5. COMMIT
        // ============================================================

        DB::commit();

        Log::info(
            'Expédition approvisionnement réussie',
            [
                'appro_id' =>
                    $appro->id,

                'reference' =>
                    $appro->reference,

                'user_id' =>
                    $utilisateurId,

                'mouvement_id' =>
                    $dernierMouvement?->mouvement_id,
            ]
        );

        return $dernierMouvement;

    } catch (\Exception $e) {

        DB::rollBack();

        Log::error(
            'Erreur expédition approvisionnement',
            [
                'appro_id' =>
                    $appro->id,

                'user_id' =>
                    $utilisateurId,

                'error' =>
                    $e->getMessage(),

                'trace' =>
                    $e->getTraceAsString(),
            ]
        );

        throw $e;
    }
}

    // ================================================================
    // RÉCEPTIONNER UN APPROVISIONNEMENT (AVEC IMEI)
    // ================================================================

    public function receptionner(Approvisionnement $appro, int $utilisateurId): array
{
    DB::beginTransaction();

    try {

        foreach ($appro->lignes as $ligne) {

            $stockDestination = StockSuccursale::where(
                    'succursale_id',
                    $appro->destination_succursale_id
                )
                ->where('produit_id', $ligne->produit_id)
                ->lockForUpdate()
                ->first();

            /*
             * ============================================================
             * CRÉATION DU STOCK DESTINATION S'IL N'EXISTE PAS
             * ============================================================
             */
            if (!$stockDestination) {

                $stockDestination = StockSuccursale::create([
                    'succursale_id'       => $appro->destination_succursale_id,
                    'produit_id'          => $ligne->produit_id,
                    'quantite_disponible' => 0,
                    'quantite_reserve'    => 0,
                    'is_depot'            => true,
                ]);
            }

            /*
             * ============================================================
             * QUANTITÉS
             * ============================================================
             */
            $quantiteRecue = (int) $ligne->quantite_recue;

            $quantiteCasse = (int) ($ligne->quantite_casse ?? 0);
            $quantitePerdue = (int) ($ligne->quantite_perdue ?? 0);
            $quantiteNonConforme = (int) ($ligne->quantite_non_conforme ?? 0);

            $ecarts = $quantiteCasse
                + $quantitePerdue
                + $quantiteNonConforme;

            $quantiteConforme = max(
                0,
                $quantiteRecue - $ecarts
            );

            /*
             * ============================================================
             * AUCUNE QUANTITÉ CONFORME
             * ============================================================
             */
            if ($quantiteConforme <= 0) {

                $ligne->save();

                continue;
            }

            /*
             * ============================================================
             * PRIX D'ACHAT DU LOT SOURCE
             * ============================================================
             */
            $prixSource = $this->getPrixAchatLotSource($ligne);

            /*
             * ============================================================
             * RÉCEPTION STOCK
             *
             * Cette méthode doit :
             *  - augmenter quantite_disponible
             *  - créer le mouvement ENTREE
             *  - créer le lot FIFO
             * ============================================================
             */
            $mouvementEntree = $stockDestination->receptionner(
                $quantiteConforme,
                [
                    'demande_parent_id' => $appro->id,

                    'reference_document' => $appro->reference,

                    'raison' =>
                        'Réception approvisionnement ' .
                        $appro->reference,

                    'responsable_id' => $utilisateurId,

                    'prix_achat' => $prixSource,

                    'metadata' => [
                        'source' =>
                            'APPROVISIONNEMENT',

                        'approvisionnement_id' =>
                            $appro->id,

                        'ligne_appro_id' =>
                            $ligne->id,

                        'mouvement_sortie_id' =>
                            $ligne->metadata['mouvement_sortie_id'] ?? null,
                    ],
                ]
            );

            /*
             * ============================================================
             * VÉRIFICATION DU MOUVEMENT
             * ============================================================
             */
            if (!$mouvementEntree) {
                throw new \Exception(
                    "Le mouvement d'entrée n'a pas été créé pour {$ligne->produit->nom}."
                );
            }

            /*
             * ============================================================
             * RÉCUPÉRATION DIRECTE DU LOT CRÉÉ
             *
             * NE PAS utiliser :
             *
             * $mouvementEntree->lotEntree
             *
             * car la relation peut ne pas être chargée/définie.
             * ============================================================
             */
            $lotDestination = LotStock::where(
                'mouvement_entree_id',
                $mouvementEntree->mouvement_id
            )
            ->where(
                'produit_id',
                $ligne->produit_id
            )
            ->lockForUpdate()
            ->first();

            if (!$lotDestination) {

                throw new \Exception(
                    "Le lot FIFO de destination n'a pas été créé pour " .
                    "{$ligne->produit->nom}. " .
                    "Mouvement ID : {$mouvementEntree->mouvement_id}"
                );
            }

            /*
             * ============================================================
             * IMEI TRANSFÉRÉS
             *
             * IMPORTANT :
             * L'absence d'IMEI ne bloque PAS la réception.
             * ============================================================
             */
            if ($ligne->produit->gere_imei) {

                $metadata = $ligne->metadata ?? [];

                $imeiIds = $metadata['imeis_expedies'] ?? [];

                if (!is_array($imeiIds)) {
                    $imeiIds = [];
                }

                $imeiIds = array_values(
                    array_unique(
                        array_filter(
                            array_map('intval', $imeiIds)
                        )
                    )
                );

                /*
                 * --------------------------------------------------------
                 * S'il existe des IMEI expédiés
                 * --------------------------------------------------------
                 */
                if (!empty($imeiIds)) {

                    $imeis = ProduitImei::whereIn('id', $imeiIds)
                        ->where('produit_id', $ligne->produit_id)
                        ->where('statut', ProduitImei::STATUT_TRANSFERE)
                        ->lockForUpdate()
                        ->get();

                    /*
                     * On ne doit jamais recevoir plus d'IMEI
                     * que la quantité réellement conforme.
                     */
                    $imeisRecus = $imeis
                        ->take($quantiteConforme);

                    $imeisPerdus = $imeis
                        ->slice($quantiteConforme);

                    /*
                     * ----------------------------------------------------
                     * TRANSFERT DES IMEI VERS LA DESTINATION
                     * ----------------------------------------------------
                     */
                    if ($imeisRecus->isNotEmpty()) {

                        $this->imeiService->recevoirImeisTransferes(
                            $imeisRecus
                                ->pluck('id')
                                ->toArray(),

                            $appro->destination_succursale_id,

                            $lotDestination->id,

                            $mouvementEntree
                        );
                    }

                    /*
                     * ----------------------------------------------------
                     * IMEI PERDUS
                     * ----------------------------------------------------
                     */
                    if ($imeisPerdus->isNotEmpty()) {

                        foreach ($imeisPerdus as $imei) {

                            $imei->statut =
                                ProduitImei::STATUT_PERDU;

                            $imei->save();

                            MouvementStock::create([
                                'reference_mouvement' =>
                                    MouvementStock::genererReference(),

                                'succursale_id' =>
                                    $appro->source_decidee_id,

                                'produit_id' =>
                                    $ligne->produit_id,

                                'type_mouvement' =>
                                    'SORTIE',

                                'statut' =>
                                    'VALIDE',

                                'quantite' =>
                                    1,

                                'raison' =>
                                    'Perte en transit - Approvisionnement ' .
                                    $appro->reference,

                                'metadata' => [
                                    'imei_id' =>
                                        $imei->id,

                                    'approvisionnement_id' =>
                                        $appro->id,

                                    'type_perte' =>
                                        $quantiteCasse > 0
                                            ? 'CASSE'
                                            : 'PERDU',
                                ],
                            ]);
                        }
                    }

                    /*
                     * ----------------------------------------------------
                     * SAUVEGARDE DES IMEI DANS LA LIGNE
                     * ----------------------------------------------------
                     */
                    $ligne->metadata = array_merge(
                        $metadata,
                        [
                            'imeis_recus' =>
                                $imeisRecus
                                    ->pluck('id')
                                    ->toArray(),

                            'imeis_perdus' =>
                                $imeisPerdus
                                    ->pluck('id')
                                    ->toArray(),

                            'mouvement_entree_id' =>
                                $mouvementEntree->mouvement_id,

                            'lot_destination_id' =>
                                $lotDestination->id,
                        ]
                    );
                }

                /*
                 * --------------------------------------------------------
                 * Aucun IMEI disponible
                 *
                 * On garde quand même la réception stock.
                 * --------------------------------------------------------
                 */
                else {

                    $ligne->metadata = array_merge(
                        $metadata,
                        [
                            'imeis_recus' => [],
                            'imeis_perdus' => [],
                            'mouvement_entree_id' =>
                                $mouvementEntree->mouvement_id,
                            'lot_destination_id' =>
                                $lotDestination->id,
                            'imei_absent_a_la_reception' => true,
                        ]
                    );
                }

            } else {

                /*
                 * ========================================================
                 * PRODUIT SANS IMEI
                 * ========================================================
                 */
                $ligne->metadata = array_merge(
                    $ligne->metadata ?? [],
                    [
                        'mouvement_entree_id' =>
                            $mouvementEntree->mouvement_id,

                        'lot_destination_id' =>
                            $lotDestination->id,
                    ]
                );
            }

            /*
             * ============================================================
             * FINALISATION DE LA LIGNE
             * ============================================================
             */
            $ligne->quantite_recue = $quantiteRecue;

            $ligne->save();
        }

        /*
         * ================================================================
         * FINALISATION APPROVISIONNEMENT
         * ================================================================
         */
        $appro->statut_operation = 'RECU';
        $appro->date_reception = now();
        $appro->save();

        DB::commit();

        return [
            'success' => true,

            'approvisionnement' =>
                $appro->fresh(),

            'message' =>
                'Réception terminée avec succès',
        ];

    } catch (\Throwable $e) {

        DB::rollBack();

        Log::error(
            'Erreur réception approvisionnement',
            [
                'appro_id' =>
                    $appro->id,

                'user_id' =>
                    $utilisateurId,

                'error' =>
                    $e->getMessage(),

                'trace' =>
                    $e->getTraceAsString(),
            ]
        );

        throw $e;
    }
}

    // ================================================================
    // ANNULER UN APPROVISIONNEMENT (AVEC LIBÉRATION IMEI)
    // ================================================================

    public function annuler(Approvisionnement $appro, string $motif): void
    {
        DB::beginTransaction();

        try {
            if ($appro->statut_operation === 'PREPARE' || $appro->statut_operation === 'EN_ATTENTE_OPERATION') {
                $this->imeiService->libererReservationTransfert($appro->id);
            }

            $appro->statut_operation = 'REJETE';
            $appro->motif = $motif;
            $appro->save();

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur annulation approvisionnement', [
                'appro_id' => $appro->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    // ================================================================
    // ARRIVÉE EN POINT INTERMÉDIAIRE (AVEC IMEI)
    // ================================================================

    public function receptionnerIntermediaire(
    Approvisionnement $appro,
    int $utilisateurId
    ): void {
        DB::beginTransaction();
    
        try {
    
            foreach ($appro->lignes as $ligne) {
    
                $produit = $ligne->produit;
    
                /*
                 * ============================================================
                 * STOCK INTERMÉDIAIRE
                 * ============================================================
                 */
                $stockIntermediaire = StockSuccursale::where(
                        'succursale_id',
                        $appro->intermediaire_succursale_id
                    )
                    ->where(
                        'produit_id',
                        $ligne->produit_id
                    )
                    ->lockForUpdate()
                    ->first();
    
                if (!$stockIntermediaire) {
    
                    $stockIntermediaire = StockSuccursale::create([
                        'succursale_id'       => $appro->intermediaire_succursale_id,
                        'produit_id'          => $ligne->produit_id,
                        'is_depot'            => true,
                        'quantite_disponible' => 0,
                        'quantite_reserve'    => 0,
                    ]);
                }
    
                /*
                 * ============================================================
                 * QUANTITÉ À RÉCEPTIONNER
                 * ============================================================
                 */
                $quantite = (int) $ligne->quantite_envoyee;
    
                if ($quantite <= 0) {
                    continue;
                }
    
                /*
                 * ============================================================
                 * RÉCEPTION STOCK
                 *
                 * Cette méthode :
                 * - augmente le stock
                 * - crée le mouvement ENTREE
                 * - crée le lot FIFO
                 * ============================================================
                 */
                $mouvement = $stockIntermediaire->receptionnerIntermediaire(
                    $quantite,
                    [
                        'demande_parent_id' => $appro->id,
    
                        'reference_document' => $appro->reference,
    
                        'raison' =>
                            'Arrivée intermédiaire - ' .
                            $appro->reference,
    
                        'responsable_id' => $utilisateurId,
    
                        /*
                         * IMPORTANT :
                         * idéalement récupérer le coût du lot source.
                         */
                        'prix_achat' =>
                            $this->getPrixAchatLotSource($ligne),
    
                        'metadata' => [
                            'source' =>
                                'APPROVISIONNEMENT_INTERMEDIAIRE',
    
                            'type' =>
                                'ARRIVEE_INTERMEDIAIRE',
    
                            'approvisionnement_id' =>
                                $appro->id,
    
                            'ligne_appro_id' =>
                                $ligne->id,
                        ],
                    ]
                );
    
                if (!$mouvement) {
                    throw new \Exception(
                        "Le mouvement d'entrée intermédiaire n'a pas été créé pour {$produit->nom}."
                    );
                }
    
                /*
                 * ============================================================
                 * RÉCUPÉRATION DU LOT CRÉÉ
                 * ============================================================
                 */
                $lot = LotStock::where(
                        'mouvement_entree_id',
                        $mouvement->mouvement_id
                    )
                    ->where(
                        'produit_id',
                        $ligne->produit_id
                    )
                    ->lockForUpdate()
                    ->first();
    
                if (!$lot) {
                    throw new \Exception(
                        "Le lot FIFO intermédiaire n'a pas été créé pour {$produit->nom}. " .
                        "Mouvement ID : {$mouvement->mouvement_id}"
                    );
                }
    
                /*
                 * ============================================================
                 * IMEI
                 *
                 * L'absence d'IMEI NE BLOQUE PAS la réception.
                 * ============================================================
                 */
                $metadata = $ligne->metadata ?? [];
    
                $imeiIds = $metadata['imeis_expedies'] ?? [];
    
                if (!is_array($imeiIds)) {
                    $imeiIds = [];
                }
    
                $imeiIds = array_values(
                    array_unique(
                        array_filter(
                            array_map('intval', $imeiIds)
                        )
                    )
                );
    
                $imeisRecus = collect();
    
                if (
                    $produit->gere_imei &&
                    !empty($imeiIds)
                ) {
    
                    $imeisRecus = ProduitImei::whereIn(
                            'id',
                            $imeiIds
                        )
                        ->where(
                            'produit_id',
                            $ligne->produit_id
                        )
                        ->where(
                            'statut',
                            ProduitImei::STATUT_TRANSFERE
                        )
                        ->lockForUpdate()
                        ->get()
                        ->take($quantite);
    
                    /*
                     * ========================================================
                     * RATTACHEMENT DES IMEI AU LOT INTERMÉDIAIRE
                     * ========================================================
                     */
                    foreach ($imeisRecus as $imei) {
    
                        $imei->statut =
                            ProduitImei::STATUT_EN_STOCK;
    
                        $imei->succursale_id =
                            $appro->intermediaire_succursale_id;
    
                        $imei->lot_stock_id =
                            $lot->id;
    
                        $imei->mouvement_entree_id =
                            $mouvement->mouvement_id;
    
                        $imei->save();
                    }
                }
    
                /*
                 * ============================================================
                 * MÉTADONNÉES
                 * ============================================================
                 */
                $ligne->metadata = array_merge(
                    $metadata,
                    [
                        'imeis_intermediaire' =>
                            $imeisRecus->pluck('id')->values()->toArray(),
    
                        'mouvement_intermediaire_id' =>
                            $mouvement->mouvement_id,
    
                        'lot_intermediaire_id' =>
                            $lot->id,
    
                        'imei_absent_a_l_arrivee_intermediaire' =>
                            $produit->gere_imei &&
                            $imeisRecus->count() < $quantite,
                    ]
                );
    
                $ligne->save();
            }
    
            /*
             * ================================================================
             * FINALISATION
             * ================================================================
             */
            $appro->statut_operation =
                'ARRIVE_INTERMEDIAIRE';
    
            $appro->date_arrivee_intermediaire =
                now();
    
            $appro->save();
    
            DB::commit();
    
        } catch (\Throwable $e) {
    
            DB::rollBack();
    
            Log::error(
                'Erreur arrivée intermédiaire',
                [
                    'appro_id' => $appro->id,
                    'user_id' => $utilisateurId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );
    
            throw $e;
        }
    }

    // ================================================================
    // DÉPART DU POINT INTERMÉDIAIRE (AVEC IMEI)
    // ================================================================

    public function expedierIntermediaire(
    Approvisionnement $appro,
    int $utilisateurId
    ): void {
        DB::beginTransaction();
    
        try {
    
            foreach ($appro->lignes as $ligne) {
    
                $produit = $ligne->produit;
    
                /*
                 * ============================================================
                 * STOCK INTERMÉDIAIRE
                 * ============================================================
                 */
                $stockIntermediaire = StockSuccursale::where(
                        'succursale_id',
                        $appro->intermediaire_succursale_id
                    )
                    ->where(
                        'produit_id',
                        $ligne->produit_id
                    )
                    ->lockForUpdate()
                    ->first();
    
                if (!$stockIntermediaire) {
                    throw new \Exception(
                        "Stock intermédiaire introuvable pour {$produit->nom}"
                    );
                }
    
                $quantite = (int) $ligne->quantite_envoyee;
    
                if ($quantite <= 0) {
                    continue;
                }
    
                /*
                 * ============================================================
                 * IMEI
                 *
                 * On récupère uniquement les IMEI actuellement EN_STOCK.
                 * ============================================================
                 */
                $metadata = $ligne->metadata ?? [];
    
                $imeiIds = $metadata['imeis_intermediaire'] ?? [];
    
                if (!is_array($imeiIds)) {
                    $imeiIds = [];
                }
    
                $imeiIds = array_values(
                    array_unique(
                        array_filter(
                            array_map('intval', $imeiIds)
                        )
                    )
                );
    
                $imeis = collect();
    
                if (
                    $produit->gere_imei &&
                    !empty($imeiIds)
                ) {
    
                    $imeis = ProduitImei::whereIn(
                            'id',
                            $imeiIds
                        )
                        ->where(
                            'produit_id',
                            $ligne->produit_id
                        )
                        ->where(
                            'succursale_id',
                            $appro->intermediaire_succursale_id
                        )
                        ->where(
                            'statut',
                            ProduitImei::STATUT_EN_STOCK
                        )
                        ->lockForUpdate()
                        ->get()
                        ->take($quantite);
                }
    
                /*
                 * ============================================================
                 * SORTIE STOCK
                 *
                 * expedier() doit appliquer le FIFO.
                 * ============================================================
                 */
                $mouvement = $stockIntermediaire->expedierIntermediaire(
                    $quantite,
                    [
                        'demande_parent_id' =>
                            $appro->id,
    
                        'reference_document' =>
                            $appro->reference,
    
                        'responsable_id' =>
                            $utilisateurId,
    
                        'metadata' => [
                            'source' =>
                                'APPROVISIONNEMENT_INTERMEDIAIRE',
    
                            'type' =>
                                'DEPART_INTERMEDIAIRE',
    
                            'approvisionnement_id' =>
                                $appro->id,
    
                            'ligne_appro_id' =>
                                $ligne->id,
                        ],
                    ]
                );
    
                if (!$mouvement) {
                    throw new \Exception(
                        "Le mouvement de sortie intermédiaire n'a pas été créé pour {$produit->nom}."
                    );
                }
    
                /*
                 * ============================================================
                 * TRANSFERT DES IMEI
                 * ============================================================
                 */
                foreach ($imeis as $imei) {
    
                    $imei->statut =
                        ProduitImei::STATUT_TRANSFERE;
    
                    $imei->mouvement_sortie_id =
                        $mouvement->mouvement_id;
    
                    $imei->save();
                }
    
                /*
                 * ============================================================
                 * MÉTADONNÉES
                 * ============================================================
                 */
                $ligne->metadata = array_merge(
                    $metadata,
                    [
                        'imeis_transferes_intermediaire' =>
                            $imeis->pluck('id')->values()->toArray(),
    
                        'mouvement_intermediaire_sortie_id' =>
                            $mouvement->mouvement_id,
    
                        'imei_absent_au_depart_intermediaire' =>
                            $produit->gere_imei &&
                            $imeis->count() < $quantite,
                    ]
                );
    
                $ligne->save();
            }
    
            /*
             * ================================================================
             * FINALISATION
             * ================================================================
             */
            $appro->statut_operation =
                'DEPART_INTERMEDIAIRE';
    
            $appro->date_depart_intermediaire =
                now();
    
            $appro->save();
    
            DB::commit();
    
        } catch (\Throwable $e) {
    
            DB::rollBack();
    
            Log::error(
                'Erreur départ intermédiaire',
                [
                    'appro_id' => $appro->id,
                    'user_id' => $utilisateurId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );
    
            throw $e;
        }
    }

    // ================================================================
    // UTILITAIRES
    // ================================================================

    private function getPrixAchatLotSource(LigneApprovisionnement $ligne): float
    {
        $mouvementSortieId = $ligne->metadata['mouvement_sortie_id'] ?? null;

        if ($mouvementSortieId) {
            $mouvement = MouvementStock::find($mouvementSortieId);
            if ($mouvement && $mouvement->cout_unitaire > 0) {
                return $mouvement->cout_unitaire;
            }
        }

        return $ligne->produit->prix_achat ?? 0;
    }
}

