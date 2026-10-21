<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CaisseExchangeRate extends Model
{
    protected $fillable = [
        'caisse_id', 'devise_source', 'devise_destination',
        'taux_reference', 'spread_achat', 'spread_vente',
        'date_effet', 'actif', 'modifie_par', 'ancien_taux_reference',
        'motif', 'statut', 'valide_par', 'date_validation',
        'notes_validation'
    ];

    protected $casts = [
        'taux_reference' => 'decimal:6',
        'spread_achat' => 'decimal:6',
        'spread_vente' => 'decimal:6',
        'date_effet' => 'datetime',
        'actif' => 'boolean',
    ];

    // RELATIONS
    public function modificateur()
    {
        return $this->belongsTo(Utilisateur::class, 'modifie_par');
    }

    public function validateur()
    {
        return $this->belongsTo(Utilisateur::class, 'valide_par');
    }

    public function caisse()
    {
        return $this->belongsTo(Caisses::class);
    }

        public function deviseSource()
    {
        return $this->belongsTo(Devise::class, 'devise_source');
    }

    public function deviseDestination()
    {
        return $this->belongsTo(Devise::class, 'devise_cible');
    }

    // LOGIQUE METIER
    public function getTauxAchat(): float
    {
        return (float) $this->taux_reference - (float) $this->spread_achat;
    }

    public function getTauxVente(): float
    {
        return (float) $this->taux_reference + (float) $this->spread_vente;
    }
}