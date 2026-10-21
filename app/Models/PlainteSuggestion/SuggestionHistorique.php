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

    public $timestamps = false;

    protected $fillable = [
        'suggestion_id', 'utilisateur_id', 'action',
        'ancien_statut', 'nouveau_statut',
        'ancien_utilisateur_id', 'nouveau_utilisateur_id',
        'commentaire', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public const ACTION_CREATION          = 'CREATION';
    public const ACTION_AFFECTATION       = 'AFFECTATION';
    public const ACTION_CHANGEMENT_STATUT = 'CHANGEMENT_STATUT';
    public const ACTION_COMMENTAIRE       = 'COMMENTAIRE';
    public const ACTION_RESOLUTION        = 'RESOLUTION';
    public const ACTION_REJET             = 'REJET';

    // -------------------------
    // Relations
    // -------------------------
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