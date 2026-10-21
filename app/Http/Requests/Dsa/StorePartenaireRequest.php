<?php

namespace App\Http\Requests\Dsa;

use App\Models\Partenaire;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePartenaireRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('dsa.partenaires.gerer') ?? false;
    }

    public function rules(): array
    {
        return [
            'code'        => 'required|string|max:30|unique:partenaires,code',
            'nom'         => 'required|string|max:255',
            'type'        => ['required', Rule::in(Partenaire::TYPES)],
            'telephone'   => 'nullable|string|max:30',
            'email'       => 'nullable|email|max:255',
            'adresse'     => 'nullable|string|max:255',
            'ville'       => 'nullable|string|max:100',
            'contact_nom' => 'nullable|string|max:255',
            'actif'       => 'sometimes|boolean',
            'metadata'    => 'nullable|array',
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'Le code est obligatoire.',
            'code.unique'   => 'Ce code est déjà utilisé.',
            'nom.required'  => 'Le nom est obligatoire.',
            'type.in'       => 'Type invalide. Valeurs acceptées : ' . implode(', ', Partenaire::TYPES),
        ];
    }
}