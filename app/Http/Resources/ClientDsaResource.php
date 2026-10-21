<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientDsaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->id,
            'client_id'              => $this->client_id,
            'agent_dsa_id'           => $this->agent_dsa_id,
            'zone_id'                => $this->zone_id,
            'code_client'            => $this->code_client,
            'nom_complet'            => $this->nom_complet,
            'sexe'                   => $this->sexe,
            'sexe_label'             => $this->sexe_label,
            'date_naissance'         => $this->date_naissance?->toDateString(),
            'telephone'              => $this->telephone,
            'telephone_normalise'    => $this->telephone_normalise,
            'email'                  => $this->email,
            'adresse'                => $this->adresse,
            'profession'             => $this->profession,
            'activite_economique'    => $this->activite_economique,
            'piece_identite_type'    => $this->piece_identite_type,
            'piece_identite_numero'  => $this->piece_identite_numero,
            'kyc_statut'             => $this->kyc_statut,
            'kyc_statut_label'       => $this->kyc_statut_label,
            'statut'                 => $this->statut,
            'statut_label'           => $this->statut_label,
            'source'                 => $this->source,
            'local_uuid'             => $this->local_uuid,
            'metadata'               => $this->metadata,

            'client_pos' => $this->whenLoaded('clientPos', fn () => $this->clientPos ? [
                'id'         => $this->clientPos->id,
                'code'       => $this->clientPos->code_client,
                'nom_complet'=> $this->clientPos->nom_complet,
                'email'      => $this->clientPos->email,
                'telephone'  => $this->clientPos->telephone,
            ] : null),

            'agent_dsa' => $this->whenLoaded('agentDsa', fn () => $this->agentDsa ? [
                'id'         => $this->agentDsa->id,
                'code'       => $this->agentDsa->code,
                'nom_complet'=> $this->agentDsa->nom_complet,
            ] : null),

            'zone' => $this->whenLoaded('zone', fn () => $this->zone ? [
                'id'   => $this->zone->id,
                'code' => $this->zone->code,
                'nom'  => $this->zone->nom,
            ] : null),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}