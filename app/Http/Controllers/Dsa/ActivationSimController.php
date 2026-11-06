<?php

namespace App\Http\Controllers\Dsa;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dsa\Concerns\ExigePermission;
use App\Models\Activation\ActivationSim;
use App\Services\Activation\ActivationSimService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

class ActivationSimController extends Controller
{
    use ExigePermission;

    public function __construct(private ActivationSimService $service) {}

    /**
     * GET /api/dsa/activations-sim
     */
    public function index(Request $request)
    {
        $this->exiger($request, 'dsa.activations_sim.view');

        $query = ActivationSim::with([
            'client:id,code_client,nom_complet,telephone',
            'agentDsa:id,code,prenom,nom',
            'produit:id,nom,code_barre',
            'facture:id,reference',
        ])
        ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->string('statut')))
        ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->integer('client_id')))
        ->when($request->filled('agent_dsa_id'), fn ($q) => $q->where('agent_dsa_id', $request->integer('agent_dsa_id')))
        ->when($request->filled('q'), function ($q) use ($request) {
            $term = '%' . $request->string('q') . '%';
            $q->where(fn ($w) => $w
                ->where('identifiant', 'like', $term)
                ->orWhere('reference', 'like', $term));
        })
        ->orderByDesc('id');

        $activations = $query->paginate($request->integer('per_page', 15));

        return response()->json([
            'success' => true,
            'data'    => $activations->items(),
            'meta'    => [
                'current_page' => $activations->currentPage(),
                'last_page'    => $activations->lastPage(),
                'total'        => $activations->total(),
            ],
        ]);
    }

    /**
     * POST /api/dsa/activations-sim
     * Activer une SIM : preuve obligatoire + identifiant.
     * Client, agent, date = auto-remplis.
     */
    public function store(Request $request)
    {
        $this->exiger($request, 'dsa.activations_sim.create');

        $data = $request->validate([
            'client_id'   => 'nullable|integer|exists:clients,id',
            'produit_id'  => 'required|integer|exists:produit,id',
            'facture_id'  => 'nullable|integer|exists:factures,id',
            'commande_id' => 'nullable|integer|exists:commandes,id',
            'identifiant' => 'required|string|max:50',
            'notes'       => 'nullable|string|max:1000',
            'preuve'      => 'required|file|mimes:jpg,jpeg,png,pdf|max:10240',
        ]);

        $activation = $this->service->activer(
            $data,
            $request->file('preuve'),
            $request->user()
        );

        return response()->json([
            'success' => true,
            'data'    => $activation->load(['client', 'produit']),
            'message' => 'SIM activée avec succès.',
        ], 201);
    }

    /**
     * GET /api/dsa/activations-sim/{id}
     */
    public function show(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.activations_sim.view');

        $activation = ActivationSim::with([
            'client', 'agentDsa', 'produit', 'facture', 'preuveUploadeePar',
        ])->findOrFail($id);

        return response()->json(['success' => true, 'data' => $activation]);
    }

    /**
     * GET /api/dsa/activations-sim/{id}/preuve
     */
    public function telechargerPreuve(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.activations_sim.view');

        $activation = ActivationSim::findOrFail($id);
        $fichier    = $this->service->telechargerPreuve($activation);

        return Response::download($fichier['path'], $fichier['nom_original'], [
            'Content-Type' => $fichier['mime_type'],
        ]);
    }

    /**
     * DELETE /api/dsa/activations-sim/{id}
     */
    public function destroy(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.activations_sim.gerer');

        $activation = ActivationSim::findOrFail($id);
        $this->service->supprimer($activation, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Activation supprimée.',
        ]);
    }
}