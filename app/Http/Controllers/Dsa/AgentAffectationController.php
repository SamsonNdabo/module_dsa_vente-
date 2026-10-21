<?php

namespace App\Http\Controllers\Dsa;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dsa\Concerns\ExigePermission;
use App\Models\AgentAffectation;
use App\Models\AgentDsa;
use App\Services\AgentAffectationService;
use Illuminate\Http\Request;

class AgentAffectationController extends Controller
{
    use ExigePermission;

    public function __construct(private AgentAffectationService $service) {}

    public function index(Request $request, int $agentId)
    {
        $this->exiger($request, 'dsa.agents.view');

        $agent = AgentDsa::findOrFail($agentId);

        return response()->json([
            'success' => true,
            'data'    => AgentAffectation::with(['zone:id,code,nom', 'superviseur:id,nom_complet', 'campagne:id,code,nom', 'partenaire:id,nom'])
                ->where('agent_dsa_id', $agent->id)
                ->orderByDesc('date_debut')
                ->get()
                ->map(fn ($a) => [
                    'id'           => $a->id,
                    'zone'         => $a->zone ? ['id' => $a->zone->id, 'code' => $a->zone->code, 'nom' => $a->zone->nom] : null,
                    'superviseur'  => $a->superviseur ? ['id' => $a->superviseur->id, 'nom_complet' => $a->superviseur->nom_complet] : null,
                    'campagne'     => $a->campagne ? ['id' => $a->campagne->id, 'code' => $a->campagne->code, 'nom' => $a->campagne->nom] : null,
                    'partenaire'   => $a->partenaire ? ['id' => $a->partenaire->id, 'nom' => $a->partenaire->nom] : null,
                    'date_debut'   => $a->date_debut?->toISOString(),
                    'date_fin'     => $a->date_fin?->toISOString(),
                    'en_cours'     => is_null($a->date_fin),
                ]),
        ]);
    }

    public function store(Request $request, int $agentId)
    {
        $this->exiger($request, 'dsa.agents.manage');

        $agent = AgentDsa::findOrFail($agentId);

        $data = $request->validate([
            'zone_id'        => 'nullable|integer|exists:zones,id',
            'superviseur_id' => 'nullable|integer|exists:utilisateurs,id',
            'campagne_id'    => 'nullable|integer|exists:campagnes,id',
            'partenaire_id'  => 'nullable|integer|exists:partenaires,id',
            'date_debut'     => 'nullable|date',
        ]);

        $affectation = $this->service->affecter($agent, $data, $request->user());

        return response()->json(['success' => true, 'data' => $affectation], 201);
    }
}