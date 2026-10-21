<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampagneObjectif extends Model
{
    protected $table = 'campagne_objectifs';

    // Dans $fillable, ajouter 'produit_dsa_id'
    protected $fillable = [
        'campagne_id', 'type_objectif', 'produit_dsa_id', 'libelle',
        'valeur_cible', 'unite', 'valeur_realisee',
        'actif', 'ordre',
    ];

    protected $casts = [
        'valeur_cible'    => 'decimal:2',
        'valeur_realisee' => 'decimal:2',
        'actif'           => 'boolean',
        'ordre'           => 'integer',
    ];

    public const TYPES_STANDARD = ['MONTANT', 'VOLUME', 'CLIENTS', 'COMPTES', 'VISITES', 'PRODUIT'];

    public const LIBELLES = [
        'MONTANT' => 'Montant',
        'VOLUME'  => 'Volume',
        'CLIENTS' => 'Clients',
        'COMPTES' => 'Comptes',
        'VISITES' => 'Visites',
        'PRODUIT' => 'Produit',
    ];



    // Nouvelle relation
    public function produit(): BelongsTo
    {
        return $this->belongsTo(ProduitDsa::class, 'produit_dsa_id');
    }

        public function campagne(): BelongsTo
    {
        return $this->belongsTo(Campagne::class, 'campagne_id');
    }

    /** Objectifs individuels (agents) rattachés à ce type. */
    public function objectifsAgents(): HasMany
    {
        return $this->hasMany(ObjectifMesurable::class, 'campagne_objectif_id');
    }

    /* ---------------- Helpers ---------------- */

    public function tauxAtteinte(): float
    {
        if ((float) $this->valeur_cible <= 0) return 0;
        return round(((float) $this->valeur_realisee / (float) $this->valeur_cible) * 100, 2);
    }
    // Libellé : si PRODUIT, afficher nom du produit
    public function libelleType(): string
    {
        if ($this->type_objectif === 'PRODUIT' && $this->produit) {
            return $this->produit->nom;
        }
        return $this->libelle ?: (self::LIBELLES[$this->type_objectif] ?? $this->type_objectif);
    }

    /**
     * Recalcule la valeur réalisée globale = somme des avancements agents.
     */
    public function recalculerRealisee(): void
    {
        $total = (float) $this->objectifsAgents()->sum('valeur_realisee');
        $this->update(['valeur_realisee' => $total]);
    }
}