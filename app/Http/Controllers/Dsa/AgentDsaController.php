<?php

namespace App\Http\Controllers\Dsa;

use App\Enums\AgentStatut;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dsa\StoreAgentDsaRequest;
use App\Http\Resources\AgentDsaResource;
use App\Models\AgentDsa;
use App\Services\AgentDsaService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AgentDsaController extends Controller
{
    public function __construct(private AgentDsaService $service) {}

    public function index(Request $request)
    {
        $this->exiger($request, 'dsa.agents.view');

        $agents = AgentDsa::visiblePour($request->user())
            ->with(['utilisateur:id,email,telephone,nom_utilisateur', 'zone'])
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->string('statut')))
            ->when($request->filled('zone_id'), fn ($q) => $q->where('zone_id', $request->integer('zone_id')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $t = '%' . $request->string('q') . '%';
                $q->where(fn ($w) => $w->where('code', 'like', $t)->orWhere('nom', 'like', $t)->orWhere('prenom', 'like', $t));
            })
            ->latest('id')
            ->paginate(min($request->integer('per_page', 20), 100));

        return AgentDsaResource::collection($agents);
    }

    public function store(StoreAgentDsaRequest $request)
    {
        $data = $request->validated();

        if ($request->hasFile('photo')) {
            $data['photo_path'] = $request->file('photo')->store('dsa/agents/photos', 'public');
        }
        if ($request->hasFile('piece_identite_fichier')) {
            $data['piece_identite_fichier'] = $request->file('piece_identite_fichier')->store('dsa/agents/pieces', 'local');
        }

        $result = $this->service->creer($data, $request->user());

        return (new AgentDsaResource($result['agent']))
            ->additional(['success' => true])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.agents.view');

        $agent = AgentDsa::visiblePour($request->user())
            ->with(['utilisateur', 'zone', 'statutHistoriques', 'affectations'])
            ->findOrFail($id);

        return new AgentDsaResource($agent);
    }

    public function update(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.agents.manage');
        $agent = AgentDsa::visiblePour($request->user())->with('utilisateur')->findOrFail($id);
        $userId = $agent->utilisateur_id;

        $data = $request->validate([
            'prenom'    => 'sometimes|string|max:100',
            'nom'       => 'sometimes|string|max:100',
            'sexe'      => 'sometimes|in:M,F',
            'telephone' => ['sometimes', 'string', 'max:20', Rule::unique('utilisateurs', 'telephone')->ignore($userId)],
            'email'     => ['sometimes', 'email', 'max:255', Rule::unique('utilisateurs', 'email')->ignore($userId)],
            'adresse'   => 'nullable|string|max:255',
            'province'  => 'sometimes|string|max:100',
            'ville'     => 'sometimes|string|max:100',
            'commune'   => 'nullable|string|max:100',
        ]);

        return new AgentDsaResource($this->service->modifier($agent, $data, $request->user()));
    }

    public function changerStatut(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.agents.statut');
        $agent = AgentDsa::visiblePour($request->user())->findOrFail($id);

        $data = $request->validate([
            'statut' => ['required', Rule::enum(AgentStatut::class)],
            'motif'  => 'nullable|string|max:500',
        ]);

        $agent = $this->service->changerStatut(
            $agent,
            AgentStatut::from($data['statut']),
            $data['motif'] ?? null,
            $request->user()
        );

        return new AgentDsaResource($agent);
    }

    public function affecter(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.agents.manage');
        $agent = AgentDsa::visiblePour($request->user())->findOrFail($id);

        $data = $request->validate([
            'zone_id' => 'required|exists:zones,id',
        ]);

        return new AgentDsaResource(
            $this->service->affecter($agent, $data['zone_id'], $request->user())
        );
    }

    public function options(Request $request)
    {
        $this->exiger($request, 'dsa.agents.view', allowSuperAdmin: true);

        $agents = AgentDsa::query()
            ->select('id', 'code', 'prenom', 'nom', 'statut')
            ->when(
                $request->boolean('only_actif', true),
                fn ($q) => $q->where('statut', AgentStatut::ACTIF->value)
            )
            ->orderBy('nom')
            ->orderBy('prenom')
            ->get()
            ->map(fn ($a) => [
                'id'          => $a->id,
                'code'        => $a->code,
                'nom_complet' => trim("{$a->prenom} {$a->nom}"),
                'statut'      => $a->statut instanceof AgentStatut ? $a->statut->value : $a->statut,
            ]);

        return response()->json(['success' => true, 'data' => $agents]);
    }

    private function exiger(Request $request, string $permission, bool $allowSuperAdmin = true): void
    {
        $user = $request->user();
        abort_unless($user, 401, 'Non authentifié');

        if ($allowSuperAdmin && method_exists($user, 'hasRole') && $user->hasRole('SUPER_ADMIN')) {
            return;
        }

        abort_unless(
            method_exists($user, 'hasPermission') && $user->hasPermission($permission),
            403,
            "Action non autorisée (permission requise : {$permission})"
        );
    }
}