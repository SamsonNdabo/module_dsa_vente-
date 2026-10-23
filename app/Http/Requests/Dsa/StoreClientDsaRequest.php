<?php

namespace App\Http\Requests\Dsa;

use App\Models\Client;
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
        $typeClient = $this->input('type_client', 'PERSONNE_PHYSIQUE');
        $estEntreprise = $typeClient === 'ENTREPRISE';
        $estPersonne = $typeClient === 'PERSONNE_PHYSIQUE';

        return [
            // ─── Rattachement
            'agent_dsa_id' => 'nullable|integer|exists:agents_dsa,id',
            'zone_id'      => 'nullable|integer|exists:zones,id',

            // ─── Type de client
            'type_client'  => ['required', Rule::in(array_keys(Client::TYPES_CLIENT))],

            // ─── Identité (commun)
            'nom_complet'  => 'required|string|max:255',
            'telephone'    => 'required|string|max:30',
            'email'        => [
                'nullable', 'email', 'max:255',
                Rule::unique('clients', 'email')->whereNull('deleted_at'),
            ],
            'adresse'      => 'required|string|max:255',

            // ─── Personne physique
            'sexe'                  => [Rule::requiredIf($estPersonne), 'nullable', Rule::in(Client::SEXES ?? ['M', 'F'])],
            'date_naissance'        => [Rule::requiredIf($estPersonne), 'nullable', 'date', 'before:today'],
            'profession'            => [Rule::requiredIf($estPersonne), 'nullable', 'string', 'max:150'],
            'activite_economique'   => [Rule::requiredIf($estPersonne), 'nullable', 'string', 'max:150'],

            // ─── Entreprise
            'raison_sociale'        => [Rule::requiredIf($estEntreprise), 'nullable', 'string', 'max:200'],
            'numero_rc'             => [Rule::requiredIf($estEntreprise), 'nullable', 'string', 'max:50'],
            'numero_impot'          => 'nullable|string|max:50',
            'representant_legal'    => [Rule::requiredIf($estEntreprise), 'nullable', 'string', 'max:150'],

            // ─── Pièce d'identité (commun)
            'piece_identite_type'   => 'nullable|string|max:50',
            'piece_identite_numero' => 'nullable|string|max:100',

            // ─── Divers
            'metadata'              => 'nullable|array',
            'local_uuid'            => 'nullable|uuid',
        ];
    }

    public function messages(): array
    {
        return [
            'type_client.required'         => 'Le type de client est obligatoire.',
            'type_client.in'               => 'Le type de client est invalide.',
            'nom_complet.required'         => 'Le nom complet est obligatoire.',
            'telephone.required'           => 'Le téléphone est obligatoire.',
            'email.unique'                 => 'Cet email existe déjà.',
            'adresse.required'             => 'L\'adresse est obligatoire.',
            'sexe.required'                => 'Le sexe est obligatoire pour une personne physique.',
            'date_naissance.required'      => 'La date de naissance est obligatoire pour une personne physique.',
            'date_naissance.before'        => 'La date de naissance doit être passée.',
            'profession.required'          => 'La profession est obligatoire pour une personne physique.',
            'activite_economique.required' => "L'activité économique est obligatoire pour une personne physique.",
            'raison_sociale.required'      => 'La raison sociale est obligatoire pour une entreprise.',
            'numero_rc.required'           => 'Le numéro RC est obligatoire pour une entreprise.',
            'representant_legal.required'  => 'Le représentant légal est obligatoire pour une entreprise.',
            'local_uuid.uuid'              => 'L\'identifiant local doit être un UUID valide.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if ($this->boolean('type_client') === false) {
                return;
            }
            // Pas de conflit possible : plus de client_id ni creer_client_pos
        });
    }
}