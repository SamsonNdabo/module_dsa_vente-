<?php

namespace App\Http\Controllers\Client\CommandeClient;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\CommandeClient;
use App\Models\Paiement;
use App\Models\ProduitImei;
use App\Services\CommandeService;
use App\Services\KelPayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CommandeClientController extends Controller
{
    protected CommandeService $commandeService;
    protected KelPayService $kelPayService;

    public function __construct(
        CommandeService $commandeService,
        KelPayService $kelPayService
    ) {
        $this->commandeService = $commandeService;
        $this->kelPayService = $kelPayService;
    }
    
    private function getClient(Request $request): Client
    {
        $sharedUser = $request->get('shared_user');

        if (!$sharedUser || !isset($sharedUser['email'])) {
            abort(401, 'Utilisateur non authentifié');
        }

        return Client::where('email', $sharedUser['email'])
            ->firstOrFail();
    }

    /**
     * Créer une commande
     */
    public function store(Request $request)
    {
        try {
            $request->merge([
                'mode_livraison' => strtoupper($request->mode_livraison)
            ]);

            $request->validate([
                'panier' => 'required|array|min:1',
                'panier.*.produit_id' => 'required|exists:produit,id',
                'panier.*.quantite' => 'required|integer|min:1',
                'panier.*.prix_unitaire' => 'required|numeric|min:0',
                'mode_livraison' => 'required|in:DOMICILE,POINT_RELAIS,RETRAIT_MAGASIN',
                'adresse_livraison' => 'nullable|string',
                'point_relais_id' => 'nullable|string|max:191',
                'notes' => 'nullable|string',
                'telephone_livraison' => 'nullable|string|max:30',
            ]);

            $client = $this->getClient($request);

            $panier = collect($request->panier)->map(function ($item) {
                return [
                    'produit_id' => $item['produit_id'],
                    'quantite' => (int) $item['quantite'],
                    'prix_unitaire' => (float) $item['prix_unitaire'],
                ];
            })->toArray();

            $commandeData = [
                'mode_livraison' => strtoupper($request->mode_livraison),
                'adresse_livraison' => $request->adresse_livraison,
                'point_relais_id' => $request->point_relais_id,
                'notes' => $request->notes,
                'telephone_livraison' => $request->telephone_livraison ?? $client->telephone,
                'metadata' => [
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]
            ];

            $commande = $this->commandeService->creerCommande($client, $panier, $commandeData);

            return response()->json([
                'success' => true,
                'data' => $commande->load(['lignes.produit', 'livraison'])
            ], 201);

        } catch (\Throwable $e) {
            Log::error('STORE COMMANDE ERROR', [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function payer(Request $request, $id)
    {
        try {
            $request->validate([
                'telephone' => 'nullable|string|max:30',
                'devise' => 'required|string|max:10'
            ]);

            $client = $this->getClient($request);
            $commande = $client->commandes()->findOrFail($id);

            if ($commande->statut === 'PAYEE') {
                return response()->json([
                    'success' => false,
                    'message' => 'Commande déjà payée.'
                ], 422);
            }

            $paiementEnCours = Paiement::where('commande_id', $commande->id)
                ->where('statut', 'EN_COURS')
                ->first();

            if ($paiementEnCours) {
                return response()->json([
                    'success' => false,
                    'message' => 'Un paiement est déjà en cours.'
                ], 422);
            }

            $telephone = $request->telephone ?? $client->telephone;
            $paymentReference = Paiement::genererReference();

            $paiement = Paiement::create([
                'reference' => $paymentReference,
                'commande_id' => $commande->id,
                'client_id' => $client->id,
                'mode_paiement' => 'MOBILE_MONEY',
                'montant' => $commande->total,
                'statut' => 'EN_COURS'
            ]);

            $response = $this->kelPayService->payer(
                $paymentReference,
                $telephone,
                $commande->total,
                $request->devise,
                'Paiement commande ' . $commande->reference
            );

            if (!KelPayService::estSucces($response)) {
                $paiement->update([
                    'statut' => 'ECHOUE',
                    'details_paiement' => $response
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $response['description'] ?? 'Paiement échoué',
                    'retry_allowed' => true,
                    'data' => $response
                ], 422);
            }

            $transactionId = $response['transactionid'] ?? null;

            $paiement->update([
                'statut' => 'EN_ATTENTE',
                'reference_externe' => $transactionId,
                'details_paiement' => $response
            ]);

            $commande->update([
                'metadata' => array_merge($commande->metadata ?? [], [
                    'transaction_id' => $transactionId
                ])
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Demande de paiement envoyée',
                'transaction_id' => $transactionId
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function verifierPaiement(Request $request, $id)
    {
        try {
            $client = $this->getClient($request);

            $commande = $client->commandes()
                ->findOrFail($id);

            $transactionId = $commande->metadata['transaction_id'] ?? null;

            if (!$transactionId) {
                throw new \Exception('Transaction introuvable');
            }

            $response = $this->kelPayService->verifierStatut($transactionId);

            if (
                isset($response['transactionstatus']) &&
                strtoupper($response['transactionstatus']) === 'DELIVERED'
            ) {
                $commande->update([
                    'statut' => 'PAYEE'
                ]);

                Paiement::where(
                    'reference_externe',
                    $transactionId
                )->update([
                    'statut' => 'PAYE',
                    'details_paiement' => $response
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Paiement confirmé'
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Paiement en attente',
                'data' => $response
            ]);

        } catch (\Throwable $e) {
            Log::error('VERIFICATION PAIEMENT ERROR', [
                'commande_id' => $id,
                'message' => $e->getMessage(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Liste des commandes du client
     */
    public function index(Request $request)
    {
        try {
            $client = $this->getClient($request);

            $paginated = $client->commandes()
                ->with(['lignes.produit', 'livraison', 'succursaleAssignee'])
                ->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 15));

            $data = collect($paginated->items())->map(function ($commande) {
                return [
                    'id' => $commande->id,
                    'numero_commande' => $commande->reference ?? $commande->id,
                    'date_commande' => $commande->created_at->format('d/m/Y H:i'),
                    'montant_total' => $commande->total,
                    'statut_commande' => $commande->statut,
                    'statut_logistique' => $commande->statut_logistique,
                    'succursale' => $commande->succursaleAssignee?->nom,
                    'nombre_articles' => $commande->lignes->sum('quantite'),
                    'mode_livraison' => $commande->livraison?->mode_livraison 
                        ?? $commande->mode_livraison 
                        ?? 'Non spécifié',
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data,
                'pagination' => [
                    'total' => $paginated->total(),
                    'per_page' => $paginated->perPage(),
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                ]
            ]);

        } catch (\Throwable $e) {
            Log::error('LISTE COMMANDES ERROR', [
                'message' => $e->getMessage(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Détails d'une commande avec IMEI
     */
    public function show(Request $request, $id)
    {
        try {
            $client = $this->getClient($request);

            $commande = $client->commandes()
                ->with([
                    'lignes.produit', 
                    'livraison',
                    'lignes' => function($query) {
                        $query->with(['produit' => function($q) {
                            $q->select('id', 'nom', 'code_barre', 'type', 'gere_imei');
                        }]);
                    }
                ])
                ->findOrFail($id);

            $statut = $commande->statut;

            if ($commande->updated_at && in_array(strtolower($statut), ['livree', 'completee'])) {
                $statut .= ' le ' . $commande->updated_at->format('d/m/Y');
            }

            //  Récupérer les IMEI pour chaque ligne
            $lignesAvecImei = $commande->lignes->map(function ($ligne) {
                $imeis = [];
                if ($ligne->produit->gere_imei && $ligne->metadata) {
                    // Récupérer les IMEI depuis metadata
                    $imeiIds = $ligne->metadata['imeis_selectionnes'] ?? 
                               $ligne->metadata['imeis_en_preparation'] ?? 
                               $ligne->metadata['imeis_expedies'] ?? 
                               $ligne->metadata['imeis_livres'] ?? [];
                    
                    if (!empty($imeiIds)) {
                        $imeis = ProduitImei::whereIn('id', $imeiIds)
                            ->select('id', 'numero_imei', 'numero_serie', 'statut', 'lot_stock_id')
                            ->get()
                            ->map(function ($imei) {
                                return [
                                    'id' => $imei->id,
                                    'numero_imei' => $imei->numero_imei,
                                    'numero_serie' => $imei->numero_serie,
                                    'statut' => $imei->statut,
                                    'lot_id' => $imei->lot_stock_id,
                                ];
                            });
                    }
                }

                return [
                    'id' => $ligne->id,
                    'nom_produit' => $ligne->produit->nom ?? 'Produit inconnu',
                    'image_thumbnail' => $ligne->produit?->image_thumbnail_url,
                    'quantite' => $ligne->quantite,
                    'prix_unitaire_origine' => $ligne->prix_unitaire,
                    'total_article' => $ligne->total,
                    'gere_imei' => $ligne->produit->gere_imei ?? false,
                    'imeis' => $imeis,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $commande->id,
                    'informations_generales' => [
                        'numero_commande' => $commande->numero_commande ?? $commande->reference ?? $commande->id,
                        'date_passation' => $commande->created_at->format('d/m/Y H:i'),
                        'statut_actuel' => $statut,
                        'adresse_livraison' => $commande->livraison->adresse_livraison
                            ?? $commande->adresse_livraison
                            ?? 'N/A',
                        'mode_livraison' => $commande->livraison->mode_livraison
                            ?? $commande->mode_livraison
                            ?? 'Non spécifié',
                    ],
                    'detail_articles' => $lignesAvecImei,
                    'resume_financier' => [
                        'sous_total' => $commande->sous_total,
                        'remise' => $commande->remise,
                        'frais_livraison' => $commande->frais_livraison
                            ?? $commande->livraison?->frais_livraison
                            ?? 0,
                        'montant_total' => $commande->total,
                    ]
                ]
            ]);

        } catch (\Throwable $e) {
            Log::error('DETAILS COMMANDE ERROR', [
                'commande_id' => $id,
                'message' => $e->getMessage(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Annuler une commande
     */
    public function annuler(Request $request, $id)
    {
        try {
            $request->validate([
                'motif' => 'nullable|string|max:255'
            ]);

            $client = $this->getClient($request);
            $commande = $client->commandes()->findOrFail($id);

            if (in_array(strtoupper($commande->statut), [
                'EXPEDIEE','EN_LIVRAISON','LIVREE','ANNULEE'
            ])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Commande non annulable'
                ], 422);
            }

            $this->commandeService->annulerCommande(
                $commande,
                $request->motif ?? 'Aucun motif'
            );

            return response()->json([
                'success' => true,
                'message' => 'Commande annulée'
            ]);

        } catch (\Throwable $e) {
            Log::error('ANNULATION COMMANDE ERROR', [
                'commande_id' => $id,
                'message' => $e->getMessage(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Suivi de livraison avec IMEI
     */
    public function suiviLivraison(Request $request, $id)
    {
        try {
            $client = $this->getClient($request);

            $commande = $client->commandes()
                ->with([
                    'livraison', 
                    'succursaleAssignee',
                    'lignes.produit'
                ])
                ->findOrFail($id);

            $historique = collect(
                $commande->livraison?->historique_statuts ?? []
            )
            ->sortBy('date')
            ->values()
            ->all();

            //  Récupérer les IMEI livrés
            $imeisLivres = collect();
            foreach ($commande->lignes as $ligne) {
                if ($ligne->produit->gere_imei && $ligne->metadata) {
                    $imeiIds = $ligne->metadata['imeis_livres'] ?? 
                               $ligne->metadata['imeis_expedies'] ?? [];
                    
                    if (!empty($imeiIds)) {
                        $imeisLivres = $imeisLivres->merge(
                            ProduitImei::whereIn('id', $imeiIds)
                                ->select('id', 'numero_imei', 'numero_serie', 'produit_id')
                                ->get()
                        );
                    }
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $commande->id,
                    'numero_commande' => $commande->reference ?? $commande->id,
                    'statut' => $commande->statut,
                    'statut_label' => $commande->statut_label,
                    'statut_logistique' => $commande->statut_logistique,
                    'statut_logistique_label' => $commande->statut_logistique_label,
                    'succursale_assignee' => $commande->succursaleAssignee?->nom,
                    'date_commande' => $commande->created_at?->format('d/m/Y H:i'),
                    'date_livraison_estimee' => $commande->livraison?->date_livraison_estimee?->format('d/m/Y'),
                    'mode_livraison' => $commande->livraison?->mode_livraison
                        ?? $commande->mode_livraison
                        ?? 'Non spécifié',
                    'numero_suivi' => $commande->livraison?->numero_suivi
                        ?? 'En attente',
                    'statut_livraison' => $commande->livraison?->statut
                        ?? 'En traitement',
                    'adresse_complete' => $commande->livraison?->adresse_livraison
                        ?? $commande->adresse_livraison
                        ?? $client->adresse
                        ?? 'Aucune adresse renseignée',
                    'historique' => $historique,
                    'derniere_mise_a_jour' => $commande->updated_at?->format('d/m/Y H:i'),
                    //  Ajout des IMEI livrés
                    'imeis_livres' => $imeisLivres->map(function ($imei) {
                        return [
                            'numero_imei' => $imei->numero_imei,
                            'numero_serie' => $imei->numero_serie,
                        ];
                    })->toArray(),
                ]
            ]);

        } catch (\Throwable $e) {
            Log::error('SUIVI LIVRAISON ERROR', [
                'commande_id' => $id,
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Statistiques du client
     */
    public function statistiques(Request $request)
    {
        try {
            $client = $this->getClient($request);

            return response()->json([
                'success' => true,
                'data' => $this->commandeService->getStatistiquesClient($client)
            ]);

        } catch (\Throwable $e) {
            Log::error('STATISTIQUES CLIENT ERROR', [
                'message' => $e->getMessage(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // ================================================================
    // NOUVEAU : RÉCUPÉRER LES IMEI D'UNE COMMANDE
    // ================================================================

    /**
     * Récupérer tous les IMEI d'une commande
     */
    public function getImeis(Request $request, $id)
    {
        try {
            $client = $this->getClient($request);

            $commande = $client->commandes()
                ->with(['lignes.produit'])
                ->findOrFail($id);

            $imeis = collect();
            
            foreach ($commande->lignes as $ligne) {
                if ($ligne->produit->gere_imei && $ligne->metadata) {
                    $imeiIds = $ligne->metadata['imeis_selectionnes'] ?? 
                               $ligne->metadata['imeis_en_preparation'] ?? 
                               $ligne->metadata['imeis_expedies'] ?? 
                               $ligne->metadata['imeis_livres'] ?? [];
                    
                    if (!empty($imeiIds)) {
                        $imeis = $imeis->merge(
                            ProduitImei::whereIn('id', $imeiIds)
                                ->with(['produit', 'lotStock'])
                                ->get()
                        );
                    }
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'commande' => [
                        'id' => $commande->id,
                        'reference' => $commande->reference,
                        'statut' => $commande->statut,
                    ],
                    'imeis' => $imeis->map(function ($imei) {
                        return [
                            'id' => $imei->id,
                            'numero_imei' => $imei->numero_imei,
                            'numero_serie' => $imei->numero_serie,
                            'produit' => $imei->produit?->nom,
                            'lot_id' => $imei->lot_stock_id,
                            'prix_achat' => $imei->prix_achat,
                            'statut' => $imei->statut,
                            'est_sous_garantie' => $imei->est_sous_garantie,
                        ];
                    }),
                    'total' => $imeis->count()
                ]
            ]);

        } catch (\Throwable $e) {
            Log::error('RECUPERATION IMEI COMMANDE ERROR', [
                'commande_id' => $id,
                'message' => $e->getMessage(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}