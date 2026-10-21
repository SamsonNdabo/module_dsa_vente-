<?php
// app/Models/LigneApprovisionnement.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
// use Illuminate\Database\Eloquent\SoftDeletes;

class LigneApprovisionnement extends Model
{
    // use SoftDeletes;

    protected $table = 'lignes_approvisionnements';

    protected $fillable = [
        'approvisionnement_id',
        'produit_id',
        'quantite_demandee',
        'quantite_envoyee',
        'quantite_recue',
        'quantite_casse',
        'quantite_perdue',
        'quantite_non_conforme',
        'quantite_restante',
        'quantite_conforme',
        'quantite_arrivee_intermediaire',
        'quantite_depart_intermediaire',
        'perte_intermediaire',
        'notes_intermediaire',
        'motif_ecart',
        'numero_lot',
        'date_expiration_lot',
        'lot_id',
        'notes',
        'metadata'
    ];

    protected $casts = [
        'quantite_demandee' => 'integer',
        'quantite_envoyee' => 'integer',
        'quantite_recue' => 'integer',
        'quantite_casse' => 'integer',
        'quantite_perdue' => 'integer',
        'quantite_non_conforme' => 'integer',
        'quantite_restante' => 'integer',
        'quantite_conforme' => 'integer',
        'quantite_arrivee_intermediaire' => 'integer',
        'quantite_depart_intermediaire' => 'integer',
        'perte_intermediaire' => 'integer',
        'date_expiration_lot' => 'date',
        'metadata' => 'array'
    ];

    protected $appends = [
        'taux_casse',
        'taux_perte',
        'taux_non_conforme',
        'taux_conformite'
    ];

    // ============================================================
    // RELATIONS
    // ============================================================

    public function approvisionnement()
    {
        return $this->belongsTo(Approvisionnement::class, 'approvisionnement_id');
    }

    public function produit()
    {
        return $this->belongsTo(Produit::class, 'produit_id');
    }

    public function lot()
    {
        return $this->belongsTo(MouvementStock::class, 'lot_id');
    }

    // ============================================================
    // ACCESSEURS
    // ============================================================

    /**
     * Quantité conforme (reçue - écarts)
     */
    public function getQuantiteConformeAttribute()
    {
        return $this->quantite_recue - ($this->quantite_casse + $this->quantite_perdue + $this->quantite_non_conforme);
    }

    /**
     * Taux de casse
     */
    public function getTauxCasseAttribute()
    {
        if ($this->quantite_recue <= 0) return 0;
        return round(($this->quantite_casse / $this->quantite_recue) * 100, 2);
    }

    /**
     * Taux de perte
     */
    public function getTauxPerteAttribute()
    {
        if ($this->quantite_recue <= 0) return 0;
        return round(($this->quantite_perdue / $this->quantite_recue) * 100, 2);
    }

    /**
     * Taux de non conforme
     */
    public function getTauxNonConformeAttribute()
    {
        if ($this->quantite_recue <= 0) return 0;
        return round(($this->quantite_non_conforme / $this->quantite_recue) * 100, 2);
    }

    /**
     * Taux de conformité
     */
    public function getTauxConformiteAttribute()
    {
        if ($this->quantite_recue <= 0) return 0;
        return round(($this->quantite_conforme / $this->quantite_recue) * 100, 2);
    }

    /**
     * Vérifier s'il y a des écarts
     */
    public function getHasEcartAttribute()
    {
        return ($this->quantite_casse + $this->quantite_perdue + $this->quantite_non_conforme) > 0;
    }

    /**
     * Quantité totale des écarts
     */
    public function getTotalEcartAttribute()
    {
        return $this->quantite_casse + $this->quantite_perdue + $this->quantite_non_conforme;
    }

    // ============================================================
    // SCOPES
    // ============================================================

    public function scopeParProduit($query, $produitId)
    {
        return $query->where('produit_id', $produitId);
    }

    public function scopeAvecEcart($query)
    {
        return $query->where(function($q) {
            $q->where('quantite_casse', '>', 0)
              ->orWhere('quantite_perdue', '>', 0)
              ->orWhere('quantite_non_conforme', '>', 0);
        });
    }

    public function scopeSansEcart($query)
    {
        return $query->where('quantite_casse', 0)
            ->where('quantite_perdue', 0)
            ->where('quantite_non_conforme', 0);
    }

    public function scopeRecues($query)
    {
        return $query->where('quantite_recue', '>', 0);
    }

    public function scopeNonRecues($query)
    {
        return $query->where('quantite_recue', 0);
    }

    // ============================================================
    // MÉTHODES
    // ============================================================

    public function enregistrerReception(array $data): void
{
    $this->quantite_recue = $data['quantite_recue'];
    $this->quantite_casse = $data['quantite_casse'] ?? 0;
    $this->quantite_perdue = $data['quantite_perdue'] ?? 0;
    $this->quantite_non_conforme = $data['quantite_non_conforme'] ?? 0;
    $this->motif_ecart = $data['motif_ecart'] ?? null;
    $this->quantite_restante = $this->quantite_envoyee - $data['quantite_recue'];
    $this->save();
}
    /**
     * Vérifier si la ligne est complètement reçue
     */
    public function isFullyReceived()
    {
        return $this->quantite_recue >= $this->quantite_envoyee;
    }

    /**
     * Vérifier si la ligne est partiellement reçue
     */
    public function isPartiallyReceived()
    {
        return $this->quantite_recue > 0 && $this->quantite_recue < $this->quantite_envoyee;
    }

    /**
     * Quantité restante à recevoir
     */
    public function getResteARecevoirAttribute()
    {
        return max(0, $this->quantite_envoyee - $this->quantite_recue);
    }
}