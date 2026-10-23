<?php

namespace App\Models;

use App\Enums\AgentStatut;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentDsa extends Model
{
    protected $table = 'agents_dsa';

    protected $fillable = [
        'code', 'utilisateur_id', 'succursale_id', 'zone_id', 'superviseur_id',
        'prenom', 'nom', 'sexe', 'adresse', 'province', 'ville', 'commune',
        'photo_path', 'piece_identite_type', 'piece_identite_numero', 'piece_identite_fichier',
        'date_embauche', 'date_resiliation', 'statut', 'objectifs', 'created_by',
    ];

    protected $hidden = ['piece_identite_fichier'];

    protected $casts = [
        'statut' => AgentStatut::class,
        'date_embauche' => 'date',
        'date_resiliation' => 'date',
        'objectifs' => 'array',
    ];

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'utilisateur_id');
    }

    public function superviseur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'superviseur_id');
    }

    public function clientsDsa(): HasMany
    {
        return $this->hasMany(Client::class, 'agent_dsa_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'zone_id'); // modèle Zone à créer à l'étape territoire
    }

    public function statutHistoriques(): HasMany
    {
        return $this->hasMany(AgentStatutHistorique::class, 'agent_dsa_id')->latest('id');
    }

    public function affectations(): HasMany
    {
        return $this->hasMany(AgentAffectation::class, 'agent_dsa_id')->latest('id');
    }

    public function objectifs(): HasMany
    {
        return $this->hasMany(ObjectifMesurable::class, 'agent_dsa_id');
    }

    public function getNomCompletAttribute(): string
    {
        return trim("{$this->prenom} {$this->nom}");
    }

    public function scopeActifs(Builder $q): Builder
    {
        return $q->where('statut', AgentStatut::ACTIF->value);
    }

    /** Périmètre de visibilité (cahier des charges §3). */
    public function scopeVisiblePour(Builder $q, Utilisateur $u): Builder
    {
        if ($u->hasRole(['SUPER_ADMIN', 'ADMIN_DSA'])) {
            return $q;
        }

        if ($u->hasRole('SUPERVISEUR_DSA')) {
            return $q->where(function (Builder $w) use ($u) {
                $w->where('superviseur_id', $u->id)
                  ->orWhereHas('zone', fn (Builder $z) => $z->where('superviseur_id', $u->id));
            });
        }

        return $q->whereRaw('1 = 0');
    }
    
}
