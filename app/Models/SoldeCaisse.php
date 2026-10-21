<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class SoldeCaisse extends Model
{
    protected $table = 'solde_caisse';

    protected $fillable = [
        'caisse_id',
        'devise_id',
        'solde',
        'solde_minimal',
        'solde_maximal',
    ];

    protected $casts = [
        'solde' => 'decimal:4',
        'solde_minimal' => 'decimal:4',
        'solde_maximal' => 'decimal:4',
    ];

    // On garde uniquement devise → pas de boucle
    public function devise()
    {
        return $this->belongsTo(Devise::class, 'devise_id');
    }
}