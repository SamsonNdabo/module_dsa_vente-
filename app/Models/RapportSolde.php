<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RapportSolde extends Model
{
    protected $table = 'rapport_soldes';

    protected $fillable = [
        'rapportof_id',
        'devise_id',
        'devise_code',
        'solde',
        'type_caisse',
        'solde_saisi',
        'ecart',
    ];

    public function rapportOf()
    {
        return $this->belongsTo(RapportOf::class, 'rapportof_id');
    }

    public function devise()
    {
        return $this->belongsTo(Devise::class);
    }
}