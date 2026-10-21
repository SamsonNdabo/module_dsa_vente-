<?php

namespace App\Http\Controllers\PlainteSuggestion;

use App\Http\Controllers\Controller;
use App\Models\PlainteSuggestion\Suggestion;
use App\Services\PlainteSuggestion\SuggestionHistoriqueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SuggestionHistoriqueController extends Controller
{
    public function __construct(
        protected SuggestionHistoriqueService $service
    ) {}

    // 📋 LISTE FILTRÉE
    public function index(Request $request, int $suggestionId): JsonResponse
    {
        Suggestion::findOrFail($suggestionId);

        return response()->json(
            $this->service->lister($suggestionId, $request->all())
        );
    }

    // 🔍 PAR ACTION
    public function parAction(int $suggestionId, string $action): JsonResponse
    {
        Suggestion::findOrFail($suggestionId);

        return response()->json(
            $this->service->parAction($suggestionId, $action)
        );
    }

    // 🔍 PAR UTILISATEUR
    public function parUtilisateur(int $suggestionId, int $utilisateurId): JsonResponse
    {
        Suggestion::findOrFail($suggestionId);

        return response()->json(
            $this->service->parUtilisateur($suggestionId, $utilisateurId)
        );
    }

    // 🔍 DERNIER CHANGEMENT
    public function dernier(int $suggestionId): JsonResponse
    {
        Suggestion::findOrFail($suggestionId);

        return response()->json(
            $this->service->dernierChangement($suggestionId)
        );
    }

    // 📊 STATS PAR ACTION
    public function stats(int $suggestionId): JsonResponse
    {
        Suggestion::findOrFail($suggestionId);

        return response()->json(
            $this->service->compterParAction($suggestionId)
        );
    }
}