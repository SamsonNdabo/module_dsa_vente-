<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivationSim extends Model
{
    protected $table = 'activations_sim';

    protected $fillable = [
        'reference', 'client_id', 'agent_dsa_id', 'produit_id',
        'facture_id', 'commande_id',
        'identifiant',
        'preuve_fichier_ref', 'preuve_mime_type', 'preuve_taille_octets',
        'preuve_uploaded_by',
        'statut', 'activated_at',
        'notes',
    ];

    protected $casts = [
        'activated_at'         => 'datetime',
        'preuve_taille_octets' => 'integer',
    ];

    public const STATUTS = ['ACTIVEE', 'ANNULEE'];

    /* ─── Relations ─── */

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function agentDsa(): BelongsTo
    {
        return $this->belongsTo(AgentDsa::class, 'agent_dsa_id');
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class, 'produit_id');
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class, 'facture_id');
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(CommandeClient::class, 'commande_id');
    }

    public function preuveUploadeePar(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'preuve_uploaded_by');
    }

    /* ─── Helpers ─── */

    public function estActivee(): bool
    {
        return $this->statut === 'ACTIVEE';
    }

    public function aPreuve(): bool
    {
        return ! empty($this->preuve_fichier_ref);
    }

    /* ─── Scopes ─── */

    public function scopeActivees($q)         { return $q->where('statut', 'ACTIVEE'); }
    public function scopePourClient($q, $id)  { return $q->where('client_id', $id); }
    public function scopePourAgent($q, $id)   { return $q->where('agent_dsa_id', $id); }
}