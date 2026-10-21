<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AlerteConformite extends Model
{
    protected $table      = 'alertes_conformite';
    public    $timestamps = false;

    protected $fillable = [
        'type_alerte',
        'niveau_alerte',
        'entite_type',
        'entite_id',
        'description',
        'donnees_contexte_json',
        'action_requise',
        'statut_alerte',
        'assignee_a',
        'date_echeance',
        'resolue_par',
        'date_resolution',
        'notes_resolution',
    ];

    protected $casts = [
        'donnees_contexte_json' => 'array',
        'date_echeance'         => 'datetime',
        'date_resolution'       => 'datetime',
        'date_creation'         => 'datetime',
    ];

    // ============================================================
    // RELATIONS
    // ============================================================

    public function assigneA()
    {
        return $this->belongsTo(Utilisateur::class, 'assignee_a');
    }

    public function resolueParUtilisateur()
    {
        return $this->belongsTo(Utilisateur::class, 'resolue_par');
    }

    // ============================================================
    // SCOPES
    // ============================================================

    public function scopeNouvelle($query)
    {
        return $query->where('statut_alerte', 'NOUVELLE');
    }

    public function scopeEnCours($query)
    {
        return $query->where('statut_alerte', 'EN_COURS');
    }

    public function scopeCritique($query)
    {
        return $query->where('niveau_alerte', 'CRITIQUE');
    }

    public function scopeNonResolue($query)
    {
        return $query->whereNotIn('statut_alerte', ['RESOLUE', 'IGNOREE']);
    }

    public function scopeEnRetard($query)
    {
        return $query->where('date_echeance', '<', now())
                     ->whereNotIn('statut_alerte', ['RESOLUE', 'IGNOREE']);
    }

    // ============================================================
    // LOGIQUE MÉTIER
    // ============================================================

    // Assigner à un utilisateur
    public function assigner(int $utilisateurId): bool
    {
        $this->assignee_a    = $utilisateurId;
        $this->statut_alerte = 'EN_COURS';
        return $this->save();
    }

    // Résoudre l'alerte
    public function resoudre(int $utilisateurId, string $notes): bool
    {
        $this->resolue_par      = $utilisateurId;
        $this->date_resolution  = now();
        $this->notes_resolution = $notes;
        $this->statut_alerte    = 'RESOLUE';
        return $this->save();
    }

    // Ignorer l'alerte
    public function ignorer(int $utilisateurId, string $notes): bool
    {
        $this->statut_alerte    = 'IGNOREE';
        $this->resolue_par      = $utilisateurId;
        $this->date_resolution  = now()->toDateTimeString();
        $this->notes_resolution = $notes;
        return $this->save();
    }

    // Vérifier si l'alerte est en retard
    public function estEnRetard(): bool
    {
        return $this->date_echeance !== null
            && \Carbon\Carbon::parse($this->date_echeance)->isPast()
            && !\in_array($this->statut_alerte, ['RESOLUE', 'IGNOREE']);
    }
}