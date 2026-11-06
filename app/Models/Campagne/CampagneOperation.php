<?php

namespace App\Models\Campagne;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Agent;
use App\Models\Produit;

class CampagneOperation extends Model
{
    use HasFactory;

    protected $table = 'campagne_operations';

    protected $fillable = [
        'id_campagne',
        'type_operation',
        'id_operation_source',
        'id_agent',
        'id_produit',
        'montant_operation',
        'quantite_operation',
        'date_operation',
        'compte_dans_objectif',
    ];

    protected $casts = [
        'date_operation' => 'datetime',
        'compte_dans_objectif' => 'boolean',
        'montant_operation' => 'decimal:2',
        'quantite_operation' => 'integer',
    ];

    // ============================================================
    // RELATIONS
    // ============================================================

    public function campagne(): BelongsTo
    {
        return $this->belongsTo(Campagne::class, 'id_campagne');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'id_agent');
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class, 'id_produit');
    }

    // ============================================================
    // RELATION POLYMORPHE (OPTIONNEL MAIS RECOMMANDÉ)
    // ============================================================
    // Permet de récupérer directement l'objet source (Client, Vente, Visite...)
    // en fonction du type_operation.
    
    public function source()
    {
        return match ($this->type_operation) {
            'CREATION_CLIENT' => $this->belongsTo(\App\Models\Client::class, 'id_operation_source'),
            'VENTE'           => $this->belongsTo(\App\Models\Vente::class, 'id_operation_source'),
            'VISITE'          => $this->belongsTo(\App\Models\Visite::class, 'id_operation_source'),
            // Ajoutez d'autres cas au besoin (PLAINTE, SUGGESTION, etc.)
            default           => null,
        };
    }
}