<?php

namespace App\Http\Controllers\Dsa;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dsa\StoreProduitDsaRequest;
use App\Http\Resources\ProduitDsaResource;
use App\Models\Produit;
use App\Models\ProduitDsa;
use App\Services\ProduitDsaService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProduitDsaController extends Controller
{
    public function __construct(private ProduitDsaService $service) {}

    public function index(Request $request)
    {
        $this->exiger($request, 'dsa.produits.view');

        $query = ProduitDsa::with([
                'partenaire:id,code,nom,type',
                'produit:id,code_barre,nom,type,gere_imei','devise',
            ])
            ->when($request->filled('q'), function ($q) use ($request) {
                $t = '%' . $request->string('q') . '%';
                $q->where(fn ($w) => $w->where('code', 'like', $t)
                    ->orWhere('nom', 'like', $t));
            })
            ->when($request->filled('partenaire_id'), fn ($q) => $q->where('partenaire_id', $request->integer('partenaire_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->string('statut')))
            ->when($request->filled('stock_requis'), fn ($q) => $q->where('stock_requis', $request->boolean('stock_requis')))
            ->orderBy('nom');

        return response()->json([
            'success' => true,
            'data'    => $query->paginate($request->integer('per_page', 15)),
        ]);
    }

    public function options(Request $request)
    {
        $this->exiger($request, 'dsa.produits.view');

        return response()->json([
            'success' => true,
            'data'    => ProduitDsa::where('statut', 'ACTIF')
                ->orderBy('nom')
                ->select('id', 'code', 'nom', 'type', 'prix_vente', 'partenaire_id')
                ->get(),
        ]);
    }

    /** Liste des produits POS pour l'autocomplete du formulaire */
    public function produitsPosDisponibles(Request $request)
    {
        $this->exiger($request, 'dsa.produits.manage');

        $query = Produit::select([
                'id', 'code_barre', 'nom', 'description', 'type',
                'gere_imei', 'prix_vente', 'prix_vente_TTC',
            ])
            ->where('actif', true)
            ->whereDoesntHave('produitDsa') // ⚠️ voir relation ci-dessous
            ->when($request->filled('q'), function ($q) use ($request) {
                $t = '%' . $request->string('q') . '%';
                $q->where(fn ($w) => $w->where('nom', 'like', $t)
                    ->orWhere('code_barre', 'like', $t));
            })
            ->orderBy('nom')
            ->limit(30);

        return response()->json([
            'success' => true,
            'data'    => $query->get(),
        ]);
    }

    public function store(StoreProduitDsaRequest $request)
    {
        $produit = $this->service->creer($request->validated(), $request->user());

        return (new ProduitDsaResource($produit->load(['partenaire', 'produit'])))
            ->additional(['success' => true, 'message' => 'Produit DSA créé'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.produits.view');

        $produit = ProduitDsa::with(['partenaire', 'produit', 'reglesKyc', 'devise'])
            ->findOrFail($id);

        return new ProduitDsaResource($produit);
    }

    public function update(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.produits.manage');

        $produit = ProduitDsa::findOrFail($id);

        $data = $request->validate([
            'partenaire_id'   => 'sometimes|integer|exists:partenaires,id',
            'produit_id'      => 'nullable|integer|exists:produit,id',
            'code'            => ['sometimes', 'string', 'max:40', Rule::unique('produits_dsa', 'code')->ignore($id)],
            'nom'             => 'sometimes|string|max:255',
            'type'            => 'sometimes|string|max:30',
            'categorie'       => 'nullable|string|max:50',
            'prix_vente'      => 'nullable|numeric|min:0',
            'unite'           => 'nullable|string|max:20',
            'commission'      => 'nullable|numeric|min:0',
            'commission_type' => 'sometimes|in:FIXE,POURCENTAGE',
            'taux_commission' => 'nullable|numeric|min:0|max:100',
            'stock_requis'    => 'sometimes|boolean',
            'est_activation'  => 'sometimes|boolean',
            'date_debut'      => 'nullable|date',
            'date_fin'        => 'nullable|date|after_or_equal:date_debut',
            'statut'          => 'sometimes|in:ACTIF,INACTIF',
            'metadata'        => 'nullable|array',
        ]);

        $produit = $this->service->modifier($produit, $data, $request->user());

        return (new ProduitDsaResource($produit))
            ->additional(['success' => true, 'message' => 'Produit DSA mis à jour']);
    }

    public function destroy(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.produits.manage');

        $produit = ProduitDsa::findOrFail($id);
        $produit->update(['statut' => 'INACTIF']);

        return response()->json([
            'success' => true,
            'message' => 'Produit DSA désactivé (conservé pour l\'historique)',
        ]);
    }

    private function exiger(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasPermission($permission), 403, 'Action non autorisée');
    }
}