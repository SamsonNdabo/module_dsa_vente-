<?php
// app/Models/DevisLigne.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DevisLigne extends Model
{
    use SoftDeletes;

    protected $table = 'devis_lignes';

    protected $fillable = [
        'devis_id',
        'produit_id',
        'rfq_ligne_id',
        'libelle',
        'quantite',
        'prix_unitaire_ht',
        'remise_ligne',
        'taux_tva',
        'notes'
    ];

    protected $casts = [
        'quantite' => 'integer',
        'prix_unitaire_ht' => 'decimal:2',
        'remise_ligne' => 'decimal:2',
        'taux_tva' => 'decimal:2'
    ];

    protected $appends = [
        'total_ht',
        'total_ttc'
    ];

    public function devis()
    {
        return $this->belongsTo(DevisFournisseur::class, 'devis_id');
    }

    public function produit()
    {
        return $this->belongsTo(Produit::class, 'produit_id');
    }

    public function rfqLigne()
    {
        return $this->belongsTo(RfqLigne::class, 'rfq_ligne_id');
    }

    public function getTotalHtAttribute()
    {
        $prixApresRemise = $this->prix_unitaire_ht * (1 - ($this->remise_ligne / 100));
        return $this->quantite * $prixApresRemise;
    }

    public function getTotalTtcAttribute()
    {
        $tva = $this->taux_tva ?? 16;
        return $this->total_ht * (1 + $tva / 100);
    }
}