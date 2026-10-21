<?php
// app/Models/LigneCommandeFournisseur.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LigneCommandeFournisseur extends Model
{
    use SoftDeletes;
    
    protected $table = 'lignes_commandes_fournisseurs';

    protected $fillable = [
        'commande_id',
        'produit_id',
        'libelle',
        'quantite',
        'quantite_recue',
        'prix_unitaire_ht',
        'remise_ligne',
        'taux_tva',
        'montant_ht',
        'montant_tva',
        'montant_ttc',
        'date_livraison_prevue',
        'notes',
        'metadata'
    ];

    protected $casts = [
        'quantite' => 'integer',
        'quantite_recue' => 'integer',
        'prix_unitaire_ht' => 'decimal:2',
        'remise_ligne' => 'decimal:2',
        'taux_tva' => 'decimal:2',
        'montant_ht' => 'decimal:2',
        'montant_tva' => 'decimal:2',
        'montant_ttc' => 'decimal:2',
        'date_livraison_prevue' => 'date',
        'metadata' => 'array'
    ];

    protected $appends = [
        'quantite_restante',
        'avancement_livraison'
    ];

    // Relations
    public function commande()
    {
        return $this->belongsTo(CommandeFournisseur::class, 'commande_id');
    }

    public function produit()
    {
        return $this->belongsTo(Produit::class, 'produit_id');
    }

    // Accesseurs
    public function getQuantiteRestanteAttribute()
    {
        return $this->quantite - ($this->quantite_recue ?? 0);
    }

    public function getAvancementLivraisonAttribute()
    {
        if ($this->quantite <= 0) return 0;
        return round(($this->quantite_recue / $this->quantite) * 100, 2);
    }

    // ============================================================
    // MÉTHODES DE CALCUL
    // ============================================================

    /**
     * Calcule les montants de la ligne
     */
    public function calculerMontants(): array
    {
        $montantBrutHT = $this->quantite * $this->prix_unitaire_ht;
        $montantRemise = $montantBrutHT * ($this->remise_ligne / 100);
        $montantHT = $montantBrutHT - $montantRemise;
        $montantTVA = $montantHT * ($this->taux_tva / 100);
        $montantTTC = $montantHT + $montantTVA;
        
        return [
            'montant_brut_ht' => round($montantBrutHT, 2),
            'montant_remise' => round($montantRemise, 2),
            'montant_ht' => round($montantHT, 2),
            'montant_tva' => round($montantTVA, 2),
            'montant_ttc' => round($montantTTC, 2)
        ];
    }

    /**
     * Met à jour les montants de la ligne
     */
    public function mettreAJourMontants(): self
    {
        $montants = $this->calculerMontants();
        
        $this->montant_ht = $montants['montant_ht'];
        $this->montant_tva = $montants['montant_tva'];
        $this->montant_ttc = $montants['montant_ttc'];
        $this->save();
        
        return $this;
    }

    /**
     * Enregistrer une réception sur la ligne
     */
    public function enregistrerReception($quantite)
    {
        $nouvelleQuantiteRecue = ($this->quantite_recue ?? 0) + $quantite;
        
        if ($nouvelleQuantiteRecue > $this->quantite) {
            throw new \Exception('La quantité reçue ne peut pas dépasser la quantité commandée');
        }
        
        $this->quantite_recue = $nouvelleQuantiteRecue;
        $this->save();
        
        return $this;
    }

    /**
     * Créer une ligne à partir d'une ligne de devis
     */
    public static function creerDepuisLigneDevis($ligneDevis, $commandeId): self
    {
        return self::create([
            'commande_id' => $commandeId,
            'produit_id' => $ligneDevis->produit_id,
            'libelle' => $ligneDevis->libelle ?? $ligneDevis->produit->nom,
            'quantite' => $ligneDevis->quantite,
            'quantite_recue' => 0,
            'prix_unitaire_ht' => $ligneDevis->prix_unitaire_ht,
            'remise_ligne' => $ligneDevis->remise_ligne ?? 0,
            'taux_tva' => $ligneDevis->taux_tva ?? 16,
        ]);
    }
}