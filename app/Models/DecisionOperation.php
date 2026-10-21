<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DecisionOperation extends Model
{
    protected $table = 'decisions_operation';
    
    public $timestamps = false;
    
    protected $fillable = [
        'approvisionnement_id',
        'user_id',
        'decision',
        'source_decidee_id',
        'type_parcours',
        'mode_transport', 
        'devise_id',
        'intermediaire_succursale_id',
        'poids_total',
        'volume_total',
        'valeur_totale',
        'cout_transport',
        'cout_assurance',
        'cout_emballage',
        'cout_total',
        'motif_rejet',
        'commentaire'
    ];

    protected $casts = [
        'mode_transport' => 'string',
        'poids_total' => 'decimal:2',
        'volume_total' => 'decimal:2',
        'valeur_totale' => 'decimal:2',
        'cout_transport' => 'decimal:2',
        'cout_assurance' => 'decimal:2',
        'cout_emballage' => 'decimal:2',
        'cout_total' => 'decimal:2'
    ];

    // ============================================================
    // RELATIONS
    // ============================================================
    
    public function devise()
    {
        return $this->belongsTo(Devise::class, 'devise_id');
    }
    public function approvisionnement()
    {
        return $this->belongsTo(Approvisionnement::class);
    }

    public function user()
    {
        return $this->belongsTo(Utilisateur::class, 'user_id');
    }

    public function sourceDecidee()
    {
        return $this->belongsTo(Succursale::class, 'source_decidee_id');
    }

    public function intermediaireSuccursale()
    {
        return $this->belongsTo(Succursale::class, 'intermediaire_succursale_id');
    }
}