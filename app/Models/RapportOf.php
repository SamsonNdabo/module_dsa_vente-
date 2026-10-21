<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RapportOf extends Model
{
    protected $table = 'rapport_of';

    protected $fillable = [
        'user_id',
        'succursale_id',
        'temps',
        'type_rapport',
        'motif',
        'ecart_global',
        'valide',
        'valide_par',
        'date_validation',
        'rejete',
        'rejete_par',
        'date_rejet',
    ];

    protected $casts = [
        'valide' => 'boolean',
        'rejete' => 'boolean',
        'ecart_global' => 'array',
        'groupes' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(Utilisateur::class, 'user_id');
    }

    public function validateur()
    {
        return $this->belongsTo(Utilisateur::class, 'valide_par');
    }

    public function rejeteur()
    {
        return $this->belongsTo(Utilisateur::class, 'rejete_par');
    }

    public function succursale()
    {
        return $this->belongsTo(Succursale::class);
    }

    public function soldes()
    {
        return $this->hasMany(RapportSolde::class, 'rapportof_id');
    }

    public function ecartsCaisse()
    {
        return $this->hasMany(RapportEcartCaisse::class, 'rapportof_id');
    }
}