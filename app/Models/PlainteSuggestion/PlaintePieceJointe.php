<?php

namespace App\Models\PlainteSuggestion;

use App\Models\Utilisateur;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlaintePieceJointe extends Model
{
    use HasFactory;

    protected $table = 'plaintes_pieces_jointes';

    protected $fillable = [
        'plainte_id', 'nom_fichier', 'chemin',
        'type_mime', 'taille_ko', 'uploaded_by',
    ];

    protected $casts = [
        'taille_ko' => 'integer',
    ];

    public function plainte(): BelongsTo
    {
        return $this->belongsTo(Plainte::class, 'plainte_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'uploaded_by');
    }
}