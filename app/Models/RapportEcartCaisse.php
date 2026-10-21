<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RapportEcartCaisse extends Model
{
    protected $table = 'rapport_ecarts_caisse';

    protected $fillable = [
        'rapportof_id',
        'caisse_id',
        'devise_id',
        'montant_attendu',
        'montant_constate',
        'ecart',
        'motif',
        'corrige',
    ];

    protected $casts = [
        'corrige' => 'boolean',
    ];

    public function rapportOf()
    {
        return $this->belongsTo(RapportOf::class, 'rapportof_id');
    }

    public function caisse()
    {
        return $this->belongsTo(Caisses::class);
    }

    public function devise()
    {
        return $this->belongsTo(Devise::class);
    }
}