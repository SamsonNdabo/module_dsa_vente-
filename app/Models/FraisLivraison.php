<?php
// app/Models/FraisLivraison.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FraisLivraison extends Model
{
    use HasFactory;

    protected $table = 'frais_livraisons';

    // =============================================
    // FILLABLE
    // =============================================
    
    protected $fillable = [
        'succursale_id',
        'mode_livraison',
        'valeur',
        'devise',
        'actif'
    ];

    // =============================================
    // CASTS
    // =============================================
    
    protected $casts = [
        'valeur' => 'decimal:2',
        'actif' => 'boolean'
    ];

    // =============================================
    // CONSTANTES
    // =============================================
    
    const MODES_LIVRAISON = [
        'DOMICILE' => 'Livraison à domicile',
        'POINT_RELAIS' => 'Point relais',
        'RETRAIT_MAGASIN' => 'Retrait en magasin'
    ];

    // =============================================
    // RELATIONS
    // =============================================
    
    public function succursale()
    {
        return $this->belongsTo(Succursale::class);
    }

    // =============================================
    // SCOPES
    // =============================================
    
    public function scopeActifs($query)
    {
        return $query->where('actif', true);
    }

    public function scopeParSuccursale($query, $succursaleId)
    {
        return $query->where('succursale_id', $succursaleId);
    }

    public function scopeParMode($query, $mode)
    {
        return $query->where('mode_livraison', $mode);
    }

    // =============================================
    // MÉTHODES
    // =============================================
    
    public static function getFrais($succursaleId, $modeLivraison): ?self
    {
        return self::actifs()
            ->parSuccursale($succursaleId)
            ->parMode($modeLivraison)
            ->first();
    }

    public function getModeLabelAttribute(): string
    {
        return self::MODES_LIVRAISON[$this->mode_livraison] ?? $this->mode_livraison;
    }
}