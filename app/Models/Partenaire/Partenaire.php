<?php

namespace App\Models\Partenaire;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

use App\Models\Utilisateur;
use App\Models\Campagne\Campagne;
use App\Models\Campagne\CommissionGeneree;
use App\Models\Partenaire\PartenaireCommissionRecue;
use App\Models\Produit;
use App\models\ProduitEpargne;

class Partenaire extends Model
{
    use HasFactory;

    protected $table = 'partenaires';

    protected $fillable = [
        'code',
        'nom',
        'logo_url',
        'nom_contact',
        'type_partenaire',
        'email',
        'telephone',
        'statut',
    ];

    protected $casts = [
        'type_partenaire' => 'string',
        'statut' => 'string',
    ];

    // --- RELATIONS ---

    public function utilisateurs(): HasMany
    {
        return $this->hasMany(Utilisateur::class, 'partenaire_id');
    }

    public function campagnes(): HasMany
    {
        return $this->hasMany(Campagne::class, 'id_partenaire');
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(CommissionGeneree::class, 'id_partenaire');
    }

    public function rapprochements(): HasMany
    {
        return $this->hasMany(PartenaireCommissionRecue::class, 'id_partenaire');
    }

    /**
     * Relation polymorphe via la table pivot pour les produits classiques (Stock)
     */
    public function produitsStock(): BelongsToMany
    {
        return $this->belongsToMany(Produit::class, 'partenaire_produits', 'id_partenaire', 'id_produit')
                    ->wherePivot('type_produit', 'STOCK')
                    ->wherePivot('actif', 1)
                    ->withPivot('date_ajout');
    }

    /**
     * Relation polymorphe via la table pivot pour les produits d'épargne
     */
    public function produitsEpargne(): BelongsToMany
    {
        return $this->belongsToMany(ProduitEpargne::class, 'partenaire_produits', 'id_partenaire', 'id_produit')
                    ->wherePivot('type_produit', 'EPARGNE')
                    ->wherePivot('actif', 1)
                    ->withPivot('date_ajout');
    }
}