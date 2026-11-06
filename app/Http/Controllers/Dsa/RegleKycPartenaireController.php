<?php

namespace App\Http\Controllers\Dsa;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dsa\Concerns\ExigePermission;
use App\Http\Requests\Dsa\StoreRegleKycRequest;
use App\Models\Kyc\RegleKycPartenaire;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RegleKycPartenaireController extends Controller
{
    use ExigePermission;

    public function index(Request $request)
    {
        $this->exiger($request, 'dsa.partenaires.gerer');

        $regles = RegleKycPartenaire::with('partenaire:id,code,nom')
            ->when($request->filled('partenaire_id'), fn ($q) => $q->where('partenaire_id', $request->integer('partenaire_id')))
            ->orderBy('partenaire_id')
            ->orderBy('type_document')
            ->get();

        // Enrichir avec produits résolus
        $regles->each(function ($r) {
            $r->produits_resolus = $r->produits_resolus;
            $r->produits_resume  = $r->produits_resume;
        });

        return response()->json($regles);
    }

    public function store(StoreRegleKycRequest $request)
    {
        $data = $request->validated();

        // Normalisation : produits_json = array ou null
        $produits = $data['produits'] ?? [];
        $produitsJson = empty($produits) ? null : array_values(array_map(function ($p) {
            return ['type' => $p['type'], 'id' => (int) $p['id']];
        }, $produits));

        // Anti-doublon (partenaire + type_document)
        $existe = RegleKycPartenaire::where('partenaire_id', $data['partenaire_id'])
            ->where('type_document', $data['type_document'])
            ->exists();

        if ($existe) {
            throw ValidationException::withMessages([
                'type_document' => 'Une règle existe déjà pour ce document chez ce partenaire.',
            ]);
        }

        $regle = RegleKycPartenaire::create([
            'partenaire_id'  => $data['partenaire_id'],
            'type_document'  => $data['type_document'],
            'obligatoire'    => $data['obligatoire'] ?? true,
            'validite_jours' => $data['validite_jours'] ?? null,
            'actif'          => $data['actif'] ?? true,
            'produits_json'  => $produitsJson,
        ]);

        $request->user()->logAudit('DSA_REGLE_KYC_CREEE', null, $regle->toArray());

        $regle->load('partenaire:id,code,nom');
        $regle->produits_resolus = $regle->produits_resolus;
        $regle->produits_resume  = $regle->produits_resume;

        return response()->json($regle, 201);
    }

    public function update(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.partenaires.gerer');
        $regle = RegleKycPartenaire::findOrFail($id);

        $data = $request->validate([
            'obligatoire'    => 'sometimes|boolean',
            'validite_jours' => 'nullable|integer|min:1|max:3650',
            'actif'          => 'sometimes|boolean',
            'produits'       => 'nullable|array',
            'produits.*.type'=> ['required_with:produits', 'in:STOCK,EPARGNE'],
            'produits.*.id'  => 'required_with:produits|integer|min:1',
        ]);

        $update = collect($data)->except(['produits'])->all();

        // Mise à jour des produits si fournis
        if (array_key_exists('produits', $data)) {
            $produits = $data['produits'] ?? [];
            $update['produits_json'] = empty($produits) ? null : array_values(array_map(function ($p) {
                return ['type' => $p['type'], 'id' => (int) $p['id']];
            }, $produits));
        }

        $regle->update($update);
        $request->user()->logAudit('DSA_REGLE_KYC_MODIFIEE', null, $update);

        $regle->load('partenaire:id,code,nom');
        $regle->produits_resolus = $regle->produits_resolus;
        $regle->produits_resume  = $regle->produits_resume;

        return $regle->fresh();
    }

    public function destroy(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.partenaires.gerer');
        $regle = RegleKycPartenaire::findOrFail($id);
        $regle->update(['actif' => false]);

        return response()->json(['success' => true]);
    }
}