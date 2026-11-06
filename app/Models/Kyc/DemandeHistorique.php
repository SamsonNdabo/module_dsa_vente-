<?php
namespace App\Models\Kyc;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemandeHistorique extends Model
{
    protected $table = 'demande_historiques';

    protected $fillable = [
        'demande_id',
        'ancien_statut',
        'nouveau_statut',
        'motif',
        'effectue_par',
        'systeme',
    ];

    protected $casts = [
        'systeme' => 'boolean',
    ];

    public function demande(): BelongsTo
    {
        return $this->belongsTo(Demande::class, 'demande_id');
    }

    public function acteur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'effectue_par');
    }
}