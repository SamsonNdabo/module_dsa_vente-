<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ArchiveTransactionGlobale extends Model
{
    use HasFactory;

    protected $table = 'archive_transaction_global';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
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
        'date_archivage',
        'motif_archivage'
    ];

    protected $casts = [
        'montant_local' => 'decimal:2',
        'commission_starehe' => 'decimal:2',
        'montant_devise_pivot' => 'decimal:2',
        'date_transaction' => 'datetime',
        'date_archivage' => 'datetime'
    ];

    public function devise()
    {
        return $this->belongsTo(Devise::class, 'devise_id');
    }

    public function succursale()
    {
        return $this->belongsTo(Succursale::class, 'id_succursale');
    }

    public function seuil()
    {
        return $this->belongsTo(SeuilTransactionnel::class, 'id_seuil_transaction');
    }
}