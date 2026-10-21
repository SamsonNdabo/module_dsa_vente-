<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class ImmobilisationsComptable
 *
 * @property int $id
 * @property string $code
 * @property string|null $reference
 * @property string $libelle
 * @property string $compte_acquisition
 * @property string $compte_amortissement
 * @property string $compte_dotation
 * @property float $valeur_acquisition
 * @property Carbon $date_acquisition
 * @property Carbon|null $date_debut_utilisation
 * @property Carbon|null $date_debut_amortissement
 * @property Carbon|null $date_fin_amortissement
 * @property float $duree_vie
 * @property string $duree_unite
 * @property int $duree_mois
 * @property float $valeur_residuelle
 * @property float $taux_amortissement
 * @property string $mode_amortissement
 * @property float $montant_amorti_cumule
 * @property float $valeur_nette_comptable
 * @property string|null $dernier_mois_amorti
 * @property int $mois_restants
 * @property bool $est_amorti
 * @property bool $est_actif
 * @property int|null $entite_id
 * @property int|null $succursale_id
 * @property int|null $operation_journal_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property-read Entite|null $entite
 * @property-read Succursale|null $succursale
 * @property-read OperationsJournal|null $operationJournal
 *
 * @package App\Models
 */
class ImmobilisationsComptable extends Model
{
    protected $table = 'immobilisation_comptable';

    protected $casts = [
        'valeur_acquisition'        => 'decimal:2',
        'date_acquisition'          => 'date',
        'date_debut_utilisation'    => 'date',
        'date_debut_amortissement'  => 'date',
        'date_fin_amortissement'    => 'date',
        'duree_vie'                 => 'float',
        'duree_mois'                => 'int',
        'valeur_residuelle'         => 'decimal:2',
        'taux_amortissement'        => 'decimal:4',
        'montant_amorti_cumule'     => 'decimal:2',
        'valeur_nette_comptable'    => 'decimal:2',
        'mois_restants'             => 'int',
        'est_amorti'                => 'bool',
        'est_actif'                 => 'bool',
        'entite_id'                 => 'int',
        'succursale_id'             => 'int',
        'operation_journal_id'      => 'int',
        'created_at'                => 'datetime',
        'updated_at'                => 'datetime',
    ];

    protected $fillable = [
        'code',
        'reference',
        'libelle',
        'compte_acquisition',
        'compte_amortissement',
        'compte_dotation',
        'valeur_acquisition',
        'date_acquisition',
        'date_debut_utilisation',
        'date_debut_amortissement',
        'date_fin_amortissement',
        'duree_vie',
        'duree_unite',
        'duree_mois',
        'valeur_residuelle',
        'taux_amortissement',
        'mode_amortissement',
        'montant_amorti_cumule',
        'valeur_nette_comptable',
        'dernier_mois_amorti',
        'mois_restants',
        'est_amorti',
        'est_actif',
        'entite_id',
        'succursale_id',
        'operation_journal_id',
    ];

    // =============================================
    // RELATIONS
    // =============================================

    public function entite()
    {
        return $this->belongsTo(Entite::class, 'entite_id');
    }

    public function succursale()
    {
        return $this->belongsTo(Succursale::class, 'succursale_id');
    }

    public function operationJournal()
    {
        return $this->belongsTo(OperationsJournal::class, 'operation_journal_id');
    }

    // =============================================
    // SCOPES
    // =============================================

    public function scopeActives($query)
    {
        return $query->where('est_actif', true);
    }

    public function scopeEnCours($query)
    {
        return $query->where('est_amorti', false);
    }

    public function scopeAmorties($query)
    {
        return $query->where('est_amorti', true);
    }

    public function scopeParEntite($query, $entiteId)
    {
        return $query->where('entite_id', $entiteId);
    }

    public function scopeParSuccursale($query, $succursaleId)
    {
        return $query->where('succursale_id', $succursaleId);
    }

    public function scopeSansOperationJournal($query)
    {
        return $query->whereNull('operation_journal_id');
    }

    // =============================================
    // ACCESSEURS
    // =============================================

    /**
     * Montant mensuel d'amortissement linéaire.
     * Basé sur duree_mois (et non duree_vie qui peut être en années).
     * Exemple : (200 - 1) / 36 = 5.5278
     */
    public function getMontantMensuelAttribute(): float
    {
        if (!$this->duree_mois || $this->duree_mois <= 0) {
            return 0.0;
        }

        $amortissable = (float) $this->valeur_acquisition - (float) $this->valeur_residuelle;

        return round(max(0, $amortissable) / (int) $this->duree_mois, 4);
    }

    /**
     * Progression de l'amortissement en pourcentage.
     */
    public function getProgressionAttribute(): float
    {
        if ($this->valeur_acquisition <= 0) {
            return 0.0;
        }

        return round(($this->montant_amorti_cumule / $this->valeur_acquisition) * 100, 2);
    }

    /**
     * Nombre de mois restants réels.
     */
    public function getMoisRestantsReelAttribute(): int
    {
        if ($this->est_amorti) {
            return 0;
        }

        if (!$this->dernier_mois_amorti) {
            return (int) $this->duree_mois;
        }

        try {
            $dernier = Carbon::createFromFormat('Y-m', $this->dernier_mois_amorti);
            $moisEcoules = (now()->year - $dernier->year) * 12 + (now()->month - $dernier->month);

            return max(0, (int) $this->mois_restants - $moisEcoules);
        } catch (\Throwable $e) {
            return (int) $this->mois_restants;
        }
    }

    /**
     * Libellé complet : code + libellé.
     */
    public function getLibelleCompletAttribute(): string
    {
        return $this->code . ' - ' . $this->libelle;
    }

    /**
     * Statut lisible.
     */
    public function getStatutLibelleAttribute(): string
    {
        return $this->est_amorti ? 'Amorti' : 'En cours';
    }

    /**
     * Durée formatée pour affichage.
     * Exemple : "3 ans (36 mois)" ou "5 mois"
     */
    public function getDureeLibelleAttribute(): string
    {
        if (!$this->duree_mois) {
            return '-';
        }

        if ($this->duree_unite === 'annees' && $this->duree_mois % 12 === 0) {
            $ans = $this->duree_mois / 12;
            return $ans . ' an' . ($ans > 1 ? 's' : '') . ' (' . $this->duree_mois . ' mois)';
        }

        return $this->duree_mois . ' mois';
    }
}