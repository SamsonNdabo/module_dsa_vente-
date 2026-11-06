<?php
// app/Models/LigneReceptionFournisseur.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LigneReceptionFournisseur extends Model
{
    use SoftDeletes;

    protected $table = 'lignes_receptions_fournisseurs';
    
    protected $fillable = [
        'reception_id',
        'commande_ligne_id',
        'produit_id',
        'quantite_attendue',
        'quantite_recue',
        'quantite_casse',
        'quantite_perdue',
        'quantite_non_conforme',
        'prix_unitaire_ht',        //  Prix saisi à la réception
        'taux_tva',                //  TVA saisi à la réception
        'remise',                  //  Remise saisi à la réception
        'lot_id',
        'date_expiration_lot',
        'numero_lot',
        'metadata',
        'notes'
    ];

    protected $casts = [
        'quantite_attendue' => 'integer',
        'quantite_recue' => 'integer',
        'quantite_casse' => 'integer',
        'quantite_perdue' => 'integer',
        'quantite_non_conforme' => 'integer',
        'prix_unitaire_ht' => 'decimal:2',
        'taux_tva' => 'decimal:2',
        'remise' => 'decimal:2',
        'date_expiration_lot' => 'date',
        'metadata' => 'array'
    ];

    protected $appends = [
        'quantite_conforme',
        'taux_casse',
        'taux_perte',
        'taux_non_conforme',
        'valeur_ht',
        'valeur_tva',
        'valeur_ttc',
        'prix_apres_remise'
    ];

    // ============================================================
    // RELATIONS
    // ============================================================

    public function reception()
    {
        return $this->belongsTo(ReceptionFournisseur::class, 'reception_id');
    }

    public function commandeLigne()
    {
        return $this->belongsTo(LigneCommandeFournisseur::class, 'commande_ligne_id');
    }

    public function produit()
    {
        return $this->belongsTo(Produit::class, 'produit_id');
    }

    public function lot()
    {
        return $this->belongsTo(MouvementStock::class, 'lot_id', 'mouvement_id');
    }

    // ============================================================
    // ACCESSEURS
    // ============================================================

    public function getQuantiteConformeAttribute()
    {
        return $this->quantite_recue - ($this->quantite_casse + $this->quantite_perdue + $this->quantite_non_conforme);
    }

    public function getTauxCasseAttribute()
    {
        if ($this->quantite_recue <= 0) return 0;
        return round(($this->quantite_casse / $this->quantite_recue) * 100, 2);
    }

    public function getTauxPerteAttribute()
    {
        if ($this->quantite_recue <= 0) return 0;
        return round(($this->quantite_perdue / $this->quantite_recue) * 100, 2);
    }

    public function getTauxNonConformeAttribute()
    {
        if ($this->quantite_recue <= 0) return 0;
        return round(($this->quantite_non_conforme / $this->quantite_recue) * 100, 2);
    }

    public function getPrixApresRemiseAttribute()
    {
        $prix = $this->prix_unitaire_ht ?? 0;
        $remise = $this->remise ?? 0;
        return $prix * (1 - $remise / 100);
    }

    public function getValeurHtAttribute()
    {
        return $this->quantite_conforme * $this->prix_apres_remise;
    }

    public function getValeurTvaAttribute()
    {
        $tva = $this->taux_tva ?? 16;
        return $this->valeur_ht * ($tva / 100);
    }

    public function getValeurTtcAttribute()
    {
        return $this->valeur_ht + $this->valeur_tva;
    }

        public function getNomProduitAttribute()
    {
        return $this->produit ? $this->produit->nom : 'N/A';
    }

    public function getEcartQuantiteAttribute()
    {
        return $this->quantite_attendue - $this->quantite_recue;
    }

    public function hasEcart()
    {
        return ($this->ecart_quantite != 0 || 
                $this->quantite_casse > 0 || 
                $this->quantite_perdue > 0 || 
                $this->quantite_non_conforme > 0);
    }

    // ============================================================
    // MÉTHODES
    // ============================================================

    /**
     * Enregistrer le stock reçu (FIFO) avec prix d'achat saisi à la réception
     */
    public function enregistrerStock($stockSuccursaleId)
    {
        if (!$this->produit || !$this->quantite_conforme) {
            return null;
        }

        $stock = StockSuccursale::find($stockSuccursaleId);
        if (!$stock) {
            throw new \Exception('Stock destination non trouvé');
        }

        if (!$stock->succursale_id) {
            throw new \Exception('Le stock de destination n\'est pas lié à une succursale');
        }

        //  Prix saisi à la réception (avec remise)
        $prixAchatApresRemise = $this->prix_apres_remise;

        //  Créer le lot FIFO
        $mouvement = MouvementStock::creerLotEntree(
            $this->produit_id,
            $stock->id,
            $this->quantite_conforme,
            $prixAchatApresRemise,
            [
                'succursale_id' => $stock->succursale_id,
                'date_expiration' => $this->date_expiration_lot,
                'raison' => "Réception commande #{$this->reception->commande->reference}",
                'reference_document' => $this->reception->reference,
                'taux_tva' => $this->taux_tva ?? 16,
                'numero_lot_fournisseur' => $this->numero_lot,
                'bon_livraison' => $this->reception->num_bon_livraison,
                'facture_fournisseur' => $this->reception->num_facture_fournisseur
            ]
        );

        $this->lot_id = $mouvement->mouvement_id;
        $this->save();

        return $mouvement;
    }
}