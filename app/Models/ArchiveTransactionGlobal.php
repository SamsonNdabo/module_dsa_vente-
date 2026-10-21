<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArchiveTransactionGlobal extends Model
{
    protected $table = 'archive_transaction_global';
    //
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
        'montant_local'           => 'decimal:4',
        'commission_starehe'      => 'decimal:4',
        'montant_devise_pivot'    => 'decimal:4',
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

    public function seuilTransaction()
    {
        return $this->belongsTo(Seuil_Transactionnels::class, 'id_seuil_transaction');
    }

    public function caisse()
    {
        return $this->belongsTo(Caisses::class, 'caisse_id');
    }
}