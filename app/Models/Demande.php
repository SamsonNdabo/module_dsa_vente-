<?php

namespace App\Models;

use App\Models\Concerns\PerimetreAgent;
use App\Services\StatutCatalogue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Demande extends Model
{
    use PerimetreAgent;

    protected $table = 'demandes';

    protected $fillable = [
        'reference',
        'type',
        'client_dsa_id',        // ← CORRIGÉ
        'agent_dsa_id',
        'partenaire_id',
        'produit_dsa_id',
        'statut',
        'payload',
        'soumise_at',
        'decidee_at',
        'decidee_par',
        'motif_decision',
        'local_uuid',
        'created_by',
    ];

    protected $casts = [
        'payload'    => 'array',
        'soumise_at' => 'datetime',
        'decidee_at' => 'datetime',
    ];

    /* ---------------- Relations ---------------- */

    public function client(): BelongsTo
    {
        // ← ClientDsa (pas Client POS)
        return $this->belongsTo(ClientDsa::class, 'client_dsa_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(AgentDsa::class, 'agent_dsa_id');
    }

    public function partenaire(): BelongsTo
    {
        return $this->belongsTo(Partenaire::class);
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(ProduitDsa::class, 'produit_dsa_id');
    }

    public function historiques(): HasMany
    {
        return $this->hasMany(DemandeHistorique::class)->latest('id');
    }

    public function kycDossier(): HasOne
    {
        return $this->hasOne(KycDossier::class);
    }

    public function compte(): HasOne
    {
        return $this->hasOne(CompteDsa::class);
    }

    public function decideur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'decidee_par');
    }

    /* ---------------- Helpers ---------------- */

    /** Le caractère terminal vient de statuts_configurables (par partenaire), sinon des valeurs par défaut. */
    public function estTerminale(): bool
    {
        return app(StatutCatalogue::class)->estTerminal($this->type, $this->partenaire_id, $this->statut);
    }

    public function libelleStatut(): string
    {
        return app(StatutCatalogue::class)->libelle($this->type, $this->partenaire_id, $this->statut);
    }
}