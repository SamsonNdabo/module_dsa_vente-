<?php

namespace App\Http\Resources;

use App\Enums\AgentStatut;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class AgentDsaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // ─── Utilisateur : source unique des données personnelles
        $u = $this->whenLoaded('utilisateur', fn () => $this->utilisateur, null);
        $prefs = $u?->preferences ?? [];

        // ─── Pièce d'identité : masquer le numéro (sensible)
        $pieceNumeroMasque = null;
        if (! empty($this->piece_identite_numero)) {
            $len = strlen($this->piece_identite_numero);
            $pieceNumeroMasque = $len > 4
                ? str_repeat('*', $len - 4) . substr($this->piece_identite_numero, -4)
                : str_repeat('*', $len);
        }

        return [
            // ─── Identité agent
            'id'   => $this->id,
            'code' => $this->code,

            // ─── Données personnelles (déléguées à Utilisateur)
            //     NB : si vous avez choisi de stocker sexe/adresse/province/ville/commune
            //     en colonnes dédiées dans `utilisateurs`, remplacez $prefs['sexe'] par $u->sexe.
            'prenom'         => $u?->prenom         ?? null, // ⚠️ voir note plus bas
            'nom'            => $u?->nom            ?? null, // ⚠️ voir note plus bas
            'nom_complet'    => $u?->nom_complet    ?? null,
            'email'          => $u?->email          ?? null,
            'telephone'      => $u?->telephone      ?? null,
            'nom_utilisateur'=> $u?->nom_utilisateur?? null,
            'sexe'           => $prefs['sexe']      ?? ($u?->sexe ?? null),
            'adresse'        => $prefs['adresse']   ?? ($u?->adresse ?? null),
            'province'       => $prefs['province']  ?? ($u?->province ?? null),
            'ville'          => $prefs['ville']     ?? ($u?->ville ?? null),
            'commune'        => $prefs['commune']   ?? ($u?->commune ?? null),
            'photo_url'      => $u?->photo_profil
                ? Storage::disk('public')->url($u->photo_profil)
                : null,

            // ─── Pièce d'identité (métier AgentDsa, jamais en clair)
            'piece_identite' => [
                'type'          => $this->piece_identite_type,
                'numero_masque' => $pieceNumeroMasque,
                'fichier_existe'=> ! empty($this->piece_identite_fichier),
                // ⚠️ on n'expose JAMAIS l'URL du fichier pièce d'identité (disque privé)
            ],

            // ─── Statut & RH
            'statut'            => $this->statut instanceof AgentStatut
                                        ? $this->statut->value
                                        : $this->statut,
            'date_embauche'     => $this->date_embauche?->toDateString(),
            'date_resiliation'  => $this->date_resiliation?->toDateString(),
            'objectifs'         => $this->objectifs,

            // ─── Rattachement
            'zone'            => $this->whenLoaded('zone', fn () => [
                'id'   => $this->zone->id,
                'code' => $this->zone->code,
                'nom'  => $this->zone->nom,
            ]),
            'superviseur_id'  => $this->superviseur_id,
            'superviseur'     => $this->whenLoaded('superviseur', fn () => $this->superviseur ? [
                'id'          => $this->superviseur->id,
                'nom_complet' => $this->superviseur->nom_complet,
                'email'       => $this->superviseur->email,
            ] : null),

            // ─── Relations secondaires
            'statut_historiques' => $this->whenLoaded('statutHistoriques'),
            'affectations'       => $this->whenLoaded('affectations'),

            // ─── Métadonnées
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}