<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction_Mobile extends Model
{
    protected $table = 'transaction_mobile';

    protected $fillable = [
        'id_transaction_global',
        'id_operateur_mobile',
        'id_commande',
        'id_facture',
        'id_creator',
        'id_succursale',
        'reference_transaction',
        'transactionid',
        'numero_client',
        'nom_client',
        'type_operation',
        'montant',
        'devise_id',
        'caisse_id',
        'commission_operateur',
        'montant_net',
        'statut_transaction',
        'numero_ref',
        'description', 
        'id_operateurMobileMoney',
        'created_at',
    ];

    protected $casts = [
        'montant'              => 'decimal:4',
        'commission_operateur' => 'decimal:4',
        'montant_net'          => 'decimal:4',
    ];

    // ============================================================
    // RELATIONS
    // ============================================================

    public function transactionGlobale()
    {
        return $this->belongsTo(Transaction_Globale::class, 'id_transaction_global');
    }

    public function operateur()
    {
        return $this->belongsTo(Operateur_Mobile::class, 'id_operateur_mobile');
    }

    public function operateurMobileMoney()
    {
        return $this->belongsTo(OperateurMobileMoney::class, 'id_operateurMobileMoney');
    }

    public function commande()
    {
        return $this->belongsTo(CommandeClient::class, 'id_commande');
    }

    public function facture()
    {
        return $this->belongsTo(Facture::class, 'id_facture');
    }

    public function succursale()
    {
        return $this->belongsTo(Succursale::class, 'id_succursale');
    }

    public function devise()
    {
        return $this->belongsTo(Devise::class, 'devise_id');
    }

    public function caisse()
    {
        return $this->belongsTo(Caisses::class, 'caisse_id');
    }
    
    // ============================================================
    // SCOPES
    // ============================================================

    public function scopeParStatut($query, string $statut)
    {
        return $query->where('statut_transaction', $statut);
    }

    public function scopeParOperateur($query, int $operateurId)
    {
        return $query->where('id_operateur_mobile', $operateurId);
    }
    
    public function scopeParType($query, string $type)
    {
        return $query->where('type_operation', $type);
    }
    public function scopeParPeriode($query, string $debut, string $fin)
    {
        return $query->whereBetween('created_at', [$debut, $fin]);
    }

    // ============================================================
    // LOGIQUE MÉTIER
    // ============================================================

        // Générer une référence unique

    public static function genererReference(string $codeOperateur): string
    {
        $year     = date('Y');
        $month    = date('m');
        $nextId   = (self::max('id') ?? 0) + 1;
        $paddedId = str_pad($nextId, 5, '0', STR_PAD_LEFT);

        return "TRM-{$codeOperateur}-{$year}-{$month}-{$paddedId}";
    }

    // Calculer automatiquement commission et montant net
    public static function calculer(float $montant, float $tauxCommission): array
    {
        $commission = round($montant * ($tauxCommission / 100), 4);
        $montantNet = round($montant - $commission, 4);

        return [
            'commission_operateur' => $commission,
            'montant_net'          => $montantNet,
        ];
    }

    // Calculer automatiquement commission PAYOUT sachant que cette commission est directement enlever dans le compte
    public static function calculerPayout(float $montant, float $tauxCommission): array
    {
        $commission = round($montant * ($tauxCommission / 100), 4);
        $montantNet = round($montant + $commission, 4);

        return [
            'commission_operateur' => $commission,
            'montant_net'          => $montantNet,
        ];
    }
}