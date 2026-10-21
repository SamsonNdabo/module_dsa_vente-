<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProduitDsaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'partenaire_id'   => $this->partenaire_id,
            'produit_id'      => $this->produit_id,
            'devise_id'       => $this->devise_id,
            'code'            => $this->code,
            'nom'             => $this->nom,
            'type'            => $this->type,
            'categorie'       => $this->categorie,
            'prix_vente'      => $this->prix_vente,
            'unite'           => $this->unite,
            'commission'      => $this->commission,
            'commission_type' => $this->commission_type,
            'taux_commission' => $this->taux_commission,
            'stock_requis'    => (bool) $this->stock_requis,
            'est_activation'  => (bool) $this->est_activation,
            'date_debut'      => $this->date_debut?->toDateString(),
            'date_fin'        => $this->date_fin?->toDateString(),
            'statut'          => $this->statut,
            'metadata'        => $this->metadata,

            'partenaire' => $this->whenLoaded('partenaire', fn () => [
                'id'   => $this->partenaire->id,
                'code' => $this->partenaire->code,
                'nom'  => $this->partenaire->nom,
                'type' => $this->partenaire->type,
            ]),

            'devise' => $this->whenLoaded('devise', fn () => $this->devise ? [
                'id'      => $this->devise->id,
                'code'    => $this->devise->code,
                'nom'     => $this->devise->nom    ?? $this->devise->libelle ?? null,
                'symbole' => $this->devise->symbole ?? null,
            ] : null),

            'produit_pos' => $this->whenLoaded('produit', fn () => $this->produit ? [
                'id'         => $this->produit->id,
                'code_barre' => $this->produit->code_barre,
                'nom'        => $this->produit->nom,
                'type'       => $this->produit->type,
                'gere_imei'  => (bool) $this->produit->gere_imei,
                'prix_vente' => $this->produit->prix_vente,
            ] : null),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}