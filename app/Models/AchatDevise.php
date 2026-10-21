<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AchatDevise extends Model
{
    protected $table = 'achat_devises';

    protected $fillable = [
        'devise_id', 'montant_devise', 'montant_cdf', 'taux_utilise',
        'taux_bancaire_id', 'date_achat', 'reference', 'motif',
        'caisse_cdf_id', 'caisse_devise_id', 'created_by'
    ];

    protected $casts = [
        'montant_devise' => 'decimal:5',
        'montant_cdf' => 'decimal:5',
        'taux_utilise' => 'decimal:5',
        'date_achat' => 'date',
    ];

    public function devise()
    {
        return $this->belongsTo(Devise::class);
    }

    public function tauxBancaire()
    {
        return $this->belongsTo(TauxBancaire::class);
    }

    public function caisseCdf()
    {
        return $this->belongsTo(Caisses::class, 'caisse_cdf_id');
    }

    public function caisseDevise()
    {
        return $this->belongsTo(Caisses::class, 'caisse_devise_id');
    }

    public function createur()
    {
        return $this->belongsTo(Utilisateur::class, 'created_by');
    }
}