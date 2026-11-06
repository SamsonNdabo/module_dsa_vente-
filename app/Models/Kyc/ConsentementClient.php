<?php

namespace App\Models\Kyc;

use App\Models\Client;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsentementClient extends Model
{
    protected $table = 'consentements_client';

    protected $fillable = [
        'client_id',
        'demande_id',
        'type_consentement',
        'version_formulaire',
        'texte_consentement',
        'consenti',
        'date_consentement',
        'ip_address',
        'user_agent',
        'signature_ref',
    ];

    protected $casts = [
        'consenti'          => 'boolean',
        'date_consentement' => 'datetime',
    ];

    public const TYPES = ['KYC_DATA', 'PARTAGE_PARTENAIRE', 'SCORE_CREDIT', 'COMMUNICATION'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function demande(): BelongsTo
    {
        return $this->belongsTo(Demande::class, 'demande_id');
    }
}