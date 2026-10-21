<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Journal_Caisses extends Model
{
    protected $table = 'journal_caisses';

    protected $fillable = [
        'caisse_id',
        'user_id',
        'type_operation',
        'document_upload',
        'date_debut',
        'date_fin',
        'statut',
        'notes',
        'valide_fermeture',
    ];

    protected $casts = [
        'document_upload' => 'array',
        'date_debut'      => 'datetime',
        'date_fin'        => 'datetime',
    ];

    // ============================================================
    // RELATIONS
    // ============================================================

    public function caisse()
    {
        return $this->belongsTo(Caisses::class, 'caisse_id');
    }

    public function utilisateur()
    {
        return $this->belongsTo(Utilisateur::class, 'user_id');
    }

    public function validateurFermeture()
    {
        return $this->belongsTo(Utilisateur::class, 'valide_fermeture');
    }

    public function soldes()
    {
        return $this->hasMany(JournalCaisseSolde::class, 'journal_id');
    }
    
    public function mouvements()
    {
        return $this->hasMany(JournalMouvementCaisse::class, 'caisse_id');
    }
    // ============================================================
    // SCOPES
    // ============================================================

    public function scopeEnCours($query)
    {
        return $query->where('statut', 'EN_COURS');
    }

    public function scopeTerminee($query)
    {
        return $query->where('statut', 'TERMINEE');
    }

    public function scopeAnomalie($query)
    {
        return $query->where('statut', 'ANOMALIE');
    }

    // ============================================================
    // LOGIQUE MÉTIER
    // ============================================================

    // Calculer l'écart automatiquement
    public function terminer(): bool
    {
        $this->date_fin = now()->toDateTimeString();
        $this->statut   = 'TERMINEE';
        return $this->save();
    }
}