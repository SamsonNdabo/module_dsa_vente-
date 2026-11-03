<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartenaireProduit extends Model
{
    protected $table = 'partenaire_produits';

    protected $fillable = [
        'id_partenaire', 'type_produit', 'id_produit', 'date_ajout', 'actif',
    ];

    protected $casts = [
        'date_ajout' => 'datetime',
        'actif'      => 'boolean',
    ];

    public const TYPES_PRODUIT = ['STOCK', 'EPARGNE'];

    /* ─── Relations ─── */

    public function partenaire(): BelongsTo
    {
        return $this->belongsTo(Partenaire::class, 'id_partenaire');
    }

    /**
     * Résolution polymorphe manuelle : retourne le bon modèle selon $type_produit.
     */
    public function produit(): ?Model
    {
        return match ($this->type_produit) {
            'STOCK'   => Produit::find($this->id_produit),        // table 'produit'
            'EPARGNE' => ProduitEpargne::find($this->id_produit), // table 'produits_epargne'
            default   => null,
        };
    }

    /* ─── Scopes ─── */

    public function scopePourPartenaire($q, int $partenaireId)
    {
        return $q->where('id_partenaire', $partenaireId);
    }

    public function scopeParType($q, string $type)
    {
        return $q->where('type_produit', $type);
    }

    public function scopeActifs($q)
    {
        return $q->where('actif', 1);
    }

    public function scopeEpargne($q)
    {
        return $q->where('type_produit', 'EPARGNE');
    }

    public function scopeStock($q)
    {
        return $q->where('type_produit', 'STOCK');
    }
}