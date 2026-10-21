<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PartenaireResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'code'            => $this->code,
            'nom'             => $this->nom,
            'type'            => $this->type,
            'telephone'       => $this->telephone,
            'email'           => $this->email,
            'adresse'         => $this->adresse,
            'ville'           => $this->ville,
            'contact_nom'     => $this->contact_nom,
            'actif'           => (bool) $this->actif,
            'metadata'        => $this->metadata,
            'produits_count'  => $this->whenCounted('produits'),
            'regles_kyc_count'=> $this->whenCounted('reglesKyc'),
            'produits'        => $this->whenLoaded('produits'),
            'regles_kyc'      => $this->whenLoaded('reglesKyc'),
            'created_at'      => $this->created_at?->toISOString(),
            'updated_at'      => $this->updated_at?->toISOString(),
        ];
    }
}