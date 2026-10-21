<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class FluxTresorerieArchive
 * 
 * @property int $id
 * @property int $periode_id
 * @property int|null $entite_id
 * @property int|null $succursale_id
 * @property float $tresorerie_initiale
 * @property float $flux_exploitation
 * @property float $flux_investissement
 * @property float $flux_financement
 * @property float $variation_nette
 * @property float $tresorerie_finale
 * @property array|null $donnees_completes
 * @property Carbon $cloture_date
 * @property int|null $cloture_par
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * 
 * @property-read PeriodesComptable $periode
 * @property-read Entite|null $entite
 * @property-read Succursale|null $succursale
 * @property-read User|null $cloturePar
 *
 * @package App\Models
 */
class FluxTresorerieArchive extends Model
{
    protected $table = 'flux_tresorerie_archives';

    protected $casts = [
        'periode_id' => 'int',
        'entite_id' => 'int',
        'succursale_id' => 'int',
        'tresorerie_initiale' => 'decimal:2',
        'flux_exploitation' => 'decimal:2',
        'flux_investissement' => 'decimal:2',
        'flux_financement' => 'decimal:2',
        'variation_nette' => 'decimal:2',
        'tresorerie_finale' => 'decimal:2',
        'donnees_completes' => 'array',
        'cloture_date' => 'datetime',
        'cloture_par' => 'int',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $fillable = [
        'periode_id',
        'entite_id',
        'succursale_id',
        'tresorerie_initiale',
        'flux_exploitation',
        'flux_investissement',
        'flux_financement',
        'variation_nette',
        'tresorerie_finale',
        'donnees_completes',
        'cloture_date',
        'cloture_par',
    ];

    // =============================================
    // RELATIONS
    // =============================================

    public function periode()
    {
        return $this->belongsTo(PeriodesComptable::class, 'periode_id');
    }

    public function entite()
    {
        return $this->belongsTo(Entite::class, 'entite_id');
    }

    public function succursale()
    {
        return $this->belongsTo(Succursale::class, 'succursale_id');
    }

    public function cloturePar()
    {
        return $this->belongsTo(User::class, 'cloture_par');
    }

    // =============================================
    // SCOPES
    // =============================================

    public function scopeParPeriode($query, $periodeId)
    {
        return $query->where('periode_id', $periodeId);
    }

    public function scopeParEntite($query, $entiteId)
    {
        return $query->where('entite_id', $entiteId);
    }

    public function scopeParSuccursale($query, $succursaleId)
    {
        return $query->where('succursale_id', $succursaleId);
    }

    public function scopePlusRecentes($query)
    {
        return $query->orderBy('cloture_date', 'desc');
    }

    // =============================================
    // ACCESSEURS
    // =============================================

    /**
     * Vérifie si l'archive est équilibrée
     */
    public function getEstEquilibreAttribute(): bool
    {
        $totalCalcule = $this->tresorerie_initiale + $this->variation_nette;
        return abs($totalCalcule - $this->tresorerie_finale) < 0.01;
    }

    /**
     * Libellé formaté de la variation
     */
    public function getVariationLibelleAttribute(): string
    {
        if ($this->variation_nette > 0) {
            return '+ ' . number_format($this->variation_nette, 2, ',', ' ') . ' $';
        } elseif ($this->variation_nette < 0) {
            return '- ' . number_format(abs($this->variation_nette), 2, ',', ' ') . ' $';
        }
        return '0,00 $';
    }

    /**
     * Résumé de l'archive
     */
    public function getResumeAttribute(): string
    {
        return sprintf(
            'Période %s | Entité %s | Variation: %s | Final: %s $',
            $this->periode->code ?? 'N/A',
            $this->entite->code ?? 'Toutes',
            $this->variation_libelle,
            number_format($this->tresorerie_finale, 2, ',', ' ')
        );
    }

    /**
     * Détail des flux formaté
     */
    public function getDetailFluxAttribute(): array
    {
        return [
            'exploitation' => [
                'montant' => $this->flux_exploitation,
                'libelle' => $this->flux_exploitation >= 0 ? 'Excédent' : 'Déficit',
            ],
            'investissement' => [
                'montant' => $this->flux_investissement,
                'libelle' => $this->flux_investissement >= 0 ? 'Désinvestissement' : 'Investissement',
            ],
            'financement' => [
                'montant' => $this->flux_financement,
                'libelle' => $this->flux_financement >= 0 ? 'Augmentation' : 'Remboursement',
            ],
        ];
    }
}