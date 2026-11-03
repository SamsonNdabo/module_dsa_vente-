<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompteEpargne extends Model
{
    protected $table = 'comptes_epargne';

    protected $fillable = [
        'numero_compte', 'client_id', 'produit_epargne_id',
        'agent_dsa_id', 'succursale_id', 'partenaire_id', 'devise_id',
        'solde', 'solde_bloque', 'statut',
        'date_ouverture', 'date_cloture', 'date_derniere_operation',
        'created_by',
    ];

    protected $casts = [
        'solde'                   => 'decimal:4',
        'solde_bloque'            => 'decimal:4',
        'date_ouverture'          => 'date',
        'date_cloture'            => 'date',
        'date_derniere_operation' => 'datetime',
    ];

    public const STATUTS = ['OUVERT', 'BLOQUE', 'CLOTURE'];

    /* ─── Relations ─── */

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(ProduitEpargne::class, 'produit_epargne_id');
    }

    public function agentDsa(): BelongsTo
    {
        return $this->belongsTo(AgentDsa::class, 'agent_dsa_id');
    }

    public function succursale(): BelongsTo
    {
        return $this->belongsTo(Succursale::class, 'succursale_id');
    }

    public function partenaire(): BelongsTo
    {
        return $this->belongsTo(Partenaire::class, 'partenaire_id');
    }

    public function devise(): BelongsTo
    {
        return $this->belongsTo(Devise::class, 'devise_id');
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'created_by');
    }

    /* ─── Helpers ─── */

    public function estOuvert(): bool
    {
        return $this->statut === 'OUVERT';
    }
}