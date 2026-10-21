<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JournalCaisse extends Model
{
    use HasFactory;

    protected $table = 'journal_caisses';
    protected $primaryKey = 'id';

    protected $fillable = [
        'caisse_id',
        'user_id',
        'type_operation',
        'solde_ouverture',
        'solde_fermeture',
        'solde_theorique',
        'solde_reel',
        'ecart',
        'justification_ecart',
        'montant_initial',
        'montant_final',
        'document_upload',
        'date_debut',
        'date_fin',
        'statut',
        'notes'
    ];

    protected $casts = [
        'solde_ouverture' => 'decimal:2',
        'solde_fermeture' => 'decimal:2',
        'solde_theorique' => 'decimal:2',
        'solde_reel' => 'decimal:2',
        'ecart' => 'decimal:2',
        'montant_initial' => 'decimal:2',
        'montant_final' => 'decimal:2',
        'document_upload' => 'array',
        'date_debut' => 'datetime',
        'date_fin' => 'datetime'
    ];

    public function caisse()
    {
        return $this->belongsTo(Caisse::class, 'caisse_id');
    }

    public function utilisateur()
    {
        return $this->belongsTo(Utilisateur::class, 'user_id');
    }

    public function activites()
    {
        return $this->hasMany(Activite::class, 'id_journal_caisse');
    }

    public function getEstClotureAttribute()
    {
        return $this->statut !== 'EN_COURS';
    }

    public function getDureeSessionAttribute()
    {
        if (!$this->date_fin) {
            return now()->diffInMinutes($this->date_debut);
        }
        return $this->date_fin->diffInMinutes($this->date_debut);
    }

    public function getEcartAbsoluAttribute()
    {
        return abs($this->ecart ?? 0);
    }

    public function getEcartPourcentageAttribute()
    {
        if ($this->solde_theorique == 0) return 0;
        return round(($this->ecart_absolu / $this->solde_theorique) * 100, 2);
    }

    public function getEstConformeAttribute()
    {
        return $this->ecart_absolu <= 100; // Seuil de tolérance
    }

    public function ajouterActivite($type, $nom, $details = null)
    {
        return $this->activites()->create([
            'type' => $type,
            'nom' => $nom,
            'details' => $details
        ]);
    }
}