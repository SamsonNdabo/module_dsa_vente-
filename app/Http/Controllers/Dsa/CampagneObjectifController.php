<?php

namespace App\Http\Controllers\Dsa;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dsa\Concerns\ExigePermission;
use App\Models\Campagne;
use App\Models\CampagneObjectif;
use App\Services\CampagneObjectifService;
use Illuminate\Http\Request;

class CampagneObjectifController extends Controller
{
    use ExigePermission;

    public function __construct(private CampagneObjectifService $service) {}

    /**
     * GET /api/dsa/campagnes/{id}/objectifs-types
     */
    public function index(Request $request, int $campagneId)
    {
        $this->exiger($request, 'dsa.campagnes.view');

        $campagne = Campagne::findOrFail($campagneId);

        return response()->json([
            'success' => true,
            'data'    => $campagne->objectifsTypes()
                ->with('produit:id,code,nom')
                ->withCount('objectifsAgents as agents_count')
                ->get()
                ->map(fn ($o) => [
                    'id'              => $o->id,
                    'type_objectif'   => $o->type_objectif,
                    'produit_dsa_id'  => $o->produit_dsa_id,
                    'libelle'         => $o->libelleType(),
                    'valeur_cible'    => (float) $o->valeur_cible,
                    'valeur_realisee' => (float) $o->valeur_realisee,
                    'unite'           => $o->unite,
                    'actif'           => (bool) $o->actif,
                    'ordre'           => $o->ordre,
                    'taux'            => $o->tauxAtteinte(),
                    'produit'         => $o->produit ? [
                        'id'   => $o->produit->id,
                        'code' => $o->produit->code,
                        'nom'  => $o->produit->nom,
                    ] : null,
                    'agents_count'    => $o->agents_count,
                ]),
        ]);
    }

    /**
     * POST /api/dsa/campagnes/{id}/objectifs-types
     */
    public function definir(Request $request, int $campagneId)
    {
        $this->exiger($request, 'dsa.campagnes.gerer');

        $data = $request->validate([
            'types'                   => 'required|array|min:1',
            'types.*.type_objectif'   => 'required|string|max:50',
            'types.*.produit_dsa_id'  => 'nullable|integer|exists:produits_dsa,id',   // ⚠️ AJOUT
            'types.*.valeur_cible'    => 'required|numeric|min:0.01',
            'types.*.unite'           => 'nullable|string|max:20',
            'types.*.libelle'         => 'nullable|string|max:150',
            'types.*.actif'           => 'sometimes|boolean',
            'types.*.ordre'           => 'sometimes|integer|min:0',
        ]);

        $campagne = Campagne::findOrFail($campagneId);
        $this->service->definirTypes($campagne, $data['types'], $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Objectifs de la campagne enregistrés',
            'data'    => $campagne->fresh()->objectifsTypes()->withCount('objectifsAgents')->get(),
        ]);
    }
}