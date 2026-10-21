<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Zone extends Model
{
    protected $table = 'zones';

    protected $fillable = [
        'code', 'nom', 'region', 'ville', 'superviseur_id',
        'coordonnees_centre', 'rayon_km', 'actif',
    ];

    protected $casts = [
        'coordonnees_centre' => 'array',
        'rayon_km' => 'decimal:2',
        'actif' => 'boolean',
    ];

    public function superviseur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'superviseur_id');
    }

    public function agents(): HasMany
    {
        return $this->hasMany(AgentDsa::class, 'zone_id');
    }
}