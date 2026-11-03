<?php

namespace App\Http\Requests\Dsa;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SouscriptionClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'client_id'       => 'required|integer|exists:clients,id',
            'type_produit'    => ['required', Rule::in(['STOCK', 'EPARGNE'])],
            'mode_execution'  => ['nullable', Rule::in(['AUTO', 'VENTE', 'COMMANDE'])],

            // Panier multi-produits
            'items'                 => 'required_if:type_produit,STOCK|array|min:1',
            'items.*.id_produit'    => 'required_with:items|integer|min:1',
            'items.*.quantite'      => 'required_with:items|integer|min:1|max:9999',
            'items.*.id_partenaire' => 'nullable|integer|exists:partenaires,id',

            // Épargne
            'id_produit'      => 'required_if:type_produit,EPARGNE|integer|min:1',
            'id_partenaire'   => 'nullable|integer|exists:partenaires,id',
            'montant_initial' => 'nullable|numeric|min:0',

            // Livraison
            'mode_livraison'           => ['nullable', Rule::in(['DOMICILE', 'POINT_RELAIS', 'RETRAIT_MAGASIN'])],
            'adresse_livraison'        => 'nullable|string|max:500',
            'telephone_livraison'      => 'nullable|string|max:30',
            'date_livraison_souhaitee' => 'nullable|date|after_or_equal:today', // 🆕

            // Récurrence
            'est_recurrente'           => 'sometimes|boolean',
            'recurrence_type'          => ['nullable', Rule::in(['AUCUNE', 'HEBDOMADAIRE', 'MENSUEL', 'TRIMESTRIEL', 'ANNUEL'])],
            'recurrence_intervalle'    => 'nullable|integer|min:1|max:12',
            'recurrence_date_fin'      => 'nullable|date|after:today',
            'recurrence_nb_occurrences'=> 'nullable|integer|min:1|max:100',

            'caisse_id' => 'nullable|integer|exists:caisses,id',
            'notes'     => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'client_id.required'                => 'Le client est obligatoire.',
            'type_produit.in'                   => 'Type invalide.',
            'items.required_if'                 => 'Ajoutez au moins un produit au panier.',
            'items.*.quantite.min'              => 'Quantité minimum : 1.',
            'id_produit.required_if'            => 'Le produit est obligatoire pour l\'épargne.',
            'date_livraison_souhaitee.after_or_equal' => 'La date de livraison doit être aujourd\'hui ou plus tard.',
            'recurrence_intervalle.min'         => 'L\'intervalle doit être au moins 1.',
            'recurrence_intervalle.max'         => 'L\'intervalle ne peut pas dépasser 12.',
            'recurrence_nb_occurrences.max'     => 'Maximum 100 occurrences.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if (! $this->boolean('est_recurrente')) {
                return;
            }

            // Type obligatoire
            if (! $this->filled('recurrence_type') || $this->input('recurrence_type') === 'AUCUNE') {
                $v->errors()->add('recurrence_type', 'Le type de récurrence est obligatoire.');
            }

            // Intervalle obligatoire
            if (! $this->filled('recurrence_intervalle')) {
                $v->errors()->add('recurrence_intervalle', 'L\'intervalle de récurrence est obligatoire.');
            }

            // ⚠️ NOUVEAU : soit date_fin, soit nb_occurrences (au moins un des deux)
            $aDateFin       = $this->filled('recurrence_date_fin');
            $aNbOccurrences = $this->filled('recurrence_nb_occurrences');

            if (! $aDateFin && ! $aNbOccurrences) {
                $v->errors()->add(
                    'recurrence_nb_occurrences',
                    'Renseignez soit le nombre d\'occurrences (la date de fin sera calculée), soit la date de fin.'
                );
            }

            // Si les deux sont renseignés, on préfère nb_occurrences (calcul auto)
            if ($aDateFin && $aNbOccurrences) {
                $v->errors()->add(
                    'recurrence_date_fin',
                    'Renseignez soit le nombre d\'occurrences (calcul auto), soit la date de fin, pas les deux.'
                );
            }
        });
    }
}