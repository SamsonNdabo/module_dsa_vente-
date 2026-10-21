<?php
// app/Services/CommandeService.php

namespace App\Services;

use App\Models\Client;
use App\Models\CommandeClient;
use App\Models\LigneCommande;
use App\Models\Produit;
use App\Models\ProduitImei;
use App\Models\StockSuccursale;
use App\Models\Succursale;
use App\Models\Utilisateur;
use App\Models\FraisLivraison;
use App\Models\MouvementStock;
use App\Services\ImeiService;
use App\Services\EcritureService;
use App\Services\RemiseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CommandeService
{
    protected RemiseService $remiseService;
    protected EcritureService $ecritureService;
    protected ImeiService $imeiService;

    public function __construct(
        RemiseService $remiseService,
        EcritureService $ecritureService,
        ImeiService $imeiService
    ) {
        $this->remiseService = $remiseService;
        $this->ecritureService = $ecritureService;
        $this->imeiService = $imeiService;
    }

    // ================================================================
    // 1. CRÉER UNE COMMANDE EN LIGNE
    // ================================================================
    // Le client crée sa commande. IL NE SAIT RIEN DES IMEI.
    // On enregistre juste le BESOIN en IMEI (pour la logistique)
    // ================================================================

    public function creerCommande(Client $client, array $panier, array $data): CommandeClient
    {
        return DB::transaction(function () use ($client, $panier, $data) {
            $sousTotal = 0;
            $lignesPreparees = [];
            $besoinsImei = [];

            foreach ($panier as $item) {
                $produit = Produit::findOrFail($item['produit_id']);
                $quantite = (int) $item['quantite'];
                $prixUnitaire = (float) $item['prix_unitaire'];

                // ✅ Le client ne sait pas ce qu'est un IMEI
                // On enregistre juste le besoin pour la logistique
                if ($produit->gere_imei) {
                    $besoinsImei[$produit->id] = ($besoinsImei[$produit->id] ?? 0) + $quantite;
                }

                $totalLigne = $prixUnitaire * $quantite;
                $sousTotal += $totalLigne;

                $lignesPreparees[] = [
                    'produit_id'    => $produit->id,
                    'quantite'      => $quantite,
                    'prix_unitaire' => $prixUnitaire,
                    'total'         => $totalLigne,
                ];
            }

            $remises = $this->remiseService->calculerRemises(
                $client,
                collect($lignesPreparees),
                $sousTotal
            );
            $totalRemise = $remises['total_remise'] ?? 0;

            $modeLivraison = strtoupper($data['mode_livraison'] ?? 'RETRAIT_MAGASIN');
            $fraisLivraison = $this->calculerFraisLivraison($modeLivraison, $sousTotal - $totalRemise);

            $commande = CommandeClient::create([
                'client_id' => $client->id,
                'statut' => 'EN_ATTENTE_PAIEMENT',
                'statut_logistique' => 'EN_ATTENTE',
                'sous_total' => $sousTotal,
                'remise' => $totalRemise,
                'frais_livraison' => $fraisLivraison,
                'total' => ($sousTotal - $totalRemise) + $fraisLivraison,
                'mode_livraison' => $modeLivraison,
                'adresse_livraison' => $data['adresse_livraison'] ?? null,
                'telephone_livraison' => $data['telephone_livraison'] ?? $client->telephone,
                'notes' => $data['notes'] ?? null,
                'metadata' => array_merge($data['metadata'] ?? [], [
                    // ✅ Besoins IMEI stockés pour la logistique, invisibles pour le client
                    'besoins_imei' => $besoinsImei,
                    'source' => 'ECOMMERCE',
                ]),
            ]);

            foreach ($lignesPreparees as $ligne) {
                LigneCommande::create([
                    'commande_id'   => $commande->id,
                    'produit_id'    => $ligne['produit_id'],
                    'quantite'      => $ligne['quantite'],
                    'prix_unitaire' => $ligne['prix_unitaire'],
                    'total'         => $ligne['total'],
                ]);
            }

            if (in_array($modeLivraison, ['DOMICILE', 'POINT_RELAIS'])) {
                $this->creerLivraison($commande, $client, $data);
            }

            return $commande->load(['lignes.produit', 'client', 'livraison']);
        });
    }

    // ================================================================
    // 2. VALIDER LE PAIEMENT
    // ================================================================
    // On vérifie juste la DISPONIBILITÉ GLOBALE des IMEI.
    // Le client ne voit rien de tout ça.
    // ================================================================

    public function validerPaiement(CommandeClient $commande, string $referencePaiement): void
    {
        DB::transaction(function () use ($commande, $referencePaiement) {
            $commande->load(['lignes.produit', 'client', 'succursaleAssignee.entite', 'livraison']);

            // ✅ Vérification interne des IMEI, invisible pour le client
            $besoinsImei = $commande->metadata['besoins_imei'] ?? [];
            
            if (!empty($besoinsImei)) {
                $disponibilite = $this->verifierDisponibiliteImeiGlobale($besoinsImei);
                
                if (!$disponibilite['suffisant']) {
                    throw new \Exception(
                        "Stock IMEI global insuffisant. " .
                        "Manquants: " . json_encode($disponibilite['manquants'])
                    );
                }
            }

            // ✅ Paiement validé normalement
            $commande->validerPaiement($referencePaiement);

            // ✅ Historique livraison
            if ($commande->livraison) {
                $commande->livraison->ajouterHistorique('PAYEE', 'Paiement confirmé.');
            }

            // ✅ Écritures comptables
            $entite = $commande->succursaleAssignee?->entite;
            if (!$entite) {
                throw new \Exception("Aucune entité comptable trouvée pour cette commande.");
            }

            foreach ($commande->lignes as $ligne) {
                $produit = $ligne->produit;
                if (!$produit) {
                    continue;
                }

                $montantProduit = ($produit->prix_vente ?? 0) * $ligne->quantite;
                if ($montantProduit <= 0) {
                    continue;
                }

                // Récupération du coût FIFO réel
                $mouvementSortie = MouvementStock::where('produit_id', $produit->id)
                    ->where('reference_document', $commande->reference)
                    ->whereIn('type_mouvement', ['SORTIE', 'TRANSFERT'])
                    ->latest('date_mouvement')
                    ->first();

                if ($mouvementSortie && $mouvementSortie->cout_unitaire > 0) {
                    $coutUnitaire = (float) $mouvementSortie->cout_unitaire;
                    Log::info('Coût FIFO commande récupéré', [
                        'produit' => $produit->nom,
                        'cout_unitaire' => $coutUnitaire,
                        'mouvement_id' => $mouvementSortie->mouvement_id
                    ]);
                } else {
                    $coutUnitaire = (float) ($produit->prix_achat ?? 0);
                    Log::warning('Coût FIFO absent commande', [
                        'produit' => $produit->nom,
                        'prix_achat' => $coutUnitaire
                    ]);
                }

                $montantDestockage = $coutUnitaire * $ligne->quantite;

                Log::info('Création écriture commande', [
                    'commande' => $commande->reference,
                    'produit' => $produit->nom,
                    'quantite' => $ligne->quantite,
                    'vente' => $montantProduit,
                    'cout_unitaire' => $coutUnitaire,
                    'cout_stock' => $montantDestockage
                ]);

                $result = $this->ecritureService->creerEcritureSimple([
                    'montant' => $montantProduit,
                    'entite_id' => $entite->id,
                    'succursale_id' => $commande->succursale_assignee_id,
                    'code' => 'FIT',
                    'operation_libelle' => 'Commande client',
                    'libelle' => 'Vente de ' . $produit->nom,
                    'date_ecriture' => now()->format('Y-m-d'),
                    'reference' => 'CMD-' . ($commande->reference ?? $commande->id) . '-' . $produit->id,
                    'auto_overrides' => [
                        [
                            'id_auto' => null,
                            'libelle' => 'Déstockage de ' . $produit->nom,
                            'use_default' => false,
                            'valeur' => $montantDestockage
                        ]
                    ]
                ]);

                if (isset($result['success']) && $result['success'] === false) {
                    throw new \Exception($result['message'] ?? 'Erreur création écriture comptable.');
                }
            }

            if ($commande->client) {
                $this->updateClientStats($commande->client, $commande->total);
            }
        });
    }

    // ================================================================
    // 3. ASSIGNER À UNE SUCCURSALE
    // ================================================================
    // Le logisticien assigne la commande à une succursale.
    // On vérifie que la succursale a assez d'IMEI, mais on ne les réserve pas encore.
    // ================================================================

    public function assignerCommande(CommandeClient $commande, int $succursaleId, int $utilisateurId): void
    {
        DB::transaction(function () use ($commande, $succursaleId, $utilisateurId) {
            try {
                $succursale = Succursale::findOrFail($succursaleId);
                $utilisateur = Utilisateur::findOrFail($utilisateurId);

                $commande->load('lignes.produit');

                // Recalcul des frais de livraison
                $modeLivraison = $commande->mode_livraison ?? 'DOMICILE';
                $montant = $commande->sous_total - ($commande->remise ?? 0);
                $fraisLivraison = $this->calculerFraisLivraison($modeLivraison, $montant, $succursaleId);

                $commande->update([
                    'frais_livraison' => $fraisLivraison,
                    'total' => $montant + $fraisLivraison
                ]);

                Log::info('Frais de livraison recalculés lors de l\'assignation', [
                    'commande_id' => $commande->id,
                    'succursale_id' => $succursaleId,
                    'mode_livraison' => $modeLivraison,
                    'ancien_frais' => $commande->getOriginal('frais_livraison'),
                    'nouveau_frais' => $fraisLivraison
                ]);

                // ✅ Vérifier que la succursale a assez d'IMEI
                foreach ($commande->lignes as $ligne) {
                    if ($ligne->produit->gere_imei) {
                        $disponibles = ProduitImei::where('produit_id', $ligne->produit_id)
                            ->where('succursale_id', $succursaleId)
                            ->where('statut', ProduitImei::STATUT_EN_STOCK)
                            ->count();

                        if ($disponibles < $ligne->quantite) {
                            throw new \Exception(
                                "Stock IMEI insuffisant dans la succursale {$succursale->nom} " .
                                "pour {$ligne->produit->nom}. " .
                                "Disponibles: {$disponibles}, Besoin: {$ligne->quantite}"
                            );
                        }

                        // ✅ On stocke juste le besoin en IMEI pour cette succursale
                        $ligne->metadata = array_merge($ligne->metadata ?? [], [
                            'quantite_imei_besoin' => $ligne->quantite,
                            'succursale_assigne' => $succursaleId,
                            'date_assignation' => now()->toDateTimeString(),
                        ]);
                        $ligne->save();
                    }
                }

                // Assignation de la succursale (inchangé)
                $commande->assignerSuccursale($succursale, $utilisateur);

                // ✅ Réservation du stock via la méthode métier
                foreach ($commande->lignes as $ligne) {
                    $stock = StockSuccursale::where('succursale_id', $succursaleId)
                        ->where('produit_id', $ligne->produit_id)
                        ->lockForUpdate()
                        ->first();

                    if (!$stock) {
                        throw new \Exception("Stock introuvable pour produit ID: {$ligne->produit_id}");
                    }

                    $stock->reserver($ligne->quantite);
                }

            } catch (\Throwable $e) {
                Log::error('ERREUR ASSIGNATION COMMANDE', [
                    'commande_id' => $commande->id,
                    'succursale_id' => $succursaleId,
                    'utilisateur_id' => $utilisateurId,
                    'message' => $e->getMessage()
                ]);
                throw $e;
            }
        });
    }

    // ================================================================
    // 4. RÉCUPÉRER LES IMEI DISPONIBLES POUR LA PRÉPARATION
    // ================================================================
    // Le logisticien voit la liste des IMEI disponibles dans sa succursale
    // pour qu'il puisse choisir ceux qu'il va expédier
    // ================================================================

    public function getImeisDisponiblesPourCommande(CommandeClient $commande, int $ligneId): array
    {
        $ligne = $commande->lignes()->with('produit')->findOrFail($ligneId);

        if (!$ligne->produit->gere_imei) {
            return ['imeis' => [], 'message' => 'Ce produit ne gère pas les IMEI'];
        }

        $succursaleId = $commande->succursale_assignee_id;
        if (!$succursaleId) {
            return ['imeis' => [], 'message' => 'La commande n\'est pas encore assignée à une succursale'];
        }

        // ✅ Récupérer tous les IMEI EN_STOCK dans cette succursale
        $imeis = ProduitImei::where('produit_id', $ligne->produit_id)
            ->where('succursale_id', $succursaleId)
            ->where('statut', ProduitImei::STATUT_EN_STOCK)
            ->with('lotStock')
            ->orderBy('lot_stock_id')  // FIFO
            ->orderBy('created_at')
            ->get();

        return [
            'imeis' => $imeis->map(function ($imei) {
                return [
                    'id' => $imei->id,
                    'numero_imei' => $imei->numero_imei,
                    'numero_serie' => $imei->numero_serie,
                    'lot_id' => $imei->lot_stock_id,
                    'prix_achat' => $imei->prix_achat,
                    'est_sous_garantie' => $imei->est_sous_garantie,
                ];
            })->toArray(),
            'quantite_disponible' => $imeis->count(),
            'quantite_besoin' => $ligne->quantite,
            'message' => 'IMEI disponibles pour sélection',
        ];
    }

    // ================================================================
    // 5. DÉMARRER LA PRÉPARATION - SÉLECTION MANUELLE DES IMEI
    // ================================================================
    // Le logisticien sélectionne MANUELLEMENT les IMEI qu'il va expédier
    // ================================================================

    public function demarrerPreparation(CommandeClient $commande, array $imeiSelections = []): void
    {
        DB::transaction(function () use ($commande, $imeiSelections) {
            $commande->load(['lignes.produit']);

            foreach ($commande->lignes as $ligne) {
                if ($ligne->produit->gere_imei) {
                    // ✅ Récupérer les IMEI sélectionnés pour cette ligne
                    $imeiIds = $imeiSelections[$ligne->id] ?? [];

                    if (empty($imeiIds) || count($imeiIds) !== $ligne->quantite) {
                        throw new \Exception(
                            "Vous devez sélectionner exactement {$ligne->quantite} IMEI " .
                            "pour {$ligne->produit->nom} (ligne #{$ligne->id})"
                        );
                    }

                    // ✅ Vérifier que les IMEI existent, sont EN_STOCK, et dans la bonne succursale
                    $imeis = ProduitImei::whereIn('id', $imeiIds)
                        ->where('produit_id', $ligne->produit_id)
                        ->where('succursale_id', $commande->succursale_assignee_id)
                        ->where('statut', ProduitImei::STATUT_EN_STOCK)
                        ->lockForUpdate()
                        ->get();

                    if ($imeis->count() !== $ligne->quantite) {
                        throw new \Exception(
                            "Certains IMEI sélectionnés pour {$ligne->produit->nom} " .
                            "ne sont plus disponibles ou ne sont pas dans la bonne succursale."
                        );
                    }

                    // ✅ Réserver individuellement les IMEI sélectionnés
                    foreach ($imeis as $imei) {
                        $imei->statut = ProduitImei::STATUT_RESERVE;
                        $imei->commande_id = $commande->id;
                        $imei->save();
                    }

                    // ✅ Marquer en préparation
                    foreach ($imeis as $imei) {
                        $imei->statut = ProduitImei::STATUT_EN_PREPARATION;
                        $imei->save();
                    }

                    $ligne->metadata = array_merge($ligne->metadata ?? [], [
                        'imeis_selectionnes' => $imeis->pluck('id')->toArray(),
                        'imeis_en_preparation' => $imeis->pluck('id')->toArray(),
                        'date_debut_preparation' => now()->toDateTimeString(),
                    ]);
                    $ligne->save();
                }
            }

            $commande->demarrerPreparation();
        });
    }

    // ================================================================
    // 6. MARQUER COMME PRÊTE
    // ================================================================

    public function marquerPrete(CommandeClient $commande): void
    {
        DB::transaction(function () use ($commande) {
            $commande->marquerPrete();
        });
    }

    // ================================================================
    // 7. EXPÉDIER LA COMMANDE
    // ================================================================

    public function expedierCommande(CommandeClient $commande): void
    {
        DB::transaction(function () use ($commande) {
            $commande->load(['lignes.produit', 'succursaleAssignee']);
            $succursaleId = $commande->succursale_assignee_id;

            foreach ($commande->lignes as $ligne) {
                $stock = StockSuccursale::where('succursale_id', $succursaleId)
                    ->where('produit_id', $ligne->produit_id)
                    ->lockForUpdate()
                    ->first();

                if (!$stock) {
                    throw new \Exception("Stock introuvable pour {$ligne->produit->nom}");
                }

                if ($ligne->produit->gere_imei) {
                    $imeiIds = $ligne->metadata['imeis_en_preparation'] ?? [];

                    $imeis = ProduitImei::whereIn('id', $imeiIds)
                        ->where('statut', ProduitImei::STATUT_EN_PREPARATION)
                        ->where('commande_id', $commande->id)
                        ->get();

                    if ($imeis->count() !== $ligne->quantite) {
                        throw new \Exception("IMEI en préparation invalides pour {$ligne->produit->nom}");
                    }

                    // 1. Sortie FIFO (inchangée)
                    $mouvementSortie = $stock->expedier($ligne->quantite, [
                        'demande_parent_id' => $commande->id,
                        'reference_document' => $commande->reference,
                        'raison' => 'Expédition commande ' . $commande->reference,
                        'type_expedition' => 'COMMANDE_EN_LIGNE',
                        'responsable_id' => auth()->id(),
                        'metadata' => [
                            'source' => 'ECOMMERCE',
                            'commande_id' => $commande->id,
                            'ligne_commande_id' => $ligne->id,
                        ],
                    ]);

                    // 2. Vérification IMEI ↔ lots consommés
                    $this->imeiService->verifierImeisApresSortie($imeiIds, $mouvementSortie);

                    // 3. Marquer IMEI comme EXPEDIES
                    $this->imeiService->marquerImeisExpedies(
                        $imeiIds,
                        $mouvementSortie,
                        $commande->id
                    );

                    $ligne->metadata = array_merge($ligne->metadata ?? [], [
                        'imeis_expedies' => $imeiIds,
                        'mouvement_sortie_id' => $mouvementSortie->mouvement_id,
                        'date_expedition' => now()->toDateTimeString(),
                    ]);
                    $ligne->save();

                } else {
                    // Produit normal
                    $stock->expedier($ligne->quantite, [
                        'demande_parent_id' => $commande->id,
                        'reference_document' => $commande->reference,
                        'raison' => 'Expédition commande ' . $commande->reference,
                        'responsable_id' => auth()->id(),
                    ]);
                }
            }

            $commande->expedier();
        });
    }

    // ================================================================
    // 8. MARQUER COMME LIVRÉE
    // ================================================================

    public function marquerLivree(CommandeClient $commande): void
    {
        DB::transaction(function () use ($commande) {
            $commande->load(['lignes.produit']);

            foreach ($commande->lignes as $ligne) {
                if ($ligne->produit->gere_imei) {
                    $imeiIds = $ligne->metadata['imeis_expedies'] ?? [];
                    
                    // ✅ Marquer les IMEI comme livrés (VENDU)
                    $this->imeiService->marquerImeisLivres($imeiIds, $commande->id);
                    
                    $ligne->metadata = array_merge($ligne->metadata ?? [], [
                        'imeis_livres' => $imeiIds,
                        'date_livraison' => now()->toDateTimeString(),
                    ]);
                    $ligne->save();
                }
            }

            $commande->marquerLivree();
        });
    }

    // ================================================================
    // 9. ANNULER UNE COMMANDE
    // ================================================================

    public function annulerCommande(CommandeClient $commande, ?string $motif = null): void
    {
        DB::transaction(function () use ($commande, $motif) {
            $commande->load(['lignes.produit']);

            foreach ($commande->lignes as $ligne) {
                if ($ligne->produit->gere_imei) {
                    // Récupérer tous les IMEI de la commande
                    $imeis = ProduitImei::where('commande_id', $commande->id)
                        ->whereIn('statut', [
                            ProduitImei::STATUT_RESERVE,
                            ProduitImei::STATUT_EN_PREPARATION,
                            ProduitImei::STATUT_EXPEDIE,
                        ])
                        ->get();

                    foreach ($imeis as $imei) {
                        if (in_array($imei->statut, [ProduitImei::STATUT_RESERVE, ProduitImei::STATUT_EN_PREPARATION])) {
                            // ✅ Remettre en stock
                            $imei->statut = ProduitImei::STATUT_EN_STOCK;
                            $imei->commande_id = null;
                        } else {
                            // Expédié mais annulé avant livraison → Annulé
                            $imei->statut = ProduitImei::STATUT_ANNULE;
                        }
                        $imei->save();
                    }

                    // Libérer la réservation quantitative
                    $stock = StockSuccursale::where('succursale_id', $commande->succursale_assignee_id)
                        ->where('produit_id', $ligne->produit_id)
                        ->first();

                    if ($stock && $stock->quantite_reserve >= $ligne->quantite) {
                        $stock->libererReservation($ligne->quantite);
                    }
                }
            }

            $commande->annuler($motif ?? 'Annulation par le client');
        });
    }

    // ================================================================
    // 10. STATISTIQUES CLIENT
    // ================================================================

    public function getStatistiquesClient(Client $client): array
    {
        $commandes = $client->commandes()->get();
        $paiements = $client->paiements()->latest()->get();
        $recentes = $client->commandes()
            ->with(['lignes.produit', 'livraison', 'succursaleAssignee'])
            ->latest()
            ->take(3)
            ->get();

        return [
            'total_commandes' => $commandes->count(),
            'commandes_en_cours' => $commandes->whereIn('statut', [
                'PAYEE', 'EN_ATTENTE_LOGISTIQUE', 'ASSIGNEE',
                'EN_PREPARATION', 'PRETE', 'EN_LIVRAISON'
            ])->count(),
            'commandes_livrees' => $commandes->where('statut', 'LIVREE')->count(),
            'commandes_annulees' => $commandes->where('statut', 'ANNULEE')->count(),
            'total_depenses' => $commandes->sum('total'),
            'derniere_commande' => optional($recentes->first())->created_at?->format('Y-m-d H:i:s'),

            'total_paiements' => $paiements->count(),
            'paiements_valides' => $paiements->where('statut', 'PAYE')->count(),
            'paiements_en_attente' => $paiements->where('statut', 'EN_ATTENTE')->count(),
            'paiements_rembourses' => $paiements->where('statut', 'REMBOURSE')->count(),
            'montant_paye' => $paiements->where('statut', 'PAYE')->sum('montant'),
            'montant_rembourse' => $paiements->where('statut', 'REMBOURSE')->sum('montant'),
            'dernier_paiement' => optional($paiements->first())->created_at?->format('Y-m-d H:i:s'),

            'commandes_recentes' => $recentes->map(function ($c) {
                return [
                    'id' => $c->id,
                    'numero_commande' => $c->reference ?? $c->id,
                    'date_commande' => $c->created_at->format('d/m/Y H:i'),
                    'montant_total' => $c->total,
                    'statut_commande' => $c->statut,
                    'statut_logistique' => $c->statut_logistique,
                    'succursale' => $c->succursaleAssignee?->nom,
                    'nombre_articles' => $c->lignes->sum('quantite'),
                ];
            }),

            'paiements_recents' => $paiements->take(5)->map(function ($p) {
                return [
                    'reference' => $p->reference,
                    'montant' => $p->montant,
                    'mode_paiement' => $p->mode_label,
                    'statut' => $p->statut,
                    'date' => $p->created_at->format('d/m/Y H:i'),
                    'reference_externe' => $p->reference_externe,
                ];
            }),
        ];
    }

    // ================================================================
    // MÉTHODES PRIVÉES
    // ================================================================

    /**
     * Calculer les frais de livraison - OPTIMISÉ PAR SUCCURSALE
     */
    private function calculerFraisLivraison(string $modeLivraison, float $montant, ?int $succursaleId = null): float
    {
        if ($modeLivraison === 'RETRAIT_MAGASIN') {
            return 0;
        }

        $query = FraisLivraison::actifs()->where('mode_livraison', $modeLivraison);

        if ($succursaleId) {
            $frais = (clone $query)->where('succursale_id', $succursaleId)->first();
            if ($frais) {
                return $this->calculerMontantFrais($frais, $montant);
            }
        }

        $fraisParDefaut = (clone $query)->whereNull('succursale_id')->first();
        if ($fraisParDefaut) {
            return $this->calculerMontantFrais($fraisParDefaut, $montant);
        }

        return 0;
    }

    /**
     * Calculer le montant des frais selon le type
     */
    private function calculerMontantFrais(FraisLivraison $frais, float $montant): float
    {
        if ($frais->type_calcul === 'FIXE') {
            return (float) $frais->valeur;
        }

        if ($frais->type_calcul === 'POURCENTAGE') {
            return round(($montant * $frais->valeur) / 100, 2);
        }

        return 0;
    }

    /**
     * Vérifier la disponibilité GLOBALE des IMEI (toutes succursales)
     * Utilisé en interne, jamais exposé au client
     */
    private function verifierDisponibiliteImeiGlobale(array $besoins): array
    {
        $result = [
            'suffisant' => true,
            'manquants' => [],
            'disponibles' => [],
        ];

        foreach ($besoins as $produitId => $quantite) {
            $disponibles = ProduitImei::where('produit_id', $produitId)
                ->where('statut', ProduitImei::STATUT_EN_STOCK)
                ->count();

            if ($disponibles < $quantite) {
                $result['suffisant'] = false;
                $result['manquants'][$produitId] = [
                    'besoin' => $quantite,
                    'disponible' => $disponibles,
                    'manquant' => $quantite - $disponibles,
                ];
            }

            $result['disponibles'][$produitId] = $disponibles;
        }

        return $result;
    }

    /**
     * Créer la livraison associée
     */
    private function creerLivraison(CommandeClient $commande, Client $client, array $data): void
    {
        $adresse = $commande->adresse_livraison;

        if (empty($adresse)) {
            $adresse = ['adresse' => $client->adresse ?? 'Non spécifiée'];
        }

        $livraison = $commande->livraison()->create([
            'reference' => 'LIV' . now()->format('YmdHis') . rand(100, 999),
            'client_id' => $client->id,
            'statut' => 'EN_ATTENTE',
            'adresse_livraison' => $adresse,
            'nom_destinataire' => $client->nom,
            'telephone_destinataire' => $commande->telephone_livraison ?? $client->telephone,
            'mode_livraison' => $commande->mode_livraison,
            'point_relais_id' => $commande->point_relais_id,
            'date_livraison_estimee' => now()->addDays(3),
        ]);

        $livraison->ajouterHistorique('EN_ATTENTE_PAIEMENT', 'Commande créée et en attente du paiement');
    }

    /**
     * Mettre à jour les statistiques du client
     */
    private function updateClientStats(Client $client, float $montant): void
    {
        $client->increment('nombre_achats');
        $client->increment('total_achats', $montant);
        $client->update(['date_dernier_achat' => now()]);
    }
}