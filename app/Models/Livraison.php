<?php
// app/Models/Livraison.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Livraison extends Model
{
    use HasFactory;

    protected $table = 'livraisons';

    protected $fillable = [
        'reference',
        'commande_id',
        'client_id',
        'statut',
        'transporteur',
        'numero_suivi',
        'adresse_livraison',
        'nom_destinataire',
        'telephone_destinataire',
        'instructions_livraison',
        'date_pris_en_charge',
        'date_livraison_estimee',
        'date_livraison_reelle',
        'historique_statuts',
        'preuve_livraison',
        'motif_echec',
        'livreur_id',
        'succursale_id'
    ];

    protected $casts = [
        'adresse_livraison' => 'array',
        'historique_statuts' => 'array',
        'preuve_livraison' => 'array',
        'date_pris_en_charge' => 'datetime',
        'date_livraison_estimee' => 'datetime',
        'date_livraison_reelle' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    protected $attributes = [
        'historique_statuts' => '[]',
        'preuve_livraison' => '[]'
    ];

    // =============================================
    // RELATIONS
    // =============================================
    
    public function commande()
    {
        return $this->belongsTo(CommandeClient::class, 'commande_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function livreur()
    {
        return $this->belongsTo(Utilisateur::class, 'livreur_id');
    }

    public function succursale()
    {
        return $this->belongsTo(Succursale::class);
    }

    // =============================================
    // ACCESSORS & MUTATORS
    // =============================================
    
    // /**
    //  * Récupérer l'historique des statuts
    //  */
    // public function getHistoriqueStatutsAttribute($value)
    // {
    //     if (is_null($value)) {
    //         return [];
    //     }
        
    //     if (is_string($value)) {
    //         $decoded = json_decode($value, true);
    //         return is_array($decoded) ? $decoded : [];
    //     }
        
    //     return is_array($value) ? $value : [];
    // }

    // /**
    //  * Définir l'historique des statuts
    //  */
    // public function setHistoriqueStatutsAttribute($value)
    // {
    //     // Si c'est une chaîne JSON valide, on la garde
    //     if (is_string($value) && json_decode($value) !== null) {
    //         $this->attributes['historique_statuts'] = $value;
    //         return;
    //     }
        
    //     // Si c'est un tableau, on l'encode en JSON
    //     if (is_array($value)) {
    //         $this->attributes['historique_statuts'] = json_encode($value, JSON_UNESCAPED_UNICODE);
    //         return;
    //     }
        
    //     // Sinon, on met un tableau vide
    //     $this->attributes['historique_statuts'] = '[]';
    // }

    // /**
    //  * Récupérer la preuve de livraison
    //  */
    // public function getPreuveLivraisonAttribute($value)
    // {
    //     if (is_null($value)) {
    //         return [];
    //     }
        
    //     if (is_string($value)) {
    //         $decoded = json_decode($value, true);
    //         return is_array($decoded) ? $decoded : [];
    //     }
        
    //     return is_array($value) ? $value : [];
    // }

    // /**
    //  * Définir la preuve de livraison
    //  */
    // public function setPreuveLivraisonAttribute($value)
    // {
    //     if (is_string($value) && json_decode($value) !== null) {
    //         $this->attributes['preuve_livraison'] = $value;
    //         return;
    //     }
        
    //     if (is_array($value)) {
    //         $this->attributes['preuve_livraison'] = json_encode($value, JSON_UNESCAPED_UNICODE);
    //         return;
    //     }
        
    //     $this->attributes['preuve_livraison'] = '[]';
    // }

    // /**
    //  * Récupérer l'adresse de livraison
    //  */
    // public function getAdresseLivraisonAttribute($value)
    // {
    //     if (is_null($value)) {
    //         return [];
    //     }
        
    //     if (is_string($value)) {
    //         $decoded = json_decode($value, true);
    //         return is_array($decoded) ? $decoded : ['adresse' => $value];
    //     }
        
    //     return is_array($value) ? $value : [];
    // }

    // /**
    //  * Définir l'adresse de livraison
    //  */
    // public function setAdresseLivraisonAttribute($value)
    // {
    //     if (is_string($value)) {
    //         // Si c'est une chaîne, on la stocke comme adresse simple
    //         $this->attributes['adresse_livraison'] = json_encode(['adresse' => $value], JSON_UNESCAPED_UNICODE);
    //         return;
    //     }
        
    //     if (is_array($value)) {
    //         $this->attributes['adresse_livraison'] = json_encode($value, JSON_UNESCAPED_UNICODE);
    //         return;
    //     }
        
    //     $this->attributes['adresse_livraison'] = '{}';
    // }

    // =============================================
    // MÉTHODES MÉTIER
    // =============================================
    

    /**
     * Ajouter un historique de statut (VERSION SIMPLIFIÉE)
     */
       public function ajouterHistorique(
    string $statut,
    string $description
): void {

    $historique = $this->historique_statuts ?? [];

    $historique[] = [
        'date' => now()->toDateTimeString(),
        'statut' => $statut,
        'description' => $description,
    ];

    $this->historique_statuts = $historique;

    $this->save();
}
    /**
     * Marquer la livraison comme prise en charge
     */
    public function marquerPriseEnCharge(): void
    {
        $this->statut = 'PRISE_EN_CHARGE';
        $this->date_pris_en_charge = now();
        $this->save();
        
        $this->ajouterHistorique('PRISE_EN_CHARGE', 'Livraison prise en charge');
    }

    /**
     * Marquer la livraison comme livrée
     */
    public function marquerLivree(array $preuve = []): void
    {
        $this->statut = 'LIVREE';
        $this->date_livraison_reelle = now();
        
        // Mettre à jour la preuve
        $preuveExistante = $this->preuve_livraison;
        $preuveExistante = array_merge($preuveExistante, $preuve);
        $this->attributes['preuve_livraison'] = json_encode($preuveExistante, JSON_UNESCAPED_UNICODE);
        
        $this->save();
        
        $this->ajouterHistorique('LIVREE', 'Livraison effectuée avec succès');
    }

    /**
     * Marquer la livraison comme échouée
     */
    public function marquerEchec(string $motif): void
    {
        $this->statut = 'ECHOUEE';
        $this->motif_echec = $motif;
        $this->save();
        
        $this->ajouterHistorique('ECHOUEE', "Échec de livraison: {$motif}");
    }

    /**
     * Annuler la livraison
     */
    public function annuler(string $motif = null): void
    {
        if (in_array($this->statut, ['LIVREE', 'ANNULEE'])) {
            return;
        }
    
        $this->statut = 'ANNULEE';
    
        $this->ajouterHistorique(
            'ANNULEE',
            $motif
                ? "Livraison annulée : {$motif}"
                : "Livraison annulée suite à l'annulation de la commande"
        );
    
        $this->save();
    }

    /**
     * Mettre à jour le numéro de suivi
     */
    public function mettreAJourSuivi(string $numeroSuivi, ?string $transporteur = null): void
    {
        $this->numero_suivi = $numeroSuivi;
        if ($transporteur) {
            $this->transporteur = $transporteur;
        }
        $this->save();
        
        $this->ajouterHistorique('SUIVI_MIS_A_JOUR', "Numéro de suivi: {$numeroSuivi}" . ($transporteur ? " - {$transporteur}" : ''));
    }

    /**
     * Récupérer le dernier statut
     */
    public function getDernierStatutAttribute(): ?array
    {
        $historique = $this->historique_statuts;
        return !empty($historique) ? end($historique) : null;
    }
}