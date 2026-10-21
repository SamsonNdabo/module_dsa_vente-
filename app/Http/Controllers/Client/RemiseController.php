<?php
// app/Http/Controllers/Client/RemiseController.php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\RemiseRegle;
use App\Services\RemiseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RemiseController extends Controller
{
    protected RemiseService $remiseService;

    public function __construct(RemiseService $remiseService)
    {
        $this->remiseService = $remiseService;
    }

    /**
     * Liste des règles de remise - OPTIMISÉ
     */
    public function index(Request $request)
    {
        try {
            $filters = $request->only(['actif', 'type', 'application']);
            $regles = $this->remiseService->listRegles($filters);

            return response()->json([
                'success' => true,
                'data' => $regles
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur liste remises', [
                'filters' => $filters ?? [],
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Créer une règle de remise
     */
    public function store(Request $request)
    {
        try {
            $request->validate([
                'nom' => 'required|string|max:255',
                'type' => 'required|in:POURCENTAGE,FIXE',
                'valeur' => 'required|numeric|min:0',
                'application' => 'required|in:GLOBALE,PRODUIT,CATEGORIE',
                'cible' => 'nullable|string',
                'cible_valeur' => 'nullable|string',
                'montant_minimum' => 'nullable|numeric|min:0',
                'nombre_achats_requis' => 'nullable|integer|min:0',
                'montant_achats_requis' => 'nullable|numeric|min:0',
                'kyc_requis' => 'boolean',
                'date_debut' => 'nullable|date',
                'date_fin' => 'nullable|date|after:date_debut',
                'priorite' => 'nullable|integer|min:0'
            ]);

            $regle = $this->remiseService->creerRegle($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Règle de remise créée',
                'data' => $regle
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Erreur création règle remise', [
                'data' => $request->all(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Afficher une règle de remise - OPTIMISÉ
     */
    public function show($id)
    {
        try {
            // ✅ Sélection des colonnes nécessaires
            $regle = RemiseRegle::select([
                'id', 'nom', 'description', 'type', 'valeur',
                'application', 'cible', 'cible_valeur',
                'montant_minimum', 'nombre_achats_requis',
                'montant_achats_requis', 'kyc_requis',
                'date_debut', 'date_fin', 'actif', 'priorite',
                'conditions', 'created_at', 'updated_at'
            ])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $regle
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur affichage règle remise', [
                'regle_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 404);
        }
    }

    /**
     * Mettre à jour une règle de remise
     */
    public function update(Request $request, $id)
    {
        try {
            $request->validate([
                'nom' => 'sometimes|string|max:255',
                'type' => 'sometimes|in:POURCENTAGE,FIXE',
                'valeur' => 'sometimes|numeric|min:0',
                'application' => 'sometimes|in:GLOBALE,PRODUIT,CATEGORIE',
                'cible' => 'nullable|string',
                'cible_valeur' => 'nullable|string',
                'montant_minimum' => 'nullable|numeric|min:0',
                'nombre_achats_requis' => 'nullable|integer|min:0',
                'montant_achats_requis' => 'nullable|numeric|min:0',
                'kyc_requis' => 'boolean',
                'date_debut' => 'nullable|date',
                'date_fin' => 'nullable|date|after:date_debut',
                'priorite' => 'nullable|integer|min:0'
            ]);

            $regle = $this->remiseService->updateRegle($id, $request->all());

            return response()->json([
                'success' => true,
                'message' => 'Règle mise à jour',
                'data' => $regle
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Erreur mise à jour règle remise', [
                'regle_id' => $id,
                'data' => $request->all(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Supprimer une règle de remise
     */
    public function destroy($id)
    {
        try {
            $this->remiseService->deleteRegle($id);

            return response()->json([
                'success' => true,
                'message' => 'Règle supprimée'
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur suppression règle remise', [
                'regle_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Activer/Désactiver une règle de remise
     */
    public function toggle($id)
    {
        try {
            $regle = $this->remiseService->toggleRegle($id);

            return response()->json([
                'success' => true,
                'message' => $regle->actif ? 'Règle activée' : 'Règle désactivée',
                'data' => $regle
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur toggle règle remise', [
                'regle_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }
}