<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionConfigSuccursale extends Model
{
    protected $table = 'commission_config_succursale';

    protected $fillable = [
        'succursale_id',
        'commission_config_id',
        'valeur',
        'statut',
        'modifie_par',
        'motif',
    ];

    protected $casts = [
        'valeur' => 'decimal:4',
    ];

    // ============================================================
    // RELATIONS
    // ============================================================

    public function succursale()
    {
        return $this->belongsTo(Succursale::class, 'succursale_id');
    }

    public function commissionConfig()
    {
        return $this->belongsTo(CommissionConfig::class, 'commission_config_id');
    }

    public function modificateur()
    {
        return $this->belongsTo(Utilisateur::class, 'modifie_par');
    }

    // ============================================================
    // SCOPES
    // ============================================================

    public function scopeActif($query)
    {
        return $query->where('statut', 'ACTIF');
    }

    public function scopeParSuccursale($query, int $succursaleId)
    {
        return $query->where('succursale_id', $succursaleId);
    }

    // ============================================================
    // LOGIQUE MÉTIER
    // ============================================================

    public static function getValeurPourSuccursale(
        int $succursaleId,
        int $commissionConfigId
    ): ?self {
        return self::where('succursale_id', $succursaleId)
            ->where('commission_config_id', $commissionConfigId)
            ->where('statut', 'ACTIF')
            ->first();
    }
}