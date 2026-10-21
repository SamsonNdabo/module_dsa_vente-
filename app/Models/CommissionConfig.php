<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\CommissionService;

class CommissionConfig extends Model
{
    protected $table = 'commission_config';

    protected $fillable = [
        'service',
        'type_calcul',
        'valeur',
        'type_transaction',
        'etat',
    ];

    protected $casts = [
        'valeur' => 'decimal:4',
    ];

    // ============================================================
    // RELATIONS
    // ============================================================

    public function commissionServices()
    {
        return $this->hasMany(CommissionService::class, 'commission_config_id');
    }

    public function configsSuccursale()
    {
        return $this->hasMany(CommissionConfigSuccursale::class, 'commission_config_id');
    }

    // ============================================================
    // SCOPES
    // ============================================================

    public function scopeActif($query)
    {
        return $query->where('etat', 'ACTIF');
    }

    public function scopeParTypeTransaction($query, string $type)
    {
        return $query->where('type_transaction', $type);
    }

    // ============================================================
    // LOGIQUE MÉTIER
    // ============================================================

    // Calculer le montant de commission pour un montant donné
    public function calculer(float $montant, ?float $valeurSuccursale = null): array
    {
        // Utiliser la valeur succursale si fournie sinon valeur globale
        $valeur = $valeurSuccursale ?? (float) $this->valeur;

        if ($this->type_calcul == 'POURCENTAGE') {
            $montantCalcule = round($montant * ($valeur / 100), 4);
            $pourcentage    = $valeur;
        } else {
            $montantCalcule = $valeur;
            $pourcentage    = $montant > 0
                ? round(($valeur / $montant) * 100, 4)
                : 0;
        }

        return [
            'service'     => $this->service,
            'type_calcul' => $this->type_calcul,
            'valeur'      => $valeur,
            'pourcentage' => $pourcentage,
            'montant'     => $montantCalcule,
        ];
    }

    // Récupérer toutes les configs actives pour un type de transaction
    public static function getConfigsActives(
        string $typeTransaction,
        ?int   $succursaleId = null
    ): \Illuminate\Database\Eloquent\Collection {
        return self::actif()
            ->parTypeTransaction($typeTransaction)
            ->get()
            ->map(function ($config) use ($succursaleId) {
                if ($succursaleId) {
                    $configSuccursale = CommissionConfigSuccursale::getValeurPourSuccursale(
                        $succursaleId,
                        $config->id
                    );
                    // Injecter la valeur succursale si elle existe
                    if ($configSuccursale) {
                        $config->valeur_effective = (float) $configSuccursale->valeur;
                    }
                }
                return $config;
            });
    }
}