<?php

namespace App\Http\Controllers\Dsa;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dsa\StorePartenaireRequest;
use App\Http\Resources\PartenaireResource;
use App\Models\Partenaire;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PartenaireController extends Controller
{
    /**
     * GET /api/dsa/partenaires
     */
    public function index(Request $request)
    {
        $this->exiger($request, 'dsa.partenaires.gerer');

        $query = Partenaire::withCount(['produits', 'reglesKyc'])
            ->when($request->filled('q'), function ($q) use ($request) {
                $t = '%' . $request->string('q') . '%';
                $q->where(fn ($w) => $w->where('code', 'like', $t)
                    ->orWhere('nom', 'like', $t)
                    ->orWhere('email', 'like', $t));
            })
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('actif'), fn ($q) => $q->where('actif', $request->boolean('actif')))
            ->orderBy('nom');

        return response()->json([
            'success' => true,
            'data'    => $query->paginate($request->integer('per_page', 15)),
        ]);
    }

    /**
     * GET /api/dsa/partenaires/options
     */
    public function options(Request $request)
    {
        $this->exiger($request, 'dsa.partenaires.gerer');

        return response()->json([
            'success' => true,
            'data'    => Partenaire::where('actif', true)
                ->orderBy('nom')
                ->select('id', 'code', 'nom', 'type')
                ->get(),
        ]);
    }

    /**
     * POST /api/dsa/partenaires
     */
    public function store(StorePartenaireRequest $request)
    {
        $data = $request->validated();
        $data['actif'] = $request->boolean('actif', true);

        $partenaire = Partenaire::create($data);

        return (new PartenaireResource($partenaire))
            ->additional(['success' => true, 'message' => 'Partenaire créé'])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/dsa/partenaires/{id}
     */
    public function show(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.partenaires.gerer');

        $partenaire = Partenaire::withCount(['produits', 'reglesKyc', 'statuts'])
            ->with([
                'produits:id,partenaire_id,code,nom,type,statut',
                'reglesKyc:id,partenaire_id,type_document,obligatoire,actif',
            ])
            ->findOrFail($id);

        return new PartenaireResource($partenaire);
    }

    /**
     * PUT /api/dsa/partenaires/{id}
     */
    public function update(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.partenaires.gerer');

        $partenaire = Partenaire::findOrFail($id);

        $data = $request->validate([
            'code'        => ['sometimes', 'string', 'max:30', Rule::unique('partenaires', 'code')->ignore($id)],
            'nom'         => 'sometimes|string|max:255',
            'type'        => ['sometimes', Rule::in(Partenaire::TYPES)],
            'telephone'   => 'nullable|string|max:30',
            'email'       => 'nullable|email|max:255',
            'adresse'     => 'nullable|string|max:255',
            'ville'       => 'nullable|string|max:100',
            'contact_nom' => 'nullable|string|max:255',
            'actif'       => 'sometimes|boolean',
            'metadata'    => 'nullable|array',
        ]);

        $partenaire->update($data);

        return (new PartenaireResource($partenaire->fresh()))
            ->additional(['success' => true, 'message' => 'Partenaire mis à jour']);
    }

    /**
     * DELETE /api/dsa/partenaires/{id}
     */
    public function destroy(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.partenaires.gerer');

        $partenaire = Partenaire::withCount('produits')->findOrFail($id);

        if ($partenaire->produits_count > 0) {
            return response()->json([
                'success' => false,
                'error'   => "Impossible de supprimer : {$partenaire->produits_count} produit(s) lié(s). Désactivez-le plutôt.",
            ], 400);
        }

        $partenaire->delete();

        return response()->json(['success' => true, 'message' => 'Partenaire supprimé']);
    }

    /**
     * POST /api/dsa/partenaires/{id}/toggle-actif
     */
    public function toggleActif(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.partenaires.gerer');

        $partenaire = Partenaire::findOrFail($id);
        $partenaire->actif = ! $partenaire->actif;
        $partenaire->save();

        return response()->json([
            'success' => true,
            'message' => $partenaire->actif ? 'Partenaire activé' : 'Partenaire désactivé',
            'actif'   => $partenaire->actif,
        ]);
    }

    private function exiger(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasPermission($permission), 403, 'Action non autorisée');
    }
}