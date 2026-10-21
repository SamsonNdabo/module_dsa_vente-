<?php
// app/Models/RfqLigne.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RfqLigne extends Model
{
    use SoftDeletes;

    protected $table = 'rfq_lignes';

    protected $fillable = [
        'rfq_id',
        'produit_id',
        'libelle',
        'quantite',
        'unite',
        'date_livraison_souhaitee',
        'notes'
    ];

    protected $casts = [
        'quantite' => 'integer',
        'date_livraison_souhaitee' => 'date'
    ];

    public function rfq()
    {
        return $this->belongsTo(RfqFournisseur::class, 'rfq_id');
    }

    public function produit()
    {
        return $this->belongsTo(Produit::class, 'produit_id');
    }
}