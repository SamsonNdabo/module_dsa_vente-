<?php
// app/Models/RemiseRegle.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RemiseRegle extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'remise_regles';

    protected $fillable = [
        'nom',
        'description',
        'type',
        'valeur',
        'application',
        'cible',
        'cible_valeur',
        'montant_minimum',
        'nombre_achats_requis',
        'montant_achats_requis',
        'kyc_requis',
        'date_debut',
        'date_fin',
        'actif',
        'priorite',
        'conditions'
    ];

    protected $casts = [
        'valeur' => 'decimal:2',
        'montant_minimum' => 'decimal:2',
        'montant_achats_requis' => 'decimal:2',
        'kyc_requis' => 'boolean',
        'date_debut' => 'date',
        'date_fin' => 'date',
        'actif' => 'boolean',
        'conditions' => 'array'
    ];

    /**
     * Scope pour les règles actives
     */
    public function scopeActifs($query)
    {
        return $query->where('actif', true)
            ->where(function($q) {
                $q->whereNull('date_debut')
                  ->orWhere('date_debut', '<=', now());
            })
            ->where(function($q) {
                $q->whereNull('date_fin')
                  ->orWhere('date_fin', '>=', now());
            });
    }

    /**
     * Vérifie si la règle est applicable à un client et/ou un produit
     */
    public function estApplicable(Client $client, ?float $montantTotal = null, ?Produit $produit = null): bool
    {
        // Vérifier que le client est actif
        if ($client->statut !== 'ACTIF') {
            return false;
        }

        // Vérifier KYC
        if ($this->kyc_requis && !$client->kyc_complet) {
            return false;
        }

        // Vérifier montant minimum - avec sécurité
        if ($this->montant_minimum > 0) {
            $montant = $montantTotal ?? 0;
            if ($montant < $this->montant_minimum) {
                return false;
            }
        }
        
        // Vérifier nombre d'achats requis
        if ($this->nombre_achats_requis > 0 && $client->nombre_achats < $this->nombre_achats_requis) {
            return false;
        }
        
        // Vérifier montant total d'achats requis
        if ($this->montant_achats_requis > 0 && $client->total_achats < $this->montant_achats_requis) {
            return false;
        }

        // Vérifier les conditions JSON - AVEC VÉRIFICATION D'EXISTENCE
        if ($this->conditions && is_array($this->conditions)) {
            //  Vérifier 'seuil_montant' uniquement s'il existe
            if (isset($this->conditions['seuil_montant'])) {
                $montant = $montantTotal ?? 0;
                if ($montant < $this->conditions['seuil_montant']) {
                    return false;
                }
            }
            
            // Vérifier 'seuil_montant_max' uniquement s'il existe
            if (isset($this->conditions['seuil_montant_max'])) {
                $montant = $montantTotal ?? 0;
                if ($montant > $this->conditions['seuil_montant_max']) {
                    return false;
                }
            }
            
            // Vérifier d'autres conditions
            if (isset($this->conditions['categorie_client'])) {
                if ($client->categorie_client !== $this->conditions['categorie_client']) {
                    return false;
                }
            }
            
            if (isset($this->conditions['produit_ids']) && is_array($this->conditions['produit_ids'])) {
                if ($produit && !in_array($produit->id, $this->conditions['produit_ids'])) {
                    return false;
                }
            }
        }

        // Vérifier cible
        if ($this->application === 'GLOBALE') {
            // Vérifier par catégorie client
            if ($this->cible === 'categorie_client' && $client->categorie_client === $this->cible_valeur) {
                return true;
            }
            // Vérifier par client spécifique
            if ($this->cible === 'client_id' && $client->id == $this->cible_valeur) {
                return true;
            }
            // Pas de cible spécifique
            if (empty($this->cible)) {
                return true;
            }
            return false;
        }

        if ($this->application === 'CATEGORIE' && $produit) {
            if ($this->cible === 'categorie_produit' && $produit->categorie_id == $this->cible_valeur) {
                return true;
            }
            if ($this->cible === 'categorie_client' && $client->categorie_client === $this->cible_valeur) {
                return true;
            }
        }

        if ($this->application === 'PRODUIT' && $produit) {
            if ($this->cible === 'produit_id' && $produit->id == $this->cible_valeur) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calcule le montant de la remise
     */
    public function calculerRemise(float $montant): float
    {
        if ($this->type === 'POURCENTAGE') {
            return $montant * ($this->valeur / 100);
        }
        
        return min($this->valeur, $montant);
    }
}