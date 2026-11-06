<?php

namespace App\Models\Campagne;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Agent;
use App\Models\Produit;

class CampagneRegle extends Model
{
    use HasFactory;

    protected $table = 'campagne_regles';

    protected $fillable = [
        'id_campagne',
        'id_produit',
        'id_agent',
        'id_zone',
        'type_regle',
        'type_indicateur',
        'valeur_cible',
        'mode_calcul',
        'valeur_commission',
        'statut_eligible',
        'condition_bonus',
        'montant_bonus',
        'date_debut_validite',
        'date_fin_validite',
        'version',
    ];

    protected $casts = [
        'condition_bonus' => 'array',
        'date_debut_validite' => 'datetime',
        'date_fin_validite' => 'datetime',
        'valeur_cible' => 'decimal:2',
        'valeur_commission' => 'decimal:2',
        'montant_bonus' => 'decimal:2',
        'version' => 'integer',
        'type_regle' => 'string',
        'mode_calcul' => 'string',
        'statut_eligible' => 'string',
    ];

    // --- RELATIONS ---

    public function campagne(): BelongsTo
    {
        return $this->belongsTo(Campagne::class, 'id_campagne');
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class, 'id_produit');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'id_agent');
    }

    public function realisations(): HasMany
    {
        return $this->hasMany(CampagneRealisation::class, 'id_regle');
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(CommissionGeneree::class, 'id_regle_commission');
    }

    // --- SCOPES UTILES ---

    /**
     * Filtre les règles actives à une date donnée.
     */
    public function scopeActives($query, $date = null)
    {
        $date = $date ?? now();
        return $query->where('date_debut_validite', '<=', $date)
                     ->where('date_fin_validite', '>=', $date);
    }

    /**
     * Filtre les règles par type (OBJECTIF, COMMISSION, BONUS).
     */
    public function scopeDeType($query, string $type)
    {
        return $query->where('type_regle', $type);
    }

    /**
     * Filtre les règles de commission éligibles à un statut donné.
     */
    public function scopeEligiblesAuStatut($query, string $statut)
    {
        return $query->where('statut_eligible', $statut);
    }

    /**
     * Filtre les règles spécifiques à un produit.
     */
    public function scopePourProduit($query, $idProduit)
    {
        return $query->where('id_produit', $idProduit);
    }

    /**
     * Filtre les règles spécifiques à un agent.
     */
    public function scopePourAgent($query, $idAgent)
    {
        return $query->where('id_agent', $idAgent);
    }

    // --- HELPERS MÉTIER ---

    /**
     * Calcule le montant de la commission pour un montant d'opération donné.
     * Si mode_calcul = FIXE : retourne valeur_commission.
     * Si mode_calcul = POURCENTAGE : retourne (montant * valeur_commission / 100).
     */
    public function calculerCommission(float $montantOperation): float
    {
        if ($this->type_regle !== 'COMMISSION') {
            return 0.0;
        }

        if ($this->mode_calcul === 'FIXE') {
            return (float) $this->valeur_commission;
        }

        if ($this->mode_calcul === 'POURCENTAGE') {
            return round(($montantOperation * (float) $this->valeur_commission) / 100, 2);
        }

        return 0.0;
    }
}