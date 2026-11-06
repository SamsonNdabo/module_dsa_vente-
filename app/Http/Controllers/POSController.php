<?php
// app/Http/Controllers/POSController.php

namespace App\Http\Controllers;

use App\Services\POSService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use App\Models\Produit;
use App\Models\ProduitImei;

class POSController extends Controller
{
    protected POSService $posService;

    public function __construct(POSService $posService)
    {
        $this->posService = $posService;
    }

    // ============================================================
    // GESTION DE LA VENTE
    // ============================================================

    public function initierVente(Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non authentifié.'
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'telephone' => 'required|string|max:20',
            'nom_client' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|max:255',
            'caisse_id' => 'required|integer|exists:caisses,id',
            'notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $data = $request->all();
            $data['succursale_id'] = $user->succursale_id;
            $data['utilisateur_id'] = $user->id;

            $vente = $this->posService->initierVente($data);

            return response()->json([
                'success' => true,
                'message' => 'Vente initialisée avec succès',
                'data' => $vente
            ], 201);
        } catch (\Exception $e) {
            Log::error('Erreur initiation vente', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Finaliser la vente avec le panier envoyé par le frontend
     */
    public function finaliserVente(Request $request, $venteId)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non authentifié.'
            ], 401);
        }
        
        if (empty($venteId) || $venteId === 'undefined' || $venteId === 'null') {
            return response()->json([
                'success' => false,
                'message' => 'ID de vente invalide. Veuillez réessayer.'
            ], 400);
        }

        $venteId = (int) $venteId;

        $validator = Validator::make($request->all(), [
            'panier' => 'required|array|min:1',
            'panier.*.produit_id' => 'required|integer|exists:produit,id',
            'panier.*.quantite' => 'required|integer|min:1',
            'panier.*.remise_ligne' => 'sometimes|numeric|min:0',
            'panier.*.type_remise_ligne' => 'sometimes|in:FIXE,POURCENTAGE',
            //  AJOUT : Validation des IMEI pour les produits qui en ont besoin
            'panier.*.imei_ids' => 'nullable|array',
            'panier.*.imei_ids.*' => 'nullable|integer|exists:produit_imei,id',
            'notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $result = $this->posService->finaliserVente($venteId, $request->all());

            return response()->json([
                'success' => true,
                'message' => $result['message'] ?? 'Vente finalisée avec succès',
                'data' => $result
            ], 200);
        } catch (\Exception $e) {
            Log::error('Erreur finalisation vente', [
                'vente_id' => $venteId,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    // ============================================================
    // GESTION DU PAIEMENT
    // ============================================================

    public function payerVente(Request $request, $venteId)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non authentifié.'
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'mode_paiement' => 'required|in:ESPECES,MOBILE_MONEY',
            'montant' => 'required|numeric|min:0.01',

            'id_operateur_mobile_money' => 'required_if:mode_paiement,MOBILE_MONEY|exists:operateurs_mobile_money,id',
            'numero_mobile' => 'required_if:mode_paiement,MOBILE_MONEY|string|max:20',
            'nom_client' => 'required_if:mode_paiement,MOBILE_MONEY|string|max:255',
            'description' => 'required_if:mode_paiement,MOBILE_MONEY|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $result = $this->posService->payerVente($venteId, $request->all());

            return response()->json([
                'success' => true,
                'message' => $result['message'] ?? 'Paiement traité avec succès',
                'data' => $result
            ], 200);
        } catch (\Exception $e) {
            Log::error('Erreur paiement vente', [
                'vente_id' => $venteId,
                'user_id' => $user->id,
                'mode' => $request->mode_paiement,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function verifierPaiement(Request $request, $venteId)
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non authentifié.'
            ], 401);
        }

        try {
            $result = $this->posService->verifierPaiement($venteId);

            return response()->json([
                'success' => $result['success'],
                'message' => $result['message'],
                'data' => $result
            ], 200);

        } catch (\Exception $e) {

            Log::error('Erreur vérification paiement', [
                'vente_id' => $venteId,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    // ============================================================
    // GESTION DES PRODUITS
    // ============================================================

    public function verifierProduit(Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non authentifié.'
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'produit_id' => 'required|integer|exists:produit,id',
            'quantite' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $resultat = $this->posService->verifierProduit(
                $request->produit_id,
                $user->succursale_id,
                $request->quantite
            );

            return response()->json([
                'success' => true,
                'message' => $resultat['disponible'] ? 'Produit disponible' : 'Stock insuffisant',
                'data' => $resultat
            ], 200);
        } catch (\Exception $e) {
            Log::error('Erreur vérification produit', [
                'user_id' => $user->id,
                'produit_id' => $request->produit_id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function verifierParScan(Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non authentifié.'
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'code_barre' => 'required|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $resultat = $this->posService->verifierParScan(
                $request->code_barre,
                $user->succursale_id
            );

            return response()->json([
                'success' => true,
                'message' => 'Produit trouvé',
                'data' => $resultat
            ], 200);
        } catch (\Exception $e) {
            Log::error('Erreur scan produit', [
                'user_id' => $user->id,
                'code_barre' => $request->code_barre,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 404);
        }
    }

    public function rechercherProduits(Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non authentifié.'
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'q' => 'required|string|min:2',
            'limit' => 'sometimes|integer|min:1|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $limit = $request->input('limit', 10);
            $produits = $this->posService->rechercherProduits(
                $request->q,
                $user->succursale_id,
                $limit
            );

            return response()->json([
                'success' => true,
                'message' => 'Résultats de recherche',
                'data' => $produits
            ], 200);
        } catch (\Exception $e) {
            Log::error('Erreur recherche produits', [
                'user_id' => $user->id,
                'query' => $request->q,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    // ============================================================
    // ANNULATION ET CONSULTATION
    // ============================================================

    public function annulerVente(Request $request, $venteId)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non authentifié.'
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'motif' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $vente = $this->posService->annulerVente($venteId, $request->motif);

            return response()->json([
                'success' => true,
                'message' => 'Vente annulée avec succès',
                'data' => $vente
            ], 200);
        } catch (\Exception $e) {
            Log::error('Erreur annulation vente', [
                'vente_id' => $venteId,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    // ============================================================
    // GESTION DES CLIENTS (INCHANGÉ)
    // ============================================================

    public function getClient(Request $request)
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non authentifié.'
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'telephone' => 'nullable|string|max:20',
            'nom_client' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors()
            ], 422);
        }

        if (empty($request->telephone) && empty($request->nom_client)) {
            return response()->json([
                'success' => false,
                'message' => 'Veuillez fournir soit le numéro soit le nom du client.'
            ], 422);
        }

        try {
            $client = $this->posService->getClient(
                $request->telephone,
                $request->nom_client
            );

            if (!$client) {
                return response()->json([
                    'success' => false,
                    'message' => 'Client introuvable.',
                    'client_existe' => false
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Client trouvé',
                'data' => [
                    'id' => $client->id,
                    'nom_complet' => $client->nom_complet,
                    'telephone' => $client->telephone,
                    'email' => $client->email,
                    'adresse' => $client->adresse,
                    'categorie_client' => $client->categorie_client,
                    'statut' => $client->statut,
                    'nombre_achats' => $client->nombre_achats,
                    'total_achats' => $client->total_achats,
                    'date_dernier_achat' => $client->date_dernier_achat,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur recherche client', [
                'user_id' => $user->id,
                'telephone' => $request->telephone,
                'nom_client' => $request->nom_client,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function createClient(Request $request)
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non authentifié.'
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'telephone'  => 'nullable|string|max:20|unique:clients,telephone',
            'nom_client' => 'required|string|max:255',
            'email'      => 'nullable|email|max:255|unique:clients,email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors()
            ], 422);
        }

        if (empty($request->telephone) && empty($request->nom_client)) {
            return response()->json([
                'success' => false,
                'message' => 'Le numéro ou le nom du client est obligatoire.'
            ], 422);
        }

        try {
            $client = $this->posService->createClient(
                $request->telephone,
                $request->nom_client,
                $request->email
            );

            return response()->json([
                'success' => true,
                'message' => 'Client créé avec succès.',
                'data' => [
                    'id' => $client->id,
                    'nom_complet' => $client->nom_complet,
                    'telephone' => $client->telephone,
                    'email' => $client->email,
                    'adresse' => $client->adresse,
                    'categorie_client' => $client->categorie_client,
                    'statut' => $client->statut,
                    'nombre_achats' => $client->nombre_achats,
                    'total_achats' => $client->total_achats,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur création client', [
                'user_id' => $user->id,
                'telephone' => $request->telephone,
                'nom_client' => $request->nom_client,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    // ============================================================
    // RÉCUPÉRATION D'UNE VENTE (INCHANGÉ)
    // ============================================================

    public function getVente($venteId)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non authentifié.'
            ], 401);
        }

        if (empty($venteId) || $venteId === 'undefined' || $venteId === 'null') {
            return response()->json([
                'success' => false,
                'message' => 'ID de vente invalide.'
            ], 400);
        }

        $venteId = (int) $venteId;

        try {
            $vente = $this->posService->getVente($venteId);

            return response()->json([
                'success' => true,
                'message' => 'Détails de la vente',
                'data' => $vente
            ], 200);
        } catch (\Exception $e) {
            Log::error('Erreur récupération vente', [
                'vente_id' => $venteId,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 404);
        }
    }

    // ============================================================
    // NOUVEAU : RÉCUPÉRATION DES IMEI DISPONIBLES POUR UN PRODUIT
    // ============================================================
    // Le caissier peut voir les IMEI disponibles pour un produit
    // ============================================================

        public function getImeisDisponibles(Request $request, $produitId)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non authentifié.'
            ], 401);
        }
    
        try {
            $produit = Produit::findOrFail($produitId);
    
            if (!$produit->gere_imei) {
                return response()->json([
                    'success' => true,
                    'message' => 'Ce produit ne gère pas les IMEI',
                    'data' => [
                        'imeis' => [],
                        'gere_imei' => false,
                    ]
                ], 200);
            }
    
            // Récupérer les IMEI EN_STOCK dans la succursale du caissier
            //  Avec filtre sur le statut du lot = ACTIF
            $imeis = ProduitImei::where('produit_id', $produitId)
                ->where('succursale_id', $user->succursale_id)
                ->where('statut', ProduitImei::STATUT_EN_STOCK)
                ->whereHas('lotStock', function ($query) {
                    $query->where('statut', 'ACTIF'); //  Filtrer les lots ACTIFS
                })
                ->with('lotStock')
                ->orderBy('lot_stock_id')
                ->orderBy('created_at')
                ->get();
    
            return response()->json([
                'success' => true,
                'message' => 'IMEI disponibles',
                'data' => [
                    'produit' => [
                        'id' => $produit->id,
                        'nom' => $produit->nom,
                    ],
                    'imeis' => $imeis->map(function ($imei) {
                        return [
                            'id' => $imei->id,
                            'numero_imei' => $imei->numero_imei,
                            'numero_serie' => $imei->numero_serie,
                            'lot_id' => $imei->lot_stock_id,
                            'prix_achat' => $imei->prix_achat,
                            'est_sous_garantie' => $imei->est_sous_garantie,
                            'lot_statut' => $imei->lotStock?->statut, //  Ajout du statut du lot pour info
                        ];
                    })->toArray(),
                    'quantite_disponible' => $imeis->count(),
                ]
            ], 200);
    
        } catch (\Exception $e) {
            Log::error('Erreur récupération IMEI disponibles', [
                'user_id' => $user->id,
                'produit_id' => $produitId,
                'error' => $e->getMessage()
            ]);
    
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }
}