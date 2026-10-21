<?php
// app/Http/Requests/Dsa/StoreCampagneRequest.php
namespace App\Http\Requests\Dsa;

use App\Models\Campagne;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCampagneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('dsa.campagnes.gerer') ?? false;
    }

    public function rules(): array
    {
        return [
            'partenaire_id'    => 'required|integer|exists:partenaires,id',
            'code'             => 'nullable|string|max:30|unique:campagnes,code',
            'nom'              => 'required|string|max:200',
            'description'      => 'nullable|string',
            'date_debut'       => 'required|date',
            'date_fin'         => 'nullable|date|after_or_equal:date_debut',
            'objectif_montant' => 'nullable|numeric|min:0',
            'objectif_volume'  => 'nullable|integer|min:0',
            'statut'           => ['nullable', Rule::in(Campagne::STATUTS)],
            'metadata'         => 'nullable|array',
        ];
    }
}