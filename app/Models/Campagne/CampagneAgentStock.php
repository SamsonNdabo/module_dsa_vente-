<?php

namespace App\Models\Campagne;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Produit;

class CampagneAgentStock extends Model
{
    use HasFactory;

    protected $table = 'campagne_agent_stocks';

    /**
     * Champs autorisés à l'assignation de masse.
     */
    protected $fillable = [
        'id_campagne_agent',
        'id_produit',
        'quantite_remise',
        'quantite_retournee',
        'quantite_vendue',
        'objectifs',           
        'prix_special',
        'offre_speciale',
        'valeur_offre',
    ];

    /**
     * Casts automatiques (notamment pour le JSON).
     */
    protected $casts = [
        'quantite_remise'    => 'integer',
        'quantite_retournee' => 'integer',
        'quantite_vendue'    => 'integer',
        'objectifs'          => 'array',      
        'prix_special'       => 'decimal:2',
        'valeur_offre'       => 'decimal:2',
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

    public function campagneAgent(): BelongsTo
    {
        return $this->belongsTo(CampagneAgent::class, 'id_campagne_agent');
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class, 'id_produit');
    }

    // ============================================================
    // ACCESSORS
    // ============================================================

    /**
     * Calcule le stock restant (remis - vendu - retourné).
     * Protégé contre les valeurs négatives.
     */
    public function getStockRestantAttribute(): int
    {
        return max(0, 
            (int) $this->quantite_remise 
            - (int) $this->quantite_vendue 
            - (int) $this->quantite_retournee
        );
    }

    // ============================================================
    // HELPERS / UTILITAIRES OBJECTIFS
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
            'quantite' => (int) $this->quantite_remise,
        ];

        return array_merge($legacy, $this->objectifs ?? []);
    }
}