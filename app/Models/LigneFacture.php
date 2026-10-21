<?php
// app/Models/LigneFacture.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;

class LigneFacture extends Model
{
    use HasFactory, Auditable;

    protected $table = 'lignes_factures';

    protected $fillable = [
        'facture_id',
        'produit_id',
        'stock_succursale_id',
        'quantite',
        'prix_unitaire_ht',
        'prix_unitaire_ttc',
        'remise_ligne',
        'type_remise_ligne',
        'total_ht',
        'total_ttc',
        'lots_fifo_utilises',
        'imeis_utilises',
        'notes'
    ];

    protected $casts = [
        'quantite' => 'integer',
        'prix_unitaire_ht' => 'decimal:2',
        'prix_unitaire_ttc' => 'decimal:2',
        'remise_ligne' => 'decimal:2',
        'total_ht' => 'decimal:2',
        'total_ttc' => 'decimal:2',
        'lots_fifo_utilises' => 'array',
        'imeis_utilises' => 'array'
    ];

    protected $appends = [
        'montant_remise',
        'taux_remise'
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($ligne) {
            // Calculer le total
            $totalHT = $ligne->prix_unitaire_ht * $ligne->quantite;
            
            // Appliquer la remise si nécessaire
            if ($ligne->remise_ligne > 0) {
                if ($ligne->type_remise_ligne === 'POURCENTAGE') {
                    $montantRemise = $totalHT * ($ligne->remise_ligne / 100);
                } else {
                    $montantRemise = $ligne->remise_ligne;
                }
                $totalHT -= $montantRemise;
            }
            
            $ligne->total_ht = $totalHT;
            
            // Calcul du TTC
            $tauxTVA = 16; // À récupérer de la configuration
            $ligne->total_ttc = $totalHT * (1 + $tauxTVA / 100);
        });
    }

    public function facture()
    {
        return $this->belongsTo(Facture::class, 'facture_id');
    }

    public function produit()
    {
        return $this->belongsTo(Produit::class, 'produit_id');
    }

    public function stockSuccursale()
    {
        return $this->belongsTo(StockSuccursale::class, 'stock_succursale_id');
    }

    public function getMontantRemiseAttribute(): float
    {
        if ($this->remise_ligne <= 0) return 0;
        
        $totalSansRemise = $this->prix_unitaire_ht * $this->quantite;
        
        if ($this->type_remise_ligne === 'POURCENTAGE') {
            return $totalSansRemise * ($this->remise_ligne / 100);
        }
        
        return $this->remise_ligne;
    }

    public function getTauxRemiseAttribute(): float
    {
        if ($this->remise_ligne <= 0) return 0;
        
        $totalSansRemise = $this->prix_unitaire_ht * $this->quantite;
        
        if ($this->type_remise_ligne === 'POURCENTAGE') {
            return $this->remise_ligne;
        }
        
        return ($this->remise_ligne / $totalSansRemise) * 100;
    }

    public function toArray()
    {
        $array = parent::toArray();
        $array['montant_remise'] = $this->montant_remise;
        $array['taux_remise'] = $this->taux_remise;
        $array['produit'] = $this->produit;
        
        return $array;
    }
}