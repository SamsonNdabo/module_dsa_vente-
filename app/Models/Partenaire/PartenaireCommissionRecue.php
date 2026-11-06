<?php

namespace App\Models\Partenaire;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Campagne\Campagne;
use App\Models\Partenaire\Partenaire;

class PartenaireCommissionRecue extends Model
{
    use HasFactory;

    protected $table = 'partenaire_commissions_recues';

    protected $fillable = [
        'id_partenaire',
        'id_campagne',
        'periode_debut',
        'periode_fin',
        'montant_theorique',
        'montant_confirme',
        'statut',
        'date_rapprochement',
        'commentaire',
    ];

    protected $casts = [
        'periode_debut' => 'date',
        'periode_fin' => 'date',
        'date_rapprochement' => 'datetime',
        'montant_theorique' => 'decimal:2',
        'montant_confirme' => 'decimal:2',
        'ecart' => 'decimal:2',
    ];

    // --- RELATIONS ---

    public function partenaire(): BelongsTo
    {
        return $this->belongsTo(Partenaire::class, 'id_partenaire');
    }

    public function campagne(): BelongsTo
    {
        return $this->belongsTo(Campagne::class, 'id_campagne');
    }
}