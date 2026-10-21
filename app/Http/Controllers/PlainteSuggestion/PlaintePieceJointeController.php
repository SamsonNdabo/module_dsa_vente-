<?php

namespace App\Http\Controllers\PlainteSuggestion;

use App\Http\Controllers\Controller;
use App\Http\Requests\PlainteSuggestion\PlainteSuggestionRequest;
use App\Models\PlainteSuggestion\Plainte;
use App\Models\PlainteSuggestion\PlaintePieceJointe;
use App\Services\PlainteSuggestion\PlaintePieceJointeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlaintePieceJointeController extends Controller
{
    public function __construct(
        protected PlaintePieceJointeService $service
    ) {}

    // =========================================================
    // 📋 INDEX — Liste filtrée des preuves d'une plainte
    // =========================================================
    public function index(Request $request, int $plainteId): JsonResponse
    {
        Plainte::findOrFail($plainteId);

        return response()->json(
            $this->service->lister($plainteId, $request->all())
        );
    }

    // =========================================================
    // ➕ STORE — Upload de preuves
    // =========================================================
    public function store(PlainteSuggestionRequest $request, int $plainteId): JsonResponse
    {
        $plainte = Plainte::findOrFail($plainteId);
        $pieces  = $this->service->uploadMultiple($plainte, $request->file('preuves'));

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
        $piece = PlaintePieceJointe::with(['plainte', 'uploader'])->findOrFail($id);

        return response()->json($this->service->enrichir($piece));
    }

    // =========================================================
    // 👁️ VOIR — Affichage inline (React <img>, <iframe>)
    // =========================================================
    public function voir(int $id)
    {
        $piece = PlaintePieceJointe::findOrFail($id);

        return $this->service->voir($piece);
    }

    // =========================================================
    // ⬇️ DOWNLOAD
    // =========================================================
    public function download(int $id)
    {
        $piece = PlaintePieceJointe::findOrFail($id);

        return $this->service->download($piece);
    }

    // =========================================================
    // 🗑️ DESTROY — Supprimer une preuve
    // =========================================================
    public function destroy(int $id): JsonResponse
    {
        $piece = PlaintePieceJointe::findOrFail($id);
        $this->service->supprimer($piece);

        return response()->json(['message' => 'Preuve supprimée.']);
    }

    // =========================================================
    // 🗑️ DESTROY ALL — Supprimer toutes les preuves
    // =========================================================
    public function destroyAll(int $plainteId): JsonResponse
    {
        Plainte::findOrFail($plainteId);
        $total = $this->service->supprimerTout($plainteId);

        return response()->json(['message' => "{$total} preuve(s) supprimée(s)."]);
    }

    // =========================================================
    // 📊 STATS
    // =========================================================
    public function stats(int $plainteId): JsonResponse
    {
        Plainte::findOrFail($plainteId);

        return response()->json($this->service->stats($plainteId));
    }

    // =========================================================
    // 📅 PAR PÉRIODE — Arbre année/mois/jour
    // =========================================================
    public function parPeriode(int $plainteId): JsonResponse
    {
        $plainte = Plainte::findOrFail($plainteId);

        return response()->json([
            'plainte_id' => $plainte->id,
            'reference'  => $plainte->reference,
            'arbre'      => $this->service->parPeriode($plainteId),
        ]);
    }
}