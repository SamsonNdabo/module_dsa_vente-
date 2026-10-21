<?php

namespace App\Http\Controllers\PlainteSuggestion;

use App\Http\Controllers\Controller;
use App\Http\Requests\PlainteSuggestion\PlainteSuggestionRequest;
use App\Models\PlainteSuggestion\Plainte;
use App\Models\Utilisateur;
use App\Services\PlainteSuggestion\PlainteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PlainteController extends Controller
{
    public function __construct(
        protected PlainteService $service
    ) {}

    // =========================================================
    // 📋 INDEX — Liste filtrée
    // =========================================================
    public function index(Request $request): JsonResponse
    {
        $plaintes = $this->service->lister($request->all());

        return response()->json($plaintes);
    }

    // =========================================================
    // ➕ STORE — Créer une plainte
    // =========================================================
    public function store(PlainteSuggestionRequest $request): JsonResponse
    {
        $plainte = $this->service->creer($request->validated(), Auth::id());

        return response()->json([
            'message' => 'Plainte créée avec succès.',
            'plainte' => $plainte->load(['client', 'utilisateur']),
        ], 201);
    }

    // =========================================================
    // 🔍 SHOW — Détail
    // =========================================================
    public function show(int $id): JsonResponse
    {
        $plainte = $this->service->trouver($id);

        return response()->json($plainte);
    }

    // =========================================================
    // ✏️ UPDATE
    // =========================================================
    public function update(PlainteSuggestionRequest $request, int $id): JsonResponse
    {
        $plainte = Plainte::findOrFail($id);
        $plainte = $this->service->mettreAJour($plainte, $request->validated());

        return response()->json([
            'message' => 'Plainte mise à jour.',
            'plainte' => $plainte,
        ]);
    }

    // =========================================================
    // 🗑️ DESTROY
    // =========================================================
    public function destroy(int $id): JsonResponse
    {
        $plainte = Plainte::findOrFail($id);
        $this->service->supprimer($plainte);

        return response()->json(['message' => 'Plainte supprimée.']);
    }

    // =========================================================
    // 👤 AFFECTER
    // =========================================================
    public function affecter(PlainteSuggestionRequest $request, int $id): JsonResponse
    {
        $plainte     = Plainte::findOrFail($id);
        $utilisateur = Utilisateur::findOrFail($request->utilisateur_id);

        $plainte = $this->service->affecter(
            $plainte,
            $utilisateur,
            $request->commentaire,
            Auth::id()
        );

        return response()->json([
            'message' => "Plainte affectée à {$utilisateur->nom_complet}.",
            'plainte' => $plainte->load(['utilisateur', 'historiques']),
        ]);
    }

    // =========================================================
    // 🔁 CHANGER STATUT
    // =========================================================
    public function changerStatut(PlainteSuggestionRequest $request, int $id): JsonResponse
    {
        $plainte = Plainte::findOrFail($id);

        $plainte = $this->service->changerStatut(
            $plainte,
            $request->statut,
            $request->commentaire_resolution,
            Auth::id()
        );

        return response()->json([
            'message' => "Statut changé en {$request->statut}.",
            'plainte' => $plainte->load(['utilisateur', 'historiques']),
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

        $query = Plainte::query();

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