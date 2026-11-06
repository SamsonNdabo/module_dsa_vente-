<?php

namespace App\Models\PlainteSuggestion;

use App\Models\Utilisateur;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SuggestionHistorique extends Model
{
    use HasFactory;

    protected $table = 'suggestions_historiques';

    //  On garde created_at automatique, mais PAS updated_at
    const UPDATED_AT = null;

    protected $fillable = [
        'suggestion_id',
        'utilisateur_id',
        'action',
        'ancien_statut',
        'nouveau_statut',
        'ancien_utilisateur_id',
        'nouveau_utilisateur_id',
        'commentaire',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    // ============================================================
    // CONSTANTES D'ACTIONS
    // ============================================================
    public const ACTION_CREATION          = 'CREATION';
    public const ACTION_AFFECTATION       = 'AFFECTATION';
    public const ACTION_CHANGEMENT_STATUT = 'CHANGEMENT_STATUT';
    public const ACTION_COMMENTAIRE       = 'COMMENTAIRE';
    public const ACTION_RESOLUTION        = 'RESOLUTION';
    public const ACTION_REJET             = 'REJET';

    // ============================================================
    // RELATIONS
    // ============================================================
    public function suggestion(): BelongsTo
    {
        return $this->belongsTo(Suggestion::class, 'suggestion_id');
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'utilisateur_id');
    }

    public function ancienUtilisateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'ancien_utilisateur_id');
    }

    public function nouveauUtilisateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'nouveau_utilisateur_id');
    }
}