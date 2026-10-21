<?php
// app/Models/LigneCommande.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LigneCommande extends Model
{
    use HasFactory;

    protected $table = 'lignes_commande';

    // =============================================
    // FILLABLE
    // =============================================
    
    protected $fillable = [
        'commande_id',
        'produit_id',
        'quantite',
        'prix_unitaire',
        'remise_ligne',
        'total',
        'logisticien_id',
        'lots_fifo_utilises',
        'imeis_utilises'
    ];

    // =============================================
    // CASTS
    // =============================================
    
    protected $casts = [
        'quantite' => 'integer',
        'prix_unitaire' => 'decimal:2',
        'remise_ligne' => 'decimal:2',
        'total' => 'decimal:2',
        'lots_fifo_utilises' => 'array',
        'imeis_utilises' => 'array'
    ];

    // =============================================
    // RELATIONS
    // =============================================
    
    public function commande()
    {
        return $this->belongsTo(CommandeClient::class, 'commande_id');
    }

    public function produit()
    {
        return $this->belongsTo(Produit::class);
    }

    public function logisticien()
    {
        return $this->belongsTo(Utilisateur::class, 'logisticien_id');
    }

    // =============================================
    // ACCESSORS
    // =============================================
    
    public function getTotalLigneAttribute(): float
    {
        return ($this->prix_unitaire * $this->quantite) - $this->remise_ligne;
    }

    // =============================================
    // MÉTHODES MÉTIER
    // =============================================
    
    /**
     * Vérifie si produit avec IMEI
     */
    public function utiliseImeis(): bool
    {
        return !empty($this->imeis_utilises);
    }

    /**
     * Vérifie si produit FIFO
     */
    public function utiliseFifo(): bool
    {
        return !empty($this->lots_fifo_utilises);
    }

    // =============================================
    // SCOPES
    // =============================================
    
    public function scopeParCommande($query, $commandeId)
    {
        return $query->where('commande_id', $commandeId);
    }

    public function scopeParProduit($query, $produitId)
    {
        return $query->where('produit_id', $produitId);
    }
}