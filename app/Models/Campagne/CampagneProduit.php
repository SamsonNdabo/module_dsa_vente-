<?php

namespace App\Models\Campagne;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Produit;

class CampagneProduit extends Model
{
    use HasFactory;

    protected $table = 'campagne_produits';

    /**
     * Champs autorisés à l'assignation de masse.
     */
    protected $fillable = [
        'id_campagne',
        'id_produit',
        'objectif_quantite',
        'objectif_montant',
        'objectifs',           
        'prix_special',
        'offre_speciale',
        'valeur_offre',
    ];

    /**
     * Casts automatiques (notamment pour le JSON).
     */
    protected $casts = [
        'objectif_quantite' => 'integer',
        'objectif_montant'  => 'decimal:2',
        'objectifs'         => 'array',      
        'prix_special'      => 'decimal:2',
        'valeur_offre'      => 'decimal:2',
    ];

    /**
     * Valeurs par défaut pour éviter les NULL.
     */
    protected $attributes = [
        'objectifs' => '{}',
    ];

    // ============================================================
    // RELATIONS
    // ============================================================

    public function campagne(): BelongsTo
    {
        return $this->belongsTo(Campagne::class, 'id_campagne');
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class, 'id_produit');
    }

    // ============================================================
    // HELPERS / UTILITAIRES
    // ============================================================

    /**
     * Récupère la valeur d'un objectif spécifique (ex: 'visite', 'plainte').
     * Retourne 0 si non défini.
     */
    public function getObjectif(string $type): float
    {
        return (float) ($this->objectifs[$type] ?? 0);
    }

    /**
     * Définit ou met à jour un objectif spécifique.
     */
    public function setObjectif(string $type, float $valeur): self
    {
        $objectifs = $this->objectifs ?? [];
        $objectifs[$type] = $valeur;
        $this->objectifs = $objectifs;
        
        return $this;
    }

    /**
     * Retourne tous les objectifs combinés (legacy + JSON).
     * Utile pour l'affichage frontend.
     */
    public function tousLesObjectifs(): array
    {
        $legacy = [
            'quantite' => (int) $this->objectif_quantite,
            'montant'  => (float) $this->objectif_montant,
        ];

        return array_merge($legacy, $this->objectifs ?? []);
    }
}