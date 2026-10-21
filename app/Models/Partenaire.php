<?php

namespace App\Models;

use App\Services\StatutCatalogue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Partenaire extends Model
{
    public const TYPES = ['BANQUE', 'IMF', 'MOBILE_MONEY', 'ASSURANCE', 'COMMERCIAL', 'AUTRE'];

    protected $table = 'partenaires';

    protected $fillable = [
        'code', 'nom', 'type', 'telephone', 'email', 'adresse',
        'ville', 'contact_nom', 'actif', 'metadata',
    ];

    protected $casts = [
        'actif'    => 'boolean',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::created(fn (Partenaire $p) =>
            app(StatutCatalogue::class)->initialiserPourPartenaire($p)
        );
    }

    public function produits(): HasMany
    {
        return $this->hasMany(ProduitDsa::class);
    }

    public function reglesKyc(): HasMany
    {
        return $this->hasMany(RegleKycPartenaire::class);
    }

    public function statuts(): HasMany
    {
        return $this->hasMany(StatutConfigurable::class);
    }
}