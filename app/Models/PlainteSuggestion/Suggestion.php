<?php

namespace App\Models\PlainteSuggestion;

use App\Models\Client;
use App\Models\Utilisateur;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Suggestion extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'suggestions';

    protected $fillable = [
        'reference', 'client_id', 'categorie', 'utilisateur_id',
        'objet', 'description', 'priorite', 'statut', 'zone',
        'latitude', 'longitude', 'adresse_gps',
        'date_affectation', 'date_resolution',
        'delai_resolution_heures', 'commentaire_resolution',
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'date_affectation' => 'datetime',
        'date_resolution' => 'datetime',
        'delai_resolution_heures' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public const STATUT_NOUVELLE = 'NOUVELLE';
    public const STATUT_EN_COURS = 'EN_COURS';
    public const STATUT_RESOLUE  = 'RESOLUE'; // = acceptée / mise en oeuvre
    public const STATUT_REJETEE  = 'REJETEE';

    public const PRIORITE_BASSE    = 'BASSE';
    public const PRIORITE_NORMALE  = 'NORMALE';
    public const PRIORITE_HAUTE    = 'HAUTE';
    public const PRIORITE_CRITIQUE = 'CRITIQUE';

    // -------------------------
    // Relations
    // -------------------------
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'utilisateur_id');
    }

    public function piecesJointes(): HasMany
    {
        return $this->hasMany(SuggestionPieceJointe::class, 'suggestion_id');
    }

    public function historiques(): HasMany
    {
        return $this->hasMany(SuggestionHistorique::class, 'suggestion_id')
                    ->orderBy('created_at', 'desc');
    }

    // -------------------------
    // Scopes
    // -------------------------
    public function scopeOuvertes($query)
    {
        return $query->whereIn('statut', [self::STATUT_NOUVELLE, self::STATUT_EN_COURS]);
    }

    public function scopeCloturees($query)
    {
        return $query->whereIn('statut', [self::STATUT_RESOLUE, self::STATUT_REJETEE]);
    }

    public function scopeParZone($query, string $zone)
    {
        return $query->where('zone', $zone);
    }

    public function scopeParAgent($query, int $utilisateurId)
    {
        return $query->where('utilisateur_id', $utilisateurId);
    }
}