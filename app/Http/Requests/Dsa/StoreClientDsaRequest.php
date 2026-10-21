<?php

namespace App\Http\Requests\Dsa;

use App\Models\ClientDsa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClientDsaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('dsa.clients.creer') ?? false;
    }

    public function rules(): array
    {
        $creerPos = (bool) $this->boolean('creer_client_pos');

        return [
            'agent_dsa_id'           => 'nullable|integer|exists:agents_dsa,id',
            'zone_id'                => 'nullable|integer|exists:zones,id',
            'nom_complet'            => 'required|string|max:255',
            'sexe'                   => ['required', Rule::in(ClientDsa::SEXES)],
            'date_naissance'         => 'required|date|before:today',
            'telephone'              => 'required|string|max:30',
            'email'                  => 'nullable|email|max:255',
            'adresse'                => 'required|string|max:255',
            'profession'             => 'required|string|max:150',
            'activite_economique'    => 'required|string|max:150',
            'piece_identite_type'    => 'nullable|string|max:50',
            'piece_identite_numero'  => 'nullable|string|max:100',
            'metadata'               => 'nullable|array',
            'local_uuid'             => 'nullable|uuid',

            // Cas 1 : lien POS existant
            'client_id'              => 'nullable|integer|exists:clients,id',

            // Cas 2b : création POS
            'creer_client_pos'       => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'nom_complet.required'         => 'Le nom complet est obligatoire.',
            'telephone.required'           => 'Le téléphone est obligatoire.',
            'date_naissance.required'      => 'La date de naissance est obligatoire.',
            'date_naissance.before'        => 'La date de naissance doit être passée.',
            'profession.required'          => 'La profession est obligatoire.',
            'activite_economique.required' => "L'activité économique est obligatoire.",
            'client_id.exists'             => 'Le client POS sélectionné est introuvable.',
            'local_uuid.uuid'              => 'L\'identifiant local doit être un UUID valide.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            // Si pas de client POS lié ET pas de création POS → client DSA seul (OK)
            // Si client_id fourni ET creer_client_pos → conflit
            if ($this->filled('client_id') && $this->boolean('creer_client_pos')) {
                $v->errors()->add('client_id', 'Impossible de lier un client POS existant ET d\'en créer un nouveau.');
            }
        });
    }
}