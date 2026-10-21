<?php

namespace App\Http\Controllers\Dsa;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dsa\Concerns\ExigePermission;
use App\Http\Requests\Dsa\StoreCampagneRequest;
use App\Http\Requests\Dsa\UpdateCampagneRequest;
use App\Models\Campagne;
use App\Services\CampagneService;
use Illuminate\Http\Request;

class CampagneController extends Controller
{
    use ExigePermission;

    public function __construct(private CampagneService $service) {}

    public function index(Request $request)
    {
        $this->exiger($request, 'dsa.campagnes.view');

        $q = Campagne::with(['partenaire:id,nom', 'devise:id,code,libelle'])
            ->withCount(['affectations', 'objectifsTypes as objectifs_types_count'])
            ->when($request->filled('q'), function ($w) use ($request) {
                $t = '%' . $request->string('q') . '%';
                $w->where(fn ($x) => $x->where('nom', 'like', $t)->orWhere('code', 'like', $t));
            })
            ->when($request->filled('partenaire_id'), fn ($w) => $w->where('partenaire_id', $request->integer('partenaire_id')))
            ->when($request->filled('statut'), fn ($w) => $w->where('statut', $request->string('statut')))
            ->orderByDesc('date_debut');

        return response()->json(['success' => true, 'data' => $q->paginate($request->integer('per_page', 15))]);
    }

    public function options(Request $request)
    {
        $this->exiger($request, 'dsa.campagnes.view', allowSuperAdmin: true);

        return response()->json([
            'success' => true,
            'data'    => Campagne::query()
                ->select('id', 'code', 'nom', 'statut')
                ->when($request->boolean('only_actives', false), fn ($q) => $q->actives())
                ->orderBy('nom')
                ->get(),
        ]);
    }

    public function store(StoreCampagneRequest $request)
    {
        $campagne = $this->service->creer($request->validated(), $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Campagne créée',
            'data'    => $campagne->load('partenaire'),
        ], 201);
    }

    public function show(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.campagnes.view');

        $campagne = Campagne::with([
                'partenaire',
                'devise',
                'affectations.agent',
                'objectifsTypes.produit',
            ])
            ->withCount(['affectations', 'objectifsTypes as objectifs_types_count'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $campagne,
        ]);
    }

    public function update(UpdateCampagneRequest $request, int $id)
        {
            $campagne = Campagne::findOrFail($id);
            $ancienStatut = $campagne->statut;

            // Détecter un changement de statut dans le payload
            $nouveauStatut = $request->input('statut');

            // Si le statut change → passer par le service qui cascade
            if ($nouveauStatut && $nouveauStatut !== $ancienStatut) {
                // 1. Modifier d'abord les autres champs (nom, budget, dates, etc.)
                $dataSansStatut = $request->validated();
                unset($dataSansStatut['statut']);

                if (! empty($dataSansStatut)) {
                    $this->service->modifier($campagne, $dataSansStatut, $request->user());
                }

                // 2. Cascade du statut (validation + cascade sur objectifs)
                $campagne = $this->service->changerStatut($campagne->fresh(), $nouveauStatut, $request->user());

                return response()->json([
                    'success' => true,
                    'message' => 'Campagne mise à jour et statut cascadé',
                    'data'    => $campagne,
                ]);
            }

            // Pas de changement de statut → update classique
            $campagne = $this->service->modifier($campagne, $request->validated(), $request->user());

            return response()->json([
                'success' => true,
                'message' => 'Campagne mise à jour',
                'data'    => $campagne,
            ]);
        }
    public function changerStatut(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.campagnes.gerer');

        $data = $request->validate(['statut' => 'required|in:PLANIFIEE,ACTIVE,SUSPENDUE,TERMINEE,ANNULEE']);
        $campagne = Campagne::findOrFail($id);
        $campagne = $this->service->changerStatut($campagne, $data['statut'], $request->user());

        return response()->json(['success' => true, 'data' => $campagne]);
    }

    public function destroy(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.campagnes.gerer');
        Campagne::findOrFail($id)->delete();
        return response()->json(['success' => true, 'message' => 'Campagne supprimée']);
    }
}