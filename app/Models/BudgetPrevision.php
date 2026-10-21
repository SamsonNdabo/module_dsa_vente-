<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BudgetPrevision extends Model
{
    protected $table = 'budget_previsions';

    const STATUT_EN_ATTENTE = 'en_attente';
    const STATUT_VALIDE = 'valide';
    const STATUT_REJETE = 'rejete';

    protected $casts = [
        'rubrique_id' => 'int',
        'periode_id' => 'int',
        'mois' => 'int',
        'montant' => 'decimal:2',
        'valide_par' => 'int',
        'date_validation' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $fillable = [
        'rubrique_id',
        'periode_id',
        'mois',
        'montant',
        'statut',
        'motif_rejet',
        'valide_par',
        'date_validation',
    ];

    protected $attributes = [
        'statut' => self::STATUT_EN_ATTENTE,
    ];

    // =============================================
    // RELATIONS
    // =============================================

    public function rubrique()
    {
        return $this->belongsTo(BudgetRubrique::class, 'rubrique_id');
    }

    public function periode()
    {
        return $this->belongsTo(PeriodesComptable::class, 'periode_id');
    }

    public function validateur()
    {
        return $this->belongsTo(Utilisateur::class, 'valide_par');
    }

    // =============================================
    // SCOPES
    // =============================================

    public function scopeParRubrique($query, $rubriqueId)
    {
        return $query->where('rubrique_id', $rubriqueId);
    }

    public function scopeParPeriode($query, $periodeId)
    {
        return $query->where('periode_id', $periodeId);
    }

    public function scopeParMois($query, $mois)
    {
        return $query->where('mois', $mois);
    }

    public function scopeEnAttente($query)
    {
        return $query->where('statut', self::STATUT_EN_ATTENTE);
    }

    public function scopeValides($query)
    {
        return $query->where('statut', self::STATUT_VALIDE);
    }

    public function scopeRejetes($query)
    {
        return $query->where('statut', self::STATUT_REJETE);
    }

    // =============================================
    // ACCESSEURS
    // =============================================

    public function getMoisLibelleAttribute(): string
    {
        $mois = [
            1 => 'Janvier', 2 => 'Février', 3 => 'Mars',
            4 => 'Avril', 5 => 'Mai', 6 => 'Juin',
            7 => 'Juillet', 8 => 'Août', 9 => 'Septembre',
            10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
        ];
        return $mois[$this->mois] ?? 'Mois ' . $this->mois;
    }

    public function getMontantFormateAttribute(): string
    {
        return number_format($this->montant, 2, ',', ' ') . ' $';
    }

    public function getStatutLibelleAttribute(): string
    {
        return match ($this->statut) {
            self::STATUT_EN_ATTENTE => 'En attente',
            self::STATUT_VALIDE => 'Validé',
            self::STATUT_REJETE => 'Rejeté',
            default => 'Inconnu',
        };
    }

    public function getStatutIconeAttribute(): string
    {
        return match ($this->statut) {
            self::STATUT_EN_ATTENTE => '🟡',
            self::STATUT_VALIDE => '🟢',
            self::STATUT_REJETE => '🔴',
            default => '⚪',
        };
    }

    public function getEstValideAttribute(): bool
    {
        return $this->statut === self::STATUT_VALIDE;
    }

    public function getEstRejeteAttribute(): bool
    {
        return $this->statut === self::STATUT_REJETE;
    }

    public function getEstEnAttenteAttribute(): bool
    {
        return $this->statut === self::STATUT_EN_ATTENTE;
    }

    public function getDateValidationFormateeAttribute(): string
    {
        return $this->date_validation ? $this->date_validation->format('d/m/Y H:i') : 'N/A';
    }

    public function getNomValidateurAttribute(): string
    {
        return $this->validateur ? ($this->validateur->nom_complet ?? $this->validateur->nom_utilisateur ?? 'N/A') : 'N/A';
    }

    // =============================================
    // MÉTHODES
    // =============================================

    public function valider(int $validateurId): bool
    {
        return $this->update([
            'statut' => self::STATUT_VALIDE,
            'valide_par' => $validateurId,
            'date_validation' => now(),
            'motif_rejet' => null,
        ]);
    }

    public function rejeter(int $validateurId, string $motif): bool
    {
        return $this->update([
            'statut' => self::STATUT_REJETE,
            'valide_par' => $validateurId,
            'date_validation' => now(),
            'motif_rejet' => $motif,
        ]);
    }

    public function remettreEnAttente(): bool
    {
        return $this->update([
            'statut' => self::STATUT_EN_ATTENTE,
            'valide_par' => null,
            'date_validation' => null,
            'motif_rejet' => null,
        ]);
    }
}