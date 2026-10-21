<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientDsa extends Model
{
    use SoftDeletes;

    protected $table = 'clients_dsa';

    protected $fillable = [
        'client_id',
        'agent_dsa_id',
        'zone_id',
        'code_client',
        'nom_complet',
        'sexe',
        'date_naissance',
        'telephone',
        'telephone_normalise',
        'email',
        'adresse',
        'profession',
        'activite_economique',
        'piece_identite_type',
        'piece_identite_numero',
        'kyc_statut',
        'statut',
        'source',
        'local_uuid',
        'metadata',
        'cree_par',
        'modifie_par',
    ];

    protected $casts = [
        'date_naissance' => 'date',
        'metadata'       => 'array',
    ];

    /* ---------------- Constantes ---------------- */

    public const SEXES   = ['M', 'F'];
    public const STATUTS = ['ACTIF', 'INACTIF', 'BLOQUE'];
    public const KYC_STATUTS = ['AUCUN', 'EN_ATTENTE', 'EN_COURS', 'VALIDE', 'REJETE'];
    public const SOURCES = ['DSA', 'POS', 'IMPORT'];

    /* ---------------- Relations ---------------- */

    public function clientPos(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function agentDsa(): BelongsTo
    {
        return $this->belongsTo(AgentDsa::class, 'agent_dsa_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'zone_id');
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'cree_par');
    }

    public function modificateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'modifie_par');
    }

    /* ---------------- Accessors ---------------- */

    public function getSexeLabelAttribute(): string
    {
        return match ($this->sexe) {
            'M' => 'Masculin',
            'F' => 'Féminin',
            default => '—',
        };
    }

    public function getStatutLabelAttribute(): string
    {
        return match ($this->statut) {
            'ACTIF'   => 'Actif',
            'INACTIF' => 'Inactif',
            'BLOQUE'  => 'Bloqué',
            default   => $this->statut,
        };
    }

    public function getKycStatutLabelAttribute(): string
    {
        return match ($this->kyc_statut) {
            'AUCUN'       => 'Aucun KYC',
            'EN_ATTENTE'  => 'En attente',
            'EN_COURS'    => 'En cours',
            'VALIDE'      => 'Validé',
            'REJETE'      => 'Rejeté',
            default       => $this->kyc_statut,
        };
    }

    /* ---------------- Scopes ---------------- */

    public function scopeActifs($q)
    {
        return $q->where('statut', 'ACTIF');
    }

    public function scopeKycValide($q)
    {
        return $q->where('kyc_statut', 'VALIDE');
    }

    public function scopeKycNonValide($q)
    {
        return $q->whereIn('kyc_statut', ['AUCUN', 'EN_ATTENTE', 'EN_COURS', 'REJETE']);
    }

    public function scopePourAgent($q, int $agentId)
    {
        return $q->where('agent_dsa_id', $agentId);
    }

    public function scopeRecherche($q, string $term)
    {
        $t = '%' . $term . '%';
        return $q->where(function ($w) use ($t) {
            $w->where('nom_complet', 'like', $t)
              ->orWhere('code_client', 'like', $t)
              ->orWhere('telephone', 'like', $t)
              ->orWhere('telephone_normalise', 'like', $t)
              ->orWhere('email', 'like', $t);
        });
    }
}