<?php

namespace App\Http\Controllers\PlainteSuggestion;

use App\Http\Controllers\Controller;
use App\Http\Requests\PlainteSuggestion\PlainteSuggestionRequest;
use App\Models\PlainteSuggestion\Suggestion;
use App\Models\Utilisateur;
use App\Services\PlainteSuggestion\SuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SuggestionController extends Controller
{
    public function __construct(
        protected SuggestionService $service
    ) {}

    // =========================================================
    // 📋 INDEX — Liste filtrée
    // =========================================================
    public function index(Request $request): JsonResponse
    {
        $suggestions = $this->service->lister($request->all());

        return response()->json($suggestions);
    }

    // =========================================================
    // ➕ STORE — Créer une suggestion
    // =========================================================
    public function store(PlainteSuggestionRequest $request): JsonResponse
    {
        $suggestion = $this->service->creer($request->validated(), Auth::id());

        return response()->json([
            'message'    => 'Suggestion créée avec succès.',
            'suggestion' => $suggestion->load(['client', 'utilisateur']),
        ], 201);
    }

    // =========================================================
    // 🔍 SHOW — Détail
    // =========================================================
    public function show(int $id): JsonResponse
    {
        $suggestion = $this->service->trouver($id);

        return response()->json($suggestion);
    }

    // =========================================================
    // ✏️ UPDATE
    // =========================================================
    public function update(PlainteSuggestionRequest $request, int $id): JsonResponse
    {
        $suggestion = Suggestion::findOrFail($id);
        $suggestion = $this->service->mettreAJour($suggestion, $request->validated());

        return response()->json([
            'message'    => 'Suggestion mise à jour.',
            'suggestion' => $suggestion,
        ]);
    }

    // =========================================================
    // 🗑️ DESTROY
    // =========================================================
    public function destroy(int $id): JsonResponse
    {
        $suggestion = Suggestion::findOrFail($id);
        $this->service->supprimer($suggestion);

        return response()->json(['message' => 'Suggestion supprimée.']);
    }

    // =========================================================
    // 👤 AFFECTER
    // =========================================================
    public function affecter(PlainteSuggestionRequest $request, int $id): JsonResponse
    {
        $suggestion  = Suggestion::findOrFail($id);
        $utilisateur = Utilisateur::findOrFail($request->utilisateur_id);

        $suggestion = $this->service->affecter(
            $suggestion,
            $utilisateur,
            $request->commentaire,
            Auth::id()
        );

        return response()->json([
            'message'    => "Suggestion affectée à {$utilisateur->nom_complet}.",
            'suggestion' => $suggestion->load(['utilisateur', 'historiques']),
        ]);
    }

    // =========================================================
    // 🔁 CHANGER STATUT
    // =========================================================
    public function changerStatut(PlainteSuggestionRequest $request, int $id): JsonResponse
    {
        $suggestion = Suggestion::findOrFail($id);

        $suggestion = $this->service->changerStatut(
            $suggestion,
            $request->statut,
            $request->commentaire_resolution,
            Auth::id()
        );

        return response()->json([
            'message'    => "Statut changé en {$request->statut}.",
            'suggestion' => $suggestion->load(['utilisateur', 'historiques']),
        ]);
    }

    // =========================================================
    // 📊 KPI GLOBAL
    // =========================================================
    public function kpi(Request $request): JsonResponse
    {
        return response()->json($this->service->kpi($request->all()));
    }

    // =========================================================
    // 📊 KPI DÉTAILLÉ (zone / agent / partenaire)
    // =========================================================
    public function kpiDetail(Request $request): JsonResponse
    {
        $request->validate([
            'type'   => ['required', 'in:zone,agent,partenaire'],
            'valeur' => ['required', 'string'],
        ]);

        $query = Suggestion::query();

        switch ($request->type) {
            case 'zone':
                $query->where('zone', $request->valeur);
                break;
            case 'agent':
                $query->where('utilisateur_id', $request->valeur);
                break;
            case 'partenaire':
                $query->whereHas('client', fn ($q) => $q->where('categorie_client', $request->valeur));
                break;
        }

        $stats = (clone $query)->selectRaw("
            COUNT(*) AS nombre_total,
            ROUND(AVG(delai_resolution_heures), 2) AS delai_moyen_heures,
            SUM(CASE WHEN statut IN ('NOUVELLE','EN_COURS') THEN 1 ELSE 0 END) AS ouvertes,
            SUM(CASE WHEN statut IN ('RESOLUE','REJETEE') THEN 1 ELSE 0 END) AS cloturees
        ")->first();

        $details = $query->with(['client', 'utilisateur'])
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'type'    => $request->type,
            'valeur'  => $request->valeur,
            'stats'   => $stats,
            'details' => $details,
        ]);
    }
}