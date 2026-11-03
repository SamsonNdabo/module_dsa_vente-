<?php

namespace App\Services;

use App\DTO\SouscriptionRequest;
use App\Models\Caisses;
use App\Models\Client;
use App\Models\CommandeClient;
use App\Models\CompteEpargne;
use App\Models\Facture;
use App\Models\KycDossier;
use App\Models\KycPiece;
use App\Models\LigneCommande;
use App\Models\LigneFacture;
use App\Models\Paiement;
use App\Models\PartenaireProduit;
use App\Models\Produit;
use App\Models\ProduitEpargne;
use App\Models\RegleKycPartenaire;
use App\Models\StockSuccursale;
use App\Models\Utilisateur;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SouscriptionService
{
    public function __construct(
        private POSService $posService,
        private CommandeService $commandeService,
    ) {}

    public function souscrire(SouscriptionRequest $dto, Utilisateur $auteur): array
    {
        return DB::transaction(function () use ($dto, $auteur) {
            $client = Client::findOrFail($dto->clientId);

            return match ($dto->typeProduit) {
                'STOCK'   => $this->souscrireStock($dto, $client, $auteur),
                'EPARGNE' => $this->souscrireEpargne($dto, $client, $auteur),
                default   => throw ValidationException::withMessages([
                    'type_produit' => 'Type inconnu.',
                ]),
            };
        });
    }

    /* ═══════════════════════════════════════════════════════════
       FINALISER VENTE — paiement + sortie FIFO + caisse + écriture
       ═══════════════════════════════════════════════════════════ */

    public function finaliserVente(
        int $factureId,
        string $modePaiement,
        ?float $montant,
        ?string $notes,
        Utilisateur $auteur
    ): array {
        return DB::transaction(function () use ($factureId, $modePaiement, $montant, $notes, $auteur) {
            $facture = Facture::with(['lignes.produit', 'client'])
                ->lockForUpdate()
                ->findOrFail($factureId);

            if (! in_array($facture->statut, ['BROUILLON', 'EN_ATTENTE_PAIEMENT'], true)) {
                throw ValidationException::withMessages([
                    'statut' => "Cette facture ne peut pas être finalisée (statut actuel : {$facture->statut}).",
                ]);
            }

            // 🆕 Détecter si c'est une vente hors-caisse (produit partenaire)
            $estHorsCaisse = (bool) ($facture->metadata['hors_caisse'] ?? false)
                || ($facture->metadata['source'] ?? null) === 'DSA_SOUSCRIPTION_HORS_CAISSE';

            if ($estHorsCaisse) {
                return $this->finaliserVenteHorsCaisse($facture, $modePaiement, $montant, $notes, $auteur);
            }

            // ─── Circuit standard (caisse + écriture) ───
            $caisseId = $facture->metadata['caisse_id_prevue']
                ?? $facture->caisse_id
                ?? null;

            if (! $caisseId) {
                throw ValidationException::withMessages([
                    'caisse_id' => 'Impossible de déterminer la caisse pour cette facture.',
                ]);
            }

            $caisse = Caisses::find($caisseId);
            if (! $caisse || $caisse->statut !== 'OUVERTE') {
                throw ValidationException::withMessages([
                    'caisse_id' => 'La caisse associée à cette facture n\'est pas ouverte.',
                ]);
            }

            $paiement = $this->posService->payerVente($factureId, [
                'mode_paiement' => $modePaiement,
                'caisse_id'     => $caisseId,
                'montant'       => $montant ?? $facture->total_ttc,
                'notes'         => $notes ?? 'Paiement finalisation souscription DSA',
            ]);

            $facture->refresh();

            return [
                'facture_id'    => $facture->id,
                'facture_ref'   => $facture->reference,
                'statut'        => $facture->statut,
                'total'         => (float) $facture->total_ttc,
                'montant_regle' => (float) $facture->montant_regle,
                'caisse_id'     => $caisseId,
                'caisse_nom'    => $caisse->nom,
                'circuit'       => 'STANDARD',
                'paiement'      => [
                    'id'            => $paiement['paiement']?->id ?? null,
                    'reference'     => $paiement['paiement']?->reference ?? null,
                    'mode_paiement' => $modePaiement,
                    'montant'       => $montant ?? $facture->total_ttc,
                ],
                'message' => "Vente finalisée — facture {$facture->reference} payée.",
            ];
        });
    }

    /**
     * Finalisation HORS-CAISSE (produit partenaire) :
     * pas de caisse, pas d'écriture, MAIS sortie FIFO obligatoire.
     */
    private function finaliserVenteHorsCaisse(
        Facture $facture,
        string $modePaiement,
        ?float $montant,
        ?string $notes,
        Utilisateur $auteur
    ): array {
        $facture->load(['lignes.produit']);

        // 1. Paiement sans caisse
        $paiement = Paiement::create([
            'reference'        => Paiement::genererReference(),
            'facture_id'       => $facture->id,
            'client_id'        => $facture->client_id,
            'caisse_id'        => null,
            'utilisateur_id'   => $auteur->id,
            'mode_paiement'    => $modePaiement,
            'montant'          => $montant ?? $facture->total_ttc,
            'montant_rendu'    => 0,
            'date_paiement'    => now(),
            'statut'           => 'COMPLET',
            'notes'            => $notes ?? 'Paiement finalisation — vente hors-caisse',
            'details_paiement' => [
                'circuit' => 'HORS_CAISSE',
                'source'  => 'DSA_SOUSCRIPTION',
            ],
        ]);

        // 2. Sortie FIFO pour chaque ligne
        foreach ($facture->lignes as $ligne) {
            $stock = StockSuccursale::where('succursale_id', $facture->succursale_id)
                ->where('produit_id', $ligne->produit_id)
                ->lockForUpdate()
                ->first();

            if (! $stock) {
                throw ValidationException::withMessages([
                    'stock' => "Aucun stock enregistré pour '{$ligne->produit->nom}'.",
                ]);
            }

            if ($stock->quantite_reserve < $ligne->quantite) {
                throw ValidationException::withMessages([
                    'stock' => "Réservation insuffisante pour '{$ligne->produit->nom}'. "
                        . "Réservé : {$stock->quantite_reserve}, demandé : {$ligne->quantite}.",
                ]);
            }

            $stock->expedier($ligne->quantite, [
                'demande_parent_id'  => $facture->id,
                'reference_document' => $facture->reference,
                'raison'             => "Finalisation vente hors-caisse — {$facture->reference}",
                'type_expedition'    => 'DSA_SOUSCRIPTION_HORS_CAISSE',
                'responsable_id'     => $auteur->id,
                'metadata'           => [
                    'source'      => 'DSA_SOUSCRIPTION_HORS_CAISSE',
                    'facture_id'  => $facture->id,
                    'paiement_id' => $paiement->id,
                ],
            ]);
        }

        // 3. Mise à jour facture
        $facture->update([
            'statut'        => 'PAYEE',
            'date_paiement' => now(),
            'montant_regle' => $montant ?? $facture->total_ttc,
            'montant_du'    => 0,
            'reste_a_payer' => 0,
        ]);

        return [
            'facture_id'    => $facture->id,
            'facture_ref'   => $facture->reference,
            'statut'        => 'PAYEE',
            'total'         => (float) $facture->total_ttc,
            'montant_regle' => $montant ?? (float) $facture->total_ttc,
            'caisse_id'     => null,
            'caisse_nom'    => 'Hors-caisse (partenaire)',
            'circuit'       => 'HORS_CAISSE',
            'paiement'      => [
                'id'            => $paiement->id,
                'reference'     => $paiement->reference,
                'mode_paiement' => $modePaiement,
                'montant'       => $montant ?? $facture->total_ttc,
            ],
            'message' => "Vente hors-caisse finalisée — facture {$facture->reference} payée.",
        ];
    }

    /* ═══════════════════════════════════════════════════════════
       STOCK — Panier multi-produits
       ═══════════════════════════════════════════════════════════ */

    private function souscrireStock(SouscriptionRequest $dto, Client $client, Utilisateur $auteur): array
    {
        if (empty($dto->items)) {
            throw ValidationException::withMessages([
                'items' => 'Le panier est vide.',
            ]);
        }

        $lignes = [];
        $totalStockOk = true;

        foreach ($dto->items as $item) {
            $produit = Produit::findOrFail($item['id_produit']);
            if (! $produit->actif) {
                throw ValidationException::withMessages([
                    'items' => "Le produit '{$produit->nom}' est inactif.",
                ]);
            }

            $partenaireId = $this->resoudrePartenaire('STOCK', $produit->id, $item['id_partenaire'] ?? null);
            $estPartenaire = $partenaireId !== null;

            if ($partenaireId) {
                $this->verifierKycProduit($client, 'STOCK', $produit->id, $partenaireId);
            }

            $succursaleId = $this->resoudreSuccursale($client, $auteur);

            $stock = StockSuccursale::where('succursale_id', $succursaleId)
                ->where('produit_id', $produit->id)
                ->first();

            $stockDisponible = $stock ? (int) $stock->quantite_disponible : 0;
            $quantite = (int) $item['quantite'];

            if ($stockDisponible < $quantite) {
                $totalStockOk = false;
            }

            $lignes[] = [
                'produit'         => $produit,
                'quantite'        => $quantite,
                'partenaire_id'   => $partenaireId,
                'est_partenaire'  => $estPartenaire,
                'stock'           => $stock,
                'stock_disponible'=> $stockDisponible,
                'succursale_id'   => $succursaleId,
            ];
        }

        $mode = $dto->modeExecution;
        if ($mode === 'AUTO') {
            $mode = $totalStockOk ? 'VENTE' : 'COMMANDE';
        }

        if ($mode === 'VENTE') {
            return $this->executerVente($lignes, $dto, $client, $auteur);
        }

        return $this->executerCommande($lignes, $dto, $client, $auteur);
    }

    /* ═══════════════════════════════════════════════════════════
       VENTE — Panier multi-produits
       ═══════════════════════════════════════════════════════════ */

    private function executerVente(array $lignes, SouscriptionRequest $dto, Client $client, Utilisateur $auteur): array
    {
        $lignesPartenaires = array_filter($lignes, fn ($l) => $l['est_partenaire']);
        $lignesStandards   = array_filter($lignes, fn ($l) => ! $l['est_partenaire']);

        $resultats = [
            'type'           => 'VENTE',
            'ventes'         => [],
            'ventes_hors_caisse' => [],
            'facture_id'     => null,
            'facture_ref'    => null,
            'total_ventes'   => 0,
            'total_hors_caisse' => 0,
            'statut'         => null,
            'caisse_id'      => null,
            'caisse_nom'     => null,
        ];

        // Vente standard → EN_ATTENTE_PAIEMENT
        if (! empty($lignesStandards)) {
            $caisseId = $dto->caisseId ?? $this->getCaisseOuverte($auteur);

            $init = $this->posService->initierVente([
                'caisse_id'  => $caisseId,
                'nom_client' => $client->nom_complet,
                'telephone'  => $client->telephone,
                'notes'      => 'Souscription DSA — panier standard',
            ]);

            $panier = [];
            foreach ($lignesStandards as $l) {
                $panier[] = [
                    'produit_id' => $l['produit']->id,
                    'quantite'   => $l['quantite'],
                ];
            }

            $finalisation = $this->posService->finaliserVente($init['vente_id'], ['panier' => $panier]);

            $facture = $finalisation['facture'];
            $facture->update([
                'statut'   => 'EN_ATTENTE_PAIEMENT',
                'metadata' => array_merge($facture->metadata ?? [], [
                    'souscription_dsa' => true,
                    'caisse_id_prevue' => $caisseId,
                ]),
            ]);

            $caisse = Caisses::find($caisseId);

            $resultats['facture_id']  = $facture->id;
            $resultats['facture_ref'] = $facture->reference;
            $resultats['total_ventes'] = $finalisation['total'];
            $resultats['statut']       = 'EN_ATTENTE_PAIEMENT';
            $resultats['caisse_id']    = $caisseId;
            $resultats['caisse_nom']   = $caisse?->nom ?? 'Caisse succursale';
        }

        // Vente hors-caisse → EN_ATTENTE_PAIEMENT (réservation uniquement)
        foreach ($lignesPartenaires as $l) {
            $v = $this->creerVenteHorsCaisseLigne($l, $client, $auteur);
            $resultats['ventes_hors_caisse'][] = $v;
            $resultats['total_hors_caisse'] += $v['montant_total'];
        }

        $resultats['message'] = count($lignesStandards) . ' vente(s) standard + '
            . count($lignesPartenaires) . ' vente(s) partenaire(s) enregistrée(s).';

        return $resultats;
    }

    private function creerVenteHorsCaisseLigne(array $ligne, Client $client, Utilisateur $auteur): array
    {
        $produit = $ligne['produit'];
        $prixUnitaireHT  = (float) $produit->prix_vente;
        $prixUnitaireTTC = (float) ($produit->prix_vente_TTC ?? $prixUnitaireHT * 1.16);
        $tauxTVA         = (float) ($produit->tva ?? 16);

        $succursaleId      = $ligne['succursale_id'] ?? $ligne['stock']?->succursale_id;
        $stockSuccursaleId = $ligne['stock']?->id;

        if (! $succursaleId) {
            throw ValidationException::withMessages([
                'items' => "Impossible de déterminer la succursale pour '{$produit->nom}'.",
            ]);
        }

        // Facture EN_ATTENTE_PAIEMENT (pas de caisse)
        $facture = Facture::create([
            'client_id'      => $client->id,
            'utilisateur_id' => $auteur->id,
            'succursale_id'  => $succursaleId,
            'caisse_id'      => null,
            'devise_id'      => $produit->devise_id,
            'date_facture'   => now(),
            'statut'         => 'EN_ATTENTE_PAIEMENT',
            'type_facture'   => 'VENTE',
            'sous_total'     => $prixUnitaireHT * $ligne['quantite'],
            'total_ht'       => $prixUnitaireHT * $ligne['quantite'],
            'taux_tva'       => $tauxTVA,
            'total_tva'      => $prixUnitaireHT * $ligne['quantite'] * ($tauxTVA / 100),
            'total_ttc'      => $prixUnitaireTTC * $ligne['quantite'],
            'montant_du'     => $prixUnitaireTTC * $ligne['quantite'],
            'reste_a_payer'  => $prixUnitaireTTC * $ligne['quantite'],
            'notes'          => "Souscription DSA — produit partenaire #{$ligne['partenaire_id']}",
            'metadata'       => [
                'source'           => 'DSA_SOUSCRIPTION_HORS_CAISSE',
                'partenaire_id'    => $ligne['partenaire_id'],
                'caisse_id_prevue' => null,
                'hors_caisse'      => true,
            ],
        ]);

        LigneFacture::create([
            'facture_id'          => $facture->id,
            'produit_id'          => $produit->id,
            'stock_succursale_id' => $stockSuccursaleId,
            'quantite'            => $ligne['quantite'],
            'prix_unitaire_ht'    => $prixUnitaireHT,
            'prix_unitaire_ttc'   => $prixUnitaireTTC,
            'total_ht'            => $prixUnitaireHT * $ligne['quantite'],
            'total_ttc'           => $prixUnitaireTTC * $ligne['quantite'],
        ]);

        // ⚠️ RÉSERVER (PAS de sortie)
        if ($ligne['stock']) {
            $ligne['stock']->increment('quantite_reserve', $ligne['quantite']);
        }

        return [
            'produit_nom'    => $produit->nom,
            'quantite'       => $ligne['quantite'],
            'facture_id'     => $facture->id,
            'facture_ref'    => $facture->reference,
            'montant_total'  => $prixUnitaireTTC * $ligne['quantite'],
            'partenaire_id'  => $ligne['partenaire_id'],
            'statut'         => 'EN_ATTENTE_PAIEMENT',
            'circuit'        => 'HORS_CAISSE',
        ];
    }

    /* ═══════════════════════════════════════════════════════════
       COMMANDE — Panier multi-produits + récurrence
       ═══════════════════════════════════════════════════════════ */

    private function executerCommande(array $lignes, SouscriptionRequest $dto, Client $client, Utilisateur $auteur): array
    {
        $lignesPartenaires = array_filter($lignes, fn ($l) => $l['est_partenaire']);
        $lignesStandards   = array_filter($lignes, fn ($l) => ! $l['est_partenaire']);

        $resultats = [
            'type'                => 'COMMANDE',
            'commandes'           => [],
            'commandes_hors_caisse' => [],
            'commande_id'         => null,
            'commande_ref'        => null,
            'total_commandes'     => 0,
            'total_hors_caisse'   => 0,
        ];

        // 🆕 Calcul automatique de la date de fin
        $dateFinRecurrence = $dto->calculerDateFinRecurrence();

        if (! empty($lignesStandards)) {
            $panier = [];
            foreach ($lignesStandards as $l) {
                $panier[] = [
                    'produit_id'    => $l['produit']->id,
                    'quantite'      => $l['quantite'],
                    'prix_unitaire' => (float) $l['produit']->prix_vente,
                ];
            }

            $commande = $this->commandeService->creerCommande(
                $client,
                $panier,
                [
                    'mode_livraison'           => $dto->modeLivraison ?? 'RETRAIT_MAGASIN',
                    'adresse_livraison'        => $dto->adresseLivraison,
                    'telephone_livraison'      => $dto->telephoneLivraison ?? $client->telephone,
                    'date_livraison_souhaitee' => $dto->dateLivraisonSouhaitee,
                    'est_recurrente'           => $dto->estRecurrente,
                    'recurrence_type'          => $dto->recurrenceType,
                    'recurrence_intervalle'    => $dto->recurrenceIntervalle,
                    'recurrence_date_fin'      => $dateFinRecurrence,   // ← Calculé auto
                    'recurrence_nb_occurrences'=> $dto->recurrenceNbOccurrences,
                    'notes'                    => 'Souscription DSA — panier standard',
                    'metadata'                 => [
                        'source' => 'DSA_SOUSCRIPTION_STANDARD',
                        'recurrence_calculee' => [
                            'date_debut'     => $dto->dateLivraisonSouhaitee,
                            'date_fin'       => $dateFinRecurrence,
                            'nb_occurrences' => $dto->recurrenceNbOccurrences,
                            'intervalle'     => $dto->recurrenceIntervalle,
                            'type'           => $dto->recurrenceType,
                        ],
                    ],
                ]
            );

            $resultats['commande_id']  = $commande->id;
            $resultats['commande_ref'] = $commande->reference;
            $resultats['total_commandes'] = $commande->total;
        }

        foreach ($lignesPartenaires as $l) {
            $c = $this->creerCommandeHorsCaisseLigne($l, $dto, $client);
            $resultats['commandes_hors_caisse'][] = $c;
            $resultats['total_hors_caisse'] += $c['montant_total'];
        }

        $resultats['message'] = count($lignesStandards) . ' commande(s) standard + '
            . count($lignesPartenaires) . ' commande(s) partenaire(s).';

        return $resultats;
    }

    private function creerCommandeHorsCaisseLigne(array $ligne, SouscriptionRequest $dto, Client $client): array
    {
        $produit = $ligne['produit'];
        $prixUnitaire = (float) $produit->prix_vente;
        $sousTotal    = $prixUnitaire * $ligne['quantite'];

        // 🆕 Calcul auto de la date de fin
        $dateFinRecurrence = $dto->calculerDateFinRecurrence();

        $commande = CommandeClient::create([
            'client_id'                => $client->id,
            'statut'                   => 'EN_ATTENTE_PAIEMENT',
            'statut_logistique'        => 'EN_ATTENTE',
            'sous_total'               => $sousTotal,
            'remise'                   => 0,
            'frais_livraison'          => 0,
            'total'                    => $sousTotal,
            'mode_livraison'           => $dto->modeLivraison ?? 'RETRAIT_MAGASIN',
            'adresse_livraison'        => $dto->adresseLivraison,
            'telephone_livraison'      => $dto->telephoneLivraison ?? $client->telephone,
            'date_livraison_souhaitee' => $dto->dateLivraisonSouhaitee,
            'est_recurrente'           => $dto->estRecurrente,
            'recurrence_type'          => $dto->recurrenceType,
            'recurrence_intervalle'    => $dto->recurrenceIntervalle,
            'recurrence_date_fin'      => $dateFinRecurrence,   // ← Calculé auto
            'recurrence_nb_occurrences'=> $dto->recurrenceNbOccurrences,
            'notes'                    => "Souscription DSA — produit partenaire #{$ligne['partenaire_id']} (hors-caisse)",
            'date_commande'            => now(),
            'metadata'                 => [
                'source'        => 'DSA_SOUSCRIPTION_HORS_CAISSE',
                'partenaire_id' => $ligne['partenaire_id'],
                'stock_initial' => $ligne['stock_disponible'],
                'recurrence_calculee' => [
                    'date_debut'     => $dto->dateLivraisonSouhaitee,
                    'date_fin'       => $dateFinRecurrence,
                    'nb_occurrences' => $dto->recurrenceNbOccurrences,
                    'intervalle'     => $dto->recurrenceIntervalle,
                    'type'           => $dto->recurrenceType,
                ],
            ],
        ]);

        LigneCommande::create([
            'commande_id'   => $commande->id,
            'produit_id'    => $produit->id,
            'quantite'      => $ligne['quantite'],
            'prix_unitaire' => $prixUnitaire,
            'remise_ligne'  => 0,
            'total'         => $sousTotal,
        ]);

        return [
            'produit_nom'          => $produit->nom,
            'quantite'             => $ligne['quantite'],
            'commande_id'          => $commande->id,
            'commande_ref'         => $commande->reference,
            'montant_total'        => $sousTotal,
            'stock_disponible'     => $ligne['stock_disponible'],
            'quantite_a_commander' => max(0, $ligne['quantite'] - $ligne['stock_disponible']),
            'partenaire_id'        => $ligne['partenaire_id'],
            'statut'               => $commande->statut,
            'circuit'              => 'HORS_CAISSE',
            'date_livraison_souhaitee' => $commande->date_livraison_souhaitee?->toDateString(),
            'est_recurrente'       => $commande->est_recurrente,
            'recurrence_date_fin'  => $commande->recurrence_date_fin?->toDateString(),
            'recurrence_nb_occurrences' => $commande->recurrence_nb_occurrences,
        ];
    }

    /* ═══════════════════════════════════════════════════════════
       ÉPARGNE
       ═══════════════════════════════════════════════════════════ */

    private function souscrireEpargne(SouscriptionRequest $dto, Client $client, Utilisateur $auteur): array
    {
        $produit = ProduitEpargne::findOrFail($dto->idProduit);
        if (! $produit->estActif()) {
            throw ValidationException::withMessages(['id_produit' => 'Ce produit est inactif.']);
        }

        $partenaireId = $this->resoudrePartenaire('EPARGNE', $produit->id, $dto->idPartenaire);
        $estPartenaire = $partenaireId !== null;

        if ($partenaireId) {
            $this->verifierKycProduit($client, 'EPARGNE', $produit->id, $partenaireId);
        }

        $existe = CompteEpargne::where('client_id', $client->id)
            ->where('produit_epargne_id', $produit->id)
            ->where('statut', 'OUVERT')
            ->exists();
        if ($existe) {
            throw ValidationException::withMessages(['id_produit' => 'Un compte OUVERT existe déjà pour ce produit.']);
        }

        $montantInitial = $dto->montantInitial ?? 0;
        if ($produit->solde_minimum && $montantInitial < (float) $produit->solde_minimum) {
            throw ValidationException::withMessages([
                'montant_initial' => "Solde initial ({$montantInitial}) < minimum ({$produit->solde_minimum}).",
            ]);
        }

        $succursaleId = $this->resoudreSuccursale($client, $auteur);

        $compte = CompteEpargne::create([
            'client_id'          => $client->id,
            'produit_epargne_id' => $produit->id,
            'devise_id'          => $produit->devise_id,
            'agent_dsa_id'       => $client->agent_dsa_id,
            'partenaire_id'      => $partenaireId,
            'succursale_id'      => $succursaleId,
            'solde'              => $montantInitial,
            'solde_bloque'       => 0,
            'statut'             => 'OUVERT',
            'date_ouverture'     => now()->toDateString(),
            'created_by'         => $auteur->id,
        ]);

        return [
            'type_operation' => 'COMPTE_EPARGNE',
            'circuit'        => $estPartenaire ? 'HORS_CAISSE' : 'STANDARD',
            'compte_id'      => $compte->id,
            'numero_compte'  => $compte->numero_compte,
            'produit_nom'    => $produit->nom,
            'solde_initial'  => $montantInitial,
            'partenaire_id'  => $partenaireId,
            'client_id'      => $client->id,
            'client_nom'     => $client->nom_complet,
            'statut'         => 'OUVERT',
            'message'        => "Compte d'épargne créé : {$compte->numero_compte}",
        ];
    }

    /* ═══════════════════════════════════════════════════════════
       HELPERS
       ═══════════════════════════════════════════════════════════ */

    private function resoudrePartenaire(string $typeProduit, int $idProduit, ?int $idPartenaire): ?int
    {
        if ($idPartenaire) {
            $existe = PartenaireProduit::where('type_produit', $typeProduit)
                ->where('id_produit', $idProduit)
                ->where('id_partenaire', $idPartenaire)
                ->where('actif', 1)
                ->exists();

            if (! $existe) {
                throw ValidationException::withMessages([
                    'items' => 'Ce produit n\'est pas lié au partenaire spécifié.',
                ]);
            }

            return $idPartenaire;
        }

        return PartenaireProduit::where('type_produit', $typeProduit)
            ->where('id_produit', $idProduit)
            ->where('actif', 1)
            ->value('id_partenaire');
    }

    private function verifierKycProduit(Client $client, string $typeProduit, int $idProduit, ?int $partenaireId): void
    {
        if (! $partenaireId) return;

        // Bypass si client VALIDE
        if ($client->kyc_statut === 'VALIDE') return;

        $regles = RegleKycPartenaire::query()
            ->where('actif', true)
            ->where('obligatoire', true)
            ->where('partenaire_id', $partenaireId)
            ->applicablesA($typeProduit, $idProduit)
            ->get();

        if ($regles->isEmpty()) return;

        $piecesValidees = KycPiece::whereHas('dossier', function ($q) use ($client) {
            $q->where('client_id', $client->id)->where('statut', '!=', 'REJETE');
        })->where('statut', 'VALIDE')->get();

        $aSignature = KycDossier::where('client_id', $client->id)
            ->where('statut', '!=', 'REJETE')
            ->whereNotNull('signature_ref')
            ->exists();

        $manquants = [];

        foreach ($regles as $regle) {
            $type = $regle->type_document;

            if ($type === 'SIGNATURE') {
                if (! $aSignature) $manquants[] = 'Signature';
                continue;
            }

            $valide = $piecesValidees->first(function ($piece) use ($type, $regle) {
                $ok = $type === 'IDENTITE'
                    ? in_array($piece->type_document, ['CARTE_IDENTITE', 'PASSEPORT', 'CARTE_ELECTEUR', 'PERMIS'])
                    : $piece->type_document === $type;
                if (! $ok) return false;
                if ($regle->validite_jours && $piece->date_expiration && $piece->date_expiration->isPast()) return false;
                return true;
            });

            if (! $valide) $manquants[] = $type;
        }

        if (! empty($manquants)) {
            throw ValidationException::withMessages([
                'kyc_produit' => 'Documents KYC manquants : ' . implode(', ', array_unique($manquants)),
            ]);
        }
    }

    private function resoudreSuccursale(Client $client, Utilisateur $auteur): int
    {
        if ($client->agent_dsa_id) {
            $agent = \App\Models\AgentDsa::with('zone.superviseur')->find($client->agent_dsa_id);
            if ($agent) {
                if ($agent->succursale_id) return (int) $agent->succursale_id;
                if ($agent->zone && $agent->zone->superviseur_id) {
                    $sup = Utilisateur::find($agent->zone->superviseur_id);
                    if ($sup && $sup->succursale_id) return (int) $sup->succursale_id;
                }
            }
        }
        if ($auteur->succursale_id) return (int) $auteur->succursale_id;
        throw ValidationException::withMessages(['succursale' => 'Impossible de déterminer la succursale.']);
    }

    private function getCaisseOuverte(Utilisateur $auteur): int
    {
        $caisse = Caisses::where('succursale_id', $auteur->succursale_id)
            ->where('statut', 'OUVERTE')
            ->where('type_caisse', 'PHYSIQUE')
            ->where('categorie', '!=', 'COFFRE')
            ->orderByDesc('id')
            ->first();

        if (! $caisse) {
            // Fallback : coffre → sous-caisse physique
            $coffre = Caisses::where('succursale_id', $auteur->succursale_id)
                ->where('statut', 'OUVERTE')
                ->where('categorie', 'COFFRE')
                ->first();

            if ($coffre) {
                $sousCaisse = $coffre->secondairePhysique();
                if ($sousCaisse && $sousCaisse->statut === 'OUVERTE') {
                    return $sousCaisse->id;
                }
            }

            throw ValidationException::withMessages([
                'caisse_id' => 'Aucune caisse physique ouverte pour la vente.',
            ]);
        }

        return $caisse->id;
    }
}