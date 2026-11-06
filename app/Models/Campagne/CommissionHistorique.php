<?php

namespace App\Models\Campagne;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Utilisateur;

class CommissionHistorique extends Model
{
    use HasFactory;

    protected $table = 'commission_historique';

    protected $fillable = [
        'id_commission',
        'ancien_statut',
        'nouveau_statut',
        'id_utilisateur',
        'commentaire',
        'date_action',
    ];

    protected $casts = [
        'date_action' => 'datetime',
    ];

    public function commission(): BelongsTo
    {
        return $this->belongsTo(CommissionGeneree::class, 'id_commission');
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'id_utilisateur');
    }
}