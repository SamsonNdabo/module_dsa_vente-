<?php

namespace App\Http\Controllers\Approvisionnement;

use App\Http\Controllers\Controller;
use App\Models\Approvisionnement;
use App\Models\LigneApprovisionnement;
use App\Models\StockSuccursale;
use App\Models\Succursale;
use App\Models\MouvementStock;
use App\Models\Produit;
use App\Models\ProduitImei;
use App\Services\ApprovisionnementService; // ✅ NOUVEAU
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Traits\Notifiable;
use Illuminate\Support\Facades\Log;

class ApprovisionnementController extends Controller
{
    use Notifiable;

    protected ApprovisionnementService $approvisionnementService; // ✅ NOUVEAU

    public function __construct(ApprovisionnementService $approvisionnementService)
    {
        $this->approvisionnementService = $approvisionnementService;
    }

    /**
     * Affiche la liste des approvisionnements/transferts.
     */
    public function index(Request $request)
    {
        return $this->indexGlobal($request);
    }

    /**
     * Crée une nouvelle demande d'approvisionnement.
     */
    public function store(Request $request)
    {
        $user = auth()->user();

        $validator = Validator::make($request->all(), [
            'destination_succursale_id' => 'required|exists:succursales,id',
            'motif' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'date_livraison_souhaitee' => 'nullable|date|after_or_equal:today',
            'lignes' => 'required|array|min:1',
            'lignes.*.produit_id' => 'required|exists:produit,id',
            'lignes.*.quantite_demandee' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            $lignesCollection = collect($request->lignes);
            $totalQuantite = $lignesCollection->sum('quantite_demandee');

            $reference = Approvisionnement::genererReference('APPROVISIONNEMENT');

            $demande = Approvisionnement::create([
                'reference' => $reference,
                'type_demande' => 'APPROVISIONNEMENT',
                'source_succursale_id' => null,
                'destination_succursale_id' => $request->destination_succursale_id,
                'quantite_demandee' => $totalQuantite,
                'motif' => $request->motif,
                'notes' => $request->notes,
                'date_demande' => now(),
                'date_livraison_souhaitee' => $request->date_livraison_souhaitee,
                'demandeur_id' => $user->id,
                'statut_demande' => 'Pending Approval',
                'statut_operation' => 'EN_ATTENTE_OPERATION'
            ]);

            $lignesData = $lignesCollection->map(function ($ligneData) use ($demande) {
                return [
                    'approvisionnement_id' => $demande->id,
                    'produit_id' => $ligneData['produit_id'],
                    'quantite_demandee' => $ligneData['quantite_demandee'],
                    'quantite_envoyee' => 0,
                    'quantite_recue' => 0,
                    'metadata' => json_encode([]),
                ];
            })->toArray();

            LigneApprovisionnement::insert($lignesData);

            DB::commit();

            $this->notifierNouvelleDemande($demande, $user);

            return response()->json([
                'success' => true,
                'message' => 'Demande d\'approvisionnement créée avec succès',
                'data' => $demande->load([
                    'lignes' => function($query) {
                        $query->with(['produit' => function($q) {
                            $q->select('id', 'nom', 'code_barre', 'type', 'gere_imei');
                        }]);
                    },
                    'destinationSuccursale'
                ])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }
    
        public function getImeisDisponibles($id)
    {
        $user = auth()->user();
    
        $demande = Approvisionnement::with([
            'lignes' => function($query) {
                $query->with(['produit' => function($q) {
                    $q->select('id', 'nom', 'code_barre', 'type', 'gere_imei');
                }]);
            },
            'sourceDecidee'
        ])->find($id);
    
        if (!$demande) {
            return response()->json(['success' => false, 'message' => 'Demande non trouvée'], 404);
        }
    
        if (!$demande->source_decidee_id) {
            return response()->json(['success' => false, 'message' => 'Aucune source n\'a été désignée par l\'OPS'], 422);
        }
    
        if ($demande->source_decidee_id != $user->succursale_id) {
            return response()->json(['success' => false, 'message' => 'Seule la source désignée peut voir les IMEI'], 403);
        }
    
        $result = [
            'success' => true,
            'data' => [
                'demande' => [
                    'id' => $demande->id,
                    'reference' => $demande->reference,
                    'source_decidee' => $demande->sourceDecidee?->nom,
                ],
                'lignes' => []
            ]
        ];
    
        foreach ($demande->lignes as $ligne) {
            // ✅ Si le produit ne gère pas les IMEI, on passe
            if (!$ligne->produit->gere_imei) {
                $result['data']['lignes'][] = [
                    'ligne_id' => $ligne->id,
                    'produit_id' => $ligne->produit_id,
                    'produit_nom' => $ligne->produit->nom,
                    'gere_imei' => false,
                    'quantite_demandee' => $ligne->quantite_demandee,
                    'message' => 'Ce produit ne gère pas les IMEI'
                ];
                continue;
            }
    
            // ✅ Récupérer les IMEI EN_STOCK dans la succursale source
            // ✅ Avec filtre sur le statut du lot = ACTIF
            $imeisDisponibles = ProduitImei::where('produit_id', $ligne->produit_id)
                ->where('succursale_id', $demande->source_decidee_id)
                ->where('statut', ProduitImei::STATUT_EN_STOCK)
                ->whereHas('lotStock', function ($query) {
                    $query->where('statut', 'ACTIF'); // ✅ Filtrer les lots ACTIFS
                })
                ->with('lotStock')
                ->orderBy('lot_stock_id')  // FIFO : les plus anciens lots d'abord
                ->orderBy('created_at')
                ->get();
    
            // ✅ Vérifier si des IMEI sont déjà sélectionnés
            $imeisSelectionnes = $ligne->metadata['imeis_selectionnes'] ?? [];
    
            $result['data']['lignes'][] = [
                'ligne_id' => $ligne->id,
                'produit_id' => $ligne->produit_id,
                'produit_nom' => $ligne->produit->nom,
                'gere_imei' => true,
                'quantite_demandee' => $ligne->quantite_demandee,
                'quantite_disponible' => $imeisDisponibles->count(),
                'imeis' => $imeisDisponibles->map(function ($imei) {
                    return [
                        'id' => $imei->id,
                        'numero_imei' => $imei->numero_imei,
                        'numero_serie' => $imei->numero_serie,
                        'lot_id' => $imei->lot_stock_id,
                        'lot_numero' => $imei->lotStock?->numero_lot,
                        'lot_statut' => $imei->lotStock?->statut, // ✅ Ajout du statut du lot
                        'prix_achat' => $imei->prix_achat,
                        'statut' => $imei->statut,
                        'est_sous_garantie' => $imei->est_sous_garantie,
                        'date_entree' => $imei->date_entree?->format('Y-m-d'),
                        'created_at' => $imei->created_at?->format('Y-m-d H:i'),
                    ];
                }),
                'imeis_selectionnes' => $imeisSelectionnes,
                'selection_possible' => $imeisDisponibles->count() >= $ligne->quantite_demandee,
                'message' => $imeisDisponibles->count() >= $ligne->quantite_demandee 
                    ? 'IMEI disponibles pour sélection' 
                    : 'IMEI insuffisants pour la quantité demandée'
            ];
        }
    
        return response()->json($result);
    }
    /**
     * Prépare une demande (réservation de stock).
     */
    public function preparer($id, Request $request)
    {
        $user = auth()->user();

        $demande = Approvisionnement::lockForUpdate()
            ->with(['lignes' => function($query) {
                $query->with(['produit' => function($q) {
                    $q->select('id', 'nom', 'code_barre', 'type', 'gere_imei');
                }]);
            }])
            ->find($id);

        if (!$demande) {
            return response()->json(['success' => false, 'message' => 'Demande non trouvée'], 404);
        }

        if (!$demande->source_decidee_id) {
            return response()->json(['success' => false, 'message' => 'Aucune source n\'a été désignée par l\'OPS'], 422);
        }

        if ($demande->source_decidee_id != $user->succursale_id) {
            return response()->json(['success' => false, 'message' => 'Seule la source désignée par l\'OPS peut préparer cette demande'], 403);
        }

        if (in_array($demande->statut_operation, ['PREPARE', 'EXPEDIE', 'ARRIVE_INTERMEDIAIRE', 'DEPART_INTERMEDIAIRE', 'RECU', 'RECU_PARTIEL'])) {
            return response()->json(['success' => false, 'message' => 'Cette demande a déjà été préparée ou est déjà en cours'], 409);
        }

        if ($demande->statut_operation !== 'VALIDE') {
            return response()->json([
                'success' => false,
                'message' => 'Cette demande doit être validée par la logistique avant préparation. Statut actuel: ' . $demande->statut_operation
            ], 422);
        }

        // ✅ Valider les sélections IMEI optionnelles
        $validator = Validator::make($request->all(), [
            'imei_selections' => 'nullable|array',
            'imei_selections.*' => 'nullable|array',
            'imei_selections.*.*' => 'nullable|integer|exists:produit_imei,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // ✅ Récupérer les sélections IMEI optionnelles
        $imeiSelections = $request->input('imei_selections', null);

        // ✅ VÉRIFICATION IMEI via le service (avec sélection optionnelle)
        $result = $this->approvisionnementService->preparer($demande, $imeiSelections);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => 'Stock IMEI insuffisant ou sélection invalide',
                'imei_manquants' => $result['imei_manquants'] ?? [],
                'imei_selectionnes' => $result['imei_selectionnes'] ?? [],
                'erreurs' => $result['erreurs'] ?? []
            ], 422);
        }

        $erreursStock = $this->verifierDisponibiliteStock($demande);

        if (!empty($erreursStock)) {
            $this->notifierStockInsuffisant($demande, $erreursStock, $user);
            return response()->json([
                'success' => false,
                'message' => 'Stock insuffisant',
                'erreurs' => $erreursStock
            ], 422);
        }

        DB::beginTransaction();

        try {
            $this->reserverStock($demande);

            $demande->update([
                'quantite_envoyee' => $demande->quantite_demandee,
                'statut_operation' => 'PREPARE',
                'preparateur_id' => $user->id,
                'date_preparation' => now()
            ]);

            DB::commit();

            $this->notifierDemandePreparee($demande, $user);

            return response()->json([
                'success' => true,
                'message' => 'Demande préparée avec succès',
                'data' => [
                    'demande' => $demande->fresh(['lignes' => function($query) {
                        $query->with(['produit' => function($q) {
                            $q->select('id', 'nom', 'code_barre', 'type', 'gere_imei');
                        }]);
                    }]),
                    'imei_selectionnes' => $result['imei_selectionnes'] ?? []
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }
    /**
     * Expédie une demande préparée.
     */
    public function expedier($id, Request $request)
    {
        $user = auth()->user();
    
        $validator = Validator::make($request->all(), [
            'responsable_transport' => 'nullable|string|max:255',
            'numero_vehicule' => 'nullable|string|max:100',
            'code_suivi' => 'nullable|string|max:100',
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }
    
        try {
    
            // ============================================================
            // 1. RÉCUPÉRER LA DEMANDE
            // ============================================================
    
            $demande = Approvisionnement::with([
                'lignes.produit',
                'sourceDecidee',
                'destinationSuccursale',
            ])->findOrFail($id);
    
            // ============================================================
            // 2. VÉRIFICATION DU STATUT
            // ============================================================
    
            if ($demande->statut_operation === 'EXPEDIE') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cette demande a déjà été expédiée.',
                ], 422);
            }
    
            // ============================================================
            // 3. EXPÉDITION
            // ============================================================
    
            $mouvement = $this->approvisionnementService->expedier(
                $demande,
                $user->id
            );
    
            // ============================================================
            // 4. INFORMATIONS DE TRANSPORT
            // ============================================================
    
            $updateData = [];
    
            if ($request->filled('responsable_transport')) {
                $updateData['responsable_transport'] =
                    $request->responsable_transport;
            }
    
            if ($request->filled('numero_vehicule')) {
                $updateData['numero_vehicule'] =
                    $request->numero_vehicule;
            }
    
            if ($request->filled('code_suivi')) {
                $updateData['code_suivi'] =
                    $request->code_suivi;
            }
    
            if (!empty($updateData)) {
                $demande->update($updateData);
            }
    
            // ============================================================
            // 5. NOTIFICATION
            // ============================================================
    
            $this->notifierDemandeExpediee($demande, $user);
    
            // ============================================================
            // 6. RÉPONSE
            // ============================================================
    
            return response()->json([
                'success' => true,
                'message' => 'Demande expédiée avec succès',
    
                'data' => $demande->fresh([
                    'lignes' => function ($query) {
                        $query->with([
                            'produit' => function ($q) {
                                $q->select(
                                    'id',
                                    'nom',
                                    'code_barre',
                                    'type',
                                    'gere_imei'
                                );
                            },
                        ]);
                    },
                    'sourceDecidee',
                    'destinationSuccursale',
                ]),
    
                'mouvement' => $mouvement ? [
                    'id' => $mouvement->mouvement_id,
                    'type' => $mouvement->type_mouvement,
                    'reference' => $mouvement->reference_document ?? null,
                ] : null,
            ]);
    
        } catch (\Exception $e) {
    
            Log::error('ERREUR EXPEDITION', [
                'demande_id' => $id,
                'user_id' => $user->id ?? null,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
    
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
    /**
     * Enregistre l'arrivée au point intermédiaire (AVEC IMEI).
     */
    public function arriverIntermediaire($id, Request $request)
    {
        $user = auth()->user();

        $demande = Approvisionnement::lockForUpdate()
            ->with(['lignes' => function($query) {
                $query->with(['produit' => function($q) {
                    $q->select('id', 'nom', 'code_barre', 'type', 'gere_imei');
                }]);
            }])
            ->find($id);

        if (!$demande) {
            return response()->json(['success' => false, 'message' => 'Demande non trouvée'], 404);
        }

        if (in_array($demande->statut_operation, ['ARRIVE_INTERMEDIAIRE', 'DEPART_INTERMEDIAIRE', 'RECU', 'RECU_PARTIEL'])) {
            return response()->json(['success' => false, 'message' => 'Les produits sont déjà arrivés ou ont déjà quitté l\'intermédiaire'], 409);
        }

        if (!$demande->intermediaire_succursale_id || $demande->intermediaire_succursale_id != $user->succursale_id) {
            return response()->json(['success' => false, 'message' => 'Seul le point intermédiaire désigné peut enregistrer l\'arrivée'], 403);
        }

        if ($demande->statut_operation !== 'EXPEDIE') {
            return response()->json(['success' => false, 'message' => 'La demande doit être expédiée avant d\'arriver à l\'intermédiaire'], 422);
        }

        if (!$demande->avec_intermediaire) {
            return response()->json(['success' => false, 'message' => 'Cette demande n\'a pas d\'intermédiaire défini'], 422);
        }

        try {
            // ✅ Utiliser le service pour l'arrivée intermédiaire
            $this->approvisionnementService->receptionnerIntermediaire($demande, $user->id);

            $this->notifierArriveeIntermediaire($demande, $user);

            return response()->json([
                'success' => true,
                'message' => 'Arrivée au point intermédiaire enregistrée',
                'data' => $demande->fresh()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Enregistre le départ du point intermédiaire (AVEC IMEI).
     */
    public function departIntermediaire($id, Request $request)
    {
        $user = auth()->user();

        $demande = Approvisionnement::lockForUpdate()
            ->with(['lignes' => function($query) {
                $query->with(['produit' => function($q) {
                    $q->select('id', 'nom', 'code_barre', 'type', 'gere_imei');
                }]);
            }])
            ->find($id);

        if (!$demande) {
            return response()->json(['success' => false, 'message' => 'Demande non trouvée'], 404);
        }

        if (!$demande->intermediaire_succursale_id || $demande->intermediaire_succursale_id != $user->succursale_id) {
            return response()->json(['success' => false, 'message' => 'Seul le point intermédiaire désigné peut enregistrer le départ'], 403);
        }

        if (in_array($demande->statut_operation, ['DEPART_INTERMEDIAIRE', 'RECU', 'RECU_PARTIEL'])) {
            return response()->json(['success' => false, 'message' => 'Les produits ont déjà quitté l\'intermédiaire ou sont déjà réceptionnés'], 409);
        }

        if ($demande->statut_operation !== 'ARRIVE_INTERMEDIAIRE') {
            return response()->json(['success' => false, 'message' => 'Les produits doivent être arrivés à l\'intermédiaire avant de pouvoir partir'], 422);
        }

        try {
            // ✅ Utiliser le service pour le départ intermédiaire
            $this->approvisionnementService->expedierIntermediaire($demande, $user->id);

            $this->notifierDepartIntermediaire($demande, $user);

            return response()->json([
                'success' => true,
                'message' => 'Départ du point intermédiaire enregistré',
                'data' => [
                    'id' => $demande->id,
                    'reference' => $demande->reference,
                    'statut' => $demande->statut_operation
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('ERREUR DEPART INTERMEDIAIRE', [
                'demande_id' => $id,
                'user_id' => $user->id,
                'message' => $e->getMessage()
            ]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Réceptionne une demande à destination (AVEC IMEI).
     */
    public function receptionner($id, Request $request)
{
    $user = auth()->user();

    $demande = Approvisionnement::with([
        'lignes.produit'
    ])->find($id);

    if (!$demande) {
        return response()->json([
            'success' => false,
            'message' => 'Demande non trouvée'
        ], 404);
    }

    if (
        in_array(
            $demande->statut_operation,
            ['RECU', 'RECU_PARTIEL']
        )
    ) {
        return response()->json([
            'success' => false,
            'message' =>
                'Cette demande a déjà été réceptionnée'
        ], 409);
    }

    if (
        $demande->destination_succursale_id
        != $user->succursale_id
    ) {
        return response()->json([
            'success' => false,
            'message' =>
                'Seule la destination peut réceptionner cette demande'
        ], 403);
    }

    $statutAttendu =
        $demande->avec_intermediaire
            ? 'DEPART_INTERMEDIAIRE'
            : 'EXPEDIE';

    if (
        $demande->statut_operation
        !== $statutAttendu
    ) {
        return response()->json([
            'success' => false,
            'message' =>
                'Statut opérationnel invalide pour la réception'
        ], 422);
    }

    $validator = Validator::make(
        $request->all(),
        [
            'lignes' =>
                'required|array|min:1',

            'lignes.*.ligne_id' =>
                'required|exists:lignes_approvisionnements,id',

            'lignes.*.quantite_recue' =>
                'required|integer|min:0',

            'lignes.*.quantite_casse' =>
                'nullable|integer|min:0',

            'lignes.*.quantite_perdue' =>
                'nullable|integer|min:0',

            'lignes.*.quantite_non_conforme' =>
                'nullable|integer|min:0',

            'lignes.*.motif_ecart' =>
                'required_if:lignes.*.quantite_casse,>0'
                . '|required_if:lignes.*.quantite_perdue,>0'
                . '|required_if:lignes.*.quantite_non_conforme,>0'
                . '|nullable|string',
        ]
    );

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'errors' => $validator->errors()
        ], 422);
    }

    try {

        /*
         * ============================================================
         * METTRE À JOUR LES LIGNES
         * ============================================================
         */

        $lignesInput =
            collect($request->lignes)
                ->keyBy('ligne_id');

        $lignesModel =
            LigneApprovisionnement::whereIn(
                'id',
                $lignesInput->keys()
            )
            ->get();

        foreach ($lignesModel as $ligne) {

            if (
                $ligne->approvisionnement_id
                != $demande->id
            ) {
                throw new \Exception(
                    'Ligne non valide'
                );
            }

            $ligneData =
                $lignesInput->get($ligne->id);

            $quantiteRecue =
                (int) $ligneData['quantite_recue'];

            $casse =
                (int) (
                    $ligneData['quantite_casse']
                    ?? 0
                );

            $perdue =
                (int) (
                    $ligneData['quantite_perdue']
                    ?? 0
                );

            $nonConforme =
                (int) (
                    $ligneData['quantite_non_conforme']
                    ?? 0
                );

            /*
             * Vérification métier.
             */
            if (
                $quantiteRecue >
                (int) $ligne->quantite_envoyee
            ) {
                throw new \Exception(
                    "La quantité reçue ({$quantiteRecue}) "
                    . "dépasse la quantité envoyée ("
                    . $ligne->quantite_envoyee
                    . ")."
                );
            }

            $ligne->quantite_recue =
                $quantiteRecue;

            $ligne->quantite_casse =
                $casse;

            $ligne->quantite_perdue =
                $perdue;

            $ligne->quantite_non_conforme =
                $nonConforme;

            $ligne->motif_ecart =
                $ligneData['motif_ecart'] ?? null;

            $ligne->save();
        }

        /*
         * ============================================================
         * TRÈS IMPORTANT
         * ============================================================
         *
         * On recharge la demande afin que le service travaille
         * avec les nouvelles quantités.
         */
        $demande->load([
            'lignes.produit'
        ]);

        /*
         * ============================================================
         * RÉCEPTION MÉTIER
         * ============================================================
         */

        $result =
            $this->approvisionnementService
                ->receptionner(
                    $demande,
                    $user->id
                );

        if ($result['success']) {
            $this->notifierReceptionComplete(
                $demande,
                $user
            );
        }

        return response()->json([
            'success' => true,
            'message' =>
                'Demande réceptionnée avec succès',
            'data' =>
                $result['approvisionnement'],
        ]);

    } catch (\Throwable $e) {

        Log::error(
            'ERREUR RECEPTION',
            [
                'demande_id' =>
                    $id,

                'user_id' =>
                    $user->id,

                'message' =>
                    $e->getMessage(),

                'trace' =>
                    $e->getTraceAsString(),
            ]
        );

        return response()->json([
            'success' => false,
            'message' =>
                'Erreur: ' . $e->getMessage()
        ], 500);
    }
}

    /**
     * Annule une demande (AVEC LIBÉRATION IMEI).
     */
    public function annulerGlobal($id, Request $request)
    {
        $user = auth()->user();
        $demande = Approvisionnement::lockForUpdate()->find($id);

        if (!$demande) {
            return response()->json(['success' => false, 'message' => 'Demande non trouvée'], 404);
        }

        $statutsBloquants = ['Received', 'In Transit'];
        $operationsBloquantes = ['RECU', 'RECU_PARTIEL', 'EXPEDIE', 'ARRIVE_INTERMEDIAIRE', 'DEPART_INTERMEDIAIRE'];

        if (in_array($demande->statut_demande, $statutsBloquants) ||
            in_array($demande->statut_operation, $operationsBloquantes)) {
            return response()->json(['success' => false, 'message' => 'Impossible d\'annuler une demande validée, reçue ou en transit'], 422);
        }

        $validator = Validator::make($request->all(), ['motif' => 'required|string|min:3']);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            // ✅ Utiliser le service pour l'annulation (libère les IMEI)
            $this->approvisionnementService->annuler($demande, $request->motif);

            $this->notifierDemandeAnnulee($demande, $user, $request->motif);

            return response()->json([
                'success' => true,
                'message' => 'Demande annulée avec succès',
                'data' => $demande->fresh()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Liste globale des demandes - OPTIMISÉE
     */
        public function indexGlobal(Request $request)
    {
        $user = auth()->user();
    
        $query = Approvisionnement::select([
            'id',
            'reference',
            'type_demande',
            'destination_succursale_id',
            'source_decidee_id',
            'intermediaire_succursale_id',
            'quantite_demandee',
            'quantite_envoyee',
            'quantite_recue',
            'statut_demande',
            'statut_operation',
            'date_demande',
            'date_livraison_souhaitee',
            'demandeur_id',
            'created_at'
        ])->with([
            'sourceSuccursale:id,nom',
            'destinationSuccursale:id,nom',
            'sourceDecidee:id,nom',
            'intermediaireSuccursale:id,nom',
            'demandeur:id,nom_complet,nom_utilisateur',
            'lignes' => function ($query) {
                $query->select(
                    'id',
                    'approvisionnement_id',
                    'produit_id',
                    'quantite_demandee',
                    'quantite_envoyee',
                    'quantite_recue'
                )->with([
                    'produit' => function ($q) {
                        $q->select('id', 'nom', 'code_barre', 'type');
                    }
                ]);
            }
        ]);
    
       if ($user->isSuperAdmin() || $user->isChefOperation()||$user->isAuditeur()) {

        }  else {
            $query->where(function ($q) use ($user) {
                $q->where('source_succursale_id', $user->succursale_id)
                    ->orWhere('destination_succursale_id', $user->succursale_id)
                    ->orWhere('source_decidee_id', $user->succursale_id)
                    ->orWhere('intermediaire_succursale_id', $user->succursale_id);
            });
        }
    
        // Filtres dynamiques
        if ($request->filled('type_demande')) {
            $query->where('type_demande', $request->type_demande);
        }
    
        if ($request->filled('statut_demande')) {
            $query->where('statut_demande', $request->statut_demande);
        }
    
        if ($request->filled('statut_operation')) {
            $query->where('statut_operation', $request->statut_operation);
        }
    
        if ($request->filled('reference')) {
            $query->where('reference', 'LIKE', '%' . $request->reference . '%');
        }
    
        if ($request->filled('date_debut')) {
            $query->whereDate('date_demande', '>=', $request->date_debut);
        }
    
        if ($request->filled('date_fin')) {
            $query->whereDate('date_demande', '<=', $request->date_fin);
        }
    
        $perPage = $request->get('per_page', 15);
    
        $demandes = $query->orderBy('created_at', 'desc')
            ->paginate($perPage);
    
        return response()->json([
            'success' => true,
            'data' => $demandes
        ]);
    }

    /**
     * Affiche une demande spécifique.
     */
    public function showGlobal($id)
    {
        $user = auth()->user();

        $demande = Approvisionnement::with([
            'sourceSuccursale:id,nom',
            'destinationSuccursale:id,nom',
            'sourceDecidee:id,nom',
            'intermediaireSuccursale:id,nom',
            'demandeur:id,nom_complet,nom_utilisateur,email',
            'validateurOps:id,nom_complet,nom_utilisateur',
            'responsableLogistique:id,nom_complet,nom_utilisateur',
            'preparateur:id,nom_complet,nom_utilisateur',
            'expediteur:id,nom_complet,nom_utilisateur',
            'recepteur:id,nom_complet,nom_utilisateur',
            'decisionsOps.user:id,nom_complet,nom_utilisateur',
            'lignes' => function($query) {
                $query->with(['produit' => function($q) {
                    $q->select('id', 'nom', 'code_barre', 'type');
                }]);
            }
        ])->findOrFail($id);

        if (!$user->hasPermission('manage-stock-agence')) {
            $succursaleIds = collect([
                $demande->source_succursale_id,
                $demande->destination_succursale_id,
                $demande->source_decidee_id,
                $demande->intermediaire_succursale_id
            ])->filter();

            if (!$succursaleIds->contains($user->succursale_id)) {
                return response()->json(['success' => false, 'message' => 'Vous n\'avez pas accès à cette demande'], 403);
            }
        }

        return response()->json(['success' => true, 'data' => $demande]);
    }



    // ============================================================
    // MÉTHODES PRIVÉES - LOGIQUE MÉTIER
    // ============================================================

    private function verifierDisponibiliteStock(Approvisionnement $demande): array
    {
        $produitIds = $demande->lignes->pluck('produit_id')->toArray();
        $stocks = StockSuccursale::where('succursale_id', $demande->source_decidee_id)
            ->whereIn('produit_id', $produitIds)
            ->select('produit_id', 'quantite_disponible', 'quantite_reserve')
            ->get()
            ->keyBy('produit_id');

        $erreurs = [];

        foreach ($demande->lignes as $ligne) {
            $stock = $stocks->get($ligne->produit_id);
            // ✅ Utilisation de la méthode métier
            $stockDisponible = $stock ? $stock->stockDisponible() : 0;

            if ($stockDisponible < $ligne->quantite_demandee) {
                $erreurs[] = [
                    'produit' => $ligne->produit->nom ?? 'Produit inconnu',
                    'disponible' => $stockDisponible,
                    'demande' => $ligne->quantite_demandee,
                    'manquant' => $ligne->quantite_demandee - $stockDisponible
                ];
            }
        }

        return $erreurs;
    }

    /**
     * Réserver le stock - Logique alignée sur le POS
     * Utilise lockForUpdate() et met à jour quantite_reserve
     */
    private function reserverStock(Approvisionnement $demande): void
{
    foreach ($demande->lignes as $ligne) {
        // Verrouiller la ligne de stock avant modification
        $stock = StockSuccursale::where('succursale_id', $demande->source_decidee_id)
            ->where('produit_id', $ligne->produit_id)
            ->lockForUpdate()
            ->first();

        if (!$stock) {
            throw new \Exception("Stock introuvable pour le produit ID {$ligne->produit_id}");
        }

        // DÉLÉGUER À LA MÉTHODE MÉTIER
        // $stock->reserver($ligne->quantite_demandee);

        // Mettre à jour la ligne d'approvisionnement
        $ligne->update(['quantite_envoyee' => $ligne->quantite_demandee]);
    }
}

    private function libererReservations(Approvisionnement $demande): void
        {
            foreach ($demande->lignes as $ligne) {
                if ($ligne->quantite_envoyee <= 0) {
                    continue;
                }
    
                $stock = StockSuccursale::where('succursale_id', $demande->source_decidee_id)
                    ->where('produit_id', $ligne->produit_id)
                    ->lockForUpdate()
                    ->first();
    
                if ($stock && $stock->quantite_reserve >= $ligne->quantite_envoyee) {
                    // ✅ Utiliser libererReservation()
                    $stock->libererReservation($ligne->quantite_envoyee);
                    // Mettre à jour la ligne
                    $ligne->update(['quantite_envoyee' => 0]);
                }
            }
        }

/**
 * Créer les mouvements de sortie - DÉLÉGUÉ À StockSuccursale
 */
private function creerMouvementsSortie(Approvisionnement $demande, $user): void
{
    foreach ($demande->lignes as $ligne) {
        $quantite = (int) $ligne->quantite_envoyee;
        if ($quantite <= 0) {
            throw new \Exception("Quantité envoyée invalide pour le produit ID {$ligne->produit_id}");
        }

        // Verrouiller le stock avant modification
        $stock = StockSuccursale::where('succursale_id', $demande->source_decidee_id)
            ->where('produit_id', $ligne->produit_id)
            ->lockForUpdate()
            ->first();

        if (!$stock) {
            throw new \Exception("Stock introuvable pour le produit ID {$ligne->produit_id}");
        }

        // DÉLÉGUER À LA MÉTHODE MÉTIER
        $stock->expedier($quantite, [
            'demande_parent_id' => $demande->id,
            'reference_document' => $demande->reference,
            'responsable_id' => $user->id,
            'raison' => 'Expédition ' . $demande->type_demande,
            'type_expedition' => 'APPROVISIONNEMENT_EXPEDITION',
            'metadata' => [
                'approvisionnement_id' => $demande->id,
                'reference_approvisionnement' => $demande->reference,
                'type' => 'EXPEDITION'
            ]
        ]);
    }
}

/**
 * Créer les mouvements d'entrée intermédiaire - DÉLÉGUÉ À StockSuccursale
 */
private function creerMouvementsEntreeIntermediaire(Approvisionnement $demande, $user): void
{
    foreach ($demande->lignes as $ligne) {
        $quantite = (int) $ligne->quantite_envoyee;
        if ($quantite <= 0) {
            continue;
        }

        // Verrouiller le stock avant modification
        $stock = StockSuccursale::where('succursale_id', $demande->intermediaire_succursale_id)
            ->where('produit_id', $ligne->produit_id)
            ->lockForUpdate()
            ->first();

        // Créer le stock s'il n'existe pas
        if (!$stock) {
            $stock = StockSuccursale::create([
                'succursale_id' => $demande->intermediaire_succursale_id,
                'produit_id' => $ligne->produit_id,
                'quantite_disponible' => 0,
                'quantite_reserve' => 0
            ]);
        }

        // DÉLÉGUER À LA MÉTHODE MÉTIER
        $stock->receptionnerIntermediaire($quantite, [
            'demande_parent_id' => $demande->id,
            'reference_document' => $demande->reference,
            'responsable_id' => $user->id,
            'raison' => 'Arrivée au point intermédiaire',
            'metadata' => [
                'approvisionnement_id' => $demande->id,
                'reference_approvisionnement' => $demande->reference,
                'type' => 'ARRIVEE_INTERMEDIAIRE'
            ]
        ]);
    }
}

/**
 * Créer les mouvements de sortie intermédiaire - DÉLÉGUÉ À StockSuccursale
 */
private function creerMouvementsSortieIntermediaire(Approvisionnement $demande, $user): void
{
    foreach ($demande->lignes as $ligne) {
        $quantite = (int) $ligne->quantite_envoyee;
        if ($quantite <= 0) {
            continue;
        }

        // Verrouiller le stock avant modification
        $stock = StockSuccursale::where('succursale_id', $demande->intermediaire_succursale_id)
            ->where('produit_id', $ligne->produit_id)
            ->lockForUpdate()
            ->first();

        if (!$stock) {
            throw new \Exception("Stock introuvable pour le produit ID {$ligne->produit_id} au point intermédiaire");
        }

        Log::info('TEST UTF8 APPRO', [
    'reference' => $demande->reference,
    'valid_reference' => mb_check_encoding($demande->reference, 'UTF-8'),
    'reference_hex' => bin2hex($demande->reference),
]);
        // DÉLÉGUER À LA MÉTHODE MÉTIER
        $stock->expedierIntermediaire($quantite, [
            'demande_parent_id' => $demande->id,
            'reference_document' => $demande->reference,
            'responsable_id' => $user->id,
            'raison' => 'Départ du point intermédiaire',
            'metadata' => [
                'approvisionnement_id' => $demande->id,
                'reference_approvisionnement' => $demande->reference,
                'type' => 'DEPART_INTERMEDIAIRE'
            ]
        ]);
    }
}

/**
 * Traiter la réception - DÉLÉGUÉ À StockSuccursale
 */
private function traiterReception(Approvisionnement $demande, Request $request, $user): array
{
    $totalRecu = 0;
    $totalCasse = 0;
    $totalPerdue = 0;
    $totalNonConforme = 0;
    $totalAttendu = 0;
    $hasEcart = false;

    $lignesInput = collect($request->lignes)->keyBy('ligne_id');
    $lignesModel = LigneApprovisionnement::lockForUpdate()
        ->whereIn('id', $lignesInput->keys())
        ->get();

    foreach ($lignesModel as $ligne) {
        if ($ligne->approvisionnement_id != $demande->id) {
            throw new \Exception('Ligne non valide');
        }

        $ligneData = $lignesInput->get($ligne->id);
        $totalAttendu += $ligne->quantite_demandee;

        $quantiteRecue = $ligneData['quantite_recue'];
        $quantiteCasse = $ligneData['quantite_casse'] ?? 0;
        $quantitePerdue = $ligneData['quantite_perdue'] ?? 0;
        $quantiteNonConforme = $ligneData['quantite_non_conforme'] ?? 0;

        if ($quantiteCasse > 0 || $quantitePerdue > 0 || $quantiteNonConforme > 0) {
            $hasEcart = true;
        }

        $totalRecu += $quantiteRecue;
        $totalCasse += $quantiteCasse;
        $totalPerdue += $quantitePerdue;
        $totalNonConforme += $quantiteNonConforme;

        $ligne->update([
            'quantite_recue' => $quantiteRecue,
            'quantite_casse' => $quantiteCasse,
            'quantite_perdue' => $quantitePerdue,
            'quantite_non_conforme' => $quantiteNonConforme,
            'motif_ecart' => $ligneData['motif_ecart'] ?? null
        ]);

        if ($quantiteRecue > 0) {
            // Verrouiller le stock avant modification
            $stock = StockSuccursale::where('succursale_id', $demande->destination_succursale_id)
                ->where('produit_id', $ligne->produit_id)
                ->lockForUpdate()
                ->first();

            // Créer le stock s'il n'existe pas
            if (!$stock) {
                $stock = StockSuccursale::create([
                    'succursale_id' => $demande->destination_succursale_id,
                    'produit_id' => $ligne->produit_id,
                    'quantite_disponible' => 0,
                    'quantite_reserve' => 0
                ]);
            }

            // DÉLÉGUER À LA MÉTHODE MÉTIER
            $stock->receptionner($quantiteRecue, [
                'demande_parent_id' => $demande->id,
                'reference_document' => $demande->reference,
                'responsable_id' => $user->id,
                'raison' => 'Réception ' . $demande->type_demande,
                'metadata' => [
                    'approvisionnement_id' => $demande->id,
                    'reference_approvisionnement' => $demande->reference,
                    'type' => 'RECEPTION',
                    'quantite_casse' => $quantiteCasse,
                    'quantite_perdue' => $quantitePerdue,
                    'quantite_non_conforme' => $quantiteNonConforme
                ]
            ]);
        }
    }

    return [
        'totalAttendu' => $totalAttendu,
        'totalRecu' => $totalRecu,
        'totalCasse' => $totalCasse,
        'totalPerdue' => $totalPerdue,
        'totalNonConforme' => $totalNonConforme,
        'hasEcart' => $hasEcart
    ];
}
    // ============================================================
    // MÉTHODES PRIVÉES - NOTIFICATIONS
    // ============================================================

    private function notifierNouvelleDemande($demande, $user)
    {
        $produitsListe = $demande->lignes->map(function ($ligne) {
            return $ligne->quantite_demandee . 'x ' . ($ligne->produit->nom ?? 'Produit inconnu');
        })->implode(', ');

        $this->notifierDestinatairesSpecifiques([
            'destinataires' => ['type' => 'role', 'code' => 'CHEF_AGENCE'],
            'type' => 'DEMANDE_APPROVISIONNEMENT',
            'titre' => "📦 Nouvelle demande de {$demande->type_demande}",
            'message' => "Demande #{$demande->reference} créée par {$user->nom_complet} - {$produitsListe}",
            'donnees' => [
                'demande_id' => $demande->id,
                'reference' => $demande->reference,
                'type' => $demande->type_demande,
                'quantite_totale' => $demande->quantite_demandee,
                'produits' => $produitsListe,
                'date_livraison_souhaitee' => $demande->date_livraison_souhaitee
            ],
            'succursale_id' => $demande->destination_succursale_id,
            'priorite' => 'HAUTE'
        ]);

        $this->notifierDestinatairesSpecifiques([
            'destinataires' => ['type' => 'role', 'code' => 'CHEF_OPERATIONS'],
            'type' => 'DEMANDE_APPROVISIONNEMENT',
            'titre' => "🆕 Demande en attente de validation",
            'message' => "Demande #{$demande->reference} - Source: " . ($demande->source_succursale->nom ?? 'À définir') . " → Destination: {$demande->destinationSuccursale->nom}",
            'donnees' => ['demande_id' => $demande->id, 'reference' => $demande->reference],
            'priorite' => 'HAUTE'
        ]);
    }

    private function notifierDemandePreparee($demande, $user)
    {
        $this->notifierDestinatairesSpecifiques([
            'destinataires' => ['type' => 'role', 'code' => 'CHEF_AGENCE'],
            'type' => 'APPROVISIONNEMENT_VALIDE',
            'titre' => "✅ Demande préparée",
            'message' => "La demande #{$demande->reference} a été préparée par {$user->nom_complet}",
            'donnees' => [
                'demande_id' => $demande->id,
                'reference' => $demande->reference,
                'preparateur' => $user->nom_complet
            ],
            'succursale_id' => $demande->destination_succursale_id,
            'priorite' => 'NORMALE'
        ]);
    }

    private function notifierDemandeExpediee($demande, $user)
    {
        $this->notifierDestinatairesSpecifiques([
            'destinataires' => ['type' => 'responsable_succursale', 'succursale_id' => $demande->destination_succursale_id],
            'type' => 'TRANSFERT_EXPEDIE',
            'titre' => "🚚 Produits en route",
            'message' => "La demande #{$demande->reference} a été expédiée par {$user->nom_complet}",
            'donnees' => [
                'demande_id' => $demande->id,
                'reference' => $demande->reference,
                'moyen_transport' => $demande->moyen_transport,
                'code_suivi' => $demande->code_suivi
            ],
            'succursale_id' => $demande->destination_succursale_id,
            'priorite' => 'HAUTE'
        ]);

        $this->notifierDestinatairesSpecifiques([
            'destinataires' => ['type' => 'role', 'code' => 'CHEF_AGENCE'],
            'type' => 'TRANSFERT_EXPEDIE',
            'titre' => "📦 {$demande->type_demande} expédié",
            'message' => "{$demande->type_demande} #{$demande->reference} expédié vers {$demande->destinationSuccursale->nom}",
            'donnees' => ['demande_id' => $demande->id, 'reference' => $demande->reference],
            'priorite' => 'NORMALE'
        ]);
    }

    private function notifierArriveeIntermediaire($demande, $user)
    {
        $this->notifierDestinatairesSpecifiques([
            'destinataires' => ['type' => 'responsable_succursale', 'succursale_id' => $demande->destination_succursale_id],
            'type' => 'TRANSFERT_PREPARE',
            'titre' => "📍 Produits arrivés au point intermédiaire",
            'message' => "Les produits de la demande #{$demande->reference} sont arrivés chez l'intermédiaire",
            'donnees' => ['demande_id' => $demande->id, 'reference' => $demande->reference],
            'succursale_id' => $demande->destination_succursale_id,
            'priorite' => 'NORMALE'
        ]);
    }

    private function notifierDepartIntermediaire($demande, $user)
    {
        $this->notifierDestinatairesSpecifiques([
            'destinataires' => ['type' => 'responsable_succursale', 'succursale_id' => $demande->destination_succursale_id],
            'type' => 'TRANSFERT_PREPARE',
            'titre' => "🚚 Produits en route vers la destination",
            'message' => "Les produits de la demande #{$demande->reference} ont quitté l'intermédiaire",
            'donnees' => ['demande_id' => $demande->id, 'reference' => $demande->reference],
            'succursale_id' => $demande->destination_succursale_id,
            'priorite' => 'HAUTE'
        ]);
    }

    private function notifierReceptionComplete($demande, $user)
    {
        if ($demande->expediteur_id) {
            $this->notifierDestinatairesSpecifiques([
                'destinataires' => ['type' => 'utilisateur', 'id' => $demande->expediteur_id],
                'type' => 'TRANSFERT_RECU',
                'titre' => "✅ {$demande->type_demande} réceptionné avec succès",
                'message' => "Le {$demande->type_demande} #{$demande->reference} a été réceptionné par {$user->nom_complet}",
                'donnees' => [
                    'demande_id' => $demande->id,
                    'reference' => $demande->reference,
                    'quantite_recue' => $demande->quantite_recue
                ],
                'succursale_id' => $demande->destination_succursale_id,
                'priorite' => 'HAUTE'
            ]);
        }

        $this->notifierDestinatairesSpecifiques([
            'destinataires' => ['type' => 'role', 'code' => 'CHEF_AGENCE'],
            'type' => 'TRANSFERT_RECU',
            'titre' => "✅ {$demande->type_demande} complété",
            'message' => "{$demande->type_demande} #{$demande->reference} réceptionné par {$demande->destinationSuccursale->nom}",
            'donnees' => ['demande_id' => $demande->id, 'reference' => $demande->reference],
            'priorite' => 'NORMALE'
        ]);
    }

    private function notifierReceptionAvecEcart($demande, $user, $stats)
    {
        $messageEcart = "Écarts: ";
        if ($stats['totalCasse'] > 0) {
            $messageEcart .= "{$stats['totalCasse']} cassé(s), ";
        }
        if ($stats['totalPerdue'] > 0) {
            $messageEcart .= "{$stats['totalPerdue']} perdu(s), ";
        }
        if ($stats['totalNonConforme'] > 0) {
            $messageEcart .= "{$stats['totalNonConforme']} non conforme(s)";
        }
        $messageEcart = rtrim($messageEcart, ', ');

        if ($demande->expediteur_id) {
            $this->notifierDestinatairesSpecifiques([
                'destinataires' => ['type' => 'utilisateur', 'id' => $demande->expediteur_id],
                'type' => 'ECART_TRANSFERT',
                'titre' => "⚠️ Écart constaté à la réception",
                'message' => "{$demande->type_demande} #{$demande->reference}: attendu {$stats['totalAttendu']}, reçu {$stats['totalRecu']}. {$messageEcart}",
                'donnees' => [
                    'demande_id' => $demande->id,
                    'reference' => $demande->reference,
                    'stats' => $stats
                ],
                'succursale_id' => $demande->destination_succursale_id,
                'priorite' => 'URGENTE'
            ]);
        }

        $this->notifierDestinatairesSpecifiques([
            'destinataires' => ['type' => 'role', 'code' => 'CHEF_AGENCE'],
            'type' => 'ECART_TRANSFERT',
            'titre' => "⚠️ Écart sur {$demande->type_demande} #{$demande->reference}",
            'message' => $messageEcart,
            'donnees' => [
                'demande_id' => $demande->id,
                'reference' => $demande->reference,
                'stats' => $stats
            ],
            'priorite' => 'HAUTE'
        ]);
    }

    private function notifierFinanceEcart($demande, $stats)
    {
        $this->notifierDestinatairesSpecifiques([
            'destinataires' => ['type' => 'role', 'code' => 'COMPTABLE_HOLDING'],
            'type' => 'ECART_TRANSFERT',
            'titre' => "💰 Impact financier - Écart sur {$demande->type_demande}",
            'message' => "{$demande->type_demande} #{$demande->reference}: Casse: {$stats['totalCasse']}, Perte: {$stats['totalPerdue']}. Valorisation à vérifier.",
            'donnees' => [
                'demande_id' => $demande->id,
                'reference' => $demande->reference,
                'stats' => $stats
            ],
            'priorite' => 'HAUTE'
        ]);
    }

    private function notifierDemandeAnnulee($demande, $user, $motif)
    {
        if ($demande->demandeur_id && $demande->demandeur_id != $user->id) {
            $this->notifierDestinatairesSpecifiques([
                'destinataires' => ['type' => 'utilisateur', 'id' => $demande->demandeur_id],
                'type' => 'DEMANDE_APPROVISIONNEMENT',
                'titre' => "❌ Votre demande a été annulée",
                'message' => "Demande #{$demande->reference} annulée par {$user->nom_complet}. Motif: {$motif}",
                'donnees' => [
                    'demande_id' => $demande->id,
                    'reference' => $demande->reference,
                    'motif' => $motif
                ],
                'priorite' => 'HAUTE'
            ]);
        }

        $this->notifierDestinatairesSpecifiques([
            'destinataires' => ['type' => 'role', 'code' => 'CHEF_AGENCE'],
            'type' => 'MOUVEMENT_ANNULE',
            'titre' => "❌ Demande annulée",
            'message' => "Demande #{$demande->reference} annulée par {$user->nom_complet}",
            'donnees' => ['demande_id' => $demande->id, 'reference' => $demande->reference],
            'priorite' => 'NORMALE'
        ]);
    }

    private function notifierStockInsuffisant($demande, $erreursStock, $user)
    {
        $messageErreurs = '';
        foreach ($erreursStock as $erreur) {
            $messageErreurs .= "- {$erreur['produit']}: besoin {$erreur['demande']}, disponible {$erreur['disponible']}\n";
        }

        $this->notifierDestinatairesSpecifiques([
            'destinataires' => ['type' => 'role', 'code' => 'SUPER_ADMIN'],
            'type' => 'ALERTE_STOCK',
            'titre' => "⚠️ Stock insuffisant pour préparation",
            'message' => "Demande #{$demande->reference}:\n" . $messageErreurs,
            'donnees' => [
                'demande_id' => $demande->id,
                'reference' => $demande->reference,
                'erreurs' => $erreursStock
            ],
            'succursale_id' => $demande->source_decidee_id,
            'priorite' => 'URGENTE'
        ]);
    }
}