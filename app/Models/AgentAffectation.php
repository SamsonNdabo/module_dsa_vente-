<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentAffectation extends Model
{
    protected $table = 'agent_affectations';

    public $timestamps = false; // ta table n'a pas created_at/updated_at

    protected $fillable = [
        'agent_dsa_id', 'zone_id', 'superviseur_id',
        'campagne_id', 'partenaire_id',
        'date_debut', 'date_fin', 'effectue_par',
    ];

    protected $casts = [
        'date_debut' => 'datetime',
        'date_fin'   => 'datetime',
    ];

    /* ---------------- Relations ---------------- */

    public function agent(): BelongsTo
    {
        return $this->belongsTo(AgentDsa::class, 'agent_dsa_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'zone_id');
    }

    public function superviseur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'superviseur_id');
    }

    public function campagne(): BelongsTo
    {
        return $this->belongsTo(Campagne::class, 'campagne_id');
    }

    public function partenaire(): BelongsTo
    {
        return $this->belongsTo(Partenaire::class, 'partenaire_id');
    }

    public function acteur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'effectue_par');
    }

    /* ---------------- Scopes ---------------- */

    public function scopeEnCours($q)
    {
        return $q->whereNull('date_fin');
    }

    public function scopeHistorique($q)
    {
        return $q->whereNotNull('date_fin');
    }
}