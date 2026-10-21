<?php

namespace App\Models\PlainteSuggestion;

use App\Models\Utilisateur;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SuggestionPieceJointe extends Model
{
    use HasFactory;

    protected $table = 'suggestions_pieces_jointes';

    protected $fillable = [
        'suggestion_id', 'nom_fichier', 'chemin',
        'type_mime', 'taille_ko', 'uploaded_by',
    ];

    protected $casts = [
        'taille_ko' => 'integer',
    ];

    public function suggestion(): BelongsTo
    {
        return $this->belongsTo(Suggestion::class, 'suggestion_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'uploaded_by');
    }
}