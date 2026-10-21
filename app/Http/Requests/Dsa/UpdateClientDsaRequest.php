<?php

namespace App\Http\Requests\Dsa;

use App\Models\ClientDsa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClientDsaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('dsa.clients.gerer') ?? false;
    }

    public function rules(): array
    {
        return [
            'nom_complet'            => 'sometimes|string|max:255',
            'sexe'                   => ['sometimes', Rule::in(ClientDsa::SEXES)],
            'date_naissance'         => 'sometimes|date|before:today',
            'telephone'              => 'sometimes|string|max:30',
            'email'                  => 'nullable|email|max:255',
            'adresse'                => 'sometimes|string|max:255',
            'profession'             => 'sometimes|string|max:150',
            'activite_economique'    => 'sometimes|string|max:150',
            'piece_identite_type'    => 'nullable|string|max:50',
            'piece_identite_numero'  => 'nullable|string|max:100',
            'agent_dsa_id'           => 'nullable|integer|exists:agents_dsa,id',
            'zone_id'                => 'nullable|integer|exists:zones,id',
            'kyc_statut'             => ['sometimes', Rule::in(ClientDsa::KYC_STATUTS)],
            'statut'                 => ['sometimes', Rule::in(ClientDsa::STATUTS)],
            'metadata'               => 'nullable|array',
        ];
    }
}