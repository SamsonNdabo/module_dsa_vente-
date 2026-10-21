<?php

namespace App\Http\Requests\Dsa;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAgentDsaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('dsa.agents.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'prenom' => 'required|string|max:100',
            'nom' => 'required|string|max:100',
            'sexe' => 'required|in:M,F',
            'telephone' => 'required|string|max:20|unique:utilisateurs,telephone',
            'email' => 'required|email|max:255|unique:utilisateurs,email',
            'nom_utilisateur' => 'required|alpha_dash|max:50|unique:utilisateurs,nom_utilisateur',
            'adresse' => 'nullable|string|max:255',
            'province' => 'required|string|max:100',
            'ville' => 'required|string|max:100',
            'commune' => 'nullable|string|max:100',
            'piece_identite_type' => 'required|in:CARTE_IDENTITE,PASSEPORT,CARTE_ELECTEUR,PERMIS,AUTRE',
            'piece_identite_numero' => [
                'required', 'string', 'max:60',
                Rule::unique('agents_dsa', 'piece_identite_numero')
                    ->where('piece_identite_type', $this->input('piece_identite_type')),
            ],
            'date_embauche' => 'required|date',
            'succursale_id' => 'nullable|exists:succursales,id',
            'zone_id' => 'nullable|exists:zones,id',
            'superviseur_id' => [
                'nullable',
                Rule::exists('utilisateurs', 'id')->where(
                    fn ($q) => $q->whereIn('role_id', Role::where('code', 'SUPERVISEUR_DSA')->pluck('id'))
                ),
            ],
            'photo' => 'nullable|image|max:2048',
            'piece_identite_fichier' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:4096',
        ];
    }
}
