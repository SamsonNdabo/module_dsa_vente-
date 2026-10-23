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
            'code_client'            => $this->code_client,
            'type_client'            => $this->type_client,
            'type_client_label'      => $this->type_client_label,

            // ─── Identité
            'nom_complet'            => $this->nom_complet,
            'sexe'                   => $this->sexe,
            'sexe_label'             => $this->sexe_label,
            'date_naissance'         => $this->date_naissance?->toDateString(),

            // ─── Contact
            'telephone'              => $this->telephone,
            'telephone_normalise'    => $this->telephone_normalise,
            'email'                  => $this->email,
            'adresse'                => $this->adresse,

            // ─── Personne physique
            'profession'             => $this->profession,
            'activite_economique'    => $this->activite_economique,

            // ─── Entreprise
            'raison_sociale'         => $this->raison_sociale,
            'numero_rc'              => $this->numero_rc,
            'numero_impot'           => $this->numero_impot,
            'representant_legal'     => $this->representant_legal,

            // ─── Identité légale
            'piece_identite_type'    => $this->piece_identite_type,
            'piece_identite_numero'  => $this->piece_identite_numero,

            // ─── Statuts
            'kyc_statut'             => $this->kyc_statut,
            'kyc_statut_label'       => $this->kyc_statut_label,
            'statut'                 => $this->statut,
            'statut_label'           => $this->statut_label,
            'categorie_client'       => $this->categorie_client,
            'categorie_label'        => $this->categorie_label,
            'source'                 => $this->source,

            // ─── Rattachement
            'agent_dsa_id'           => $this->agent_dsa_id,
            'zone_id'                => $this->zone_id,

            // ─── Divers
            'local_uuid'             => $this->local_uuid,
            'metadata'               => $this->metadata,

            // ─── Relations
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