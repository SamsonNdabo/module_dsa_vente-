<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProduitEpargne extends Model
{
    protected $table = 'produits_epargne';

    protected $fillable = [
        'code', 'nom', 'type', 'devise_id',
        'solde_minimum', 'plafond_maximum',
        'montant_min_depot', 'montant_min_retrait',
        'frais_depot_pct', 'frais_retrait_pct', 'commission_dsa_pct',
        'periodicite', 'duree_min_mois',
        'date_debut_validite', 'date_fin_validite',
        'actif', 'regles_specifiques',
        'validation_retrait_obligatoire', 'seuil_validation_retrait',
        'validation_depot_obligatoire',
    ];

    protected $casts = [
        'regles_specifiques'              => 'array',
        'solde_minimum'                   => 'decimal:4',
        'plafond_maximum'                 => 'decimal:4',
        'montant_min_depot'               => 'decimal:4',
        'montant_min_retrait'             => 'decimal:4',
        'frais_depot_pct'                 => 'decimal:4',
        'frais_retrait_pct'               => 'decimal:4',
        'commission_dsa_pct'              => 'decimal:4',
        'seuil_validation_retrait'        => 'decimal:4',
        'date_debut_validite'             => 'date',
        'date_fin_validite'               => 'date',
        'actif'                           => 'boolean',
        'validation_retrait_obligatoire'  => 'boolean',
        'validation_depot_obligatoire'    => 'boolean',
    ];

    public const TYPES = ['CLASSIQUE','SCOLAIRE','PROJET','ENTREPRISE','GROUPE','AUTRE'];

    /* ─── Relations ─── */

    public function devise(): BelongsTo
    {
        return $this->belongsTo(Devise::class, 'devise_id');
    }

    public function comptesEpargne(): HasMany
    {
        return $this->hasMany(CompteEpargne::class, 'produit_epargne_id');
    }

    /**
     * Partenaires liés à ce produit d'épargne (via pivot polymorphe).
     */
    public function partenaires()
    {
        return $this->belongsToMany(
            Partenaire::class,
            'partenaire_produits',
            'id_produit',       // clé locale (this.id) dans le pivot
            'id_partenaire',    // clé distante (partenaire.id) dans le pivot
            'id',               // clé primaire locale
            'id'                // clé primaire distante
        )
        ->wherePivot('type_produit', 'EPARGNE')
        ->wherePivot('actif', 1)
        ->withPivot(['date_ajout', 'actif'])
        ->withTimestamps();
    }

    /**
     * Vérifie si le produit est actif et dans sa période de validité.
     */
    public function estActif(): bool
    {
        if (! $this->actif) {
            return false;
        }

        $aujourdhui = now()->toDateString();

        if ($this->date_debut_validite && $this->date_debut_validite->toDateString() > $aujourdhui) {
            return false;
        }

        if ($this->date_fin_validite && $this->date_fin_validite->toDateString() < $aujourdhui) {
            return false;
        }

        return true;
    }
}