<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegleKycPartenaire extends Model
{
    protected $table = 'regles_kyc_partenaires';

    protected $fillable = ['partenaire_id', 'produit_dsa_id', 'type_document', 'obligatoire', 'validite_jours', 'actif'];

    protected $casts = ['obligatoire' => 'boolean', 'actif' => 'boolean', 'validite_jours' => 'integer'];

    public function partenaire(): BelongsTo
    {
        return $this->belongsTo(Partenaire::class);
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(ProduitDsa::class, 'produit_dsa_id');
    }
}
