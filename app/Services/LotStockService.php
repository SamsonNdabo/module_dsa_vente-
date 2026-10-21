<?php

namespace App\Services;

use App\Models\LotStock;
use App\Models\Produit;
use App\Models\StockSuccursale;
use App\Models\MouvementStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LotStockService
{
    /**
     * Créer un lot à partir d'un mouvement d'entrée.
     * La transaction est gérée par l'appelant.
     */
    public function creerLotDepuisMouvement(MouvementStock $mouvement): ?LotStock
    {
        // ============================================================
        // 1. VÉRIFIER LE TYPE DE MOUVEMENT
        // ============================================================
    
        if (!in_array(
            $mouvement->type_mouvement,
            ['ENTREE', 'RETOUR', 'AJUSTEMENT']
        )) {
            return null;
        }
    
        // ============================================================
        // 2. POUR UN AJUSTEMENT, LE LOT EST CRÉÉ UNIQUEMENT
        //    POUR UNE AUGMENTATION DE STOCK
        // ============================================================
    
        if ($mouvement->type_mouvement === 'AJUSTEMENT') {
    
            $metadata = $mouvement->metadata;
    
            if (is_string($metadata)) {
                $metadata = json_decode($metadata, true) ?? [];
            }
    
            if (!is_array($metadata)) {
                $metadata = [];
            }
    
            if (
                ($metadata['type_ajustement'] ?? null)
                !== 'AUGMENTATION'
            ) {
                return null;
            }
        }
    
        // ============================================================
        // 3. VÉRIFIER SI LE LOT EXISTE DÉJÀ
        // ============================================================
    
        if ($mouvement->lotEntree) {
            return $mouvement->lotEntree;
        }
    
        // ============================================================
        // 4. RÉCUPÉRER STOCK / PRODUIT
        // ============================================================
    
        $stock = $mouvement->stockDestination;
        $produit = $mouvement->produit;
    
        if (!$stock || !$produit) {
    
            Log::warning(
                'Création lot impossible : stock ou produit introuvable',
                [
                    'mouvement_id' =>
                        $mouvement->mouvement_id,
                ]
            );
    
            return null;
        }
    
        // ============================================================
        // 5. TVA
        // ============================================================
    
        $tauxTVA = $produit->tva ?? 16;
    
        // ============================================================
        // 6. CRÉATION DU LOT
        // ============================================================
    
        $coutUnitaire =
            (float) (
                $mouvement->cout_unitaire
                ?? $produit->prix_achat
                ?? 0
            );
    
        $lot = LotStock::create([
    
            'produit_id' =>
                $produit->id,
    
            'stock_succursale_id' =>
                $stock->id,
    
            'mouvement_entree_id' =>
                $mouvement->mouvement_id,
    
            'numero_lot' =>
                data_get(
                    $mouvement->metadata,
                    'numero_lot_fournisseur'
                ),
    
            'date_fabrication' =>
                $mouvement->date_lot ?? null,
    
            'date_expiration' =>
                $mouvement->date_expiration_lot ?? null,
    
            'quantite_initiale' =>
                $mouvement->quantite,
    
            'quantite_restante' =>
                $mouvement->quantite,
    
            'cout_unitaire' =>
                $coutUnitaire,
    
            'cout_unitaire_ttc' =>
                $coutUnitaire *
                (1 + ($tauxTVA / 100)),
    
            'statut' =>
                $this->determinerStatutInitial(
                    $mouvement
                ),
        ]);
    
        Log::info(
            'Lot créé',
            [
                'lot_id' =>
                    $lot->id,
    
                'mouvement_id' =>
                    $mouvement->mouvement_id,
    
                'type_mouvement' =>
                    $mouvement->type_mouvement,
    
                'produit_id' =>
                    $produit->id,
    
                'quantite' =>
                    $lot->quantite_initiale,
            ]
        );
    
        return $lot;
    }

    /**
     * Trouver les lots disponibles dans l'ordre de sortie.
     * PAS de transaction ici - laissée à l'appelant.
     */
        public function trouverLotsPourSortie(
        StockSuccursale $stock,
        int $quantite,
        array $options = []
    ): array {
    
        $autoriserExpires = $options['autoriser_expires'] ?? false;
    
        $produit = $stock->produit;
    
    
        $query = LotStock::where('stock_succursale_id', $stock->id)
            ->where('statut', 'ACTIF')
            ->where('quantite_restante', '>', 0);
    
    
        /**
         * Exclure les lots expirés
         */
        if (!$autoriserExpires) {
    
            $query->where(function($q){
    
                $q->whereNull('date_expiration')
                  ->orWhere('date_expiration','>',now());
    
            });
    
        }
    
    
        /**
         * STRATEGIE SORTIE
         *
         * FEFO :
         * First Expired First Out
         *
         * FIFO :
         * First In First Out
         */
    
    
        if($produit?->est_perissable){
    
            // FEFO
            $query->orderByRaw("
                CASE 
                    WHEN date_expiration IS NULL 
                    THEN 1 
                    ELSE 0 
                END
            ")
            ->orderBy('date_expiration','asc')
            ->orderBy('created_at','asc');
    
    
        }else{
    
            // FIFO
            $query->orderBy('created_at','asc');
    
        }
    
    
    
        $lots = $query
            ->lockForUpdate()
            ->get();
    
    
    
        $disponible = $lots->sum('quantite_restante');
    
    
        if($disponible < $quantite){
    
            throw new \Exception(
                "Stock insuffisant. Disponible {$disponible}, demandé {$quantite}"
            );
    
        }
    
    
        return $lots->all();
    }

    /**
     * Consommer des lots pour une sortie.
     * PAS de transaction ici - laissée à l'appelant.
     * Le mouvement de sortie doit déjà être créé.
     */

    public function consommerLots(
    StockSuccursale $stock,
    int $quantite,
    MouvementStock $mouvementSortie,
    array $options = []
    ): array {
    
        /*
         * ================================================================
         * 1. VALIDATION
         * ================================================================
         */
    
        if ($quantite <= 0) {
            throw new \InvalidArgumentException(
                'La quantité à consommer doit être supérieure à zéro.'
            );
        }
    
        /*
         * ================================================================
         * 2. DÉTERMINER LES LOTS À CONSOMMER
         *
         * Si lots_fifo est fourni :
         *
         *     → on respecte EXACTEMENT cette répartition.
         *
         * Sinon :
         *
         *     → comportement FIFO / FEFO normal.
         * ================================================================
         */
    
        $lotsFifo = $options['lots_fifo'] ?? null;
    
        /*
         * ================================================================
         * CAS A : RÉPARTITION FIFO PRÉPARÉE
         * ================================================================
         */
    
        if ($lotsFifo !== null) {
    
            if (!is_array($lotsFifo) || empty($lotsFifo)) {
                throw new \Exception(
                    "La répartition des lots FIFO fournie est invalide."
                );
            }
    
            /*
             * ------------------------------------------------------------
             * Nettoyer la répartition
             * ------------------------------------------------------------
             */
    
            $lotsFifo = collect($lotsFifo)
                ->filter(function ($item) {
    
                    return is_array($item)
                        && isset($item['lot_stock_id'])
                        && isset($item['quantite'])
                        && (int) $item['lot_stock_id'] > 0
                        && (int) $item['quantite'] > 0;
                })
                ->map(function ($item) {
    
                    return [
                        'lot_stock_id' =>
                            (int) $item['lot_stock_id'],
    
                        'quantite' =>
                            (int) $item['quantite'],
                    ];
                })
                ->values()
                ->all();
    
            if (empty($lotsFifo)) {
                throw new \Exception(
                    "Aucun lot valide n'a été fourni dans la répartition FIFO."
                );
            }
    
            /*
             * ------------------------------------------------------------
             * Vérifier la quantité totale
             * ------------------------------------------------------------
             */
    
            $quantiteFifo = collect($lotsFifo)->sum(
                fn ($lot) => (int) $lot['quantite']
            );
    
            if ($quantiteFifo !== $quantite) {
    
                throw new \Exception(
                    "La répartition FIFO est incohérente pour le produit ID " .
                    "{$stock->produit_id}. " .
                    "Quantité demandée : {$quantite}, " .
                    "quantité répartie dans les lots : {$quantiteFifo}."
                );
            }
    
            /*
             * ------------------------------------------------------------
             * Vérifier les doublons
             * ------------------------------------------------------------
             */
    
            $lotIds = collect($lotsFifo)
                ->pluck('lot_stock_id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
    
            if (
                count($lotIds) !==
                count(array_unique($lotIds))
            ) {
                throw new \Exception(
                    "Un même lot apparaît plusieurs fois dans la " .
                    "répartition FIFO."
                );
            }
    
            /*
             * ------------------------------------------------------------
             * Récupérer exactement les lots demandés
             *
             * IMPORTANT :
             *
             * lots_stock ne possède PAS de succursale_id.
             *
             * La succursale source est déjà contrôlée au niveau de
             * StockSuccursale dans expedier().
             *
             * On vérifie donc ici :
             *
             *     - ID du lot
             *     - produit
             *
             * puis on verrouille les lots.
             * ------------------------------------------------------------
             */
    
            $lotsModel = LotStock::whereIn(
                'id',
                $lotIds
            )
                ->where(
                    'produit_id',
                    $stock->produit_id
                )
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
    
            /*
             * ------------------------------------------------------------
             * Vérifier que TOUS les lots demandés existent
             * ------------------------------------------------------------
             */
    
            foreach ($lotIds as $lotId) {
    
                if (!$lotsModel->has($lotId)) {
    
                    throw new \Exception(
                        "Le lot #{$lotId} n'existe pas ou " .
                        "n'appartient pas au produit ID " .
                        "{$stock->produit_id}."
                    );
                }
            }
    
            /*
             * ------------------------------------------------------------
             * Construire la liste des lots dans EXACTEMENT l'ordre
             * préparé par preparer().
             * ------------------------------------------------------------
             */
    
            $lots = [];
    
            foreach ($lotsFifo as $lotFifo) {
    
                $lotId =
                    (int) $lotFifo['lot_stock_id'];
    
                $lot =
                    $lotsModel->get($lotId);
    
                $quantiteDemandeeLot =
                    (int) $lotFifo['quantite'];
    
                /*
                 * Vérifier le stock réellement restant dans le lot.
                 */
    
                if (
                    (int) $lot->quantite_restante <
                    $quantiteDemandeeLot
                ) {
    
                    throw new \Exception(
                        "Stock insuffisant dans le lot #{$lot->id} " .
                        "({$lot->numero_lot}). " .
                        "Demandé : {$quantiteDemandeeLot}, " .
                        "restant : {$lot->quantite_restante}."
                    );
                }
    
                /*
                 * Vérifier que le lot est réellement disponible.
                 */
    
                if (
                    isset($lot->statut) &&
                    $lot->statut === 'EPUISE'
                ) {
    
                    throw new \Exception(
                        "Le lot #{$lot->id} ({$lot->numero_lot}) " .
                        "est déjà épuisé."
                    );
                }
    
                $lots[] = [
    
                    'model' =>
                        $lot,
    
                    'quantite' =>
                        $quantiteDemandeeLot,
                ];
            }
    
        } else {
    
            /*
             * ============================================================
             * CAS B : AUCUNE RÉPARTITION PRÉPARÉE
             *
             * On conserve le comportement FIFO / FEFO existant.
             * ============================================================
             */
    
            $lotsTrouves =
                $this->trouverLotsPourSortie(
                    $stock,
                    $quantite,
                    $options
                );
    
            /*
             * Normaliser le résultat.
             */
    
            if (
                $lotsTrouves instanceof
                \Illuminate\Support\Collection
            ) {
    
                $lotsTrouves =
                    $lotsTrouves->all();
    
            } elseif (!is_array($lotsTrouves)) {
    
                throw new \Exception(
                    'Format invalide retourné par ' .
                    'trouverLotsPourSortie().'
                );
            }
    
            if (empty($lotsTrouves)) {
    
                throw new \Exception(
                    "Aucun lot disponible pour le produit ID " .
                    "{$stock->produit_id}."
                );
            }
    
            /*
             * ------------------------------------------------------------
             * Vérifier la quantité disponible dans les lots
             * ------------------------------------------------------------
             */
    
            $quantiteDisponibleLots = 0;
    
            foreach ($lotsTrouves as $lot) {
    
                $quantiteDisponibleLots += max(
                    0,
                    (int) $lot->quantite_restante
                );
            }
    
            if ($quantiteDisponibleLots < $quantite) {
    
                throw new \Exception(
                    "Stock par lots insuffisant pour le produit ID " .
                    "{$stock->produit_id}. " .
                    "Demandé : {$quantite}, " .
                    "Disponible dans les lots : " .
                    "{$quantiteDisponibleLots}."
                );
            }
    
            /*
             * ------------------------------------------------------------
             * Déterminer la quantité dans chaque lot
             * ------------------------------------------------------------
             */
    
            $lots = [];
    
            $reste =
                $quantite;
    
            foreach ($lotsTrouves as $lot) {
    
                if ($reste <= 0) {
                    break;
                }
    
                $disponible =
                    (int) $lot->quantite_restante;
    
                if ($disponible <= 0) {
                    continue;
                }
    
                $qte =
                    min(
                        $disponible,
                        $reste
                    );
    
                if ($qte <= 0) {
                    continue;
                }
    
                $lots[] = [
    
                    'model' =>
                        $lot,
    
                    'quantite' =>
                        $qte,
                ];
    
                $reste -= $qte;
            }
    
            if ($reste > 0) {
    
                throw new \Exception(
                    "Impossible de déterminer les lots nécessaires " .
                    "pour consommer {$quantite} unité(s)."
                );
            }
        }
    
        /*
         * ================================================================
         * 3. VARIABLES DE CONSOMMATION
         * ================================================================
         */
    
        $reste =
            $quantite;
    
        $totalValeurHT =
            0;
    
        $totalValeurTTC =
            0;
    
        $lotsConsommes =
            [];
    
        /*
         * ================================================================
         * 4. CONSOMMER LES LOTS
         * ================================================================
         */
    
        foreach ($lots as $lotData) {
    
            if ($reste <= 0) {
                break;
            }
    
            /** @var LotStock $lot */
            $lot =
                $lotData['model'];
    
            $qte =
                (int) $lotData['quantite'];
    
            /*
             * Sécurité.
             */
    
            if ($qte <= 0) {
                continue;
            }
    
            /*
             * Vérification finale avant modification.
             */
    
            if (
                (int) $lot->quantite_restante <
                $qte
            ) {
    
                throw new \Exception(
                    "Le lot #{$lot->id} ne possède plus assez " .
                    "de stock. " .
                    "Demandé : {$qte}, " .
                    "restant : {$lot->quantite_restante}."
                );
            }
    
            /*
             * ============================================================
             * 5. COÛTS HISTORIQUES DU LOT
             * ============================================================
             *
             * On privilégie les champs présents dans ton système.
             */
    
            $coutUnitaireHT =
                (float) (
                    $lot->cout_unitaire
                    ?? $lot->prix_achat_ht_unitaire
                    ?? 0
                );
    
            $coutUnitaireTTC =
                (float) (
                    $lot->cout_unitaire_ttc
                    ?? $lot->prix_achat_ttc_unitaire
                    ?? 0
                );
    
            /*
             * Si le HT n'est pas disponible, on ne bloque pas
             * inutilement le déstockage si ton système ne stocke que
             * le TTC dans le lot.
             *
             * Dans ce cas, le HT reste à 0.
             */
    
            if ($coutUnitaireTTC <= 0) {
    
                throw new \Exception(
                    "Prix d'achat TTC invalide pour le lot #{$lot->id}."
                );
            }
    
            /*
             * ============================================================
             * 6. CALCUL DES VALEURS
             * ============================================================
             */
    
            $valeurHT =
                round(
                    $qte * $coutUnitaireHT,
                    2
                );
    
            $valeurTTC =
                round(
                    $qte * $coutUnitaireTTC,
                    2
                );
    
            /*
             * ============================================================
             * 7. DIMINUER LE LOT
             * ============================================================
             */
    
            $lot->quantite_restante -=
                $qte;
    
            if ($lot->quantite_restante <= 0) {
    
                $lot->quantite_restante =
                    0;
    
                $lot->statut =
                    'EPUISE';
            }
    
            $lot->save();
    
            /*
             * ============================================================
             * 8. LIAISON LOT / MOUVEMENT
             * ============================================================
             */
    
            $mouvementSortie->lotsSortie()->attach(
                $lot->id,
                [
                    'quantite' =>
                        $qte,
    
                    'cout_unitaire' =>
                        $coutUnitaireHT,
                ]
            );
    
            /*
             * ============================================================
             * 9. HISTORIQUE
             * ============================================================
             */
    
            $lotsConsommes[] = [
    
                'lot_id' =>
                    $lot->id,
    
                'numero_lot' =>
                    $lot->numero_lot,
    
                'quantite' =>
                    $qte,
    
                'cout_unitaire' =>
                    $coutUnitaireHT,
    
                'cout_unitaire_ttc' =>
                    $coutUnitaireTTC,
    
                'valeur_ht' =>
                    $valeurHT,
    
                'valeur_ttc' =>
                    $valeurTTC,
    
                'date_expiration' =>
                    $lot->date_expiration,
            ];
    
            /*
             * ============================================================
             * 10. TOTAUX
             * ============================================================
             */
    
            $totalValeurHT +=
                $valeurHT;
    
            $totalValeurTTC +=
                $valeurTTC;
    
            $reste -=
                $qte;
        }
    
        /*
         * ================================================================
         * 11. VÉRIFICATION FINALE
         * ================================================================
         */
    
        if ($reste > 0) {
    
            throw new \Exception(
                "Impossible de consommer entièrement la quantité demandée " .
                "pour le produit ID {$stock->produit_id}. " .
                "Quantité restante : {$reste}."
            );
        }
    
        /*
         * ================================================================
         * 12. COÛT MOYEN HT
         * ================================================================
         */
    
        $coutUnitaireMoyenHT =
            round(
                $totalValeurHT / $quantite,
                2
            );
    
        /*
         * ================================================================
         * 13. RÉCUPÉRER LES METADATA DU MOUVEMENT
         * ================================================================
         */
    
        $metadata =
            $mouvementSortie->metadata;
    
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
    
        /*
         * ================================================================
         * 14. ENREGISTRER LES LOTS CONSOMMÉS
         * ================================================================
         */
    
        $metadata =
            array_merge(
                $metadata,
                [
    
                    'lots_consommes' =>
                        $lotsConsommes,
    
                    'methode_sortie' =>
                        $stock->produit?->est_perissable
                            ? 'FEFO'
                            : 'FIFO',
    
                    'quantite_lots' =>
                        $quantite,
    
                    'cout_total_ht' =>
                        round(
                            $totalValeurHT,
                            2
                        ),
    
                    'cout_total_ttc' =>
                        round(
                            $totalValeurTTC,
                            2
                        ),
                ]
            );
    
        /*
         * Si une répartition FIFO avait été préparée,
         * on la conserve également.
         */
    
        if ($lotsFifo !== null) {
    
            $metadata['lots_fifo'] =
                $lotsFifo;
        }
    
        /*
         * ================================================================
         * 15. MISE À JOUR DU MOUVEMENT
         * ================================================================
         *
         * cout_total / cout_unitaire restent HT.
         *
         * Le TTC reste disponible dans metadata.
         * ================================================================
         */
    
        $mouvementSortie->cout_total =
            round(
                $totalValeurHT,
                2
            );
    
        $mouvementSortie->cout_unitaire =
            $coutUnitaireMoyenHT;
    
        $mouvementSortie->metadata =
            $metadata;
    
        $mouvementSortie->save();
    
        /*
         * ================================================================
         * 16. LOG
         * ================================================================
         */
    
        Log::info(
            'Lots consommés avec succès',
            [
    
                'mouvement_id' =>
                    $mouvementSortie->mouvement_id,
    
                'stock_id' =>
                    $stock->id,
    
                'produit_id' =>
                    $stock->produit_id,
    
                'succursale_id' =>
                    $stock->succursale_id,
    
                'quantite' =>
                    $quantite,
    
                'methode' =>
                    $stock->produit?->est_perissable
                        ? 'FEFO'
                        : 'FIFO',
    
                'multi_lots' =>
                    count($lotsConsommes) > 1,
    
                'lots_fifo_fourni' =>
                    $lotsFifo !== null,
    
                'cout_total_ht' =>
                    round(
                        $totalValeurHT,
                        2
                    ),
    
                'cout_total_ttc' =>
                    round(
                        $totalValeurTTC,
                        2
                    ),
    
                'lots' =>
                    $lotsConsommes,
            ]
        );
    
        /*
         * ================================================================
         * 17. RETOUR
         * ================================================================
         */
    
        return [
    
            'lots' =>
                $lotsConsommes,
    
            'cout_total' =>
                round(
                    $totalValeurHT,
                    2
                ),
    
            'cout_total_ht' =>
                round(
                    $totalValeurHT,
                    2
                ),
    
            'cout_total_ttc' =>
                round(
                    $totalValeurTTC,
                    2
                ),
    
            'cout_unitaire' =>
                $coutUnitaireMoyenHT,
    
            'quantite' =>
                $quantite,
    
            'mouvement_id' =>
                $mouvementSortie->mouvement_id,
        ];
    }



    /**
     * Déterminer le statut initial d'un lot.
     */
    private function determinerStatutInitial(MouvementStock $mouvement): string
    {
        $dateExpiration = $mouvement->date_expiration_lot;

        if ($dateExpiration && $dateExpiration->isPast()) {
            return 'EXPIRE';
        }

        return 'ACTIF';
    }

    /**
     * Vérifier la cohérence entre stock et lots (sans modifier).
     */
    public function verifierCohérence(StockSuccursale $stock): array
    {
        $totalLots = LotStock::where(
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
    
        $estCoherent =
            $stock->quantite_disponible == $totalLots;
    
        return [
            'coherent' =>
                $estCoherent,
    
            'quantite_stock' =>
                $stock->quantite_disponible,
    
            'quantite_lots' =>
                $totalLots,
    
            'ecart' =>
                $stock->quantite_disponible - $totalLots,
        ];
    }
}