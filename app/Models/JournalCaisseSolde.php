<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JournalCaisseSolde extends Model
{
    protected $table = 'journal_caisses_soldes';

    protected $fillable = [
        'journal_id',
        'devise_id',
        'solde_ouverture',
        'solde_fermeture',
        'ecart',
    ];

    protected $casts = [
        'solde_ouverture' => 'decimal:4',
        'solde_fermeture' => 'decimal:4',
        'ecart'           => 'decimal:4',
    ];

    public function journal()
    {
        return $this->belongsTo(Journal_Caisses::class, 'journal_id');
    }

    public function devise()
    {
        return $this->belongsTo(Devise::class, 'devise_id');
    }
}