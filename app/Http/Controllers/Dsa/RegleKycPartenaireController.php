<?php

namespace App\Http\Controllers\Dsa;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dsa\Concerns\ExigePermission;
use App\Models\KycPiece;
use App\Models\ProduitDsa;
use App\Models\RegleKycPartenaire;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Configuration des exigences KYC d'un partenaire (une règle se désactive, elle ne se supprime pas). */
class RegleKycPartenaireController extends Controller
{
    use ExigePermission;

    public function index(Request $request)
    {
        $this->exiger($request, 'dsa.partenaires.gerer');

        return RegleKycPartenaire::with('produit:id,code,nom')
            ->when($request->filled('partenaire_id'), fn ($q) => $q->where('partenaire_id', $request->integer('partenaire_id')))
            ->orderBy('partenaire_id')->orderBy('type_document')
            ->get();
    }

    public function store(Request $request)
    {
        $this->exiger($request, 'dsa.partenaires.gerer');

        $data = $request->validate([
            'partenaire_id' => 'required|integer|exists:partenaires,id',
            'produit_dsa_id' => 'nullable|integer|exists:produits_dsa,id',
            'type_document' => ['required', Rule::in(KycPiece::TYPES_REGLE)],
            
            'obligatoire' => 'sometimes|boolean',
            'validite_jours' => 'nullable|integer|min:1|max:3650',
            'actif' => 'sometimes|boolean',
        ]);

        $produitId = $data['produit_dsa_id'] ?? null;

        if ($produitId && ! ProduitDsa::whereKey($produitId)->where('partenaire_id', $data['partenaire_id'])->exists()) {
            throw ValidationException::withMessages(['produit_dsa_id' => "Ce produit n'appartient pas à ce partenaire."]);
        }

        $existe = RegleKycPartenaire::where('partenaire_id', $data['partenaire_id'])
            ->where('type_document', $data['type_document'])
            ->when($produitId, fn ($q) => $q->where('produit_dsa_id', $produitId), fn ($q) => $q->whereNull('produit_dsa_id'))
            ->exists();

        if ($existe) {
            throw ValidationException::withMessages(['type_document' => 'Une règle existe déjà pour ce document (modifier la règle existante).']);
        }

        $regle = RegleKycPartenaire::create($data);
        $request->user()->logAudit('DSA_REGLE_KYC_CREEE', null, $regle->toArray());

        return response()->json($regle, 201);
    }

    public function update(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.partenaires.gerer');
        $regle = RegleKycPartenaire::findOrFail($id);

        $data = $request->validate([
            'obligatoire' => 'sometimes|boolean',
            'validite_jours' => 'nullable|integer|min:1|max:3650',
            'actif' => 'sometimes|boolean',
        ]);

        $avant = $regle->only(array_keys($data));
        $regle->update($data);
        $request->user()->logAudit('DSA_REGLE_KYC_MODIFIEE', $avant, $data);

        return $regle->fresh();
    }
}
