<?php

namespace App\Models\Campagne;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\AgentDsa;
use App\Models\Campagne\CampagneRegle;
use App\Models\Campagne\Campagne;

class CampagneRealisation extends Model
{
    use HasFactory;

    protected $table = 'campagne_realisations';

    /**
     * Champs autorisés à l'assignation de masse.
     */
    protected $fillable = [
        'id_regle',
        'id_agent',
        'id_campagne',
        'valeur_realisee',
    ];

    /**
     * Casts automatiques.
     */
    protected $casts = [
        'valeur_realisee' => 'decimal:2',
    ];

    // ============================================================
    // RELATIONS
    // ============================================================

    public function regle(): BelongsTo
    {
        return $this->belongsTo(CampagneRegle::class, 'id_regle');
    }

    /**
     * Relation avec l'agent.
     * ✅ Correction : utilise AgentDsa au lieu de Agent.
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(AgentDsa::class, 'id_agent');
    }

    public function campagne(): BelongsTo
    {
        return $this->belongsTo(Campagne::class, 'id_campagne');
    }

    // ============================================================
    // ACCESSORS / HELPERS
    // ============================================================

    /**
     * Calcule le taux d'atteinte par rapport à la valeur cible de la règle.
     * Retourne 0 si la règle n'a pas de valeur cible définie.
     */
    public function getTauxAtteinteAttribute(): float
    {
        $cible = $this->regle?->valeur_cible;
        
        if (!$cible || $cible <= 0) {
            return 0;
        }

        return round(($this->valeur_realisee / $cible) * 100, 2);
    }

    /**
     * Vérifie si l'objectif est atteint.
     */
    public function getEstAtteintAttribute(): bool
    {
        return $this->taux_atteinte >= 100;
    }
}