<?php

namespace App\Http\Controllers\Dsa;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dsa\Concerns\ExigePermission;   // ← AJOUTER
use App\Http\Requests\Dsa\StoreClientDsaRequest;
use App\Http\Requests\Dsa\UpdateClientDsaRequest;
use App\Http\Resources\ClientDsaResource;
use App\Models\Client;
use App\Models\ClientDsa;
use App\Services\ClientDsaService;
use Illuminate\Http\Request;

class ClientDsaController extends Controller
{
    use ExigePermission;   // ← AJOUTER

    public function __construct(private ClientDsaService $service) {}

    /**
     * GET /api/dsa/clients
     */
    public function index(Request $request)
    {
        $this->exiger($request, 'dsa.clients.view');

        $query = ClientDsa::with(['clientPos', 'agentDsa', 'zone'])
            ->when($request->filled('q'), fn ($q) => $q->recherche($request->string('q')))
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->string('statut')))
            ->when($request->filled('kyc_statut'), fn ($q) => $q->where('kyc_statut', $request->string('kyc_statut')))
            ->when($request->filled('agent_dsa_id'), fn ($q) => $q->where('agent_dsa_id', $request->integer('agent_dsa_id')))
            ->when($request->filled('zone_id'), fn ($q) => $q->where('zone_id', $request->integer('zone_id')))
            ->when($request->boolean('kyc_valide'), fn ($q) => $q->kycValide())
            ->orderByDesc('created_at');

        $paginator = $query->paginate($request->integer('per_page', 15));

        return ClientDsaResource::collection($paginator)
            ->additional(['success' => true]);
    }

    /**
     * GET /api/dsa/clients/options
     */
    public function options(Request $request)
    {
        $this->exiger($request, 'dsa.clients.view');

        $clients = ClientDsa::select('id', 'code_client', 'nom_complet', 'telephone')
            ->where('statut', 'ACTIF')
            ->orderBy('nom_complet')
            ->limit(200)
            ->get();

        return response()->json(['success' => true, 'data' => $clients]);
    }

    /**
     * GET /api/dsa/clients/clients-pos-disponibles
     * Liste les clients POS qui ne sont pas encore liés à un client DSA.
     */
    public function clientsPosDisponibles(Request $request)
    {
        $this->exiger($request, 'dsa.clients.view');

        $q = $request->string('q');
        $query = Client::query()
            ->select('id', 'code_client', 'nom_complet', 'email', 'telephone', 'adresse')
            ->whereNotIn('id', ClientDsa::whereNotNull('client_id')->select('client_id'))
            ->when($q, function ($w) use ($q) {
                $t = '%' . $q . '%';
                $w->where(fn ($x) => $x->where('nom_complet', 'like', $t)
                    ->orWhere('code_client', 'like', $t)
                    ->orWhere('telephone', 'like', $t)
                    ->orWhere('email', 'like', $t));
            })
            ->orderBy('nom_complet')
            ->limit(20);

        return response()->json(['success' => true, 'data' => $query->get()]);
    }

    /**
     * POST /api/dsa/clients
     */
    public function store(StoreClientDsaRequest $request)
    {
        $client = $this->service->creer($request->validated(), $request->user());

        return (new ClientDsaResource($client))
            ->additional(['success' => true, 'message' => 'Client DSA créé'])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/dsa/clients/{id}
     */
    public function show(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.clients.view');

        $client = ClientDsa::with(['clientPos', 'agentDsa', 'zone'])->findOrFail($id);

        return new ClientDsaResource($client);
    }

    /**
     * PUT /api/dsa/clients/{id}
     */
    public function update(UpdateClientDsaRequest $request, int $id)
    {
        $client = ClientDsa::findOrFail($id);
        $client = $this->service->modifier($client, $request->validated(), $request->user());

        return (new ClientDsaResource($client))
            ->additional(['success' => true, 'message' => 'Client DSA mis à jour']);
    }

    /**
     * DELETE /api/dsa/clients/{id}
     */
    public function destroy(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.clients.gerer');

        $client = ClientDsa::findOrFail($id);
        $client->delete(); // SoftDelete

        return response()->json(['success' => true, 'message' => 'Client DSA supprimé']);
    }

    /**
     * POST /api/dsa/clients/{id}/statut
     */
    public function changerStatut(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.clients.gerer');

        $data = $request->validate([
            'statut' => 'required|in:ACTIF,INACTIF,BLOQUE',
        ]);

        $client = ClientDsa::findOrFail($id);
        $client = $this->service->changerStatut($client, $data['statut'], $request->user());

        return (new ClientDsaResource($client))
            ->additional(['success' => true, 'message' => 'Statut mis à jour']);
    }
}