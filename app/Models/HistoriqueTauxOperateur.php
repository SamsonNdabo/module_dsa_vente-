<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HistoriqueTauxOperateur extends Model
{
    use HasFactory;

    protected $table = 'historique_taux_operateur';
    protected $primaryKey = 'id';

    protected $fillable = [
        'id_operateur',
        'devise_source_id',
        'devise_destination_id',
        'taux_achat',
        'taux_vente',
        'date_reception',
        'date_expiration',
        'type_operateur'
    ];

    protected $casts = [
        'taux_achat' => 'decimal:4',
        'taux_vente' => 'decimal:4',
        'date_reception' => 'datetime',
        'date_expiration' => 'datetime'
    ];

    public function operateurMobile()
    {
        return $this->belongsTo(OperateurMobileMoney::class, 'id_operateur');
    }

    public function operateurInternational()
    {
        return $this->belongsTo(OperateurInternational::class, 'id_operateur');
    }

    public function deviseSource()
    {
        return $this->belongsTo(Devise::class, 'devise_source_id');
    }

    public function deviseDestination()
    {
        return $this->belongsTo(Devise::class, 'devise_destination_id');
    }

    public function getEstActuelAttribute()
    {
        $now = now();
        return $this->date_reception <= $now && 
               ($this->date_expiration === null || $this->date_expiration >= $now);
    }

    public function getOperateurNomAttribute()
    {
        if ($this->type_operateur === 'MOBILE') {
            return $this->operateurMobile?->nom_operateur;
        }
        return $this->operateurInternational?->nom_operateur;
    }
}