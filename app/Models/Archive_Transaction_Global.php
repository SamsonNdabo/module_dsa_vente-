<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Archive_Transaction_Global extends Model
{
    protected $table = 'archive_transaction_global';

    protected $fillable = [
        'reference',
        'type',
        'montant_local',
        'devise_id',
        'date_transaction',
        'statut',
        'commission_starehe',
        'id_succursale',
        'caisse_id',
        'id_seuil_transaction',
        'montant_devise_pivot',
    ];

    protected $casts = [
        'montant_local'        => 'decimal:4',
        'commission_starehe'   => 'decimal:4',
        'montant_devise_pivot' => 'decimal:4',
        'date_transaction'     => 'date',
    ];

    // ============================================================
    // RELATIONS
    // ============================================================

    public function devise()
    {
        return $this->belongsTo(Devise::class, 'devise_id');
    }

    public function succursale()
    {
        return $this->belongsTo(Succursale::class, 'id_succursale');
    }

    public function caisse()
    {
        return $this->belongsTo(Caisses::class, 'caisse_id');
    }

    // ============================================================
    // SCOPES
    // ============================================================

    public function scopeParType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeParPeriode($query, string $debut, string $fin)
    {
        return $query->whereBetween('date_transaction', [$debut, $fin]);
    }

    // ============================================================
    // LOGIQUE MÉTIER
    // ============================================================

    // Archiver une transaction globale
    public static function archiver(Transaction_Globale $transaction): self
    {
        return self::create([
            'reference'            => $transaction->reference,
            'type'                 => $transaction->type,
            'montant_local'        => $transaction->montant_local,
            'devise_id'            => $transaction->devise_id,
            'date_transaction'     => $transaction->date_transaction,
            'statut'               => $transaction->statut,
            'commission_starehe'   => $transaction->commission_starehe,
            'id_succursale'        => $transaction->id_succursale,
            'caisse_id'            => $transaction->caisse_id,
            'id_seuil_transaction' => $transaction->id_seuil_transaction,
            'montant_devise_pivot' => $transaction->montant_devise_pivot,
        ]);
    }
}