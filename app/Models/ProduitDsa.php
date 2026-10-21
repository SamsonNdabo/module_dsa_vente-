<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProduitDsa extends Model
{
    protected $table = 'produits_dsa';

    protected $fillable = [
        'partenaire_id', 'produit_id','devise_id', 'code', 'nom', 'type', 'categorie',
        'prix_vente', 'unite', 'commission', 'commission_type', 'taux_commission',
        'stock_requis', 'est_activation', 'date_debut', 'date_fin', 'statut', 'metadata',
    ];

    protected $casts = [
        'stock_requis'    => 'boolean',
        'est_activation'  => 'boolean',
        'prix_vente'      => 'decimal:2',
        'commission'      => 'decimal:2',
        'taux_commission' => 'decimal:2',
        'date_debut'      => 'date',
        'date_fin'        => 'date',
        'metadata'        => 'array',
    ];

    public function partenaire(): BelongsTo
    {
        return $this->belongsTo(Partenaire::class);
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class, 'produit_id');
    }

    public function reglesKyc(): HasMany
    {
        return $this->hasMany(RegleKycPartenaire::class);
    }

    public function devise(): BelongsTo
    {
        return $this->belongsTo(Devise::class, 'devise_id');
    }
}