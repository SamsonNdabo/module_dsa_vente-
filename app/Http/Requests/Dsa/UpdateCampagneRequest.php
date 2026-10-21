<?php
// app/Http/Requests/Dsa/UpdateCampagneRequest.php
namespace App\Http\Requests\Dsa;

use App\Models\Campagne;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCampagneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('dsa.campagnes.gerer') ?? false;
    }

    public function rules(): array
    {
        return [
            'nom'              => 'sometimes|string|max:200',
            'description'      => 'nullable|string',
            'date_debut'       => 'sometimes|date',
            'date_fin'         => 'nullable|date|after_or_equal:date_debut',
            'objectif_montant' => 'nullable|numeric|min:0',
            'objectif_volume'  => 'nullable|integer|min:0',
            'statut'           => ['sometimes', Rule::in(Campagne::STATUTS)],
            'metadata'         => 'nullable|array',
        ];
    }
}