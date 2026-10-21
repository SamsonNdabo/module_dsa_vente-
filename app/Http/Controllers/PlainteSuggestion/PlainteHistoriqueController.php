<?php

namespace App\Http\Controllers\PlainteSuggestion;

use App\Http\Controllers\Controller;
use App\Models\PlainteSuggestion\Plainte;
use App\Services\PlainteSuggestion\PlainteHistoriqueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlainteHistoriqueController extends Controller
{
    public function __construct(
        protected PlainteHistoriqueService $service
    ) {}

    // 📋 LISTE FILTRÉE
    public function index(Request $request, int $plainteId): JsonResponse
    {
        Plainte::findOrFail($plainteId);

        return response()->json(
            $this->service->lister($plainteId, $request->all())
        );
    }

    // 🔍 PAR ACTION
    public function parAction(int $plainteId, string $action): JsonResponse
    {
        Plainte::findOrFail($plainteId);

        return response()->json(
            $this->service->parAction($plainteId, $action)
        );
    }

    // 🔍 PAR UTILISATEUR
    public function parUtilisateur(int $plainteId, int $utilisateurId): JsonResponse
    {
        Plainte::findOrFail($plainteId);

        return response()->json(
            $this->service->parUtilisateur($plainteId, $utilisateurId)
        );
    }

    // 🔍 DERNIER CHANGEMENT
    public function dernier(int $plainteId): JsonResponse
    {
        Plainte::findOrFail($plainteId);

        return response()->json(
            $this->service->dernierChangement($plainteId)
        );
    }

    // 📊 STATS PAR ACTION
    public function stats(int $plainteId): JsonResponse
    {
        Plainte::findOrFail($plainteId);

        return response()->json(
            $this->service->compterParAction($plainteId)
        );
    }
}