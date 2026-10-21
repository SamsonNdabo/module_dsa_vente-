<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ObjectifMesurable extends Model
{
    protected $table = 'objectifs_mesurables';

    protected $fillable = [
        'campagne_id', 'campagne_objectif_id', 'partenaire_id',
        'agent_dsa_id', 'produit_dsa_id', 'zone_id',
        'code', 'libelle', 'type_objectif',
        'valeur_cible', 'valeur_realisee', 'unite',
        'periode_debut', 'periode_fin',
        'statut', 'metadata', 'cree_par',
    ];

    protected $casts = [
        'valeur_cible'    => 'decimal:2',
        'valeur_realisee' => 'decimal:2',
        'periode_debut'   => 'date',
        'periode_fin'     => 'date',
        'metadata'        => 'array',
    ];

    /**
     * Workflow Objectif :
     * ACTIF → ATTEINT (auto quand valeur_realisee >= cible)
     * ACTIF → DEPASSE (auto quand valeur_realisee > cible)
     * ACTIF → ANNULE  (manuel)
     * ATTEINT / DEPASSE → ANNULE (manuel)
     * Terminal : ANNULE
     */

    public const TYPES   = ['MONTANT', 'VOLUME', 'CLIENTS', 'COMPTES', 'VISITES'];
    public const STATUTS = ['PLANIFIE', 'ACTIF', 'ATTEINT', 'DEPASSE', 'ANNULE'];

    public const LIBELLES = [
        'PLANIFIE' => 'Planifié',
        'ACTIF'    => 'Actif',
        'ATTEINT'  => 'Atteint',
        'DEPASSE'  => 'Dépassé',
        'ANNULE'   => 'Annulé',
    ];
    public const TRANSITIONS = [
        'ACTIF'   => ['ANNULE'],
        'ATTEINT' => ['ANNULE'],
        'DEPASSE' => ['ANNULE'],
        'ANNULE'  => [],
    ];

    /* ---------------- Relations ---------------- */

    public function campagne(): BelongsTo
    {
        return $this->belongsTo(Campagne::class, 'campagne_id');
    }

    public function partenaire(): BelongsTo
    {
        return $this->belongsTo(Partenaire::class, 'partenaire_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(AgentDsa::class, 'agent_dsa_id');
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(ProduitDsa::class, 'produit_dsa_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'zone_id');
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'cree_par');
    }

    public function campagneObjectif(): BelongsTo
    {
        return $this->belongsTo(CampagneObjectif::class, 'campagne_objectif_id');
    }


    /* ---------------- Scopes ---------------- */

    public function scopeActifs($q)                 { return $q->where('statut', 'ACTIF'); }
    public function scopeAtteints($q)               { return $q->whereIn('statut', ['ATTEINT', 'DEPASSE']); }
    public function scopePourCampagne($q, int $id)  { return $q->where('campagne_id', $id); }
    public function scopePourAgent($q, int $id)     { return $q->where('agent_dsa_id', $id); }
    public function scopeEnCours($q)
    {
        return $q->where('periode_debut', '<=', now())
                 ->where('periode_fin', '>=', now());
    }

    /* ---------------- Helpers ---------------- */
    
    /**
     * Recalcule le statut et remonte la valeur réalisée au global.
     */
    public function recalculerStatutEtGlobal(): void
    {
        if ($this->statut === 'ANNULE') return;

        $cible   = (float) $this->valeur_cible;
        $realise = (float) $this->valeur_realisee;

        $this->statut = $realise > $cible ? 'DEPASSE'
            : ($realise >= $cible ? 'ATTEINT' : 'ACTIF');
        $this->save();

        // Cascade vers campagne_objectifs
        $this->campagneObjectif?->recalculerRealisee();
    }

    public function tauxAtteinte(): float
    {
        if ((float) $this->valeur_cible <= 0) return 0;
        return round(((float) $this->valeur_realisee / (float) $this->valeur_cible) * 100, 2);
    }

    public function estAtteint(): bool
    {
        return (float) $this->valeur_realisee >= (float) $this->valeur_cible;
    }

    public function estEnRetard(): bool
    {
        return ! $this->estAtteint()
            && $this->periode_fin
            && $this->periode_fin->isPast();
    }

    public function estTerminale(): bool
    {
        return $this->statut === 'ANNULE';
    }

    public function peutTransitionnerVers(string $cible): bool
    {
        return in_array($cible, self::TRANSITIONS[$this->statut] ?? [], true);
    }

    public function libelleStatut(): string
    {
        return self::LIBELLES[$this->statut] ?? $this->statut;
    }
    /**
     * Retourne le statut cible à appliquer à un ObjectifMesurable
     * en fonction du statut de sa campagne.
     * (Ne modifie PAS le statut si déjà ATTEINT/DEPASSE/ANNULE)
     */
    public static function statutCibleSelonCampagne(string $campagneStatut, ?string $statutActuel = null): string
    {
        // Un objectif déjà terminal ne change plus (sauf ANNULÉE qui force ANNULE)
        if ($campagneStatut === 'ANNULEE') {
            return 'ANNULE';
        }
        if (in_array($statutActuel, ['ATTEINT', 'DEPASSE', 'ANNULE'], true)) {
            return $statutActuel;
        }

        return match ($campagneStatut) {
            'PLANIFIEE' => 'PLANIFIE',
            'ACTIVE'    => 'ACTIF',
            'SUSPENDUE' => 'ACTIF',   // gelé, mais reste ACTIF
            'TERMINEE'  => 'ACTIF',   // gelé
            default     => 'PLANIFIE',
        };
    }
}