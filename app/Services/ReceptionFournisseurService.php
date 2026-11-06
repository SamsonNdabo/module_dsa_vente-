<?php

namespace App\Services;

use App\Models\CommandeFournisseur;
use App\Models\ReceptionFournisseur;
use App\Models\LigneReceptionFournisseur;
use App\Models\Paiement;
use App\Models\StockSuccursale;
use App\Services\StockService;
use App\Services\EcritureService;
use App\Services\ImeiService; 
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReceptionFournisseurService
{
    protected StockService $stockService;
    protected EcritureService $ecritureService;
    protected ImeiService $imeiService; 

    public function __construct(
        StockService $stockService,
        EcritureService $ecritureService,
        ImeiService $imeiService 
    ) {
        $this->stockService = $stockService;
        $this->ecritureService = $ecritureService;
        $this->imeiService = $imeiService; 
    }

    /**
     * Créer une réception à partir d'une commande
     */
    public function creer(array $data, CommandeFournisseur $commande, int $userId): ReceptionFournisseur
    {
        DB::beginTransaction();

        try {
            // 1. Vérifications préalables
            $this->validateCommandeReceptionnable($commande);

            // 2. Validation des lignes
            $lignesValides = $this->validerLignesReception($commande, $data['lignes']);

            // 3. Création de l'entête de réception
            $reception = $this->creerEntete($data, $commande, $userId);

            // 4. Création des lignes (calculs HT/TVA/TTC)
            $totaux = $this->creerLignes($reception, $lignesValides, $commande);

            // 5. Mise à jour des totaux de la réception
            $reception->update([
                'total_valeur_ht' => $totaux['total_ht'],
                'total_valeur_tva' => $totaux['total_tva'],
                'total_valeur_ttc' => $totaux['total_ttc'],
            ]);
            $reception->refresh();

            // 6. Vérification du montant total par rapport à la commande
            $this->verifierMontantCommande($reception, $commande);

            // 7. Enregistrement du paiement si fourni
            if (!empty($data['paiement'])) {
                $this->creerPaiement($reception, $commande, $data['paiement'], $userId);
            }

            DB::commit();

            return $reception->load(['lignes', 'commande', 'paiements']);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur création réception', [
                'commande_id' => $commande->id,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Valider une réception (intégration stock + écritures)
     */
    public function valider(ReceptionFournisseur $reception, int $userId): array
    {
        if ($reception->statut !== 'EN_ATTENTE') {
            throw new \Exception('Seules les réceptions en attente peuvent être validées.');
        }

        // Recharger avec relations
        $reception->load([
            'lignes.produit',
            'lignes.commandeLigne',
            'commande',
        ]);

        DB::beginTransaction();

        try {
            // 1. Vérification du montant total
            $this->verifierMontantCommande($reception, $reception->commande);

            // 2. Traitement des lignes (mise à jour du stock)
            $result = $this->traiterLignesReception($reception, $userId);

            // 3. Création des écritures comptables
            $ecritureResult = $this->creerEcrituresComptables($reception);

            // 4. Mise à jour du statut de la réception
            $reception->statut = 'VALIDE';
            $reception->valide_par_id = $userId;
            $reception->date_validation = now();
            $reception->save();

            // 5. Mise à jour du paiement
            if ($reception->paiements()->exists()) {
                $reception->paiements()->update(['statut' => 'VALIDE']);
            }

            // 6. Mise à jour du statut de la commande
            $this->mettreAJourCommande($reception->commande);

            DB::commit();

            return [
                'success' => true,
                'reception' => $reception->fresh(['lignes', 'commande', 'paiements']),
                'stock' => $result,
                'ecritures' => $ecritureResult,
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur validation réception', [
                'reception_id' => $reception->id,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Annuler une réception (si non validée)
     */
    public function annuler(ReceptionFournisseur $reception, string $motif, int $userId): ReceptionFournisseur
    {
        if (!in_array($reception->statut, ['EN_ATTENTE', 'BROUILLON'])) {
            throw new \Exception('Cette réception ne peut pas être annulée (statut: ' . $reception->statut . ')');
        }

        DB::beginTransaction();

        try {
            $reception->annuler($userId, $motif);

            // Mise à jour du statut de la commande
            if ($reception->commande) {
                $this->mettreAJourCommande($reception->commande);
            }

            DB::commit();

            return $reception->fresh();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur annulation réception', [
                'reception_id' => $reception->id,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    // ============================================================
    // MÉTHODES PRIVÉES
    // ============================================================

    private function validateCommandeReceptionnable(CommandeFournisseur $commande): void
    {
        if (!in_array($commande->statut, ['VALIDEE', 'PARTIELLEMENT_RECUE'])) {
            throw new \Exception('La commande n\'est pas réceptionnable.');
        }
    }

    private function validerLignesReception(CommandeFournisseur $commande, array $lignes): array
    {
        $commandeLigneIds = array_column($lignes, 'ligne_commande_id');
        $commandeLignes = $commande->lignes()
            ->whereIn('id', $commandeLigneIds)
            ->get()
            ->keyBy('id');

        $processed = [];
        $validated = [];

        foreach ($lignes as $index => $ligneData) {
            $ligneId = $ligneData['ligne_commande_id'];

            if (!$commandeLignes->has($ligneId)) {
                throw new \Exception("Ligne #" . ($index + 1) . " n'appartient pas à cette commande.");
            }

            if (in_array($ligneId, $processed)) {
                throw new \Exception("Ligne #" . ($index + 1) . " est en double.");
            }
            $processed[] = $ligneId;

            $ligneCommande = $commandeLignes[$ligneId];
            $quantiteRecue = (int)$ligneData['quantite_recue'];
            $quantiteCasse = (int)($ligneData['quantite_casse'] ?? 0);
            $quantitePerdue = (int)($ligneData['quantite_perdue'] ?? 0);
            $quantiteNonConforme = (int)($ligneData['quantite_non_conforme'] ?? 0);

            if ($quantiteRecue < 0 || $quantiteCasse < 0 || $quantitePerdue < 0 || $quantiteNonConforme < 0) {
                throw new \Exception("Quantités négatives interdites (ligne #" . ($index + 1) . ").");
            }

            $dejaRecu = $ligneCommande->quantite_recue ?? 0;
            $reste = $ligneCommande->quantite - $dejaRecu;
            if ($quantiteRecue > $reste) {
                throw new \Exception(
                    "Quantité reçue ({$quantiteRecue}) > reste à recevoir ({$reste}) pour la ligne #" . ($index + 1) . "."
                );
            }

            $totalEcarts = $quantiteCasse + $quantitePerdue + $quantiteNonConforme;
            if ($totalEcarts > $quantiteRecue) {
                throw new \Exception(
                    "Écarts ({$totalEcarts}) > quantité reçue ({$quantiteRecue}) pour la ligne #" . ($index + 1) . "."
                );
            }

            $validated[] = $ligneData;
        }

        return $validated;
    }

    private function creerEntete(array $data, CommandeFournisseur $commande, int $userId): ReceptionFournisseur
    {
        $reference = 'REC-' . now()->format('YmdHis') . '-' . strtoupper(substr(uniqid(), -6));

        return ReceptionFournisseur::create([
            'reference' => $reference,
            'commande_id' => $commande->id,
            'fournisseur_id' => $commande->fournisseur_id,
            'depot_destination_id' => $commande->depot_destination_id,
            'date_reception' => now(),
            'num_bon_livraison' => $data['num_bon_livraison'] ?? null,
            'num_facture_fournisseur' => $data['num_facture_fournisseur'] ?? null,
            'date_facture' => $data['date_facture'] ?? null,
            'transporteur' => $data['transporteur'] ?? null,
            'nom_livreur' => $data['nom_livreur'] ?? null,
            'vehicule_immatriculation' => $data['vehicule_immatriculation'] ?? null,
            'statut' => 'EN_ATTENTE',
            'total_valeur_ht' => 0,
            'total_valeur_tva' => 0,
            'total_valeur_ttc' => 0,
            'notes' => $data['notes'] ?? null,
            'cree_par_id' => $userId,
            'metadata' => [
                'created_from' => 'commande',
                'commande_reference' => $commande->reference,
                'user_agent' => request()->userAgent(),
            ],
        ]);
    }

        private function creerLignes(ReceptionFournisseur $reception, array $lignes, CommandeFournisseur $commande): array
    {
        $commandeLigneIds = array_column($lignes, 'ligne_commande_id');
        $commandeLignes = $commande->lignes()
            ->whereIn('id', $commandeLigneIds)
            ->get()
            ->keyBy('id');
    
        $insert = [];
        $totalHT = 0;
        $totalTVA = 0;
        $totalTTC = 0;
    
        foreach ($lignes as $index => $ligneData) {
            $ligneCommande = $commandeLignes[$ligneData['ligne_commande_id']];
    
            $prixUnitaireHT = (float) $ligneCommande->prix_unitaire_ht;
            $tauxTVA = (float) ($ligneCommande->taux_tva ?? 16);
            $remise = (float) ($ligneCommande->remise_ligne ?? 0);
            $quantiteRecue = (int) $ligneData['quantite_recue'];
    
            $montantBrutHT = $quantiteRecue * $prixUnitaireHT;
            $montantRemise = $montantBrutHT * ($remise / 100);
            $montantHT = round($montantBrutHT - $montantRemise, 2);
            $montantTVA = round($montantHT * ($tauxTVA / 100), 2);
            $montantTTC = round($montantHT + $montantTVA, 2);
    
            $totalHT += $montantHT;
            $totalTVA += $montantTVA;
            $totalTTC += $montantTTC;
    
            //  Récupération des IMEI envoyés par le frontend
            $imeis = $ligneData['imeis'] ?? [];
            if (!is_array($imeis)) {
                $imeis = [];
            }
    
            //  Nettoyage des IMEI (supprimer les vides)
            $imeis = array_values(array_filter($imeis, function($imei) {
                return is_string($imei) && trim($imei) !== '';
            }));
    
            //  Construction des métadata
            $metadata = [
                'imeis' => $imeis,
            ];
    
            $insert[] = [
                'reception_id' => $reception->id,
                'commande_ligne_id' => $ligneCommande->id,
                'produit_id' => $ligneCommande->produit_id,
                'quantite_attendue' => $ligneCommande->quantite,
                'quantite_recue' => $quantiteRecue,
                'quantite_casse' => $ligneData['quantite_casse'] ?? 0,
                'quantite_perdue' => $ligneData['quantite_perdue'] ?? 0,
                'quantite_non_conforme' => $ligneData['quantite_non_conforme'] ?? 0,
                'prix_unitaire_ht' => $prixUnitaireHT,
                'taux_tva' => $tauxTVA,
                'remise' => $remise,
                'valeur_ht' => $montantHT,
                'valeur_tva' => $montantTVA,
                'valeur_ttc' => $montantTTC,
                'created_at' => now(),
                'updated_at' => now(),
                'metadata' => json_encode($metadata), //  Stockage des IMEI
            ];
        }
    
        if (!empty($insert)) {
            LigneReceptionFournisseur::insert($insert);
        }
    
        return [
            'total_ht' => round($totalHT, 2),
            'total_tva' => round($totalTVA, 2),
            'total_ttc' => round($totalTTC, 2),
            'nb_lignes' => count($insert),
        ];
    }

    private function verifierMontantCommande(ReceptionFournisseur $reception, CommandeFournisseur $commande): void
    {
        $totalAncien = ReceptionFournisseur::where('commande_id', $commande->id)
            ->whereIn('statut', ['EN_ATTENTE', 'VALIDE'])
            ->where('id', '!=', $reception->id)
            ->sum('total_valeur_ttc');

        $totalApres = $totalAncien + $reception->total_valeur_ttc;

        if (round($totalApres, 2) > round($commande->montant_total_ttc, 2)) {
            throw new \Exception(
                "Le montant des réceptions dépasse la commande. " .
                "Total réceptions: {$totalApres}, Commande: {$commande->montant_total_ttc}."
            );
        }
    }

    private function creerPaiement(ReceptionFournisseur $reception, CommandeFournisseur $commande, array $paiementData, int $userId): Paiement
    {
        if (Paiement::where('reception_id', $reception->id)->exists()) {
            throw new \Exception('Un paiement existe déjà pour cette réception.');
        }

        $montant = round($reception->total_valeur_ttc, 2);
        if ($montant <= 0) {
            throw new \Exception('Montant de paiement nul.');
        }

        $nouveauPaye = ($commande->montant_paye ?? 0) + $montant;
        if (round($nouveauPaye, 2) > round($commande->montant_total_ttc, 2)) {
            throw new \Exception('Ce paiement dépasse le montant total de la commande.');
        }

        $paiement = Paiement::create([
            'operation_libelle' => 'PAIEMENT_RECEPTION_FOURNISSEUR',
            'reference' => 'PAY-REC-' . now()->format('YmdHis') . '-' . strtoupper(substr(uniqid(), -5)),
            'commande_fournisseur_id' => $commande->id,
            'reception_id' => $reception->id,
            'utilisateur_id' => $userId,
            'mode_paiement' => $paiementData['mode_paiement'],
            'montant' => $montant,
            'statut' => 'EN_ATTENTE',
            'date_paiement' => now(),
            'reference_externe' => $paiementData['reference_externe'] ?? null,
            'details_paiement' => $paiementData['details_paiement'] ?? null,
            'notes' => $paiementData['notes'] ?? null,
        ]);

        // Mise à jour de la commande
        $commande->montant_paye = $nouveauPaye;
        $commande->montant_restant = max(0, $commande->montant_total_ttc - $nouveauPaye);
        if (method_exists($commande, 'updateStatutPaiement')) {
            $commande->updateStatutPaiement();
        }
        $commande->save();

        return $paiement;
    }

    private function traiterLignesReception(ReceptionFournisseur $reception, int $userId): array
    {
        $succursaleId = $reception->depotDestination?->succursale_id;
    
        if (!$succursaleId) {
            throw new \Exception(
                'Aucune succursale associée au dépôt de réception.'
            );
        }
    
        $mouvementsCount = 0;
        $stocksCrees = [];
        $tousLesEcarts = [];
    
        foreach ($reception->lignes as $ligne) {
    
            // ============================================================
            // 1. PROTECTION CONTRE UN DOUBLE TRAITEMENT
            // ============================================================
            if ($ligne->lot_id) {
                throw new \Exception(
                    "La ligne réception {$ligne->id} a déjà été traitée."
                );
            }
    
            // ============================================================
            // 2. QUANTITÉS
            // ============================================================
            $quantiteRecue = (int) $ligne->quantite_recue;
            $quantiteCasse = (int) ($ligne->quantite_casse ?? 0);
            $quantitePerdue = (int) ($ligne->quantite_perdue ?? 0);
            $quantiteNonConforme = (int) ($ligne->quantite_non_conforme ?? 0);
    
            $totalEcarts =
                $quantiteCasse +
                $quantitePerdue +
                $quantiteNonConforme;
    
            $quantiteConforme = max(
                0,
                $quantiteRecue - $totalEcarts
            );
    
            // ============================================================
            // 3. DÉTAILS DES ÉCARTS
            // ============================================================
            $tousLesEcarts[] = [
                'produit_id' => $ligne->produit_id,
                'produit_nom' => $ligne->produit->nom ?? 'N/A',
    
                'quantite_recue' => $quantiteRecue,
                'quantite_conforme' => $quantiteConforme,
    
                'ecarts' => [
                    'casse' => $quantiteCasse,
                    'perdue' => $quantitePerdue,
                    'non_conforme' => $quantiteNonConforme,
                    'total' => $totalEcarts,
                ],
            ];
    
            // ============================================================
            // 4. TROUVER OU CRÉER LE STOCK
            // ============================================================
            $stock = $this->stockService->trouverOuCreerStock(
                $succursaleId,
                $ligne->produit_id,
                [
                    'is_depot' => true,
                    'emplacement' => 'Stock principal',
                ]
            );
    
            if ($stock->wasRecentlyCreated) {
                $stocksCrees[] = $stock->id;
            }
    
            // ============================================================
            // 5. CALCUL DU PRIX D'ACHAT HT
            // ============================================================
            $prixUnitaireHT = (float) ($ligne->prix_unitaire_ht ?? 0);
            $remise = (float) ($ligne->remise ?? 0);
    
            $prixAchatHT = round(
                $prixUnitaireHT * (1 - ($remise / 100)),
                2
            );
    
            // ============================================================
            // 6. RÉCEPTION DU STOCK
            // ============================================================
            if ($quantiteConforme > 0) {
    
                /*
                 * Cette méthode reste la source de vérité pour :
                 *
                 * - l'entrée en stock
                 * - la création du mouvement ENTREE
                 * - la création du lot FIFO
                 * - l'augmentation de la quantité disponible
                 */
                $mouvement = $this->stockService->receptionner(
                    $stock,
                    $quantiteConforme,
                    [
                        'prix_achat' => $prixAchatHT,
    
                        'date_expiration' =>
                            $ligne->date_expiration_lot ?? null,
    
                        'raison' =>
                            'Réception commande fournisseur #' .
                            ($reception->commande->reference ?? 'N/A'),
    
                        'reference_document' =>
                            $reception->reference,
    
                        'metadata' => [
                            'cout_unitaire_entree' => $prixAchatHT,
                            'numero_lot_fournisseur' =>
                                $ligne->numero_lot,
                            'reception_id' =>
                                $reception->id,
                        ],
    
                        'numero_lot' =>
                            $ligne->numero_lot ??
                            ('LOT-' . now()->format('YmdHis')),
                    ]
                );
    
                // ========================================================
                // 7. VÉRIFIER LE MOUVEMENT
                // ========================================================
                if (!$mouvement || !$mouvement->mouvement_id) {
                    throw new \Exception(
                        "Le mouvement d'entrée n'a pas été créé pour " .
                        "le produit {$ligne->produit->nom}."
                    );
                }
    
                // ========================================================
                // 8. RÉCUPÉRER LE LOT FIFO
                // ========================================================
                /*
                 * On recharge explicitement la relation.
                 *
                 * Si la relation lotEntree n'est pas correctement hydratée,
                 * on effectue une recherche directe avec mouvement_entree_id.
                 */
                $mouvement->load('lotEntree');
    
                $lot = $mouvement->lotEntree;
    
                if (!$lot) {
                    $lot = \App\Models\LotStock::query()
                        ->where(
                            'mouvement_entree_id',
                            $mouvement->mouvement_id
                        )
                        ->where(
                            'produit_id',
                            $ligne->produit_id
                        )
                        ->first();
                }
    
                if (!$lot) {
                    throw new \Exception(
                        "Le lot n'a pas été retrouvé pour le produit " .
                        "{$ligne->produit->nom} " .
                        "(mouvement_id: {$mouvement->mouvement_id})."
                    );
                }
    
                // ========================================================
                // 9. GESTION DES IMEI
                // ========================================================
                /*
                 * IMPORTANT :
                 *
                 * gere_imei = true signifie que le produit est capable
                 * d'être suivi par IMEI.
                 *
                 * Cela ne signifie PAS que les IMEI doivent obligatoirement
                 * être fournis lors de la réception fournisseur.
                 *
                 * Donc :
                 *
                 * - 0 IMEI fourni  => réception OK
                 * - tous les IMEI  => création immédiate
                 * - nombre partiel => erreur
                 * - nombre supérieur => erreur
                 */
    
                if ($ligne->produit->gere_imei) {
    
                    // ----------------------------------------------------
                    // Récupération des metadata
                    // ----------------------------------------------------
                    if (is_string($ligne->metadata)) {
    
                        $metadata = json_decode(
                            $ligne->metadata,
                            true
                        );
    
                        if (!is_array($metadata)) {
                            $metadata = [];
                        }
    
                    } elseif (is_array($ligne->metadata)) {
    
                        $metadata = $ligne->metadata;
    
                    } else {
    
                        $metadata = [];
                    }
    
                    // ----------------------------------------------------
                    // Récupération des IMEI
                    // ----------------------------------------------------
                    $imeis = $metadata['imeis'] ?? [];
    
                    if (!is_array($imeis)) {
                        $imeis = [];
                    }
    
                    // ----------------------------------------------------
                    // Nettoyage des valeurs vides
                    // ----------------------------------------------------
                    $imeis = array_values(
                        array_filter(
                            $imeis,
                            static function ($imei) {
                                return is_string($imei)
                                    && trim($imei) !== '';
                            }
                        )
                    );
    
                    // ----------------------------------------------------
                    // Aucun IMEI fourni
                    // ----------------------------------------------------
                    if (empty($imeis)) {
    
                        /*
                         * Ce n'est PAS une erreur.
                         *
                         * Le produit est bien entré en stock et le lot
                         * FIFO a été créé.
                         *
                         * Les IMEI pourront être renseignés ultérieurement.
                         */
                        Log::info(
                            'Réception produit IMEI sans saisie IMEI - saisie différée',
                            [
                                'reception_id' =>
                                    $reception->id,
    
                                'ligne_reception_id' =>
                                    $ligne->id,
    
                                'produit_id' =>
                                    $ligne->produit_id,
    
                                'produit' =>
                                    $ligne->produit->nom,
    
                                'quantite_conforme' =>
                                    $quantiteConforme,
    
                                'lot_id' =>
                                    $lot->id,
    
                                'mouvement_id' =>
                                    $mouvement->mouvement_id,
                            ]
                        );
    
                    } else {
    
                        // ------------------------------------------------
                        // IMEI fournis : quantité obligatoire cohérente
                        // ------------------------------------------------
                        if (count($imeis) !== $quantiteConforme) {
    
                            throw new \Exception(
                                "Pour le produit " .
                                "{$ligne->produit->nom}, " .
                                "{$quantiteConforme} IMEI(s) doivent " .
                                "être fournis si la saisie des IMEI est " .
                                "effectuée. Reçu : " .
                                count($imeis) . "."
                            );
                        }
    
                        // ------------------------------------------------
                        // Création des IMEI
                        // ------------------------------------------------
                        $this->imeiService->creerImeisPourLot(
                            $lot,
                            $stock,
                            $imeis,
                            $mouvement->mouvement_id
                        );
    
                        Log::info(
                            'IMEI créés lors de la réception fournisseur',
                            [
                                'reception_id' =>
                                    $reception->id,
    
                                'ligne_reception_id' =>
                                    $ligne->id,
    
                                'produit_id' =>
                                    $ligne->produit_id,
    
                                'produit' =>
                                    $ligne->produit->nom,
    
                                'quantite_conforme' =>
                                    $quantiteConforme,
    
                                'nombre_imeis' =>
                                    count($imeis),
    
                                'lot_id' =>
                                    $lot->id,
    
                                'mouvement_id' =>
                                    $mouvement->mouvement_id,
                            ]
                        );
                    }
                }
    
                // ========================================================
                // 10. LIER LA LIGNE DE RÉCEPTION AU MOUVEMENT
                // ========================================================
                $ligne->lot_id = $mouvement->mouvement_id;
            }
    
            // ============================================================
            // 11. SAUVEGARDER LA LIGNE DE RÉCEPTION
            // ============================================================
            $ligne->save();
    
            // ============================================================
            // 12. MISE À JOUR DE LA LIGNE DE COMMANDE
            // ============================================================
            if ($ligne->commandeLigne) {
    
                $ligne->commandeLigne->quantite_recue =
                    ($ligne->commandeLigne->quantite_recue ?? 0)
                    + $quantiteRecue;
    
                $ligne->commandeLigne->save();
            }
    
            // ============================================================
            // 13. SYNCHRONISATION DU PRIX PRODUIT
            // ============================================================
            if (
                $ligne->produit &&
                $prixAchatHT > 0
            ) {
                $ligne->produit->synchroniserPrix(
                    $prixAchatHT,
                    $ligne->taux_tva ?? 16
                );
            }
    
            $mouvementsCount++;
        }
    
        // ================================================================
        // 14. RÉSULTAT
        // ================================================================
        return [
            'mouvements_count' => $mouvementsCount,
    
            'stocks_crees' => $stocksCrees,
    
            'details_ecarts' => $tousLesEcarts,
    
            'recap_ecarts' => [
    
                'total_recu' =>
                    $reception->lignes->sum(
                        'quantite_recue'
                    ),
    
                'total_conforme' =>
                    $reception->lignes->sum(
                        function ($l) {
                            return max(
                                0,
                                $l->quantite_recue
                                - ($l->quantite_casse ?? 0)
                                - ($l->quantite_perdue ?? 0)
                                - ($l->quantite_non_conforme ?? 0)
                            );
                        }
                    ),
    
                'total_casse' =>
                    $reception->lignes->sum(
                        'quantite_casse'
                    ),
    
                'total_perdue' =>
                    $reception->lignes->sum(
                        'quantite_perdue'
                    ),
    
                'total_non_conforme' =>
                    $reception->lignes->sum(
                        'quantite_non_conforme'
                    ),
            ],
        ];
    }

    private function creerEcrituresComptables(ReceptionFournisseur $reception): array
    {

        $ecritures = [];
        $errors = [];

        $entite = $this->getEntiteFromReception($reception);
        if (!$entite) {
            return ['success' => false, 'message' => 'Aucune entité trouvée.'];
        }

        $succursaleId = $reception->depotDestination?->succursale_id;

        foreach ($reception->lignes as $ligne) {
            $montantHT = $ligne->valeur_ht ?? 0;
            if ($montantHT <= 0) {
                continue;
            }

            try {
                $result = $this->ecritureService->creerEcritureSimple([
                    'montant' => $montantHT,
                    'entite_id' => $entite->id,
                    'succursale_id' => $succursaleId,
                    'operation_libelle' => 'Achat de marchandise au comptant',
                    'libelle' => 'Achat de ' . ($ligne->produit->nom ?? 'produit') . ' au comptant'.','.$reception->reference ,
                    'date_ecriture' => $reception->date_reception->format('Y-m-d'),
                    'reference' => 'REC-' . $reception->reference . '-' . $ligne->id,
                    'auto_overrides' => [
                        [
                            'libelle' => 'Stockage Marchandise ' . ($ligne->produit->nom ?? ''),
                            'use_default' => false,
                            'valeur' => $montantHT,
                        ],
                    ],
                ]);

                if ($result['success']) {
                    $ecritures[] = $result['data'];
                } else {
                    $errors[] = $result['message'];
                }
            } catch (\Exception $e) {
                $errors[] = $e->getMessage();
            }
        }

        return [
            'success' => empty($errors),
            'ecritures' => $ecritures,
            'errors' => $errors,
            'total' => count($ecritures),
        ];
    }

    private function getEntiteFromReception(ReceptionFournisseur $reception)
    {
        // Logique identique à celle du contrôleur
        if ($reception->depotDestination?->succursale?->entite) {
            return $reception->depotDestination->succursale->entite;
        }
        if ($reception->commande?->depotDestination?->succursale?->entite) {
            return $reception->commande->depotDestination->succursale->entite;
        }
        $user = auth()->user();
        if ($user && $user->succursale_id) {
            $succursale = \App\Models\Succursale::with('entite')->find($user->succursale_id);
            if ($succursale && $succursale->entite) {
                return $succursale->entite;
            }
        }
        return \App\Models\Entite::first();
    }

    private function mettreAJourCommande(CommandeFournisseur $commande): void
    {
        $totalRecu = $commande->lignes()->sum('quantite_recue');
        $totalCommande = $commande->lignes()->sum('quantite');

        if ($totalRecu >= $totalCommande) {
            $commande->statut = 'RECUE';
            $commande->date_reception = now();
        } elseif ($totalRecu > 0) {
            $commande->statut = 'PARTIELLEMENT_RECUE';
        } else {
            $commande->statut = 'EN_ATTENTE';
        }
        $commande->reception_par_id = auth()->id();
        $commande->save();
    }
}