<?php

namespace App\Models;

use App\Enums\AgentStatut;
use App\Models\Agent\AgentAffectation;
use App\Models\Agent\AgentStatutHistorique;
use App\Models\Campagne\CampagneAgent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentDsa extends Model
{
    protected $table = 'agents_dsa';

    protected $fillable = [
        'code',
        'utilisateur_id',
        'zone_id',
        'superviseur_id',
        'date_embauche',
        'date_resiliation',
        'statut',
        'objectifs',
        'piece_identite_type',
        'piece_identite_numero',
        'piece_identite_fichier',
        'created_by',
    ];

    protected $casts = [
        'objectifs'        => 'array',
        'date_embauche'    => 'date',
        'date_resiliation' => 'date',
        'statut'           => AgentStatut::class,
    ];

    protected $attributes = [
        'statut' => 'ACTIF',
    ];

    /* ---------------- Relations ---------------- */

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'utilisateur_id', 'id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'zone_id');
    }

    public function superviseur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'superviseur_id');
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'created_by');
    }

    public function campagnes(): HasMany
    {
        return $this->hasMany(CampagneAgent::class, 'id_agent');
    }

    public function statutHistoriques(): HasMany
    {
        return $this->hasMany(AgentStatutHistorique::class, 'agent_dsa_id');
    }

    public function affectations(): HasMany
    {
        return $this->hasMany(AgentAffectation::class, 'agent_dsa_id');
    }

    /* ---------------- Scopes ---------------- */

    public function scopeActifs($query)
    {
        return $query->where('statut', AgentStatut::ACTIF->value);
    }

    public function scopeParZone($query, int $zoneId)
    {
        return $query->where('zone_id', $zoneId);
    }

    public function scopeParSuperviseur($query, int $superviseurId)
    {
        return $query->where('superviseur_id', $superviseurId);
    }

    public function scopeVisiblePour(Builder $query, ?Utilisateur $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasRole(['SUPER_ADMIN'])) {
            return $query;
        }

        // Un superviseur voit tous les agents rattachés à ses zones
        if ($user->hasRole('SUPERVISEUR')) {
            return $query->where(function ($q) use ($user) {
                $q->where('superviseur_id', $user->id)
                  ->orWhereHas('zone', fn ($z) => $z->where('superviseur_id', $user->id));
            });
        }

        // Un agent DSA ne voit que son propre profil
        if ($user->hasRole('AGENT_DSA')) {
            return $query->where('utilisateur_id', $user->id);
        }

        // Par défaut : aucun accès
        return $query->whereRaw('1 = 0');
    }

    /* ---------------- Helpers ---------------- */

    public function estActif(): bool
    {
        return $this->statut === AgentStatut::ACTIF;
    }

    /** Délègue à Utilisateur (pas de duplication) */
    public function nomComplet(): string
    {
        return $this->utilisateur?->nom_complet ?? 'Inconnu';
    }
}