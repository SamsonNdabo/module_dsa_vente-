<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Facture;
use App\Models\LigneFacture;
use App\Models\Produit;
use App\Models\ProduitImei;
use App\Models\StockSuccursale;
use App\Models\Caisses;
use App\Models\Paiement;
use App\Models\MouvementStock;
use App\Services\ImeiService;
use App\Services\EcritureService;
use App\Services\RemiseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;

class POSService
{
    protected RemiseService $remiseService;
    protected KelPayService $kelPayService;
    protected EcritureService $ecritureService;
    protected ImeiService $imeiService;

    public function __construct(
        RemiseService $remiseService,
        KelPayService $kelPayService,
        EcritureService $ecritureService,
        ImeiService $imeiService
    ) {
        $this->remiseService = $remiseService;
        $this->kelPayService = $kelPayService;
        $this->ecritureService = $ecritureService;
        $this->imeiService = $imeiService;
    }

    // ================================================================
    // INITIER UNE VENTE
    // ================================================================

    public function initierVente(array $data): array
    {
        $user = auth()->user();
        if (!$user) {
            throw new \Exception('Utilisateur non authentifié.');
        }

        return DB::transaction(function () use ($data, $user) {
            $succursaleId = $user->succursale_id ?? $data['succursale_id'] ?? null;
            if (!$succursaleId) {
                throw new \Exception('Aucune succursale associée à cet utilisateur.');
            }

            $caisse = Caisses::where('id', $data['caisse_id'])
                ->where('succursale_id', $succursaleId)
                ->where('statut', 'OUVERTE')
                ->first();

            if (!$caisse) {
                throw new \Exception('La caisse doit être ouverte et appartenir à votre succursale.');
            }

            $telephone = $data['telephone'] ?? null;
            if (is_array($telephone)) {
                $telephone = $telephone['telephone'] ?? $telephone['numero'] ?? $telephone[0] ?? null;
            }
            if (!$telephone && isset($data['client']) && is_array($data['client'])) {
                $telephone = $data['client']['telephone'] ?? null;
            }

            $client = $this->getClient($telephone, $data['nom_client'] ?? null);
            if (!$client) {
                $client = $this->createClient($telephone, $data['nom_client'] ?? null);
            }

            $facture = Facture::create([
                'reference' => $this->genererReferenceFacture(),
                'client_id' => $client->id,
                'utilisateur_id' => $user->id,
                'succursale_id' => $succursaleId,
                'caisse_id' => $caisse->id,
                'date_facture' => now(),
                'statut' => 'BROUILLON',
                'type_facture' => 'VENTE',
                'sous_total' => 0,
                'remise_globale' => 0,
                'total_ht' => 0,
                'taux_tva' => 16.00,
                'total_tva' => 0,
                'total_ttc' => 0,
                'montant_regle' => 0,
                'montant_du' => 0,
                'notes' => $data['notes'] ?? null,
                'metadata' => [
                    'client' => $client->only(['id', 'nom_complet', 'telephone', 'email']),
                    'initie_par' => $user->id,
                    'date_initiation' => now()->toDateTimeString()
                ]
            ]);

            return [
                'vente_id' => $facture->id,
                'reference' => $facture->reference,
                'client' => $client,
                'facture' => $facture,
            ];
        });
    }

    /**
     * Vérifier un produit avant ajout au panier
     */
    public function verifierProduit(int $produitId, int $succursaleId, int $quantite): array
    {
        $produit = Produit::where('id', $produitId)
            ->where('actif', 1)
            ->with(['categorie', 'devise'])
            ->first();

        if (!$produit) {
            throw new \Exception('Produit non trouvé ou désactivé.');
        }

        $stock = StockSuccursale::where('succursale_id', $succursaleId)
            ->where('produit_id', $produitId)
            ->first();

        if (!$stock) {
            return [
                'produit' => $this->formatProduit($produit),
                'stock' => [
                    'disponible' => 0,
                    'reserve' => 0,
                    'quantite_maximale' => 0,
                    'est_en_stock' => false,
                    'est_en_alerte' => true,
                    'est_en_rupture' => true,
                ],
                'disponible' => false,
            ];
        }

        $quantiteDisponible = $stock->quantite_disponible - $stock->quantite_reserve;

        return [
            'produit' => $this->formatProduit($produit),
            'stock' => [
                'disponible' => max(0, $quantiteDisponible),
                'reserve' => $stock->quantite_reserve,
                'quantite_maximale' => $stock->quantite_maximale,
                'est_en_stock' => $quantiteDisponible > 0,
                'est_en_alerte' => $quantiteDisponible <= $stock->quantite_minimale,
                'est_en_rupture' => $quantiteDisponible <= 0,
            ],
            'disponible' => $quantiteDisponible >= $quantite,
            'suffisant_pour' => $quantite,
        ];
    }

    /**
     * Vérifier un produit par code-barres (scan)
     */
    public function verifierParScan(string $codeBarre, int $succursaleId): array
    {
        $produit = $this->trouverProduitParCodeBarre($codeBarre);

        if (!$produit) {
            throw new \Exception('Produit non trouvé avec ce code-barres.');
        }

        return $this->verifierProduit($produit->id, $succursaleId, 1);
    }

    /**
     * Rechercher des produits (autocomplétion)
     */
    public function rechercherProduits(string $q, int $succursaleId, int $limit = 10): array
    {
        $produits = Produit::where('actif', 1)
            ->where(function ($query) use ($q) {
                $query->where('nom', 'LIKE', "%{$q}%")
                    ->orWhere('code_barre', 'LIKE', "%{$q}%")
                    ->orWhere('marque', 'LIKE', "%{$q}%")
                    ->orWhere('modele', 'LIKE', "%{$q}%")
                    ->orWhere('description', 'LIKE', "%{$q}%");
            })
            ->with(['categorie', 'devise'])
            ->limit($limit)
            ->get();

        $stockIds = $produits->pluck('id')->toArray();
        $stocks = StockSuccursale::where('succursale_id', $succursaleId)
            ->whereIn('produit_id', $stockIds)
            ->get()
            ->keyBy('produit_id');

        return $produits->map(function ($produit) use ($stocks) {
            $stock = $stocks->get($produit->id);
            $quantiteDisponible = $stock ? ($stock->quantite_disponible - $stock->quantite_reserve) : 0;

            return array_merge($this->formatProduit($produit), [
                'stock_disponible' => max(0, $quantiteDisponible),
                'est_en_rupture' => $quantiteDisponible <= 0,
                'est_en_alerte' => $stock ? $quantiteDisponible <= $stock->quantite_minimale : true,
            ]);
        })->toArray();
    }

