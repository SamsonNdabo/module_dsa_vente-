<?php

namespace App\Http\Controllers\Dsa;

use App\Http\Controllers\Controller;
use App\Models\Zone;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ZoneController extends Controller
{
    public function index(Request $request)
    {
        $this->exiger($request, 'dsa.zones.view');

        $query = Zone::with('superviseur:id,nom_complet,email')
            ->withCount('agentsDsa')          // ⬅️ agentsDsa (pas agents)
            ->when($request->filled('q'), function ($q) use ($request) {
                $t = '%' . $request->string('q') . '%';
                $q->where(fn ($w) => $w->where('code', 'like', $t)
                    ->orWhere('nom', 'like', $t)
                    ->orWhere('ville', 'like', $t)
                    ->orWhere('region', 'like', $t));
            })
            ->when($request->filled('actif'), fn ($q) => $q->where('actif', $request->boolean('actif')))
            ->orderBy('nom');

        return response()->json([
            'success' => true,
            'data'    => $query->paginate(min($request->integer('per_page', 15), 100)),
        ]);
    }

    public function store(Request $request)
    {
        $this->exiger($request, 'dsa.zones.manage');

        $data = $this->validateZone($request);

        $zone = Zone::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Zone créée',
            'data'    => $zone->load('superviseur:id,nom_complet,email'),
        ], 201);
    }

    public function show(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.zones.view');

        $zone = Zone::with([
                'superviseur:id,nom_complet,email',
                // ⬇️ On ne sélectionne que les colonnes métier de AgentDsa
                //    et on charge utilisateur pour récupérer nom_complet
                'agentsDsa:id,code,utilisateur_id,zone_id,statut',
                'agentsDsa.utilisateur:id,nom_complet,email,telephone',
            ])
            ->withCount('agentsDsa')
            ->findOrFail($id);

        return response()->json(['success' => true, 'data' => $zone]);
    }

    public function update(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.zones.manage');

        $zone = Zone::findOrFail($id);
        $data = $this->validateZone($request, $zone->id);

        $zone->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Zone mise à jour',
            'data'    => $zone->fresh('superviseur:id,nom_complet,email'),
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.zones.manage');

        $zone = Zone::withCount('agentsDsa')->findOrFail($id);

        if ($zone->agents_dsa_count > 0) {   // ⬅️ agents_dsa_count
            return response()->json([
                'success' => false,
                'error'   => 'Impossible de supprimer : ' . $zone->agents_dsa_count . ' agent(s) affecté(s).',
            ], 400);
        }

        $zone->delete();

        return response()->json(['success' => true, 'message' => 'Zone supprimée']);
    }

    public function options(Request $request)
    {
        $this->exiger($request, 'dsa.zones.view');

        return response()->json([
            'success' => true,
            'data'    => Zone::where('actif', true)
                ->orderBy('nom')
                ->select('id', 'code', 'nom', 'region', 'ville')
                ->get(),
        ]);
    }

    private function validateZone(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:50',
                Rule::unique('zones', 'code')->ignore($ignoreId)],
            'nom'                => 'required|string|max:150',
            'region'             => 'nullable|string|max:100',
            'ville'              => 'nullable|string|max:100',
            'superviseur_id'     => 'nullable|exists:utilisateurs,id',
            'coordonnees_centre' => 'nullable|array',
            'rayon_km'           => 'nullable|numeric|min:0',
            'actif'              => 'sometimes|boolean',
        ]);
    }

    private function exiger(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasPermission($permission), 403, 'Action non autorisée');
    }
}