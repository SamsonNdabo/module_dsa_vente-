<?php
// app/Services/Stock/ImeiService.php

namespace App\Services;

use App\Models\ProduitImei;
use App\Models\StockSuccursale;
use App\Models\LotStock;
use App\Models\MouvementStock;
use App\Models\Approvisionnement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ImeiService
{
    protected StockService $stockService;

    public function __construct(StockService $stockService)
    {
        $this->stockService = $stockService;
    }

    // ================================================================
    // RÉCEPTION FOURNISSEUR
    // ================================================================

    /**
     * Créer des IMEI après réception d'un lot
     */
    public function creerImeisPourLot(
        LotStock $lot,
        StockSuccursale $stock,
        array $numerosImei,
        int $mouvementEntreeId
    ): array {
        // 1. Normaliser et valider
        $imeisNormalises = [];
        foreach ($numerosImei as $imei) {
            $imei = trim((string) $imei);
            if (!preg_match('/^\d{15,17}$/', $imei)) {
                throw new \Exception("L'IMEI '$imei' est invalide.");
            }
            $imeisNormalises[] = $imei;
        }

        // 2. Vérifier doublons dans le payload
        if (count($imeisNormalises) !== count(array_unique($imeisNormalises))) {
            throw new \Exception("Les IMEI ne peuvent pas être dupliqués.");
        }

        // 3. Vérifier l'unicité en base
        $existants = ProduitImei::whereIn('numero_imei', $imeisNormalises)
            ->pluck('numero_imei')
            ->toArray();

        if (!empty($existants)) {
            throw new \Exception("IMEI existants : " . implode(', ', $existants));
        }

        // 4. Créer les IMEI
        $created = [];
        foreach ($imeisNormalises as $numeroImei) {
            $imei = ProduitImei::create([
                'produit_id' => $lot->produit_id,
                'numero_imei' => $numeroImei,
                'succursale_id' => $stock->succursale_id,
                'lot_stock_id' => $lot->id,
                'mouvement_entree_id' => $mouvementEntreeId,
                'statut' => ProduitImei::STATUT_EN_STOCK,
                'date_entree' => now(),
                'prix_achat' => $lot->cout_unitaire ?? $lot->prix_achat_ttc_unitaire ?? 0,
            ]);
            $created[] = $imei;
        }

        Log::info('IMEI créés pour lot', [
            'lot_id' => $lot->id,
            'quantite' => count($created),
            'produit_id' => $lot->produit_id,
        ]);

        return $created;
    }

    // ================================================================
    // POS - VENTE EN MAGASIN
    // ================================================================

    /**
     * Réserver des IMEI pour une facture POS
     */
    public function reserverImeisPourFacture(
        int $produitId,
        int $succursaleId,
        int $quantite,
        int $factureId,
        ?array $imeiIds = null
    ): array {
        if ($imeiIds !== null) {
            $imeis = ProduitImei::whereIn('id', $imeiIds)
                ->where('produit_id', $produitId)
                ->where('succursale_id', $succursaleId)
                ->where('statut', ProduitImei::STATUT_EN_STOCK)
                ->lockForUpdate()
                ->get();

            if ($imeis->count() !== count($imeiIds)) {
                throw new \Exception("Certains IMEI ne sont plus disponibles.");
            }
        } else {
            $imeis = ProduitImei::where('produit_id', $produitId)
                ->where('succursale_id', $succursaleId)
                ->where('statut', ProduitImei::STATUT_EN_STOCK)
                ->with('lotStock')
                ->orderBy('lot_stock_id')
                ->orderBy('created_at')
                ->lockForUpdate()
                ->limit($quantite)
                ->get();

            if ($imeis->count() < $quantite) {
                throw new \Exception("IMEI insuffisants.");
            }
        }

        $reservedIds = [];
        foreach ($imeis as $imei) {
            $imei->marquerReserve($factureId);
            $reservedIds[] = $imei->id;
        }

        return $reservedIds;
    }

    /**
     * Vérifier après sortie FIFO que les IMEI correspondent aux lots consommés
     */
        public function verifierImeisApresSortie(
        array $imeiIds,
        MouvementStock $mouvementSortie
    ): void {
        if (empty($imeiIds)) {
            return;
        }
    
        /*
         * ============================================================
         * 1. RÉCUPÉRER LES IMEI
         * ============================================================
         */
    
        $imeis = ProduitImei::whereIn('id', $imeiIds)
            ->lockForUpdate()
            ->get();
    
        if ($imeis->count() !== count($imeiIds)) {
    
            $trouves = $imeis->pluck('id')->map(fn ($id) => (int) $id);
    
            $manquants = array_diff(
                array_map('intval', $imeiIds),
                $trouves->toArray()
            );
    
            throw new \Exception(
                "IMEI introuvables : " .
                implode(', ', $manquants)
            );
        }
    
        /*
         * ============================================================
         * 2. RÉCUPÉRER TOUS LES LOTS CONSOMMÉS PAR CE MOUVEMENT
         * ============================================================
         *
         * IMPORTANT :
         * une vente peut consommer plusieurs lots.
         */
    
        $lotsConsommes = $mouvementSortie
            ->lotsSortie()
            ->get();
    
        if ($lotsConsommes->isEmpty()) {
    
            throw new \Exception(
                "Le mouvement #{$mouvementSortie->mouvement_id} " .
                "ne possède aucun lot consommé."
            );
        }
    
        $lotsConsommesIds = $lotsConsommes
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    
        /*
         * ============================================================
         * 3. LOG DE CONTRÔLE
         * ============================================================
         */
    
        Log::info(
            'Vérification IMEI après sortie',
            [
                'mouvement_id' =>
                    $mouvementSortie->mouvement_id,
    
                'lots_consommes' =>
                    $lotsConsommes->map(function ($lot) {
                        return [
                            'lot_id' => $lot->id,
                            'quantite' => (int) ($lot->pivot->quantite ?? 0),
                        ];
                    })->values()->toArray(),
    
                'lots_consommes_ids' =>
                    $lotsConsommesIds,
    
                'imei_ids' =>
                    $imeiIds,
            ]
        );
    
        /*
         * ============================================================
         * 4. VALIDATION DE CHAQUE IMEI
         * ============================================================
         */
    
        foreach ($imeis as $imei) {
    
            /*
             * Produit
             */
            if ((int) $imei->produit_id !==
                (int) $mouvementSortie->produit_id) {
    
                throw new \Exception(
                    "IMEI {$imei->numero_imei} " .
                    "n'appartient pas au produit de la sortie."
                );
            }
    
            /*
             * Lot
             */
            $lotImei = (int) $imei->lot_stock_id;
    
            if (!in_array(
                $lotImei,
                $lotsConsommesIds,
                true
            )) {
    
                throw new \Exception(
                    "IMEI {$imei->numero_imei} " .
                    "(lot #{$lotImei}) " .
                    "n'appartient à aucun des lots consommés."
                );
            }
        }
    }


          /**
     * Marquer les IMEI comme vendus
     */
    public function marquerImeisVendus(
        array $imeiIds,
        MouvementStock $mouvementSortie,
        int $factureId,
        float $prixVente = null // ✅ AJOUT : Prix de vente unitaire
    ): array {
        $imeis = ProduitImei::whereIn('id', $imeiIds)
            ->where('statut', ProduitImei::STATUT_RESERVE)
            ->where('facture_id', $factureId)
            ->lockForUpdate()
            ->get();
    
        if ($imeis->count() !== count($imeiIds)) {
            $trouves = $imeis->pluck('id')->toArray();
            $manquants = array_values(array_diff($imeiIds, $trouves));
            throw new \Exception(
                "Impossible de marquer les IMEI comme vendus. " .
                "IMEI non trouvés ou non réservés pour cette facture : " .
                implode(', ', $manquants)
            );
        }
    
        foreach ($imeis as $imei) {
            // ✅ Appel de la méthode marquerVendu() du modèle avec le prix de vente
            $imei->marquerVendu($mouvementSortie, $factureId, $prixVente);
        }
    
        return $imeis->toArray();
    }

    /**
     * Libérer des IMEI réservés (annulation)
     */
    public function libererReservationImeis(int $factureId): void
    {
        ProduitImei::where('facture_id', $factureId)
            ->where('statut', ProduitImei::STATUT_RESERVE)
            ->each(function ($imei) {
                $imei->libererReservation();
            });
    }

    /**
     * Retourner un IMEI vendu (remboursement)
     */
    public function retournerImeiVendu(
        int $imeiId,
        int $succursaleId,
        string $motif
    ): array {
        $imei = ProduitImei::where('id', $imeiId)
            ->where('statut', ProduitImei::STATUT_VENDU)
            ->lockForUpdate()
            ->first();

        if (!$imei) {
            throw new \Exception("IMEI non vendu ou introuvable.");
        }

        // 1. Marquer comme retourné
        $imei->marquerRetourne($motif);

        // 2. Récupérer le stock
        $stock = StockSuccursale::where('succursale_id', $succursaleId)
            ->where('produit_id', $imei->produit_id)
            ->lockForUpdate()
            ->first();

        if (!$stock) {
            throw new \Exception("Stock introuvable.");
        }

        // 3. Réceptionner (1 unité)
        $prixRetour = $imei->prix_achat ?? 0;
        $mouvementEntree = $this->stockService->receptionner($stock, 1, [
            'type_mouvement' => 'RETOUR',
            'raison' => 'Retour IMEI ' . $imei->numero_imei . ' - ' . $motif,
            'reference_document' => 'RET-' . $imei->numero_imei,
            'prix_achat' => $prixRetour,
            'metadata' => [
                'source' => 'RETOUR_IMEI',
                'imei_id' => $imei->id,
                'motif' => $motif,
            ],
        ]);

        // 4. Mettre à jour l'IMEI
        $nouveauLot = $mouvementEntree->lotEntree;
        $imei->remettreEnStock($nouveauLot->id, $mouvementEntree);

        return [
            'imei' => $imei,
            'mouvement' => $mouvementEntree,
        ];
    }

    // ================================================================
    // APPROVISIONNEMENT / TRANSFERT
    // ================================================================

    /**
     * Réserver des IMEI pour un transfert
     */
      public function reserverImeisPourTransfert(
    int $produitId,
    int $succursaleId,
    int $quantite,
    int $approvisionnementId,
    ?array $imeiIds = null
): array {

    /*
     * ================================================================
     * 1. VALIDATIONS
     * ================================================================
     */

    if ($quantite <= 0) {
        throw new \InvalidArgumentException(
            'La quantité à transférer doit être supérieure à zéro.'
        );
    }

    /*
     * ================================================================
     * 2. RÉCUPÉRER LA RÉPARTITION FIFO PRÉPARÉE
     * ================================================================
     *
     * preparer() a déjà enregistré :
     *
     * metadata['lots_fifo']
     *
     * Exemple :
     *
     * [
     *     [
     *         'lot_stock_id' => 10,
     *         'quantite' => 2
     *     ],
     *     [
     *         'lot_stock_id' => 15,
     *         'quantite' => 3
     *     ],
     *     [
     *         'lot_stock_id' => 21,
     *         'quantite' => 2
     *     ]
     * ]
     *
     * ================================================================
     */

    $appro = Approvisionnement::with('lignes')
        ->lockForUpdate()
        ->findOrFail($approvisionnementId);

    /*
     * Chercher la ligne correspondant au produit.
     *
     * Dans une demande normale, un produit apparaît une seule fois.
     */

    $ligne = $appro->lignes
        ->firstWhere('produit_id', $produitId);

    if (!$ligne) {
        throw new \Exception(
            "Ligne d'approvisionnement introuvable pour le produit ID {$produitId}."
        );
    }

    $metadataLigne = $ligne->metadata ?? [];

    if (is_string($metadataLigne)) {
        $metadataLigne = json_decode(
            $metadataLigne,
            true
        ) ?? [];
    }

    if (!is_array($metadataLigne)) {
        $metadataLigne = [];
    }

    $lotsFifo = $metadataLigne['lots_fifo'] ?? [];

    if (!is_array($lotsFifo) || empty($lotsFifo)) {
        throw new \Exception(
            "Aucune répartition FIFO n'a été préparée pour " .
            "le produit ID {$produitId}."
        );
    }

    /*
     * ================================================================
     * 3. NORMALISER LES LOTS FIFO
     * ================================================================
     */

    $lotsFifo = collect($lotsFifo)
        ->filter(function ($lot) {
            return isset($lot['lot_stock_id'])
                && isset($lot['quantite'])
                && (int) $lot['quantite'] > 0;
        })
        ->map(function ($lot) {
            return [
                'lot_stock_id' => (int) $lot['lot_stock_id'],
                'quantite' => (int) $lot['quantite'],
            ];
        })
        ->values()
        ->all();

    if (empty($lotsFifo)) {
        throw new \Exception(
            "La répartition FIFO préparée est vide pour le produit ID {$produitId}."
        );
    }

    /*
     * Vérifier que la somme des lots correspond à la quantité.
     */

    $quantiteFifo = collect($lotsFifo)->sum(
        fn ($lot) => (int) $lot['quantite']
    );

    if ($quantiteFifo !== $quantite) {
        throw new \Exception(
            "La répartition FIFO est incohérente pour le produit ID {$produitId}. " .
            "Quantité attendue : {$quantite}, " .
            "quantité répartie : {$quantiteFifo}."
        );
    }

    /*
     * Map :
     *
     * lot_id => quantité maximale consommée
     */

    $quantitesParLot = collect($lotsFifo)
        ->keyBy('lot_stock_id')
        ->map(fn ($lot) => (int) $lot['quantite']);

    $lotIdsFifo = $quantitesParLot
        ->keys()
        ->map(fn ($id) => (int) $id)
        ->values()
        ->all();

    /*
     * ================================================================
     * 4. NORMALISER LA SÉLECTION
     * ================================================================
     */

    $selection = collect($imeiIds ?? [])
        ->map(fn ($value) => trim((string) $value))
        ->filter(fn ($value) => $value !== '')
        ->values()
        ->all();

    /*
     * Aucun IMEI sélectionné :
     *
     * ce n'est PAS une erreur.
     *
     * Le stock physique reste la source de vérité.
     */

    if (empty($selection)) {

        /*
         * ============================================================
         * SÉLECTION AUTOMATIQUE PARTIELLE
         * ============================================================
         *
         * On ne prend que les IMEI disponibles dans les lots
         * effectivement consommés.
         *
         * Et surtout :
         *
         * lot 10 → maximum 2 IMEI
         * lot 15 → maximum 3 IMEI
         * lot 21 → maximum 2 IMEI
         *
         * ============================================================
         */

        $imeisDisponibles = ProduitImei::where(
            'produit_id',
            $produitId
        )
        ->where(
            'succursale_id',
            $succursaleId
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
        ->orderBy('id')
        ->lockForUpdate()
        ->get();

        $imeis = collect();

        foreach ($lotsFifo as $lotFifo) {

            $lotId =
                (int) $lotFifo['lot_stock_id'];

            $capacite =
                (int) $lotFifo['quantite'];

            $imeisDuLot = $imeisDisponibles
                ->where('lot_stock_id', $lotId)
                ->take($capacite);

            foreach ($imeisDuLot as $imei) {
                $imeis->push($imei);
            }
        }

    } else {

        /*
         * ============================================================
         * SÉLECTION MANUELLE
         * ============================================================
         */

        /*
         * Nombre maximum d'IMEI :
         *
         * <= quantité transférée
         *
         * mais pas obligatoirement égal.
         */

        if (count($selection) > $quantite) {
            throw new \Exception(
                "Vous avez sélectionné " .
                count($selection) .
                " IMEI alors que seulement " .
                $quantite .
                " unité(s) seront transférées."
            );
        }

        /*
         * Doublons.
         */

        if (
            count($selection) !==
            count(array_unique($selection))
        ) {
            throw new \Exception(
                "Un même IMEI ne peut pas être sélectionné plusieurs fois."
            );
        }

        /*
         * ============================================================
         * RÉCUPÉRER LES IMEI
         * ============================================================
         */

        $imeis = ProduitImei::where(
            'produit_id',
            $produitId
        )
        ->where(
            'succursale_id',
            $succursaleId
        )
        ->where(
            'statut',
            ProduitImei::STATUT_EN_STOCK
        )
        ->whereIn(
            'lot_stock_id',
            $lotIdsFifo
        )
        ->where(function ($query) use ($selection) {

            $query
                ->whereIn('id', $selection)
                ->orWhereIn('numero_imei', $selection);

        })
        ->lockForUpdate()
        ->get();

        /*
         * ============================================================
         * VÉRIFIER QUE TOUS LES IMEI ONT ÉTÉ TROUVÉS
         * ============================================================
         */

        if ($imeis->count() !== count($selection)) {

            $valeursTrouvees = [];

            foreach ($imeis as $imei) {

                $valeursTrouvees[] =
                    (string) $imei->id;

                $valeursTrouvees[] =
                    (string) $imei->numero_imei;
            }

            $manquants = array_values(
                array_diff(
                    $selection,
                    $valeursTrouvees
                )
            );

            throw new \Exception(
                "Les IMEI suivants ne sont plus disponibles, " .
                "n'appartiennent pas à la succursale source, " .
                "ou appartiennent à un lot qui ne sera pas consommé : " .
                implode(', ', $manquants)
            );
        }

        /*
         * ============================================================
         * VÉRIFIER L'UNICITÉ RÉELLE
         * ============================================================
         */

        if (
            $imeis->pluck('id')->unique()->count()
            !== count($selection)
        ) {
            throw new \Exception(
                "Certains IMEI sélectionnés correspondent à la même unité."
            );
        }

        /*
         * ============================================================
         * VÉRIFIER LA CAPACITÉ DE CHAQUE LOT
         * ============================================================
         */

        $imeisParLot = $imeis
            ->groupBy('lot_stock_id')
            ->map(fn ($collection) => $collection->count());

        foreach ($imeisParLot as $lotStockId => $nombreImeis) {

            $capaciteLot =
                $quantitesParLot[(int) $lotStockId] ?? 0;

            if ($nombreImeis > $capaciteLot) {

                throw new \Exception(
                    "Le lot #{$lotStockId} ne peut fournir que " .
                    "{$capaciteLot} unité(s) selon le FIFO, " .
                    "mais {$nombreImeis} IMEI ont été sélectionnés."
                );
            }
        }
    }

    /*
     * ================================================================
     * 5. RÉSERVER LES IMEI
     * ================================================================
     *
     * IMPORTANT :
     *
     * Le nombre d'IMEI peut être :
     *
     * 0
     * 1
     * 2
     * ...
     * quantite
     *
     * ================================================================
     */

    $reservedIds = [];

    foreach ($imeis as $imei) {

        /*
         * Double sécurité.
         */

        if (
            $imei->statut !==
            ProduitImei::STATUT_EN_STOCK
        ) {
            throw new \Exception(
                "L'IMEI {$imei->numero_imei} n'est plus disponible."
            );
        }

        /*
         * Vérifier une dernière fois son lot.
         */

        $capaciteLot =
            $quantitesParLot[
                (int) $imei->lot_stock_id
            ] ?? 0;

        if ($capaciteLot <= 0) {
            throw new \Exception(
                "L'IMEI {$imei->numero_imei} appartient à un lot " .
                "qui ne sera pas consommé par cette expédition."
            );
        }

        /*
         * Réservation.
         */

        $imei->statut =
            ProduitImei::STATUT_RESERVE;

        $metadataImei =
            $imei->metadata ?? [];

        if (is_string($metadataImei)) {

            $metadataImei =
                json_decode(
                    $metadataImei,
                    true
                ) ?? [];
        }

        if (!is_array($metadataImei)) {
            $metadataImei = [];
        }

        $metadataImei = array_merge(
            $metadataImei,
            [
                'transfert_reserve' => true,

                'approvisionnement_id' =>
                    $approvisionnementId,

                'lot_fifo_id' =>
                    (int) $imei->lot_stock_id,

                'date_reservation_transfert' =>
                    now()->toDateTimeString(),
            ]
        );

        $imei->metadata =
            $metadataImei;

        $imei->save();

        $reservedIds[] =
            $imei->id;
    }

    /*
     * ================================================================
     * 6. RETOUR
     * ================================================================
     *
     * Peut parfaitement retourner moins que $quantite.
     * Exemple :
     *
     * quantité = 7
     * IMEI = 3
     *
     * => [12, 18, 24]
     *
     * ================================================================
     */

    return $reservedIds;
}

    /**
     * Marquer les IMEI comme transférés (expédition)
     */
   public function marquerImeisTransferes(
    array $imeiIds,
    MouvementStock $mouvementSortie
): array {
    /*
     * ================================================================
     * 1. VALIDATION
     * ================================================================
     */

    if (empty($imeiIds)) {
        return [];
    }

    if (!$mouvementSortie->mouvement_id) {
        throw new \Exception(
            "Le mouvement de sortie est invalide."
        );
    }

    /*
     * Nettoyer les IDs
     */
    $imeiIds = collect($imeiIds)
        ->map(fn ($id) => (int) $id)
        ->filter(fn ($id) => $id > 0)
        ->unique()
        ->values()
        ->all();

    if (empty($imeiIds)) {
        return [];
    }

    /*
     * ================================================================
     * 2. RÉCUPÉRER LES IMEI RÉSERVÉS
     * ================================================================
     *
     * On accepte uniquement les IMEI :
     *
     *   RESERVE
     *
     * et qui ont été explicitement réservés pour un transfert.
     *
     * lockForUpdate() empêche un autre traitement de modifier
     * simultanément les mêmes IMEI.
     */

    $imeis = ProduitImei::whereIn('id', $imeiIds)
        ->where('statut', ProduitImei::STATUT_RESERVE)
        ->whereJsonContains(
            'metadata->transfert_reserve',
            true
        )
        ->lockForUpdate()
        ->get();

    /*
     * ================================================================
     * 3. VÉRIFICATION
     * ================================================================
     */

    if ($imeis->count() !== count($imeiIds)) {

        $trouves = $imeis
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $manquants = array_values(
            array_diff(
                $imeiIds,
                $trouves
            )
        );

        throw new \Exception(
            "Certains IMEI ne sont pas réservés pour ce transfert. " .
            "IMEI IDs concernés : " .
            implode(', ', $manquants)
        );
    }

    /*
     * ================================================================
     * 4. TRANSFERT DÉFINITIF
     * ================================================================
     */

    foreach ($imeis as $imei) {

        /*
         * IMPORTANT :
         *
         * marquerTransfere() doit accepter RESERVE.
         */

        $imei->marquerTransfere(
            $mouvementSortie
        );
    }

    /*
     * ================================================================
     * 5. RETOUR
     * ================================================================
     */

    return $imeis
        ->fresh()
        ->toArray();
}

    /**
     * Réceptionner des IMEI transférés (destination)
     */
    public function recevoirImeisTransferes(
        array $imeiIds,
        int $succursaleDestinationId,
        int $lotDestinationId,
        MouvementStock $mouvementEntree
    ): array {
        $imeis = ProduitImei::whereIn('id', $imeiIds)
            ->where('statut', ProduitImei::STATUT_TRANSFERE)
            ->lockForUpdate()
            ->get();

        if ($imeis->count() !== count($imeiIds)) {
            throw new \Exception("Certains IMEI ne sont pas en transit.");
        }

        foreach ($imeis as $imei) {
            $imei->recevoirTransfert($succursaleDestinationId, $lotDestinationId, $mouvementEntree);
        }

        return $imeis->toArray();
    }

    /**
     * Libérer des IMEI réservés pour un transfert (annulation)
     */
    public function libererReservationTransfert(int $approvisionnementId): void
    {
        ProduitImei::where('statut', ProduitImei::STATUT_RESERVE)
            ->whereJsonContains('metadata->transfert_reserve', true)
            ->whereJsonContains('metadata->approvisionnement_id', $approvisionnementId)
            ->each(function ($imei) {
                $imei->libererReservation();
                $imei->metadata = array_merge($imei->metadata ?? [], [
                    'transfert_reserve' => false,
                    'approvisionnement_id' => null,
                ]);
                $imei->save();
            });
    }

    // ================================================================
    // COMMANDE EN LIGNE
    // ================================================================

    /**
     * Réserver des IMEI pour une commande en ligne
     */
    public function reserverImeisPourCommande(
        int $produitId,
        int $succursaleId,
        int $quantite,
        int $commandeId,
        ?array $imeiIds = null
    ): array {
        if ($imeiIds !== null) {
            $imeis = ProduitImei::whereIn('id', $imeiIds)
                ->where('produit_id', $produitId)
                ->where('succursale_id', $succursaleId)
                ->where('statut', ProduitImei::STATUT_EN_STOCK)
                ->lockForUpdate()
                ->get();

            if ($imeis->count() !== count($imeiIds)) {
                throw new \Exception("Certains IMEI ne sont plus disponibles.");
            }
        } else {
            $imeis = ProduitImei::where('produit_id', $produitId)
                ->where('succursale_id', $succursaleId)
                ->where('statut', ProduitImei::STATUT_EN_STOCK)
                ->with('lotStock')
                ->orderBy('lot_stock_id')
                ->orderBy('created_at')
                ->lockForUpdate()
                ->limit($quantite)
                ->get();

            if ($imeis->count() < $quantite) {
                throw new \Exception("IMEI insuffisants pour la commande.");
            }
        }

        $reservedIds = [];
        foreach ($imeis as $imei) {
            $imei->statut = ProduitImei::STATUT_RESERVE;
            $imei->commande_id = $commandeId;
            $imei->save();
            $reservedIds[] = $imei->id;
        }

        return $reservedIds;
    }

    /**
     * Marquer les IMEI comme expédiés (commande en ligne)
     */
    public function marquerImeisExpedies(
        array $imeiIds,
        MouvementStock $mouvementSortie,
        int $commandeId
    ): array {
        $imeis = ProduitImei::whereIn('id', $imeiIds)
            ->where('statut', ProduitImei::STATUT_RESERVE)
            ->where('commande_id', $commandeId)
            ->lockForUpdate()
            ->get();

        if ($imeis->count() !== count($imeiIds)) {
            throw new \Exception("Certains IMEI ne sont pas réservés pour cette commande.");
        }

        foreach ($imeis as $imei) {
            $imei->statut = ProduitImei::STATUT_EXPEDIE;
            $imei->mouvement_sortie_id = $mouvementSortie->mouvement_id;
            $imei->date_sortie = now();
            $imei->save();
        }

        return $imeis->toArray();
    }

    /**
     * Marquer les IMEI comme livrés (fin de commande)
     */
    public function marquerImeisLivres(
        array $imeiIds,
        int $commandeId
    ): array {
        $imeis = ProduitImei::whereIn('id', $imeiIds)
            ->where('statut', ProduitImei::STATUT_EXPEDIE)
            ->where('commande_id', $commandeId)
            ->lockForUpdate()
            ->get();

        if ($imeis->count() !== count($imeiIds)) {
            throw new \Exception("Certains IMEI ne sont pas en cours de livraison.");
        }

        foreach ($imeis as $imei) {
            $imei->statut = ProduitImei::STATUT_VENDU;
            $imei->date_vente = now();
            $imei->save();
        }

        return $imeis->toArray();
    }

    // ================================================================
    // UTILITAIRES
    // ================================================================

    /**
     * Audit de cohérence
     */
    public function verifierCohérenceStockImei(int $succursaleId, int $produitId): array
    {
        $stock = StockSuccursale::where('succursale_id', $succursaleId)
            ->where('produit_id', $produitId)
            ->first();

        if (!$stock) {
            return ['coherent' => false, 'message' => 'Stock introuvable'];
        }

        $totalLots = LotStock::where('stock_succursale_id', $stock->id)
            ->where('statut', 'ACTIF')
            ->sum('quantite_restante');

        $totalImeis = ProduitImei::where('succursale_id', $succursaleId)
            ->where('produit_id', $produitId)
            ->where('statut', ProduitImei::STATUT_EN_STOCK)
            ->count();

        $estCoherent = ($stock->quantite_disponible == $totalLots) && ($totalLots == $totalImeis);

        return [
            'coherent' => $estCoherent,
            'quantite_stock' => $stock->quantite_disponible,
            'quantite_lots' => $totalLots,
            'quantite_imeis' => $totalImeis,
            'ecart' => $stock->quantite_disponible - $totalLots,
        ];
    }

    /**
     * Récupérer les IMEI disponibles par lot
     */
    public function getImeisDisponiblesParLot(int $produitId, int $succursaleId): array
    {
        return ProduitImei::where('produit_id', $produitId)
            ->where('succursale_id', $succursaleId)
            ->where('statut', ProduitImei::STATUT_EN_STOCK)
            ->with('lotStock')
            ->orderBy('lot_stock_id')
            ->orderBy('created_at')
            ->get()
            ->groupBy('lot_stock_id')
            ->map(function ($group) {
                return [
                    'lot_id' => $group->first()->lot_stock_id,
                    'lot' => $group->first()->lotStock,
                    'imeis' => $group->map(function ($imei) {
                        return [
                            'id' => $imei->id,
                            'numero_imei' => $imei->numero_imei,
                            'prix_achat' => $imei->prix_achat,
                        ];
                    })->values()->toArray(),
                    'quantite' => $group->count(),
                ];
            })->values()->toArray();
    }
}