<?php

namespace App\Models\Campagne;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\AgentDsa;
use App\Models\Utilisateur;
use App\Models\Zone;

class CampagneAgent extends Model
{
    use HasFactory;

    protected $table = 'campagne_agents';

    /**
     * Champs autorisés à l'assignation de masse.
     */
    protected $fillable = [
        'id_campagne',
        'id_agent',
        'id_superviseur',
        'id_zone',
        'date_debut_affectation',
        'date_fin_affectation',
        'statut_affectation',
    ];

    /**
     * Casts automatiques.
     */
    protected $casts = [
        'date_debut_affectation' => 'datetime',
        'date_fin_affectation'   => 'datetime',
        'statut_affectation'     => 'string',
    ];

    // ============================================================
    // RELATIONS
    // ============================================================

    public function campagne(): BelongsTo
    {
        return $this->belongsTo(Campagne::class, 'id_campagne');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(AgentDsa::class, 'id_agent');
    }

    public function superviseur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'id_superviseur');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'id_zone');
    }

    /**
     * Les stocks (et objectifs individuels) affectés à cet agent pour cette campagne.
     */
    public function stocks(): HasMany
    {
        return $this->hasMany(CampagneAgentStock::class, 'id_campagne_agent');
    }

    // ============================================================
    // ACCESSORS / HELPERS
    // ============================================================

    /**
     * Vérifie si l'affectation est actuellement active.
     */
    public function getEstActifAttribute(): bool
    {
        return $this->statut_affectation === 'ACTIVE' 
            && is_null($this->date_fin_affectation);
    }

    /**
     * Récupère le nom complet de l'agent (via la relation Utilisateur).
     */
    public function getNomCompletAttribute(): string
    {
        return $this->agent?->utilisateur?->nom_complet 
            ?? $this->agent?->nomComplet() 
            ?? 'Inconnu';
    }

    /**
     * Agrège tous les objectifs des stocks de cet agent.
     * Utile pour l'affichage rapide sans boucler dans le service.
     */
    public function objectifsGlobaux(): array
    {
        $totaux = [];

        foreach ($this->stocks as $stock) {
            // On prend les objectifs JSON
            foreach (($stock->objectifs ?? []) as $type => $valeur) {
                $totaux[$type] = ($totaux[$type] ?? 0) + (float) $valeur;
            }
            
            // On gère le legacy (quantite_remise) si le JSON est vide
            if (empty($stock->objectifs)) {
                $totaux['quantite'] = ($totaux['quantite'] ?? 0) + (int) $stock->quantite_remise;
            }
        }

        return $totaux;
    }
}