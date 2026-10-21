<?php

namespace App\Http\Requests\Dsa;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProduitDsaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('dsa.produits.manage') ?? false;
    }

    public function rules(): array
    {
        $creerPos  = (bool) $this->boolean('creer_produit_pos');
        $produitId = $this->input('produit_id');

        return [
            'partenaire_id'    => 'required|integer|exists:partenaires,id',
            'code'             => 'required|string|max:40|unique:produits_dsa,code',
            'nom'              => 'required|string|max:255',
            'type'             => 'required|string|max:30',
            'categorie'        => 'nullable|string|max:50',
            'prix_vente'       => 'nullable|numeric|min:0',
            'devise_id'        => 'nullable|integer|exists:devise,id',
            'unite'            => 'nullable|string|max:20',

            'commission'       => 'nullable|numeric|min:0',
            'commission_type'  => 'required|in:FIXE,POURCENTAGE',
            'taux_commission'  => 'nullable|numeric|min:0|max:100',

            'stock_requis'     => 'sometimes|boolean',
            'est_activation'   => 'sometimes|boolean',
            'date_debut'       => 'nullable|date',
            'date_fin'         => 'nullable|date|after_or_equal:date_debut',
            'statut'           => 'sometimes|in:ACTIF,INACTIF',
            'metadata'         => 'nullable|array',

            // Cas 1 : lien POS existant
            'produit_id'       => [
                Rule::requiredIf(fn () => !$creerPos && !$this->boolean('est_activation') && $this->boolean('stock_requis')),
                'nullable',
                'integer',
                'exists:produit,id',
            ],

            // Cas 2b : création POS
            'creer_produit_pos'          => 'sometimes|boolean',
            'produit_pos_code_barre'     => 'nullable|string|max:100|unique:produit,code_barre',
            'produit_pos_description'    => 'nullable|string',
            'produit_pos_marque'         => 'nullable|string|max:100',
            'produit_pos_modele'         => 'nullable|string|max:100',
            'produit_pos_type'           => 'nullable|string|max:30',
            'produit_pos_gere_imei'      => 'sometimes|boolean',
            'produit_pos_prix_achat'     => 'nullable|numeric|min:0',
            'produit_pos_tva'            => 'nullable|integer|min:0|max:100',
            'produit_pos_stock_minimum'  => 'nullable|integer|min:0',
            'produit_pos_est_perissable' => 'sometimes|boolean',
            'produit_pos_categorie_id'   => 'nullable|integer',
            'produit_pos_devise_id'      => [
                Rule::requiredIf(fn () => $creerPos),
                'nullable',
                'integer',
                'exists:devise,id',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'produit_id.required'      => 'Sélectionnez un produit POS ou cochez « Créer dans le POS ».',
            'partenaire_id.required'   => 'Le partenaire est obligatoire.',
            'code.unique'              => 'Ce code produit DSA est déjà utilisé.',
            'devise_id.exists'         => 'La devise sélectionnée est invalide.',
            'produit_pos_devise_id.required' => 'La devise du produit POS est obligatoire.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if ($this->boolean('est_activation') && $this->boolean('stock_requis')) {
                $v->errors()->add(
                    'est_activation',
                    'Un produit d\'activation ne peut pas avoir de stock physique.'
                );
            }
        });
    }
}