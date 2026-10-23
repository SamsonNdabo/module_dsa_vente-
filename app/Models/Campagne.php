<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Campagne extends Model
{
    protected $table = 'campagnes';

    protected $fillable = [
        'partenaire_id', 'code', 'nom', 'description',
        'date_debut', 'date_fin','budget', 'devise_id',
        // 'objectif_montant', 'objectif_volume',
        'statut', 'metadata', 'cree_par',
    ];

    protected $casts = [
        'date_debut'       => 'date',
        'date_fin'         => 'date',
        // 'objectif_montant' => 'decimal:2',
        // 'objectif_volume'  => 'integer',
        'metadata'         => 'array',
        'budget' => 'decimal:2',
    ];

    /**
     * Workflow Campagne : PLANIFIEE → ACTIVE → SUSPENDUE → ACTIVE
     *                                            → TERMINEE
     *                     PLANIFIEE → ANNULEE
     *                     ACTIVE    → ANNULEE
     *                     SUSPENDUE → ANNULEE
     * Terminal : TERMINEE, ANNULEE
     */
    public const STATUTS = ['PLANIFIEE', 'ACTIVE', 'SUSPENDUE', 'TERMINEE', 'ANNULEE'];

    /** Transitions autorisées entre statuts. */
    public const TRANSITIONS = [
        'PLANIFIEE' => ['ACTIVE', 'ANNULEE'],
        'ACTIVE'    => ['SUSPENDUE', 'TERMINEE', 'ANNULEE'],
        'SUSPENDUE' => ['ACTIVE', 'ANNULEE'],
        'TERMINEE'  => [],
        'ANNULEE'   => [],
    ];

    /** Libellés lisibles. */
    public const LIBELLES = [
        'PLANIFIEE' => 'Planifiée',
        'ACTIVE'    => 'Active',
        'SUSPENDUE' => 'Suspendue',
        'TERMINEE'  => 'Terminée',
        'ANNULEE'   => 'Annulée',
    ];

    /* ---------------- Relations ---------------- */

    public function partenaire(): BelongsTo
    {
        return $this->belongsTo(Partenaire::class, 'partenaire_id');
    }

     public function devise(): BelongsTo        // ← AJOUT — LA RELATION MANQUANTE
    {
        return $this->belongsTo(Devise::class, 'devise_id');
    }

    public function affectations(): HasMany
    {
        return $this->hasMany(AgentAffectation::class, 'campagne_id');
    }

    public function objectifs(): HasMany
    {
        return $this->hasMany(ObjectifMesurable::class, 'campagne_id');
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'cree_par');
    }
    public function objectifsTypes(): HasMany
    {
        return $this->hasMany(CampagneObjectif::class, 'campagne_id')->orderBy('ordre');
    }

    /* ---------------- Scopes ---------------- */

    public function scopeActives($q)
    {
        return $q->where('statut', 'ACTIVE')
            ->where('date_debut', '<=', now())
            ->where(fn ($w) => $w->whereNull('date_fin')->orWhere('date_fin', '>=', now()));
    }

    public function scopePlanifiees($q) { return $q->where('statut', 'PLANIFIEE'); }
    public function scopeTerminees($q)  { return $q->where('statut', 'TERMINEE'); }
    public function scopeAnnulees($q)   { return $q->where('statut', 'ANNULEE'); }
    public function scopeEnCours($q)    { return $q->whereIn('statut', ['ACTIVE', 'SUSPENDUE']); }

    /* ---------------- Helpers ---------------- */

    public function estActive(): bool
    {
        return $this->statut === 'ACTIVE'
            && $this->date_debut->lte(now())
            && (! $this->date_fin || $this->date_fin->gte(now()));
    }

    public function estTerminale(): bool
    {
        return in_array($this->statut, ['TERMINEE', 'ANNULEE'], true);
    }

    /**
     * Vérifie qu'une transition est autorisée.
     */
    public function peutTransitionnerVers(string $cible): bool
    {
        return in_array($cible, self::TRANSITIONS[$this->statut] ?? [], true);
    }

    /**
     * Change le statut avec contrôle de workflow.
     * @throws ValidationException
     */
    public function changerStatut(string $cible): void
    {
        if (! in_array($cible, self::STATUTS, true)) {
            throw ValidationException::withMessages(['statut' => 'Statut invalide.']);
        }

        if (! $this->peutTransitionnerVers($cible)) {
            $libelleActuel = self::LIBELLES[$this->statut] ?? $this->statut;
            $libelleCible  = self::LIBELLES[$cible] ?? $cible;
            throw ValidationException::withMessages([
                'statut' => "Transition non autorisée : {$libelleActuel} → {$libelleCible}.",
            ]);
        }

        $this->update(['statut' => $cible]);
    }

    /**
     * Clients DSA créés pendant cette campagne par les agents affectés.
     */
    public function clientsDsa()
    {
        return \App\Models\Client::query()
            ->whereIn('agent_dsa_id', $this->affectations()
                ->whereNull('date_fin')
                ->pluck('agent_dsa_id'))
            ->whereBetween('created_at', [
                $this->date_debut->startOfDay(),
                ($this->date_fin ?? now())->endOfDay(),
            ]);
    }

    /**
     * Retourne les transitions disponibles pour ce statut.
     */
    public function transitionsDisponibles(): array
    {
        return self::TRANSITIONS[$this->statut] ?? [];
    }

    public function libelleStatut(): string
    {
        return self::LIBELLES[$this->statut] ?? $this->statut;
    }
}