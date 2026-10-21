<?php
// app/Models/Paiement.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;

class Paiement extends Model
{
    use HasFactory, Auditable;

    protected $table = 'paiements';

    const MODES_PAIEMENT = [
        'ESPECES' => 'Espèces',
        'CARTE_BANCAIRE' => 'Carte bancaire',
        'CHEQUE' => 'Chèque',
        'VIREMENT' => 'Virement',
        'MOBILE_MONEY' => 'Mobile money',
        'CREDIT' => 'Crédit',
        'MIXTE' => 'Mixte'
    ];

    protected $fillable = [
        'operation_libelle',
        'reference',
        'commande_id',
        'commande_fournisseur_id',
        'reception_id',
        'facture_id',
        'caisse_id',
        'utilisateur_id',
        'client_id',
        'mode_paiement',
        'montant',
        'montant_rendu',
        'date_paiement',
        'statut',
        'reference_externe',
        'details_paiement',
        'notes'
    ];

    protected $casts = [
        'montant' => 'decimal:2',
        'montant_rendu' => 'decimal:2',
        'date_paiement' => 'datetime',
        'details_paiement' => 'array'
    ];

    protected $appends = [
        'mode_label'
    ];


        public function commande()
    {
        return $this->belongsTo(CommandeClient::class, 'commande_id');
    }
    
        public static function genererReference(): string
    {
        return 'PAY' . now()->format('YmdHis') . strtoupper(substr(uniqid(), -5));
    }

    public function transactionMobile()
    {
        return $this->belongsTo(TransactionMobileMoney::class, 'transaction_mobile_id');
    }

    public function facture()
    {
        return $this->belongsTo(Facture::class, 'facture_id');
    }

    public function caisse()
    {
        return $this->belongsTo(Caisse::class, 'caisse_id');
    }

    public function utilisateur()
    {
        return $this->belongsTo(Utilisateur::class, 'utilisateur_id');
    }
    
        public function reception()
    {
        return $this->belongsTo(ReceptionFournisseur::class, 'reception_id');
    }
        public function commandeFournisseur()
    {
        return $this->belongsTo(CommandeFournisseur::class, 'commande_fournisseur_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function getModeLabelAttribute(): string
    {
        return self::MODES_PAIEMENT[$this->mode_paiement] ?? $this->mode_paiement;
    }
}