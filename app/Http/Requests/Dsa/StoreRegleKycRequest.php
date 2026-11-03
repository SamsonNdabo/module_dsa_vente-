<?php

namespace App\Http\Requests\Dsa;

use App\Models\KycPiece;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRegleKycRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('dsa.partenaires.gerer') ?? false;
    }

    public function rules(): array
    {
        return [
            'partenaire_id'  => 'required|integer|exists:partenaires,id',
            'type_document'  => ['required', Rule::in(KycPiece::TYPES_REGLE)],
            'obligatoire'    => 'sometimes|boolean',
            'validite_jours' => 'nullable|integer|min:1|max:3650',
            'actif'          => 'sometimes|boolean',

            // ⚠️ NOUVEAU : N produits (mix STOCK + EPARGNE)
            'produits'                => 'nullable|array',
            'produits.*.type'         => ['required_with:produits', Rule::in(['STOCK', 'EPARGNE'])],
            'produits.*.id'           => 'required_with:produits|integer|min:1',
        ];
    }

    public function messages(): array
    {
        return [
            'partenaire_id.required'  => 'Le partenaire est obligatoire.',
            'type_document.required'  => 'Le type de document est obligatoire.',
            'produits.*.type.in'      => 'Type de produit invalide (STOCK ou EPARGNE).',
            'produits.*.id.required_with' => 'L\'ID du produit est obligatoire.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $partenaireId = (int) $this->input('partenaire_id');
            $produits     = $this->input('produits', []) ?? [];

            if (empty($produits)) return;

            foreach ($produits as $index => $p) {
                $type = $p['type'] ?? null;
                $id   = $p['id'] ?? null;

                if (! $type || ! $id) continue;

                // Vérifier que le produit existe dans sa table
                $exists = $type === 'STOCK'
                    ? \App\Models\Produit::where('id', $id)->exists()
                    : \App\Models\ProduitEpargne::where('id', $id)->exists();

                if (! $exists) {
                    $v->errors()->add("produits.{$index}.id", "Le produit #{$id} ({$type}) est introuvable.");
                    continue;
                }

                // Vérifier le lien partenaire-produit (partenaire_produits)
                $lien = \App\Models\PartenaireProduit::where('id_partenaire', $partenaireId)
                    ->where('type_produit', $type)
                    ->where('id_produit', $id)
                    ->where('actif', 1)
                    ->exists();

                if (! $lien) {
                    $v->errors()->add("produits.{$index}.id", "Le produit #{$id} ({$type}) n'est pas lié au partenaire.");
                }
            }

            // Vérifier l'absence de doublons (même type + id)
            $vus = [];
            foreach ($produits as $index => $p) {
                $key = ($p['type'] ?? '') . ':' . ($p['id'] ?? '');
                if (isset($vus[$key])) {
                    $v->errors()->add("produits.{$index}.id", 'Ce produit est déjà dans la liste.');
                }
                $vus[$key] = true;
            }
        });
    }
}