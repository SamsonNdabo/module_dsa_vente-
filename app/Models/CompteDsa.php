<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompteDsa extends Model
{
    protected $table = 'comptes_dsa';

    protected $fillable = [
        'numero_dossier', 'demande_id', 'client_id', 'produit_dsa_id',
        'partenaire_id', 'agent_dsa_id',
        'numero_compte', 'reference_partenaire', 'statut',
        'devise', 'solde',
        'date_ouverture', 'date_activation', 'motif_rejet',
        'metadata',
    ];

    protected $casts = [
        'solde'            => 'decimal:2',
        'date_ouverture'   => 'date',
        'date_activation'  => 'date',
        'metadata'         => 'array',
    ];

    public function demande(): BelongsTo
    {
        return $this->belongsTo(Demande::class, 'demande_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(ProduitDsa::class, 'produit_dsa_id');
    }

    public function partenaire(): BelongsTo
    {
        return $this->belongsTo(Partenaire::class, 'partenaire_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(AgentDsa::class, 'agent_dsa_id');
    }
}