    /**
     * Finaliser la vente avec le panier envoyé par le frontend
     */
    public function finaliserVente(int $venteId, array $data): array
    {
        $user = auth()->user();
        if (!$user) {
            throw new \Exception('Utilisateur non authentifié.');
        }

        return DB::transaction(function () use ($venteId, $data, $user) {
            $facture = Facture::with(['client', 'lignes'])
                ->where('id', $venteId)
                ->where('succursale_id', $user->succursale_id)
                ->where('statut', 'BROUILLON')
                ->lockForUpdate()
                ->firstOrFail();

            $panier = $data['panier'] ?? [];
            if (empty($panier)) {
                throw new \Exception('Le panier est vide.');
            }

            $lignesData = [];
            $sousTotal = 0;
            $totalTTC = 0;

            foreach ($panier as &$item) {
                $produit = Produit::findOrFail($item['produit_id']);
                $stock = StockSuccursale::where('succursale_id', $facture->succursale_id)
                    ->where('produit_id', $item['produit_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                $dispo = $stock->quantite_disponible - $stock->quantite_reserve;
                if ($dispo < $item['quantite']) {
                    throw new \Exception("Stock insuffisant pour {$produit->nom}.");
                }

                // ============================================================
                // RÉSERVATION IMEI
                // ============================================================
                if ($produit->gere_imei) {
                    $imeiIds = $item['imei_ids'] ?? [];
                    
                    if (!is_array($imeiIds)) {
                        $imeiIds = [];
                    }
                    
                    if (!empty($imeiIds)) {
                        $reservedIds = $this->imeiService->reserverImeisPourFacture(
                            $produit->id,
                            $facture->succursale_id,
                            $item['quantite'],
                            $facture->id,
                            $imeiIds
                        );
                        
                        $item['imei_ids'] = $reservedIds;
                    } else {
                        $item['imei_ids'] = [];
                    }
                }

                // Réservation quantitative
                $stock->quantite_reserve += $item['quantite'];
                $stock->save();

                $prix = $produit->prix_vente_TTC ?? $produit->prix_vente;
                $totalLigne = $prix * $item['quantite'];

                $lignesData[] = [
                    'produit' => $produit,
                    'stock' => $stock,
                    'quantite' => $item['quantite'],
                    'prix' => $prix,
                    'total_ligne' => $totalLigne,
                    'imei_ids' => $item['imei_ids'] ?? null,
                ];

                $sousTotal += $totalLigne;
                $totalTTC += $totalLigne;
            }

            // Remises
            $remiseGlobale = 0;
            if ($facture->client) {
                $lignesRemise = collect($lignesData)->map(function ($l) {
                    return [
                        'produit_id' => $l['produit']->id,
                        'quantite' => $l['quantite'],
                        'prix_unitaire_ht' => $l['prix'] / 1.16,
                    ];
                });

                $remises = $this->remiseService->calculerRemises(
                    $facture->client,
                    $lignesRemise,
                    $sousTotal
                );
                $remiseGlobale = $remises['total_remise'] ?? 0;
                $totalTTC = max(0, $totalTTC - $remiseGlobale);
            }

            // Création des lignes de facture
            foreach ($lignesData as $ligne) {
                $ligneFacture = LigneFacture::create([
                    'facture_id' => $facture->id,
                    'produit_id' => $ligne['produit']->id,
                    'stock_succursale_id' => $ligne['stock']->id,
                    'quantite' => $ligne['quantite'],
                    'prix_unitaire_ht' => $ligne['prix'] / 1.16,
                    'prix_unitaire_ttc' => $ligne['prix'],
                    'total_ht' => $ligne['total_ligne'] / 1.16,
                    'total_ttc' => $ligne['total_ligne'],
                    'imeis_utilises' => $ligne['imei_ids'] ?? [],
                ]);
            }

            $deviseId = $lignesData[0]['produit']->devise_id ?? 1;
            $totalHT = $totalTTC / 1.16;
            $totalTVA = $totalTTC - $totalHT;

            $facture->update([
                'devise_id' => $deviseId,
                'sous_total' => $sousTotal,
                'remise_globale' => $remiseGlobale,
                'total_ht' => round($totalHT, 2),
                'total_tva' => round($totalTVA, 2),
                'total_ttc' => round($totalTTC, 2),
                'montant_du' => round($totalTTC, 2),
                'statut' => 'VALIDEE',
            ]);

            return [
                'facture' => $facture->load('lignes.produit'),
                'total' => $totalTTC,
                'message' => 'Vente finalisée et stock réservé'
            ];
        });
    }

    // ================================================================
    // PAYER UNE VENTE
    // ================================================================
    
    public function payerVente(int $venteId, array $data): array
    {
        $user = auth()->user();
    
        if (!$user) {
            throw new \Exception('Utilisateur non authentifié.');
        }
    
        return DB::transaction(function () use ($venteId, $data, $user) {
    
            $facture = Facture::query()
                ->where('id', $venteId)
                ->where('succursale_id', $user->succursale_id)
                ->lockForUpdate()
                ->firstOrFail();
    
            if ($facture->statut === 'PAYEE') {
                throw new \Exception('Cette facture est déjà payée.');
            }
    
            if ($facture->statut === 'ANNULEE') {
                throw new \Exception('Cette facture est annulée.');
            }
    
            if (!in_array($facture->statut, ['VALIDEE', 'EN_ATTENTE_PAIEMENT'], true)) {
                throw new \Exception(
                    "La facture n'est pas dans un état payable. " .
                    "Statut actuel : {$facture->statut}"
                );
            }
    
            $modePaiement = strtoupper(trim($data['mode_paiement'] ?? ''));
    
            if ($modePaiement === '') {
                throw new \Exception('Le mode de paiement est obligatoire.');
            }
    
            if ($modePaiement === 'ESPECES') {
    
                $paiementEnCours = Paiement::where('facture_id', $facture->id)
                    ->whereIn('statut', ['EN_COURS', 'EN_ATTENTE'])
                    ->lockForUpdate()
                    ->first();
    
                if ($paiementEnCours) {
                    throw new \Exception(
                        'Un paiement Mobile Money est déjà en attente. ' .
                        'Le client doit confirmer ou annuler ce paiement avant de continuer.'
                    );
                }
    
                return $this->validerPaiementEspeces($facture, $data, $user);
            }
    
            if ($modePaiement === 'MOBILE_MONEY') {
                return $this->initierPaiementMobileMoney($facture, $data, $user);
            }
    
            throw new \Exception("Mode de paiement invalide : {$modePaiement}");
        });
    }

    /**
     * ================================================================
     * VÉRIFIER LE PAIEMENT MOBILE MONEY
     * ================================================================
     */
    public function verifierPaiement(int $venteId): array
    {
        $user = auth()->user();
    
        if (!$user) {
            throw new \Exception('Utilisateur non authentifié.');
        }
    
        $facture = Facture::with([
            'lignes.produit',
            'client',
            'succursale.entite'
        ])
            ->where('id', $venteId)
            ->where('succursale_id', $user->succursale_id)
            ->firstOrFail();
    
        if ($facture->statut === 'PAYEE') {
            return [
                'success' => true,
                'statut' => 'PAYEE',
                'message' => 'Cette facture est déjà payée.',
                'facture' => $facture,
            ];
        }
    
        if ($facture->statut === 'ANNULEE') {
            throw new \Exception('Cette facture a été annulée.');
        }
    
        if ($facture->statut !== 'EN_ATTENTE_PAIEMENT') {
            throw new \Exception(
                "Cette facture n'est pas en attente d'un paiement Mobile Money. " .
                "Statut actuel : {$facture->statut}"
            );
        }
    
        $paiement = Paiement::where('facture_id', $facture->id)
            ->where('mode_paiement', 'MOBILE_MONEY')
            ->whereIn('statut', ['EN_COURS', 'EN_ATTENTE'])
            ->latest('id')
            ->first();
    
        if (!$paiement) {
            throw new \Exception('Aucun paiement Mobile Money en attente pour cette facture.');
        }
    
        $transactionId = $paiement->reference_externe ?? ($facture->metadata['transaction_id'] ?? null);
    
        if (!$transactionId) {
            throw new \Exception('Transaction KelPay introuvable pour ce paiement.');
        }
    
        try {
            $response = $this->kelPayService->verifierStatut((string) $transactionId);
        } catch (\Throwable $e) {
            Log::error('Erreur vérification paiement Mobile Money', [
                'facture_id' => $facture->id,
                'paiement_id' => $paiement->id,
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);
    
            return [
                'success' => false,
                'statut' => 'EN_ATTENTE',
                'message' => 'Impossible de vérifier le paiement pour le moment.',
            ];
        }
    
        Log::info('Réponse vérification KelPay POS', [
            'facture_id' => $facture->id,
            'paiement_id' => $paiement->id,
            'transaction_id' => $transactionId,
            'response' => $response,
        ]);
    
        $statutKelPay = strtoupper(trim((string) ($response['transactionstatus'] ?? '')));
    
        if ($statutKelPay === '') {
            Log::warning('KelPay ne retourne aucun transactionstatus', [
                'facture_id' => $facture->id,
                'paiement_id' => $paiement->id,
                'transaction_id' => $transactionId,
                'response' => $response,
            ]);
    
            return [
                'success' => false,
                'statut' => 'EN_ATTENTE',
                'message' => 'Le paiement est toujours en attente de confirmation.',
                'data' => $response,
            ];
        }
    
        if ($statutKelPay === 'DELIVERED') {
            Log::info('KelPay confirme DELIVERED - finalisation autorisée', [
                'facture_id' => $facture->id,
                'paiement_id' => $paiement->id,
                'transaction_id' => $transactionId,
                'statut_kelpay' => $statutKelPay,
            ]);
    
            return $this->finaliserPaiementMobileMoney($facture, $response, (string) $transactionId, $user);
        }
    
        if (in_array($statutKelPay, [
            'PENDING', 'PROCESSING', 'INITIATED', 'INITIALIZED', 'WAITING', 'WAITING_FOR_CONFIRMATION', 'CREATED'
        ], true)) {
    
            $paiement->update([
                'statut' => 'EN_ATTENTE',
                'details_paiement' => array_merge(
                    $paiement->details_paiement ?? [],
                    [
                        'last_kelpay_status' => $statutKelPay,
                        'last_status_check' => now()->toDateTimeString(),
                        'last_kelpay_response' => $response,
                    ]
                ),
            ]);
    
            return [
                'success' => false,
                'statut' => 'EN_ATTENTE',
                'message' => 'Le client doit encore confirmer le paiement sur son téléphone.',
                'transaction_id' => $transactionId,
                'kelpay_status' => $statutKelPay,
                'data' => $response,
            ];
        }
    
        if (in_array($statutKelPay, [
            'FAILED', 'FAIL', 'EXPIRED', 'CANCELLED', 'CANCELED', 'REJECTED', 'DECLINED'
        ], true)) {
    
            $paiement->update([
                'statut' => 'ECHOUE',
                'details_paiement' => array_merge(
                    $paiement->details_paiement ?? [],
                    [
                        'last_kelpay_status' => $statutKelPay,
                        'date_echec' => now()->toDateTimeString(),
                        'kelpay_response' => $response,
                    ]
                ),
            ]);
    
            return [
                'success' => false,
                'statut' => 'ECHOUE',
                'message' => 'Le paiement Mobile Money a échoué, été annulé ou refusé.',
                'transaction_id' => $transactionId,
                'kelpay_status' => $statutKelPay,
                'retry_allowed' => true,
                'data' => $response,
            ];
        }
    
        Log::warning('Statut KelPay inconnu - vente maintenue en attente', [
            'facture_id' => $facture->id,
            'paiement_id' => $paiement->id,
            'transaction_id' => $transactionId,
            'statut_kelpay' => $statutKelPay,
            'response' => $response,
        ]);
    
        return [
            'success' => false,
            'statut' => 'EN_ATTENTE',
            'message' => 'Le statut du paiement n\'est pas encore confirmé par KelPay.',
            'transaction_id' => $transactionId,
            'kelpay_status' => $statutKelPay,
            'data' => $response,
        ];
    }

    protected function validerPaiementEspeces(Facture $facture, array $data, $user): array
    {
        return DB::transaction(function () use ($facture, $data, $user) {
            
            $montant = (float) ($data['montant'] ?? $facture->total_ttc);
            $totalTTC = (float) $facture->total_ttc;
            
            // ✅ Tolérance de 0.01 pour les erreurs d'arrondi
            if ($montant < $totalTTC - 0.01) {
                throw new \Exception("Le montant payé est inférieur au total.");
            }
            
            $montant = max($montant, $totalTTC);
    
            // ============================================================
            // SORTIE DE STOCK AVEC IMEI
            // ============================================================
            $this->appliquerSortieStock($facture);
    
            // ✅ RECHARGER COMPLÈTEMENT LA FACTURE avec les relations
            $facture = Facture::with(['succursale.entite', 'client'])
                ->find($facture->id);
            
            // ✅ Si la succursale est toujours null, on la récupère manuellement
            if (!$facture->succursale) {
                $succursale = \App\Models\Succursale::with('entite')
                    ->find($facture->succursale_id);
                $facture->setRelation('succursale', $succursale);
            }
    
            $paiementData = [
                'operation_libelle' => 'VENTE ' . $facture->reference,
                'mode_paiement' => $data['mode_paiement'] ?? 'ESPECES',
                'montant' => $montant,
                'reference_externe' => $data['reference_externe'] ?? null,
                'details_paiement' => $data['details_paiement'] ?? null,
                'notes' => $data['notes'] ?? null,
            ];
            $paiement = $this->creerPaiement($facture, $paiementData);
    
            // ✅ CRÉDITER LA CAISSE (passer un array)
            $this->validerEtCrediterCaisse($facture, ['montant' => $montant], $user);
    
            if ($facture->client) {
                $this->updateClientStats($facture->client, $facture->total_ttc);
            }
    
            $facture->update([
                'statut' => 'PAYEE',
                'date_paiement' => now(),
                'montant_regle' => $montant,
                'montant_du' => 0,
            ]);
    
            return [
                'statut' => 'PAYEE',
                'facture' => $facture->fresh(['lignes.produit', 'client']),
                'paiement' => $paiement,
                'message' => 'Vente payée',
            ];
        });
    }
    /**
     * ================================================================
     * INITIER UN PAIEMENT MOBILE MONEY
     * ================================================================
     */
    protected function initierPaiementMobileMoney(
        Facture $facture,
        array $data,
        $user
    ): array {
    
        $paiementEnCours = Paiement::where('facture_id', $facture->id)
            ->whereIn('statut', ['EN_COURS', 'EN_ATTENTE'])
            ->lockForUpdate()
            ->first();
    
        if ($paiementEnCours) {
            if ($paiementEnCours->statut === 'EN_ATTENTE') {
                return [
                    'success' => true,
                    'statut' => 'EN_ATTENTE',
                    'transaction_id' => $paiementEnCours->reference_externe,
                    'message' => 'Une demande de paiement est déjà en attente.',
                    'paiement' => $paiementEnCours,
                    'facture' => $facture,
                ];
            }
            throw new \Exception('Un paiement Mobile Money est déjà en cours.');
        }
    
        $montant = (float) $facture->total_ttc;
        if ($montant <= 0) {
            throw new \Exception('Le montant de la facture est invalide.');
        }
    
        $numeroMobile = $data['numero_mobile'] ?? $data['telephone'] ?? null;
    
        if (is_array($numeroMobile)) {
            $numeroMobile = $numeroMobile['telephone'] ?? $numeroMobile['numero'] ?? $numeroMobile['phone'] ?? $numeroMobile[0] ?? null;
        }
    
        $numeroMobile = is_string($numeroMobile) ? trim($numeroMobile) : $numeroMobile;
    
        if (empty($numeroMobile)) {
            if ($facture->client && $facture->client->telephone) {
                $numeroMobile = $facture->client->telephone;
            } else {
                throw new \Exception('Le numéro de téléphone du client est obligatoire pour Mobile Money.');
            }
        }
    
        // ✅ VÉRIFIER LA DISPONIBILITÉ DES IMEI
        $facture->load(['lignes.produit']);
        foreach ($facture->lignes as $ligne) {
            if ($ligne->produit->gere_imei) {
                $imeiIds = $ligne->imeis_utilises ?? [];
                if (empty($imeiIds)) {
                    $disponibles = ProduitImei::where('produit_id', $ligne->produit_id)
                        ->where('succursale_id', $facture->succursale_id)
                        ->where('statut', ProduitImei::STATUT_EN_STOCK)
                        ->count();
                    
                    if ($disponibles < $ligne->quantite) {
                        throw new \Exception(
                            "Stock IMEI insuffisant pour {$ligne->produit->nom}. " .
                            "Disponibles: {$disponibles}, Besoin: {$ligne->quantite}"
                        );
                    }
                }
            }
        }
    
        $paymentReference = Paiement::genererReference();
    
        $paiement = Paiement::create([
            'reference' => $paymentReference,
            'facture_id' => $facture->id,
            'client_id' => $facture->client_id,
            'mode_paiement' => 'MOBILE_MONEY',
            'montant' => $montant,
            'statut' => 'EN_COURS',
            'details_paiement' => [
                'numero_mobile' => $numeroMobile,
                'date_initiation' => now()->toDateTimeString(),
                'initie_par' => $user->id,
            ],
        ]);
    
        try {
            $response = $this->kelPayService->payer(
                $paymentReference,
                $numeroMobile,
                $montant,
                'USD',
                'Paiement vente ' . $facture->reference
            );
        } catch (\Throwable $e) {
            $paiement->update([
                'statut' => 'ECHOUE',
                'details_paiement' => array_merge(
                    $paiement->details_paiement ?? [],
                    ['error' => $e->getMessage(), 'date_echec' => now()->toDateTimeString()]
                ),
            ]);
    
            Log::error('Erreur initiation Mobile Money POS', [
                'facture_id' => $facture->id,
                'paiement_id' => $paiement->id,
                'error' => $e->getMessage(),
            ]);
    
            throw new \Exception('Impossible d\'initier le paiement Mobile Money : ' . $e->getMessage());
        }
    
        if (!KelPayService::estSucces($response)) {
            $paiement->update([
                'statut' => 'ECHOUE',
                'details_paiement' => array_merge(
                    $paiement->details_paiement ?? [],
                    ['response_kelpay' => $response, 'date_echec' => now()->toDateTimeString()]
                ),
            ]);
    
            return [
                'success' => false,
                'statut' => 'ECHOUE',
                'retry_allowed' => true,
                'message' => $response['description'] ?? $response['message'] ?? 'La demande de paiement a été refusée.',
                'data' => $response,
                'paiement' => $paiement->fresh(),
            ];
        }
    
        $transactionId = $response['transactionid'] ?? $response['transaction_id'] ?? $response['transactionId'] ?? null;
    
        if (empty($transactionId)) {
            $paiement->update([
                'statut' => 'ECHOUE',
                'details_paiement' => array_merge(
                    $paiement->details_paiement ?? [],
                    ['response_kelpay' => $response, 'error' => 'Aucun transaction_id retourné', 'date_echec' => now()->toDateTimeString()]
                ),
            ]);
    
            throw new \Exception('La transaction Mobile Money n\'a pas retourné de référence.');
        }
    
        $paiement->update([
            'statut' => 'EN_ATTENTE',
            'reference_externe' => $transactionId,
            'details_paiement' => array_merge(
                $paiement->details_paiement ?? [],
                [
                    'response_kelpay' => $response,
                    'transaction_id' => $transactionId,
                    'numero_mobile' => $numeroMobile,
                    'date_mise_en_attente' => now()->toDateTimeString(),
                ]
            ),
        ]);
    
        $facture->update([
            'statut' => 'EN_ATTENTE_PAIEMENT',
            'metadata' => array_merge(
                $facture->metadata ?? [],
                [
                    'transaction_id' => $transactionId,
                    'numero_mobile' => $numeroMobile,
                    'paiement_id' => $paiement->id,
                    'paiement_reference' => $paymentReference,
                    'date_paiement_initie' => now()->toDateTimeString(),
                ]
            ),
        ]);
    
        Log::info('Paiement Mobile Money en attente', [
            'facture_id' => $facture->id,
            'transaction_id' => $transactionId,
            'numero_mobile' => $numeroMobile,
        ]);
    
        return [
            'success' => true,
            'statut' => 'EN_ATTENTE',
            'transaction_id' => $transactionId,
            'message' => 'Demande de paiement envoyée. En attente de confirmation.',
            'paiement' => $paiement->fresh(),
            'facture' => $facture->fresh(['client', 'lignes.produit']),
        ];
    }

    /**
     * ================================================================
     * FINALISER PAIEMENT MOBILE MONEY
     * ================================================================
     */
   protected function finaliserPaiementMobileMoney(
    Facture $facture,
    array $response,
    string $transactionId,
    $user
    ): array {
    
        $statutKelPay = strtoupper(trim((string) ($response['transactionstatus'] ?? '')));
    
        if ($statutKelPay !== 'DELIVERED') {
            Log::critical('Tentative de finalisation sans DELIVERED', [
                'facture_id' => $facture->id,
                'transaction_id' => $transactionId,
                'statut_kelpay' => $statutKelPay,
            ]);
            throw new \Exception('Paiement Mobile Money non confirmé par KelPay.');
        }
    
        return DB::transaction(function () use ($facture, $response, $transactionId, $user) {
    
            // ✅ Charger la facture avec les relations de base
            $facture = Facture::query()
                ->with(['lignes.produit', 'client'])
                ->where('id', $facture->id)
                ->lockForUpdate()
                ->firstOrFail();
    
            if ($facture->statut === 'PAYEE') {
                return [
                    'success' => true,
                    'statut' => 'PAYEE',
                    'message' => 'Cette facture est déjà payée.',
                    'facture' => $facture,
                ];
            }
    
            if ($facture->statut !== 'EN_ATTENTE_PAIEMENT') {
                throw new \Exception('La facture ne peut pas être finalisée. Statut actuel : ' . $facture->statut);
            }
    
            $paiement = Paiement::where('facture_id', $facture->id)
                ->where('reference_externe', $transactionId)
                ->lockForUpdate()
                ->first();
    
            if (!$paiement) {
                throw new \Exception('Paiement Mobile Money introuvable.');
            }
    
            if ($paiement->statut === 'PAYE') {
                return [
                    'success' => true,
                    'statut' => 'PAYEE',
                    'message' => 'Paiement déjà finalisé.',
                    'facture' => $facture,
                ];
            }
    
            if ($paiement->statut !== 'EN_ATTENTE') {
                throw new \Exception('Le paiement Mobile Money n\'est pas en attente. Statut : ' . $paiement->statut);
            }
    
            $montantFacture = (float) $facture->total_ttc;
            if ($montantFacture <= 0) {
                throw new \Exception('Montant de facture invalide.');
            }
    
            // ============================================================
            // SORTIE STOCK (AVEC IMEI)
            // ============================================================
            $this->appliquerSortieStock($facture);
    
            // ✅ Recharger la relation entite APRÈS la sortie de stock
            $facture->load('succursale.entite');
    
            // ============================================================
            // FACTURE PAYÉE
            // ============================================================
            $facture->update([
                'statut' => 'PAYEE',
                'date_paiement' => now(),
                'montant_regle' => $montantFacture,
                'montant_du' => 0,
            ]);
    
            // ============================================================
            // PAIEMENT PAYÉ
            // ============================================================
            $paiement->update([
                'statut' => 'PAYE',
                'details_paiement' => array_merge(
                    $paiement->details_paiement ?? [],
                    $response,
                    ['transactionstatus' => 'DELIVERED', 'date_confirmation' => now()->toDateTimeString()]
                ),
            ]);
    
            // ✅ Maintenant la relation entite est chargée
            // $resultatsEcritures = $this->creerEcrituresComptables($facture);
    
            // ============================================================
            // STATISTIQUES CLIENT
            // ============================================================
            if ($facture->client) {
                $this->updateClientStats($facture->client, $montantFacture);
            }
    
            Log::info('PAIEMENT MOBILE MONEY CONFIRMÉ', [
                'facture_id' => $facture->id,
                'transaction_id' => $transactionId,
                'montant' => $montantFacture,
            ]);
    
            return [
                'success' => true,
                'statut' => 'PAYEE',
                'message' => 'Paiement Mobile Money confirmé. Vente finalisée.',
                'transaction_id' => $transactionId,
                'facture' => $facture->fresh(['client', 'lignes.produit']),
                'paiement' => $paiement->fresh(),
                // 'ecritures' => $resultatsEcritures['details'] ?? [],
            ];
        });
    }

    /**
     * ================================================================
     * CRÉER LES ÉCRITURES COMPTABLES
     * ================================================================
     */

    protected function creerEcrituresComptables(Facture $facture): array
    {
        // ✅ Récupérer l'entité comme dans ReceptionFournisseurService
        $entite = null;
        
        // 1. Via la succursale de la facture
        if ($facture->succursale?->entite) {
            $entite = $facture->succursale->entite;
        }
        
        // 2. Via la succursale de l'utilisateur connecté
        if (!$entite) {
            $user = auth()->user();
            if ($user && $user->succursale_id) {
                $succursale = \App\Models\Succursale::with('entite')->find($user->succursale_id);
                if ($succursale && $succursale->entite) {
                    $entite = $succursale->entite;
                }
            }
        }
        
        // 3. Fallback : première entité disponible
        if (!$entite) {
            $entite = \App\Models\Entite::first();
        }
    
        if (!$entite) {
            throw new \Exception(
                "Aucune entité comptable trouvée pour la succursale ID: " . 
                ($facture->succursale_id ?? 'null')
            );
        }
        
        $ecrituresResultats = [];
        $totalEcritures = 0;
        $ecrituresReussies = 0;
    
        foreach ($facture->lignes as $ligne) {
    
            $produit = $ligne->produit;
    
            if (!$produit) {
                throw new \Exception("Produit introuvable pour la ligne de facture #{$ligne->id}.");
            }
    
            $montantProduitHT = round(
                (float) ($produit->prix_vente ?? 0) * (int) $ligne->quantite,
                2
            );
    
            if ($montantProduitHT <= 0) {
                throw new \Exception("Montant de vente HT invalide pour {$produit->nom}.");
            }
    
            // Récupération des mouvements de sortie
            $mouvementsSortie = collect();
    
            $mouvementsSortie = MouvementStock::query()
                ->where('produit_id', $produit->id)
                ->where('demande_parent_id', $facture->id)
                ->where('type_mouvement', 'SORTIE')
                ->whereJsonContains('metadata->ligne_facture_id', $ligne->id)
                ->get();
    
            if ($mouvementsSortie->isEmpty()) {
                $mouvementsSortie = MouvementStock::query()
                    ->where('produit_id', $produit->id)
                    ->where('type_mouvement', 'SORTIE')
                    ->whereJsonContains('metadata->facture_id', $facture->id)
                    ->whereJsonContains('metadata->ligne_facture_id', $ligne->id)
                    ->get();
            }
    
            if ($mouvementsSortie->isEmpty()) {
                $mouvementsSortie = MouvementStock::query()
                    ->where('produit_id', $produit->id)
                    ->where('type_mouvement', 'SORTIE')
                    ->where('reference_document', $facture->reference)
                    ->get();
            }
    
            if ($mouvementsSortie->isEmpty()) {
                $tousMouvements = MouvementStock::query()
                    ->where('produit_id', $produit->id)
                    ->where('type_mouvement', 'SORTIE')
                    ->where(function ($query) use ($facture) {
                        $query->where('demande_parent_id', $facture->id)
                              ->orWhere('reference_document', $facture->reference);
                    })
                    ->get();
    
                foreach ($tousMouvements as $mouvement) {
                    $metadata = $mouvement->metadata;
                    if (is_string($metadata)) {
                        $metadata = json_decode($metadata, true) ?? [];
                    }
                    if (!is_array($metadata)) {
                        $metadata = [];
                    }
                    $factureId = $metadata['facture_id'] ?? null;
                    $ligneFactureId = $metadata['ligne_facture_id'] ?? null;
                    if ((string) $factureId === (string) $facture->id && (string) $ligneFactureId === (string) $ligne->id) {
                        $mouvementsSortie->push($mouvement);
                    }
                }
            }
    
            if ($mouvementsSortie->isEmpty()) {
                Log::error('Aucun mouvement de sortie trouvé pour écriture comptable', [
                    'facture_id' => $facture->id,
                    'facture_reference' => $facture->reference,
                    'ligne_id' => $ligne->id,
                    'produit_id' => $produit->id,
                    'produit_nom' => $produit->nom,
                    'quantite' => $ligne->quantite,
                ]);
                throw new \Exception("Aucun mouvement de sortie trouvé pour la ligne #{$ligne->id}.");
            }
    
            $montantDestockage = 0;
            $quantiteTotaleLots = 0;
            $detailsLots = [];
    
            foreach ($mouvementsSortie as $mouvement) {
                $lots = $mouvement->lotsSortie()->get();
                if ($lots->isEmpty()) {
                    Log::warning('Mouvement de sortie sans lot associé', [
                        'mouvement_id' => $mouvement->mouvement_id,
                        'facture_id' => $facture->id,
                        'ligne_id' => $ligne->id,
                    ]);
                    continue;
                }
    
                foreach ($lots as $lot) {
                    $quantiteConsommee = (int) ($lot->pivot->quantite ?? 0);
                    if ($quantiteConsommee <= 0) {
                        throw new \Exception("Quantité consommée invalide pour le lot #{$lot->id}.");
                    }
                    $prixAchatTTC = (float) ($lot->cout_unitaire_ttc ?? 0);
                    if ($prixAchatTTC <= 0) {
                        throw new \Exception("Prix d'achat TTC invalide pour le lot #{$lot->id}.");
                    }
                    $montantLotTTC = round($prixAchatTTC * $quantiteConsommee, 2);
                    $montantDestockage += $montantLotTTC;
                    $quantiteTotaleLots += $quantiteConsommee;
                    $detailsLots[] = [
                        'mouvement_id' => $mouvement->mouvement_id,
                        'lot_id' => $lot->id,
                        'numero_lot' => $lot->numero_lot,
                        'quantite' => $quantiteConsommee,
                        'prix_achat_ttc' => $prixAchatTTC,
                        'montant_ttc' => $montantLotTTC,
                    ];
                }
            }
    
            if ($quantiteTotaleLots !== (int) $ligne->quantite) {
                throw new \Exception("Incohérence de quantité pour {$produit->nom}. Quantité vendue : {$ligne->quantite}. Quantité issue des lots : {$quantiteTotaleLots}.");
            }
    
            $montantDestockage = round($montantDestockage, 2);
    
            if ($montantDestockage <= 0) {
                throw new \Exception("Coût de déstockage TTC invalide pour {$produit->nom}.");
            }
    
            Log::info('Création écriture comptable - coût TTC réel des lots', [
                'facture' => $facture->reference,
                'facture_id' => $facture->id,
                'ligne_id' => $ligne->id,
                'produit' => $produit->nom,
                'produit_id' => $produit->id,
                'quantite' => $ligne->quantite,
                'vente_ht' => $montantProduitHT,
                'cout_destockage_ttc' => $montantDestockage,
                'nb_mouvements' => $mouvementsSortie->count(),
                'nb_lots' => count($detailsLots),
                'lots' => $detailsLots,
            ]);
    
            try {
                $result = $this->ecritureService->creerEcritureSimple([
                    'montant' => $montantProduitHT,
                    'entite_id' => $entite->id,
                    'succursale_id' => $facture->succursale_id,
                    'code' => 'FIT',
                    'operation_libelle' => 'Vente au comptant',
                    'libelle' => 'Vente de ' . $produit->nom . ' au comptant,' . $facture->reference,
                    'date_ecriture' => now()->format('Y-m-d'),
                    'reference' => 'FAC-' . $facture->reference . '-' . $produit->id,
                    'auto_overrides' => [
                        [
                            'libelle' => 'Déstockage de ' . $produit->nom,
                            'valeur' => $montantDestockage,
                        ],
                    ],
                ]);
            } catch (\Throwable $e) {
                Log::error('Échec écriture comptable', [
                    'facture_id' => $facture->id,
                    'ligne_id' => $ligne->id,
                    'produit' => $produit->nom,
                    'montant_vente_ht' => $montantProduitHT,
                    'montant_destockage_ttc' => $montantDestockage,
                    'details_lots' => $detailsLots,
                    'error' => $e->getMessage(),
                ]);
                throw new \Exception("Échec de l'écriture comptable pour {$produit->nom} : " . $e->getMessage(), 0, $e);
            }
    
            if (!is_array($result) || ($result['success'] ?? false) !== true) {
                throw new \Exception("L'écriture comptable a échoué pour {$produit->nom} : " . ($result['message'] ?? 'Erreur inconnue'));
            }
    
            $ecrituresResultats[] = [
                'produit' => $produit->nom,
                'produit_id' => $produit->id,
                'quantite' => $ligne->quantite,
                'montant' => $montantProduitHT,
                'cout_stock' => $montantDestockage,
                'cout_stock_ttc' => $montantDestockage,
                'nb_mouvements' => $mouvementsSortie->count(),
                'nb_lots' => count($detailsLots),
                'lots' => $detailsLots,
                'success' => true,
            ];
    
            $totalEcritures += $montantProduitHT;
            $ecrituresReussies++;
        }
    
        return [
            'details' => $ecrituresResultats,
            'total' => round($totalEcritures, 2),
            'reussies' => $ecrituresReussies,
        ];
    }

    /**
     * ✅ APPLIQUER LA SORTIE DE STOCK
     */
    protected function appliquerSortieStock(Facture $facture): void
    {
        $facture->load(['lignes.produit']);
        
        foreach ($facture->lignes as $ligne) {
        
            $stock = StockSuccursale::query()
                ->lockForUpdate()
                ->find($ligne->stock_succursale_id);
        
            if (!$stock) {
                throw new \Exception("Stock introuvable pour le produit ID {$ligne->produit_id}.");
            }
        
            $produit = $stock->produit;
        
            $imeiIds = $ligne->imeis_utilises ?? [];
        
            if (is_string($imeiIds)) {
                $imeiIds = json_decode($imeiIds, true) ?? [];
            }
        
            if (!is_array($imeiIds)) {
                $imeiIds = [];
            }
        
            $imeiIds = collect($imeiIds)
                ->map(fn ($id) => (int) $id)
                ->filter(fn ($id) => $id > 0)
                ->unique()
                ->values()
                ->all();
        
            if ($produit->gere_imei) {
        
                if (count($imeiIds) !== (int) $ligne->quantite) {
                    
                    if (empty($imeiIds)) {
                        $imeisDisponibles = ProduitImei::where('produit_id', $produit->id)
                            ->where('succursale_id', $stock->succursale_id)
                            ->where('statut', ProduitImei::STATUT_RESERVE)
                            ->where('commande_id', $facture->id)
                            ->orWhere(function($q) use ($produit, $stock) {
                                $q->where('produit_id', $produit->id)
                                  ->where('succursale_id', $stock->succursale_id)
                                  ->where('statut', ProduitImei::STATUT_EN_STOCK);
                            })
                            ->limit($ligne->quantite)
                            ->get();
                            
                        if ($imeisDisponibles->count() < $ligne->quantite) {
                            throw new \Exception(
                                "Stock IMEI insuffisant pour {$produit->nom}. " .
                                "Disponibles: {$imeisDisponibles->count()}, Besoin: {$ligne->quantite}"
                            );
                        }
                        
                        foreach ($imeisDisponibles as $imei) {
                            $imei->statut = ProduitImei::STATUT_RESERVE;
                            $imei->commande_id = $facture->id;
                            $imei->save();
                            $imeiIds[] = $imei->id;
                        }
                    } else {
                        throw new \Exception(
                            "Le nombre d'IMEI sélectionnés (" . count($imeiIds) .
                            ") ne correspond pas à la quantité vendue (" . $ligne->quantite .
                            ") pour le produit " . $produit->nom . "."
                        );
                    }
                }
        
                $imeis = ProduitImei::query()
                    ->whereIn('id', $imeiIds)
                    ->where('produit_id', $produit->id)
                    ->where('succursale_id', $stock->succursale_id)
                    ->lockForUpdate()
                    ->get();
        
                if ($imeis->count() !== count($imeiIds)) {
                    throw new \Exception("Certains IMEI sélectionnés sont introuvables ou n'appartiennent pas à la succursale.");
                }
        
                foreach ($imeis as $imei) {
                    if (!$imei->lot_stock_id) {
                        throw new \Exception("L'IMEI {$imei->numero_imei} n'est associé à aucun lot FIFO.");
                    }
                }
        
                $lotsFifo = $imeis
                    ->groupBy('lot_stock_id')
                    ->map(function ($group, $lotId) {
                        return [
                            'lot_stock_id' => (int) $lotId,
                            'quantite' => $group->count(),
                        ];
                    })
                    ->values()
                    ->all();
        
                $quantiteLots = collect($lotsFifo)->sum(fn ($lot) => (int) $lot['quantite']);
                if ($quantiteLots !== (int) $ligne->quantite) {
                    throw new \Exception("La répartition des lots est incohérente pour {$produit->nom}.");
                }
        
                Log::info('Répartition des lots déterminée par les IMEI', [
                    'facture_id' => $facture->id,
                    'ligne_id' => $ligne->id,
                    'produit_id' => $produit->id,
                    'quantite' => $ligne->quantite,
                    'imei_ids' => $imeiIds,
                    'lots_fifo' => $lotsFifo,
                ]);
        
                $optionsSortie = [
                    'demande_parent_id' => $facture->id,
                    'reference_document' => $facture->reference,
                    'raison' => 'Sortie POS - Vente ' . $facture->reference,
                    'type_expedition' => 'POS',
                    'responsable_id' => $facture->utilisateur_id,
                    'metadata' => [
                        'source' => 'POS',
                        'facture_id' => $facture->id,
                        'ligne_facture_id' => $ligne->id,
                        'imei_ids' => $imeiIds,
                    ],
                ];
        
                if (!empty($lotsFifo)) {
                    $optionsSortie['lots_fifo'] = $lotsFifo;
                }
        
                $mouvementSortie = $stock->expedier($ligne->quantite, $optionsSortie);
        
                if (!empty($imeiIds)) {
                    $this->imeiService->verifierImeisApresSortie($imeiIds, $mouvementSortie);
                    
                    // ✅ Calcul du prix de vente unitaire
                    $prixVenteUnitaire = $ligne->prix_unitaire_ttc ?? 0;
                    
                    // ✅ Marquer les IMEI comme vendus avec le prix de vente
                    $this->imeiService->marquerImeisVendus(
                        $imeiIds,
                        $mouvementSortie,
                        $facture->id,
                        $prixVenteUnitaire // ✅ Passage du prix de vente
                    );
                    
                    $ligne->imeis_utilises = $imeiIds;
                    $ligne->save();
                }
            } else {
                $stock->expedier(
                    $ligne->quantite,
                    [
                        'demande_parent_id' => $facture->id,
                        'reference_document' => $facture->reference,
                        'raison' => 'Sortie POS - Vente ' . $facture->reference,
                        'type_expedition' => 'POS',
                        'responsable_id' => $facture->utilisateur_id,
                        'metadata' => [
                            'source' => 'POS',
                            'facture_id' => $facture->id,
                            'ligne_facture_id' => $ligne->id,
                        ],
                    ]
                );
            }
        }
    }
    /**
     * Annuler une vente (avant paiement ou après)
     */
    public function annulerVente(int $venteId, string $motif): array
    {
        $user = auth()->user();
    
        if (!$user) {
            throw new \Exception('Utilisateur non authentifié.');
        }
    
        return DB::transaction(function () use ($venteId, $motif, $user) {
    
            $facture = Facture::with(['lignes.produit'])
                ->where('id', $venteId)
                ->where('succursale_id', $user->succursale_id)
                ->lockForUpdate()
                ->firstOrFail();
    
            if ($facture->statut === 'ANNULEE') {
                throw new \Exception('Cette vente est déjà annulée.');
            }
    
            if ($facture->statut === 'PAYEE') {
                throw new \Exception('Cette vente est déjà payée. Utilisez le remboursement.');
            }
    
            $this->imeiService->libererReservationImeis($facture->id);
    
            foreach ($facture->lignes as $ligne) {
                $stock = StockSuccursale::query()
                    ->lockForUpdate()
                    ->find($ligne->stock_succursale_id);
    
                if (!$stock) {
                    throw new \Exception("Stock introuvable pour le produit ID {$ligne->produit_id}.");
                }
    
                if ($stock->quantite_reserve < $ligne->quantite) {
                    throw new \Exception("La réservation du stock est incohérente pour le produit {$ligne->produit->nom}.");
                }
    
                $stock->libererReservation($ligne->quantite);
            }
    
            $facture->statut = 'ANNULEE';
            $facture->metadata = array_merge(
                $facture->metadata ?? [],
                [
                    'motif_annulation' => $motif,
                    'date_annulation' => now()->toDateTimeString(),
                    'annule_par' => $user->id,
                ]
            );
            $facture->save();
    
            return [
                'facture' => $facture->load(['lignes.produit', 'client']),
                'message' => 'Vente annulée avec succès',
            ];
        });
    }

    /**
     * Rembourser une vente payée
     */
    public function rembourserVente(int $venteId, string $motif): array
    {
        $user = auth()->user();
        if (!$user) {
            throw new \Exception('Utilisateur non authentifié.');
        }

        return DB::transaction(function () use ($venteId, $motif, $user) {
            $facture = Facture::with(['lignes.produit'])
                ->where('id', $venteId)
                ->where('succursale_id', $user->succursale_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($facture->statut !== 'PAYEE') {
                throw new \Exception('Seules les factures payées peuvent être remboursées.');
            }

            foreach ($facture->lignes as $ligne) {
                if ($ligne->produit->gere_imei) {
                    $imeiIds = $ligne->imeis_utilises ?? [];
                    foreach ($imeiIds as $imeiId) {
                        $this->imeiService->retournerImeiVendu($imeiId, $facture->succursale_id, $motif);
                    }
                } else {
                    $stock = StockSuccursale::find($ligne->stock_succursale_id);
                    if ($stock) {
                        $stock->receptionner($ligne->quantite, [
                            'raison' => 'Remboursement - ' . $motif,
                            'reference_document' => $facture->reference,
                            'prix_achat' => $ligne->prix_unitaire_ht ?? 0,
                            'metadata' => [
                                'source' => 'REMBOURSEMENT',
                                'facture_id' => $facture->id,
                                'motif' => $motif,
                            ],
                            'responsable_id' => $user->id,
                        ]);
                    }
                }
            }

            $facture->statut = 'REMBOURSEE';
            $facture->metadata = array_merge($facture->metadata ?? [], [
                'motif_remboursement' => $motif,
                'date_remboursement' => now()->toDateTimeString(),
                'rembourse_par' => $user->id,
            ]);
            $facture->save();

            return [
                'facture' => $facture->load(['lignes.produit', 'client']),
                'message' => 'Remboursement effectué avec succès',
            ];
        });
    }
    
        private function validerEtCrediterCaisse(Facture $facture, array $data, $user): Caisses
    {
        $montant = $data['montant'] ?? $facture->total_ttc;

        $caisseSelectionnee = Caisses::find($facture->caisse_id);
        if (!$caisseSelectionnee) {
            throw new \Exception('Caisse introuvable.');
        }

        $caissePrincipale = null;
        $caisseOperationnelle = null;

        if ($caisseSelectionnee->isPrincipale()) {
            $caisseOperationnelle = $caisseSelectionnee->secondairePhysique();
            if (!$caisseOperationnelle) {
                throw new \Exception("La caisse principale n'a pas de caisse secondaire physique ouverte.");
            }
            $caissePrincipale = $caisseSelectionnee;
        } elseif ($caisseSelectionnee->isSecondaire()) {
            if ($caisseSelectionnee->type_caisse !== 'PHYSIQUE') {
                throw new \Exception('Une vente nécessite une caisse physique.');
            }
            if ($caisseSelectionnee->statut != 'OUVERTE') {
                throw new \Exception('La caisse doit être ouverte pour effectuer une vente.');
            }
            $caisseOperationnelle = $caisseSelectionnee;
            $caissePrincipale = $caisseSelectionnee->parent ?? $caisseSelectionnee;
        } else {
            throw new \Exception('Type de caisse non autorisé pour une vente.');
        }

        if (!$caissePrincipale->peut('VENTE')) {
            throw new \Exception('Cette caisse n\'est pas autorisée à effectuer une vente.');
        }

        // Créditer la caisse
        $caisseOperationnelle->crediter(
            (float) $montant,
            $facture->devise_id,
            'Vente ' . $facture->reference,
            $facture->reference,
            $user->id
        );

        return $caisseOperationnelle;
    }

    /**
     * Récupérer les détails d'une vente
     */
    public function getVente(int $venteId): array
    {
        $user = auth()->user();
        if (!$user) {
            throw new \Exception('Utilisateur non authentifié.');
        }

        $facture = Facture::with(['client', 'lignes.produit', 'lignes.produit.categorie'])
            ->where('id', $venteId)
            ->where('succursale_id', $user->succursale_id)
            ->firstOrFail();

        return [
            'facture' => $facture,
            'client'  => $facture->client,
            'lignes'  => $facture->lignes,
            'total'   => $facture->total_ttc,
        ];
    }

    // ============================================
    // MÉTHODES PRIVÉES
    // ============================================

    public function getClient(?string $telephone = null, ?string $nomClient = null): ?Client
    {
        $recherche = trim($telephone ?: $nomClient ?: '');

        if (empty($recherche)) {
            throw new \Exception('Le numéro ou le nom du client est obligatoire.');
        }

        return Client::where(function ($query) use ($recherche) {
            $query->where('telephone', 'LIKE', "%{$recherche}%")
                  ->orWhere('nom_complet', 'LIKE', "%{$recherche}%")
                  ->orWhere('email', 'LIKE', "%{$recherche}%");
        })->first();
    }

    public function createClient(?string $telephone = null, ?string $nomClient = null, ?string $email = null): Client
    {
        if (!$telephone && !$nomClient) {
            throw new \Exception('Le numéro ou le nom du client est obligatoire.');
        }

        $client = new Client();
        $client->telephone = $telephone;
        $client->nom_complet = $nomClient ?: 'Client POS';
        $client->email = $email;
        $client->statut = 'ACTIF';
        $client->categorie_client = 'STANDARD';
        $client->nombre_achats = 0;
        $client->total_achats = 0;
        $client->save();

        return $client;
    }

    protected function trouverProduitParCodeBarre(string $codeBarre): ?Produit
    {
        $cacheKey = 'produit_code_barre_' . md5($codeBarre);

        return Cache::remember($cacheKey, 3600, function () use ($codeBarre) {
            return Produit::where('code_barre', $codeBarre)
                ->where('actif', 1)
                ->first();
        });
    }

    protected function formatProduit(Produit $produit): array
    {
        return [
            'id' => $produit->id,
            'code_barre' => $produit->code_barre,
            'nom' => $produit->nom,
            'description' => $produit->description,
            'marque' => $produit->marque,
            'modele' => $produit->modele,
            'type' => $produit->type,
            'type_label' => $produit->type_label,
            'prix_vente' => $produit->prix_vente,
            'prix_vente_TTC' => $produit->prix_vente_TTC ?? $produit->prix_vente,
            'categorie' => $produit->categorie?->nom,
            'image_url' => $produit->image_url,
            'stock_minimum' => $produit->stock_minimum,
        ];
    }

    protected function creerPaiement(Facture $facture, array $data): Paiement
    {
        $paiementData = [
            'reference' => 'PAY-' . now()->format('YmdHis') . '-' . Str::random(6),
            'facture_id' => $facture->id,
            'client_id' => $facture->client_id,
            'caisse_id' => $facture->caisse_id,
            'utilisateur_id' => $facture->utilisateur_id,
            'mode_paiement' => $data['mode_paiement'] ?? 'ESPECES',
            'montant' => $data['montant'] ?? $facture->total_ttc,
            'montant_rendu' => ($data['montant'] ?? $facture->total_ttc) - $facture->total_ttc,
            'date_paiement' => now(),
            'statut' => 'COMPLET',
            'reference_externe' => $data['reference_externe'] ?? null,
            'details_paiement' => $data['details_paiement'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];

        if (isset($data['seuil_montant'])) {
            $paiementData['details_paiement'] = array_merge(
                $paiementData['details_paiement'] ?? [],
                ['seuil_montant' => $data['seuil_montant']]
            );
        }

        return Paiement::create($paiementData);
    }

    protected function updateClientStats(Client $client, float $montant): void
    {
        $client = Client::lockForUpdate()->find($client->id);
    
        if (!$client) {
            Log::warning('Client introuvable pour mise à jour statistiques');
            return;
        }
    
        $client->increment('nombre_achats', 1);
        $client->increment('total_achats', $montant);
        $client->update(['date_dernier_achat' => now()]);
    
        Log::info('Statistiques client mises à jour', [
            'client_id' => $client->id,
            'nombre_achats' => $client->nombre_achats,
            'total_achats' => $client->total_achats,
        ]);
    
        if (method_exists($client, 'verifierUpgradeCategorie')) {
            $client->verifierUpgradeCategorie();
        }
    }

    protected function genererReferenceFacture(): string
    {
        return 'POS-' . now()->format('Ymd') . '-' . strtoupper(Str::random(8));
    }
}