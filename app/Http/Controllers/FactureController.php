<?php
// app/Http/Controllers/FactureController.php

namespace App\Http\Controllers;

use App\Models\Facture;
use App\Models\Paiement;
use App\Models\Caisses;
use App\Models\Utilisateur;
use App\Services\POSService;
use App\Services\FacturePrintService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class FactureController extends Controller
{
    protected POSService $posService;
    protected FacturePrintService $printService;

    public function __construct(POSService $posService, FacturePrintService $printService)
    {
        $this->posService = $posService;
        $this->printService = $printService;
    }

    /**
     * Liste des factures avec filtres optimisés
     */
    public function index(Request $request)
    {
        $user = auth()->user();
    
        $query = Facture::with([
            'client:id,nom_complet,telephone,email',
            'utilisateur:id,nom_complet',
            'succursale:id,nom',
            'caisse:id,nom_caisse'
        ]);
    
        if ($user->isSuperAdmin() || $user->isChefOperation()) {
            // Accès complet
        } elseif ($user->isChefAgence()) {
            if (!$user->succursale_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune succursale associée'
                ], 403);
            }
            $query->where('succursale_id', $user->succursale_id);
        } else {
            $query->where(function ($q) use ($user) {
                $q->where('utilisateur_id', $user->id)
                  ->orWhereHas('caisse', function ($cq) use ($user) {
                      $cq->where('caissier_attribue', $user->id);
                  });
            });
        }
        
        // Filtres
        if ($request->filled('statut')) {
            $query->parStatut($request->statut);
        }
        if ($request->filled('client_id')) {
            $query->parClient($request->client_id);
        }
        if ($request->filled('date_debut') && $request->filled('date_fin')) {
            $query->parPeriode($request->date_debut, $request->date_fin);
        }
        if ($request->filled('succursale_id') && ($user->isSuperAdmin() || $user->isChefOperation())) {
            $query->where('succursale_id', $request->succursale_id);
        }
        if ($request->filled('type_facture')) {
            $query->where('type_facture', $request->type_facture);
        }

        // Tri sécurisé
        $sortField = $request->input('sort', 'date_facture');
        $sortDirection = $request->input('direction', 'desc');
        $allowedSorts = ['date_facture', 'total_ttc', 'reference', 'statut', 'created_at'];
        if (in_array($sortField, $allowedSorts)) {
            $sortDirection = in_array(strtolower($sortDirection), ['asc', 'desc']) ? $sortDirection : 'desc';
            $query->orderBy($sortField, $sortDirection);
        }

        $perPage = $request->input('per_page', 20);
        $perPage = min(max((int) $perPage, 1), 100);

        return response()->json([
            'success' => true,
            'data' => $query->paginate($perPage)
        ]);
    }

    /**
     * Détail d'une facture - OPTIMISÉ avec IMEI
     */
    public function show($id)
    {
        if (empty($id) || $id === 'undefined' || $id === 'null') {
            return response()->json([
                'success' => false,
                'message' => 'ID de facture invalide.'
            ], 400);
        }

        $id = (int) $id;

        $facture = Facture::with([
            'client:id,nom_complet,telephone,email,adresse,categorie_client,statut',
            'utilisateur:id,nom_complet,email',
            'succursale:id,nom,adresse,telephone,email',
            'caisse:id,nom_caisse,type_caisse,code',
            'lignes' => function($query) {
                $query->select(
                    'id', 'facture_id', 'produit_id', 'stock_succursale_id', 
                    'quantite', 'prix_unitaire_ht', 'prix_unitaire_ttc', 
                    'total_ht', 'total_ttc', 'remise_ligne', 'type_remise_ligne',
                    'lots_fifo_utilises', 'imeis_utilises', 'notes'
                )
                ->with([
                    'produit:id,nom,code_barre,prix_vente,gere_imei'
                ]);
            },
            'paiements' => function($query) {
                $query->select('id', 'facture_id', 'montant', 'mode_paiement', 'date_paiement', 'statut', 'reference_externe')
                    ->orderBy('date_paiement', 'desc');
            }
        ])->findOrFail($id);

        // ✅ Pour chaque ligne, charger les IMEI associés
        foreach ($facture->lignes as $ligne) {
            if ($ligne->imeis_utilises) {
                $ligne->imeis_details = \App\Models\ProduitImei::whereIn('id', $ligne->imeis_utilises)
                    ->select('id', 'numero_imei', 'numero_serie', 'lot_stock_id', 'prix_achat')
                    ->get();
            } else {
                $ligne->imeis_details = collect();
            }
        }

        return response()->json([
            'success' => true,
            'data' => $facture
        ]);
    }

    /**
     * Annulation d'une facture (avec gestion des lots FIFO et IMEI via POSService)
     */
    public function annuler(Request $request, $id)
    {
        if (empty($id) || $id === 'undefined' || $id === 'null') {
            return response()->json([
                'success' => false,
                'message' => 'ID de facture invalide.'
            ], 400);
        }

        $id = (int) $id;

        $validator = Validator::make($request->all(), [
            'motif' => 'required|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $result = $this->posService->annulerVente($id, $request->motif);
            return response()->json([
                'success' => true,
                'message' => $result['message'] ?? 'Vente annulée avec succès.',
                'data' => $result['facture'] ?? null
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur annulation facture', [
                'facture_id' => $id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Payer une facture (délègue à POSService - gère IMEI)
     */
    public function payer(Request $request, $id)
    {
        if (empty($id) || $id === 'undefined' || $id === 'null') {
            return response()->json([
                'success' => false,
                'message' => 'ID de facture invalide.'
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'mode_paiement' => 'required|in:ESPECES,MOBILE_MONEY',
            'montant' => 'nullable|numeric|min:0',
            'numero_mobile' => 'required_if:mode_paiement,MOBILE_MONEY|string',
            'reference_externe' => 'nullable|string|max:255',
            'details_paiement' => 'nullable|array',
            'notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $resultat = $this->posService->payerVente((int) $id, $request->all());
            return response()->json([
                'success' => true,
                'message' => $resultat['message'] ?? 'Paiement effectué avec succès.',
                'data' => $resultat
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur paiement facture', [
                'facture_id' => $id,
                'user_id' => auth()->id(),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Vérifier le statut d'un paiement Mobile Money
     */
    public function verifierPaiement($id)
    {
        try {
            $resultat = $this->posService->verifierPaiement((int) $id);
            return response()->json([
                'success' => true,
                'data' => $resultat
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Remboursement d'une vente payée (avec gestion des lots FIFO et IMEI via POSService)
     */
    public function rembourser(Request $request, $id)
    {
        if (empty($id) || $id === 'undefined' || $id === 'null') {
            return response()->json([
                'success' => false,
                'message' => 'ID de facture invalide.'
            ], 400);
        }

        $id = (int) $id;

        $validator = Validator::make($request->all(), [
            'motif' => 'required|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $result = $this->posService->rembourserVente($id, $request->motif);
            return response()->json([
                'success' => true,
                'message' => $result['message'] ?? 'Remboursement effectué avec succès.',
                'data' => $result['facture'] ?? null
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur remboursement facture', [
                'facture_id' => $id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Impression d'une facture (A4) avec IMEI
     */
    public function imprimer($id)
    {
        try {
            $facture = $this->loadFactureWithRelations($id);
            return response($this->printService->imprimerA4($facture))
                ->header('Content-Type', 'text/html');
        } catch (\Exception $e) {
            Log::error('Erreur impression facture', [
                'facture_id' => $id,
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Impression ticket 80mm avec IMEI
     */
    public function ticket($id)
    {
        try {
            $facture = $this->loadFactureWithRelations($id);
            return response($this->printService->imprimerTicket($facture))
                ->header('Content-Type', 'text/html');
        } catch (\Exception $e) {
            Log::error('Erreur impression ticket', [
                'facture_id' => $id,
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Générer le PDF de la facture avec IMEI
     */
    public function pdf($id)
    {
        try {
            $facture = $this->loadFactureWithRelations($id);
            return $this->printService->pdfA4($facture);
        } catch (\Exception $e) {
            Log::error('Erreur génération PDF', [
                'facture_id' => $id,
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Télécharger le PDF de la facture avec IMEI
     */
    public function download($id)
    {
        try {
            $facture = $this->loadFactureWithRelations($id);
            return $this->printService->downloadPdf($facture);
        } catch (\Exception $e) {
            Log::error('Erreur téléchargement PDF', [
                'facture_id' => $id,
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Charger une facture avec toutes ses relations et IMEI
     */
    protected function loadFactureWithRelations($id): Facture
    {
        if (empty($id) || $id === 'undefined' || $id === 'null') {
            throw new \InvalidArgumentException('ID de facture invalide.');
        }

        $id = (int) $id;

        $facture = Facture::with([
            'client',
            'lignes.produit',
            'succursale',
            'utilisateur',
            'paiements',
            'devise',
            'caisse'
        ])->findOrFail($id);

        // ✅ Charger les IMEI pour chaque ligne
        foreach ($facture->lignes as $ligne) {
            if ($ligne->imeis_utilises) {
                $ligne->imeis_details = \App\Models\ProduitImei::whereIn('id', $ligne->imeis_utilises)
                    ->select('id', 'numero_imei', 'numero_serie', 'lot_stock_id', 'prix_achat')
                    ->get();
            } else {
                $ligne->imeis_details = collect();
            }
        }

        return $facture;
    }

    /**
     * Statistiques des factures
     */
    public function statistiques(Request $request)
    {
        $user = auth()->user();
        
        $query = Facture::query();
        
        if ($user && !$user->hasPermission('view-all-factures')) {
            $query->where('succursale_id', $user->succursale_id);
        }

        if ($request->filled('date_debut') && $request->filled('date_fin')) {
            $query->whereBetween('date_facture', [
                $request->date_debut,
                $request->date_fin
            ]);
        } else {
            $query->whereMonth('date_facture', now()->month)
                  ->whereYear('date_facture', now()->year);
        }

        $stats = [
            'total_factures' => $query->count(),
            'total_ttc' => $query->sum('total_ttc'),
            'total_paye' => $query->sum('montant_regle'),
            'total_impaye' => $query->sum('reste_a_payer'),
            'par_statut' => $query->selectRaw('statut, count(*) as total, sum(total_ttc) as montant')
                ->groupBy('statut')
                ->get(),
            'par_type' => $query->selectRaw('type_facture, count(*) as total, sum(total_ttc) as montant')
                ->groupBy('type_facture')
                ->get()
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * Suppression d'une facture (brouillon uniquement)
     */
    public function destroy($id)
    {
        if (empty($id) || $id === 'undefined' || $id === 'null') {
            return response()->json([
                'success' => false,
                'message' => 'ID de facture invalide.'
            ], 400);
        }

        $id = (int) $id;

        try {
            $facture = Facture::findOrFail($id);

            if ($facture->statut !== 'BROUILLON') {
                return response()->json([
                    'success' => false,
                    'message' => 'Seules les factures en brouillon peuvent être supprimées. Statut actuel : ' . $facture->statut_label
                ], 422);
            }

            if (!auth()->user()->hasPermission('delete-factures')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vous n\'avez pas les droits pour supprimer des factures.'
                ], 403);
            }

            $facture->delete();

            return response()->json([
                'success' => true,
                'message' => 'Facture supprimée avec succès.'
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur suppression facture', [
                'facture_id' => $id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

}