<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Campagne\Campagne;

class Zone extends Model
{
    use HasFactory;

    protected $table = 'zones';

    protected $fillable = [
        'code',
        'nom',
        'region',
        'ville',
        'superviseur_id',
        'coordonnees_centre',
        'rayon_km',
        'actif',
    ];

    protected $casts = [
        'coordonnees_centre' => 'array',
        'rayon_km'           => 'decimal:2',
        'actif'              => 'boolean',
    ];

    /* ============================================================
       RELATIONS
       ============================================================ */

    /**
     * Le superviseur de la zone (pointe directement vers la table utilisateurs)
     */
    public function superviseur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'superviseur_id');
    }

    public function agentsDsa(): HasMany
    {
        return $this->hasMany(AgentDsa::class, 'zone_id');
    }

    public function campagnes(): BelongsToMany
    {
        return $this->belongsToMany(Campagne::class, 'campagne_zones', 'id_zone', 'id_campagne')
            ->withTimestamps();
    }

    /* ============================================================
       SCOPES
       ============================================================ */

    public function scopeActives($query)
    {
        return $query->where('actif', true);
    }

    public function scopeRecherche($query, $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('nom', 'like', "%{$search}%")
              ->orWhere('code', 'like', "%{$search}%")
              ->orWhere('region', 'like', "%{$search}%")
              ->orWhere('ville', 'like', "%{$search}%");
        });
    }
}