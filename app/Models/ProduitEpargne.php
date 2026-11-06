<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\models\Partenaire\Partenaire;

class ProduitEpargne extends Model
{
    use HasFactory;

    protected $table = 'produits_epargne';

    protected $fillable = [
        'code',
        'nom',
        'type',
        'devise_id',
        'solde_minimum',
        'plafond_maximum',
        'montant_min_depot',
        'montant_min_retrait',
        'frais_depot_pct',
        'frais_retrait_pct',
        'commission_dsa_pct',
        'periodicite',
        'duree_min_mois',
        'date_debut_validite',
        'date_fin_validite',
        'actif',
        'regles_specifiques',
    ];

    protected $casts = [
        'type'                 => 'string',
        'solde_minimum'        => 'decimal:4',
        'plafond_maximum'      => 'decimal:4',
        'montant_min_depot'    => 'decimal:4',
        'montant_min_retrait'  => 'decimal:4',
        'frais_depot_pct'      => 'decimal:4',
        'frais_retrait_pct'    => 'decimal:4',
        'commission_dsa_pct'   => 'decimal:4',
        'periodicite'          => 'string',
        'duree_min_mois'       => 'integer',
        'date_debut_validite'  => 'date',
        'date_fin_validite'    => 'date',
        'actif'                => 'boolean',
        'regles_specifiques'   => 'array',
    ];

    // --- RELATIONS ---

    /**
     * La devise associée à ce produit d'épargne.
     */
    public function devise(): BelongsTo
    {
        return $this->belongsTo(Devise::class, 'devise_id');
    }

    /**
     * Les partenaires qui proposent ce produit d'épargne.
     */
    public function partenaires(): BelongsToMany
    {
        return $this->belongsToMany(Partenaire::class, 'partenaire_produits', 'id_produit', 'id_partenaire')
                    ->wherePivot('type_produit', 'EPARGNE')
                    ->withPivot('actif', 'date_ajout');
    }
}