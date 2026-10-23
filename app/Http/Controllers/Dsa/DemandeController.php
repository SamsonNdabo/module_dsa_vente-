<?php

namespace App\Http\Controllers\Dsa;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dsa\Concerns\ExigePermission;
use App\Models\Demande;
use App\Services\WorkflowService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class DemandeController extends Controller
{
    use ExigePermission;

    public function __construct(private WorkflowService $workflow) {}

    /**
     * GET /api/dsa/demandes
     */
    public function index(Request $request)
    {
        $this->exiger($request, 'dsa.demandes.view');

        $paginator = Demande::visiblePour($request->user())
            ->with([
                'client:id,code_client,nom_complet,telephone,kyc_statut',
                'agent:id,code,prenom,nom',
                'partenaire:id,code,nom',
                'produit:id,code,nom,type',
                'kycDossier.pieces',       // ← AJOUT CRITIQUE
            ])
            ->when($request->filled('type'),          fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('statut'),        fn ($q) => $q->where('statut', $request->string('statut')))
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->integer('client_id')))
            ->when($request->filled('agent_dsa_id'),  fn ($q) => $q->where('agent_dsa_id', $request->integer('agent_dsa_id')))
            ->when($request->filled('partenaire_id'), fn ($q) => $q->where('partenaire_id', $request->integer('partenaire_id')))
            ->latest('id')
            ->paginate(min($request->integer('per_page', 20), 100));

        return response()->json(['success' => true, 'data' => $paginator]);
    }

    /**
     * GET /api/dsa/demandes/options
     * Types + statuts distincts pour les filtres.
     */
    public function options(Request $request): JsonResponse
    {
        $this->exiger($request, 'dsa.demandes.view');

        $statuts = Demande::visiblePour($request->user())
            ->select('statut')
            ->distinct()
            ->orderBy('statut')
            ->pluck('statut');

        return response()->json([
            'success' => true,
            'data'    => [
                'types'   => ['KYC', 'OUVERTURE_COMPTE'],
                'statuts' => $statuts,
            ],
        ]);
    }

    /**
     * GET /api/dsa/demandes/{id}
     */
    public function show(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.demandes.view');

        $demande = Demande::visiblePour($request->user())
            ->with([
                'client',
                'agent',
                'partenaire',
                'produit',
                'historiques',
                'kycDossier.pieces',
                'compte',
            ])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $demande,
        ]);
    }

    /**
     * GET /api/dsa/demandes/{id}/historiques
     */
    public function historiques(Request $request, int $id): JsonResponse
    {
        $this->exiger($request, 'dsa.demandes.view');

        $demande = Demande::visiblePour($request->user())->findOrFail($id);

        $historiques = $demande->historiques()
            ->with('acteur:id,nom_complet,email')
            ->get()
            ->map(fn ($h) => [
                'id'             => $h->id,
                'ancien_statut'  => $h->ancien_statut,
                'nouveau_statut' => $h->nouveau_statut,
                'motif'          => $h->motif,
                'systeme'        => (bool) $h->systeme,
                'acteur'         => $h->acteur
                    ? ['id' => $h->acteur->id, 'nom_complet' => $h->acteur->nom_complet]
                    : null,
                'created_at'     => $h->created_at?->toISOString(),
            ]);

        return response()->json(['success' => true, 'data' => $historiques]);
    }

    /**
     * POST /api/dsa/demandes/{id}/transition
     * Unique point d'entrée pour changer un statut.
     */
    public function transitionner(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.demandes.view');
        $demande = Demande::visiblePour($request->user())->findOrFail($id);

        $data = $request->validate([
            'statut'               => 'required|string|max:30',
            'motif'                => 'nullable|string|max:500',
            'numero_compte'        => 'nullable|string|max:60',
            'reference_partenaire' => 'nullable|string|max:60',
        ]);

        $demande = $this->workflow->transitionner(
            $demande,
            $data['statut'],
            $data['motif'] ?? null,
            $request->user(),
            false,
            [
                'numero_compte'        => $data['numero_compte'] ?? null,
                'reference_partenaire' => $data['reference_partenaire'] ?? null,
            ],
        );

        return response()->json([
            'success' => true,
            'message' => 'Transition effectuée',
            'demande' => $demande->load(['kycDossier', 'compte']),
        ]);
    }
}