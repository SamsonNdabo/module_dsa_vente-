<?php

namespace App\Http\Controllers\Dsa;

use App\Enums\AgentStatut;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dsa\StoreAgentDsaRequest;
use App\Http\Resources\AgentDsaResource;
use App\Models\AgentDsa;
use App\Services\Agent\AgentDsaService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AgentDsaController extends Controller
{
    public function __construct(private AgentDsaService $service) {}

    public function index(Request $request)
    {
        $this->exiger($request, 'dsa.agents.view');

        $agents = AgentDsa::visiblePour($request->user())
            ->with([
                // ✅ nom, prénom, email, téléphone vivent dans Utilisateur
                'utilisateur:id,nom_complet,email,telephone,nom_utilisateur,photo_profil',
                'zone:id,code,nom',
            ])
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->string('statut')))
            ->when($request->filled('zone_id'), fn ($q) => $q->where('zone_id', $request->integer('zone_id')))
            ->when($request->filled('q'), function ($q) use ($request) {
                // ✅ Recherche sur code agent + champs Utilisateur
                $t = '%' . $request->string('q') . '%';
                $q->where(function ($w) use ($t) {
                    $w->where('code', 'like', $t)
                      ->orWhereHas('utilisateur', function ($u) use ($t) {
                          $u->where('nom_complet', 'like', $t)
                            ->orWhere('email', 'like', $t)
                            ->orWhere('telephone', 'like', $t)
                            ->orWhere('nom_utilisateur', 'like', $t);
                      });
                });
            })
            ->latest('id')
            ->paginate(min($request->integer('per_page', 20), 100));

        return AgentDsaResource::collection($agents);
    }

    public function store(StoreAgentDsaRequest $request)
    {
        $data = $request->validated();

        // ✅ photo → Utilisateur.photo_profil (via le service)
        if ($request->hasFile('photo')) {
            $data['photo_path'] = $request->file('photo')->store('dsa/agents/photos', 'public');
        }

        // ✅ pièce d'identité → reste dans AgentDsa
        if ($request->hasFile('piece_identite_fichier')) {
            $data['piece_identite_fichier'] = $request->file('piece_identite_fichier')
                ->store('dsa/agents/pieces', 'local');
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
            ->with([
                'utilisateur',
                'zone',
                'superviseur:id,nom_complet,email',
                'statutHistoriques',
                'affectations.zone:id,code,nom',
            ])
            ->findOrFail($id);

        return new AgentDsaResource($agent);
    }

    public function update(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.agents.manage');

        $agent = AgentDsa::visiblePour($request->user())
            ->with('utilisateur')
            ->findOrFail($id);

        $userId = $agent->utilisateur_id;

        // ✅ On valide les champs personnels (Utilisateur) ET les champs métier (AgentDsa)
        $data = $request->validate([
            // --- Données personnelles → Utilisateur
            'prenom'    => 'sometimes|string|max:100',
            'nom'       => 'sometimes|string|max:100',
            'sexe'      => 'sometimes|in:M,F',
            'telephone' => ['sometimes', 'string', 'max:20', Rule::unique('utilisateurs', 'telephone')->ignore($userId)],
            'email'     => ['sometimes', 'email', 'max:255', Rule::unique('utilisateurs', 'email')->ignore($userId)],
            'adresse'   => 'nullable|string|max:255',
            'province'  => 'sometimes|string|max:100',
            'ville'     => 'sometimes|string|max:100',
            'commune'   => 'nullable|string|max:100',
            'photo'     => 'sometimes|file|image|max:2048',

            // --- Données métier → AgentDsa
            'objectifs'              => 'sometimes|nullable|array',
            'piece_identite_type'    => 'sometimes|nullable|string|max:50',
            'piece_identite_numero'  => 'sometimes|nullable|string|max:100',
            'piece_identite_fichier' => 'sometimes|nullable|string|max:255',
            'date_embauche'          => 'sometimes|date',
        ]);

        if ($request->hasFile('photo')) {
            $data['photo_path'] = $request->file('photo')->store('dsa/agents/photos', 'public');
        }

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

        // ✅ On joint Utilisateur pour récupérer nom_complet (pas de doublon dans AgentDsa)
        $agents = AgentDsa::query()
            ->with('utilisateur:id,nom_complet,nom_utilisateur,email')
            ->select('id', 'code', 'utilisateur_id', 'statut')
            ->when(
                $request->boolean('only_actif', true),
                fn ($q) => $q->where('statut', AgentStatut::ACTIF->value)
            )
            ->orderBy('code')
            ->get()
            ->map(fn (AgentDsa $a) => [
                'id'          => $a->id,
                'code'        => $a->code,
                // ✅ Récupéré depuis Utilisateur
                'nom_complet' => $a->utilisateur?->nom_complet ?? 'Inconnu',
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