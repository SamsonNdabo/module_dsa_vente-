<?php

namespace App\Models;

use App\Models\Devise;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Receipt extends Model
{
    protected $fillable = [
        'reference',
        'reference_change',
        'operation_change_id',
        'statut',
        'type_operation',
        'client_id',
        'client_nom',
        'client_telephone',
        'devise_remise',
        'devise_remise_montant',
        'devise_recue',
        'devise_recue_montant',
        'taux_achat',
        'taux_vente',
        'taux_affichage',
        'commission',
        'frais',
        'montant_net',
        'guichetier_nom',
        'caisse_nom',
        'agence_nom',
        'agence_adresse',
        'agence_telephone',
        'est_duplicata',
        'original_receipt_id',
        'date_impression',
        'signature_client',
        'signature_guichetier',
        'qr_code',
        'pdf_path',
    ];

    protected $casts = [
        'est_duplicata' => 'boolean',
        'date_impression' => 'datetime',
        'signature_client' => 'array',
        'signature_guichetier' => 'array',
    ];

    public function operationChange()
    {
        return $this->belongsTo(OperationChange::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function originalReceipt()
    {
        return $this->belongsTo(Receipt::class, 'original_receipt_id');
    }

    public function duplicatas()
    {
        return $this->hasMany(Receipt::class, 'original_receipt_id');
    }

    public function deviseRemise()
    {
        return $this->belongsTo(Devise::class, 'devise_remise_id');
    }

    public function deviseRecue()
    {
        return $this->belongsTo(Devise::class, 'devise_recue_id');
    }

    public static function genererReference(): string
    {
        $prefix = config('receipt.prefix', 'FX-');
        $year = date('Y');
        $month = date('m');
        $day = date('d');
        $last = self::whereYear('created_at', $year)->max('id') ?? 0;
        $next = str_pad($last + 1, 6, '0', STR_PAD_LEFT);
        return $prefix . $year . '-' . $month . '-' . $day . '-' . $next;
    }

    // Accesseur pour l'URL complète du PDF
    public function getPdfUrlAttribute(): ?string
    {
        if (!$this->pdf_path) {
            return null;
        }
        return asset('storage/' . $this->pdf_path);
    }
}