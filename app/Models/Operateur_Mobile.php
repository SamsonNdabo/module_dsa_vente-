<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Operateur_Mobile extends Model
{
    protected $table      = 'operateur_mobile';
    public    $timestamps = false;

    protected $fillable = [
        'code_operateur',
        'nom_operateur',
        'service_mobile_money_id',
        'commission_defaut',
    ];

    protected $casts = [
        'commission_defaut' => 'decimal:4',
        'created_at'        => 'datetime',
    ];

    // ============================================================
    // RELATIONS
    // ============================================================

    public function transactions()
    {
        return $this->hasMany(Transaction_Mobile::class, 'id_operateur_mobile');
    }

    public function serviceMobileMoney()
    {
        return $this->belongsTo(ServiceMobileMoney::class, 'service_mobile_money_id');
    }

    // ============================================================
    // LOGIQUE MÉTIER
    // ============================================================

    // Calculer la commission sur un montant
    public function calculerCommission(float $montant): float
    {
        return round($montant * ($this->commission_defaut / 100), 4);
    }

    // Calculer le montant net après commission
    public function calculerMontantNet(float $montant): float
    {
        return $montant - $this->calculerCommission($montant);
    }

    public static function getNameById(string $id) {
        return self::where('id', $id)->firstOrFail()->nom_operateur;
    }
}