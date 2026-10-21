<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Registre_Transactions_Sensibles extends Model
{
    protected $table      = 'registre_transactions_sensibles';
    public    $timestamps = false;

    protected $fillable = [
        'transaction_type',
        'transaction_id',
        'client_id',
        'montant',
        'devise',
        'raison_sensibilite',
        'details_sensibilite',
        'actions_appliquees',
        'date_transaction',
        'succursale_id',
        'caissier_id',
        'superviseur_notifie',
        'date_notification',
    ];

    protected $casts = [
        'montant'             => 'decimal:4',
        'actions_appliquees'  => 'array',
        'date_transaction'    => 'datetime',
        'date_notification'   => 'datetime',
        'date_creation'       => 'datetime',
    ];

    // ============================================================
    // RELATIONS
    // ============================================================

    public function succursale()
    {
        return $this->belongsTo(Succursale::class, 'succursale_id');
    }

    public function caissier()
    {
        return $this->belongsTo(Utilisateur::class, 'caissier_id');
    }

    public function superviseur()
    {
        return $this->belongsTo(Utilisateur::class, 'superviseur_notifie');
    }

    // ============================================================
    // SCOPES
    // ============================================================

    public function scopeParType($query, string $type)
    {
        return $query->where('transaction_type', $type);
    }

    public function scopeNonNotifie($query)
    {
        return $query->whereNull('superviseur_notifie');
    }

    public function scopeParRaison($query, string $raison)
    {
        return $query->where('raison_sensibilite', $raison);
    }

    // ============================================================
    // LOGIQUE MÉTIER
    // ============================================================

    // Notifier le superviseur
    public function notifierSuperviseur(int $superviseurId): bool
    {
        $this->superviseur_notifie = $superviseurId;
        $this->date_notification   = now();
        return $this->save();
    }

    // Créer automatiquement depuis une transaction
    public static function creerDepuisTransaction(
        string $type,
        int    $transactionId,
        float  $montant,
        string $devise,
        string $raison,
        string $details,
        int    $succursaleId,
        int    $caissierId
    ): self {
        return self::create([
            'transaction_type'    => $type,
            'transaction_id'      => $transactionId,
            'montant'             => $montant,
            'devise'              => $devise,
            'raison_sensibilite'  => $raison,
            'details_sensibilite' => $details,
            'date_transaction'    => now(),
            'succursale_id'       => $succursaleId,
            'caissier_id'         => $caissierId,
        ]);
    }
}