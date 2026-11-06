<?php

namespace App\Models\Partenaire;

use Illuminate\Database\Eloquent\Model;

class PartenaireProduit extends Model
{
    protected $table = 'partenaire_produits';
    
    // Désactivé car on utilise 'date_ajout' 
    public $timestamps = false; 

    protected $fillable = [
        'id_partenaire',
        'type_produit',
        'id_produit',
        'actif'
    ];

    protected $casts = [
        'actif' => 'boolean',
        'date_ajout' => 'datetime',
    ];

    /**
     * Relation vers le partenaire
     */
    public function partenaire()
    {
        // On précise le namespace complet car Partenaire est peut-être aussi dans ce dossier
        return $this->belongsTo(Partenaire::class, 'id_partenaire');
    }
}