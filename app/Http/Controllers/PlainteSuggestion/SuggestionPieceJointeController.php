<?php

namespace App\Http\Controllers\PlainteSuggestion;

use App\Http\Controllers\Controller;
use App\Http\Requests\PlainteSuggestion\PlainteSuggestionRequest;
use App\Models\PlainteSuggestion\Suggestion;
use App\Models\PlainteSuggestion\SuggestionPieceJointe;
use App\Services\PlainteSuggestion\SuggestionPieceJointeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SuggestionPieceJointeController extends Controller
{
    public function __construct(
        protected SuggestionPieceJointeService $service
    ) {}

    // =========================================================
    // 📋 INDEX — Liste filtrée des preuves d'une suggestion
    // =========================================================
    public function index(Request $request, int $suggestionId): JsonResponse
    {
        Suggestion::findOrFail($suggestionId);

        return response()->json(
            $this->service->lister($suggestionId, $request->all())
        );
    }

    // =========================================================
    // ➕ STORE — Upload de preuves
    // =========================================================
    public function store(PlainteSuggestionRequest $request, int $suggestionId): JsonResponse
    {
        $suggestion = Suggestion::findOrFail($suggestionId);
        $pieces     = $this->service->uploadMultiple($suggestion, $request->file('preuves'));

        return response()->json([
            'message' => count($pieces) . ' preuve(s) ajoutée(s).',
            'pieces'  => collect($pieces)->map(fn ($p) => $this->service->enrichir($p)),
        ], 201);
    }

    // =========================================================
    // 🔍 SHOW — Détail d'une preuve
    // =========================================================
    public function show(int $id): JsonResponse
    {
        $piece = SuggestionPieceJointe::with(['suggestion', 'uploader'])->findOrFail($id);

        return response()->json($this->service->enrichir($piece));
    }

    // =========================================================
    // 👁️ VOIR — Affichage inline (React <img>, <iframe>)
    // =========================================================
    public function voir(int $id)
    {
        $piece = SuggestionPieceJointe::findOrFail($id);

        return $this->service->voir($piece);
    }

    // =========================================================
    // ⬇️ DOWNLOAD
    // =========================================================
    public function download(int $id)
    {
        $piece = SuggestionPieceJointe::findOrFail($id);

        return $this->service->download($piece);
    }

    // =========================================================
    // 🗑️ DESTROY — Supprimer une preuve
    // =========================================================
    public function destroy(int $id): JsonResponse
    {
        $piece = SuggestionPieceJointe::findOrFail($id);
        $this->service->supprimer($piece);

        return response()->json(['message' => 'Preuve supprimée.']);
    }

    // =========================================================
    // 🗑️ DESTROY ALL — Supprimer toutes les preuves
    // =========================================================
    public function destroyAll(int $suggestionId): JsonResponse
    {
        Suggestion::findOrFail($suggestionId);
        $total = $this->service->supprimerTout($suggestionId);

        return response()->json(['message' => "{$total} preuve(s) supprimée(s)."]);
    }

    // =========================================================
    // 📊 STATS
    // =========================================================
    public function stats(int $suggestionId): JsonResponse
    {
        Suggestion::findOrFail($suggestionId);

        return response()->json($this->service->stats($suggestionId));
    }

    // =========================================================
    // 📅 PAR PÉRIODE — Arbre année/mois/jour
    // =========================================================
    public function parPeriode(int $suggestionId): JsonResponse
    {
        $suggestion = Suggestion::findOrFail($suggestionId);

        return response()->json([
            'suggestion_id' => $suggestion->id,
            'reference'     => $suggestion->reference,
            'arbre'         => $this->service->parPeriode($suggestionId),
        ]);
    }
}