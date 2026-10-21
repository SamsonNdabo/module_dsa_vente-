<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LotStock extends Model
{
    use SoftDeletes;

    protected $table = 'lots_stock';

    protected $fillable = [
        'produit_id',
        'stock_succursale_id',
        'mouvement_entree_id',
        'numero_lot',
        'date_fabrication',
        'date_expiration',
        'quantite_initiale',
        'quantite_restante',
        'cout_unitaire',
        'cout_unitaire_ttc',
        'statut',
        'motif_statut',
    ];

    protected $casts = [
        'date_fabrication' => 'date',
        'date_expiration' => 'date',
        'cout_unitaire' => 'decimal:2',
        'cout_unitaire_ttc' => 'decimal:2',
    ];

    // ============================================================
    // RELATIONS
    // ============================================================
    
    public function produit()
    {
        return $this->belongsTo(Produit::class);
    }

    public function stockSuccursale()
    {
        return $this->belongsTo(StockSuccursale::class);
    }

    public function mouvementEntree()
    {
        return $this->belongsTo(MouvementStock::class, 'mouvement_entree_id', 'mouvement_id');
    }

    public function mouvementsSortie()
    {
        return $this->belongsToMany(MouvementStock::class, 'mouvement_stock_lots', 'lot_stock_id', 'mouvement_stock_id')
                    ->withPivot('quantite', 'cout_unitaire')
                    ->withTimestamps();
    }

    // ============================================================
    // SCOPES - FIFO/FEFO adapté
    // ============================================================
    
    /**
     * Lots disponibles (non expirés, non bloqués)
     */
    public function scopeDisponibles($query)
    {
        return $query->where('statut', 'ACTIF')
                     ->where('quantite_restante', '>', 0)
                     ->where(function ($q) {
                         $q->whereNull('date_expiration')
                           ->orWhere('date_expiration', '>', now());
                     });
    }

    /**
     * FIFO pur : date d'entrée uniquement
     */
    public function scopeFIFO($query)
    {
        return $query->orderBy('created_at', 'asc');
    }

    /**
     * FEFO : date d'expiration prioritaire (périssables)
     */
    public function scopeFEFO($query)
    {
        return $query->orderBy('date_expiration', 'asc')
                     ->orderBy('created_at', 'asc');
    }

    /**
     * FIFO/FEFO mixte selon le produit
     */
    public function scopeOrdreDeSortie($query, Produit $produit)
    {
        if ($produit->est_perissable) {
            return $query->FEFO();
        }

        return $query->FIFO();
    }
}