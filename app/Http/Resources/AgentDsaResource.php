<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class AgentDsaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'prenom' => $this->prenom,
            'nom' => $this->nom,
            'nom_complet' => $this->nom_complet,
            'sexe' => $this->sexe,
            'telephone' => $this->whenLoaded('utilisateur', fn () => $this->utilisateur->telephone),
            'email' => $this->whenLoaded('utilisateur', fn () => $this->utilisateur->email),
            'nom_utilisateur' => $this->whenLoaded('utilisateur', fn () => $this->utilisateur->nom_utilisateur),
            'adresse' => $this->adresse,
            'province' => $this->province,
            'ville' => $this->ville,
            'commune' => $this->commune,
            'photo_url' => $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null,
            // Donnée sensible : jamais exposée en clair, le fichier reste sur un disque privé.
            'piece_identite' => [
                'type' => $this->piece_identite_type,
                'numero_masque' => '****'.substr($this->piece_identite_numero, -4),
            ],
            'statut' => $this->statut->value,
            'date_embauche' => $this->date_embauche?->toDateString(),
            'date_resiliation' => $this->date_resiliation?->toDateString(),
            'zone' => $this->whenLoaded('zone'),
            'superviseur_id' => $this->superviseur_id,
            'statut_historiques' => $this->whenLoaded('statutHistoriques'),
            'affectations' => $this->whenLoaded('affectations'),
        ];
    }
}
