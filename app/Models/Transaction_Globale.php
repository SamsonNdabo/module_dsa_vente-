<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction_Globale extends Model
{
    protected $table = 'transaction_globale';

    protected $fillable = [
        'reference',
        'type',
        'montant_local',
        'devise_id',
        'date_transaction',
        'statut',
        'commission_starehe',
        'id_succursale',
        'id_creator',
        'client_id',
        'caisse_id',
        'caisse_mobile_id',
        'id_seuil_transaction',
        'montant_devise_pivot',
        'operation_change_id',
        'numero_client',
        'transactionid',    
        'description',
        'transfert_international_id',
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

    public function operationChange()
    {
        return $this->belongsTo(OperationChange::class, 'operation_change_id');
    }

    public function seuil()
    {
        return $this->belongsTo(Seuil_Transactionnels::class, 'id_seuil_transaction');
    }

    public function transactionMobile()
    {
        return $this->hasOne(Transaction_Mobile::class, 'id_transaction_global');
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function creator()
    {
        return $this->belongsTo(Utilisateur::class, 'id_creator');
    }

    // ============================================================
    // SCOPES
    // ============================================================

    public function scopeParType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeParStatut($query, string $statut)
    {
        return $query->where('statut', $statut);
    }

    public function scopeParSuccursale($query, int $succursaleId)
    {
        return $query->where('id_succursale', $succursaleId);
    }

    public function scopeAujourdhui($query)
    {
        return $query->whereDate('date_transaction', today());
    }

    // ============================================================
    // LOGIQUE MÉTIER
    // ============================================================

    // Générer une référence unique

    public static function genererReference(): string
    {
        $year     = date('Y');
        $month    = date('m');
        $day      = date('d');
        $nextId   = (self::max('id') ?? 0) + 1;
        $paddedId = str_pad($nextId, 5, '0', STR_PAD_LEFT);

        return "TXN-{$year}-{$month}-{$day}-{$paddedId}";
    }

    // Vérifier si la transaction est complète
    public function estComplete(): bool
    {
        return $this->statut === 'COMPLETEE';
    }

    // Vérifier si la transaction est en attente
    public function estEnAttente(): bool
    {
        return $this->statut === 'EN_ATTENTE';
    }

    // Vérifier l'état de la transaction est l'appliquer dans la commission

    public function mettreAJourCommission(string $statut): void
    {
        $commission = Commission::where('id_transaction_global', $this->id)->first();

        if (!$commission) return;

        match($statut) {
            'COMPLETEE' => $commission->update(['statut' => 'PAYEE']),
            'ANNULEE'   => $commission->update(['statut' => 'ANNULEE']),
            default     => null,
        };
    }
}