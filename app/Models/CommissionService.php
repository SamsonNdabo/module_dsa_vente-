<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CommissionService extends Model
{
    use HasFactory;

    protected $table = 'commission_service';
    protected $primaryKey = 'id';

    protected $fillable = [
        'service',
        'pourcentage',
        'montant',
        'id_transaction_global',
        'type_commission',
        'date_calcul',
        'statut',
        'notes'
    ];

    protected $casts = [
        'pourcentage' => 'decimal:2',
        'montant' => 'decimal:2',
        'date_calcul' => 'datetime'
    ];

    public function transaction()
    {
        return $this->belongsTo(TransactionGlobale::class, 'id_transaction_global');
    }

    public function getTypeLibelleAttribute()
    {
        $types = [
            'FIXE' => 'Fixe',
            'POURCENTAGE' => 'Pourcentage',
            'MIXTE' => 'Mixte'
        ];
        return $types[$this->type_commission] ?? $this->type_commission;
    }

    public function getMontantTotalAttribute()
    {
        if ($this->type_commission === 'FIXE') {
            return $this->montant;
        } elseif ($this->type_commission === 'POURCENTAGE') {
            return $this->transaction->montant_local * ($this->pourcentage / 100);
        } else {
            return $this->montant + ($this->transaction->montant_local * ($this->pourcentage / 100));
        }
    }
}