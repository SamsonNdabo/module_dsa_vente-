<?php

namespace App\Http\Requests\Dsa;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAgentDsaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            // ─── Identité agent (→ Utilisateur)
            'prenom'    => 'required|string|max:100',
            'nom'       => 'required|string|max:100',
            'sexe'      => 'required|in:M,F',
            'province'  => 'required|string|max:100',
            'ville'     => 'required|string|max:100',
            'commune'   => 'nullable|string|max:100',
            'adresse'   => 'nullable|string|max:255',
            'photo'     => 'nullable|file|mimes:jpg,jpeg,png|max:4096',

            // ─── Contact (→ Utilisateur)
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('utilisateurs', 'email'),
            ],
            'telephone' => [
                'nullable', 'string', 'max:20',
                Rule::unique('utilisateurs', 'telephone'),
            ],

            // ─── Zone (obligatoire — superviseur déduit côté service)
            'zone_id' => 'required|integer|exists:zones,id',

            // ─── Pièce d'identité (→ AgentDsa)
            'piece_identite_type' => [
                'required', 'string', 'max:30',
                Rule::in(['CNI', 'PASSEPORT', 'PERMIS', 'CARTE_ELECTEUR', 'AUTRE']),
            ],
            'piece_identite_numero'  => 'required|string|max:60',
            'piece_identite_fichier' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:4096',

            // ─── Données RH (→ AgentDsa)
            'date_embauche' => 'required|date|before_or_equal:today',
            'objectifs'     => 'nullable|array',
            'objectifs.*'   => 'nullable|numeric|min:0',

            // ─── Compte POS (toujours créé)
            'nom_utilisateur' => [
                'nullable',
                'string',
                'max:50',
                'regex:/^[a-zA-Z0-9._-]+$/',
                Rule::unique('utilisateurs', 'nom_utilisateur'),
            ],
            'mot_de_passe' => [
                'required',
                'string',
                'min:8',
                'confirmed',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'zone_id.required'               => 'La zone est obligatoire pour un agent DSA.',
            'zone_id.exists'                 => 'La zone sélectionnée est introuvable.',
            'email.required'                 => 'L\'email est obligatoire (compte POS).',
            'email.unique'                   => 'Cet email existe déjà dans le POS.',
            'telephone.unique'               => 'Ce téléphone existe déjà dans le POS.',
            'mot_de_passe.required'          => 'Le mot de passe est obligatoire.',
            'mot_de_passe.min'               => 'Le mot de passe doit contenir au moins 8 caractères.',
            'mot_de_passe.confirmed'         => 'La confirmation du mot de passe ne correspond pas.',
            'nom_utilisateur.unique'         => 'Ce nom d\'utilisateur est déjà pris.',
            'nom_utilisateur.regex'          => 'Le nom d\'utilisateur ne peut contenir que lettres, chiffres, points, tirets et underscores.',
            'prenom.required'                => 'Le prénom est obligatoire.',
            'nom.required'                   => 'Le nom est obligatoire.',
            'sexe.required'                  => 'Le sexe est obligatoire.',
            'sexe.in'                        => 'Le sexe doit être M ou F.',
            'province.required'              => 'La province est obligatoire.',
            'ville.required'                 => 'La ville est obligatoire.',
            'date_embauche.required'         => 'La date d\'embauche est obligatoire.',
            'date_embauche.before_or_equal'  => 'La date d\'embauche ne peut pas être dans le futur.',
            'piece_identite_type.required'   => 'Le type de pièce est obligatoire.',
            'piece_identite_type.in'         => 'Type de pièce invalide (CNI, PASSEPORT, PERMIS, CARTE_ELECTEUR, AUTRE).',
            'piece_identite_numero.required' => 'Le numéro de pièce est obligatoire.',
        ];
    }
}