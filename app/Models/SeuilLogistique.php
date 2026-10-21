<?php
// app/Models/SeuilLogistique.php

namespace App\Models;
  
use App\Models\Role;

use Illuminate\Database\Eloquent\Model;

class SeuilLogistique extends Model
{
    protected $table = 'seuils_logistique';
    
    protected $fillable = [
        'devise_id',
        'montant_max_approbation_superieur', // Le seul montant à ne pas dépasser
        'niveau_superieur_role',
        'actif'
    ];
    
    protected $casts = [
        'montant_max_approbation_superieur' => 'decimal:2',
        'actif' => 'boolean'
    ];
    
    public function devise()
    {
        return $this->belongsTo(Devise::class);
    }
    

    public function roleSuperieur()
    {
        return $this->belongsTo(Role::class, 'niveau_superieur_role', 'code');
    }
    
    
    /**
     * Vérifier si un montant nécessite une approbation supérieure
     * Tout montant > seuil nécessite approbation
     */
    public function necessiteApprobationSuperieure($montant): bool
    {
        // Si le seuil est à 0 ou négatif, aucune approbation requise
        if ($this->montant_max_approbation_superieur <= 0) {
            return false;
        }
        
        // Approbation requise si le montant dépasse le seuil
        return $montant > $this->montant_max_approbation_superieur;
    }
    
    /**
     * Déterminer le niveau d'approbation requis
     */
    public function getNiveauApprobation($montant): string
    {
        return $this->necessiteApprobationSuperieure($montant) ? 'SUPERIEUR' : 'AUCUNE';
    }
}