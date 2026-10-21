<?php

namespace App\Http\Controllers\Approvisionnement;

use App\Http\Controllers\Controller;
use App\Models\Approvisionnement;
use App\Models\SeuilLogistique;
use App\Services\Logistique\ValidationLogistiqueService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ApprobationLogistiqueController extends Controller
{
    protected ValidationLogistiqueService $validationService;

    public function __construct(ValidationLogistiqueService $validationService)
    {
        $this->validationService = $validationService;
    }

    /**
     * Dashboard des demandes en attente d'approbation supérieure
     */
    public function dashboard(Request $request)
    {
        $user = auth()->user();

        // ✅ Récupérer toutes les demandes en attente
        $demandesEnAttente = Approvisionnement::where('statut_approbation_logistique', 'EN_ATTENTE')
            ->with([
                'destinationSuccursale',
                'sourceDecidee',
                'lignes.produit',
                'demandeur',
                'responsableLogistique',
                'devise'
            ])
            ->orderBy('date_validation_logistique', 'asc')
            ->get();

        $stats = [
            'en_attente' => $demandesEnAttente->count(),
            'valides' => Approvisionnement::where('statut_approbation_logistique', 'APPROUVE')->count(),
            'rejetes' => Approvisionnement::where('statut_approbation_logistique', 'REJETE')->count()
        ];

        return response()->json([
            'success' => true,
            'stats' => $stats,
            'en_attente_approbation' => $demandesEnAttente
        ]);
    }

    /**
     * Approuver une demande de logistique
     */
    public function approuver($id, Request $request)
    {
        $user = auth()->user();

        $demande = Approvisionnement::findOrFail($id);

        if ($demande->statut_approbation_logistique !== 'EN_ATTENTE') {
            return response()->json([
                'success' => false,
                'message' => 'Cette demande n\'est pas en attente d\'approbation'
            ], 422);
        }

        // ✅ VÉRIFICATION DU RÔLE REQUIS
        $roleRequis = $this->getRoleRequisPourApprobation($demande);
        
        if ($roleRequis && !$user->hasRole($roleRequis)) {
            return response()->json([
                'success' => false,
                'message' => "Vous n'avez pas le rôle requis pour approuver cette demande. Rôle requis : {$roleRequis}"
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'commentaire' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $result = $this->validationService->approuverParSuperieur(
                $demande,
                $user,
                $request->commentaire
            );

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Rejeter une demande de logistique
     */
    public function rejeter($id, Request $request)
    {
        $user = auth()->user();

        $demande = Approvisionnement::findOrFail($id);

        if ($demande->statut_approbation_logistique !== 'EN_ATTENTE') {
            return response()->json([
                'success' => false,
                'message' => 'Cette demande n\'est pas en attente d\'approbation'
            ], 422);
        }

        // ✅ VÉRIFICATION DU RÔLE REQUIS
        $roleRequis = $this->getRoleRequisPourApprobation($demande);
        
        if ($roleRequis && !$user->hasRole($roleRequis)) {
            return response()->json([
                'success' => false,
                'message' => "Vous n'avez pas le rôle requis pour rejeter cette demande. Rôle requis : {$roleRequis}"
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'motif' => 'required|string|min:3'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $result = $this->validationService->rejeterParSuperieur(
                $demande,
                $user,
                $request->motif
            );

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Détails d'une demande en attente d'approbation
     */
    public function show($id)
    {
        $demande = Approvisionnement::where('statut_approbation_logistique', 'EN_ATTENTE')
            ->with([
                'destinationSuccursale',
                'sourceDecidee',
                'intermediaireSuccursale',
                'lignes.produit',
                'demandeur',
                'responsableLogistique',
                'devise'
            ])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $demande
        ]);
    }

    // ============================================================
    // MÉTHODES PRIVÉES
    // ============================================================

    /**
     * Récupère le rôle requis pour approuver une demande
     */
    private function getRoleRequisPourApprobation($demande): ?string
    {
        // ✅ Récupérer le seuil configuré pour la devise de la demande
        $seuil = SeuilLogistique::where('devise_id', $demande->devise_id)
            ->where('actif', true)
            ->first();

        // ✅ Si aucun seuil configuré, pas de restriction
        if (!$seuil) {
            return null;
        }

        // ✅ Vérifier si le montant total dépasse le seuil
        $montantTotal = $demande->valeur_totale ?? $demande->cout_total ?? 0;

        if ($montantTotal <= $seuil->montant_max_approbation_superieur) {
            // ✅ En dessous du seuil, pas besoin d'approbation supérieure
            return null;
        }

        // ✅ Au-dessus du seuil, retourner le rôle requis
        return $seuil->niveau_superieur_role;
    }
}