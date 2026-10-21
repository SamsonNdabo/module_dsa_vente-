<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransfertInternational extends Model
{
    protected $table      = 'transfert_international';
    public    $timestamps = false;

    const TYPE_ENVOI = 'ENVOI';
    const TYPE_PAIEMENT = 'PAIEMENT';
    
    protected $fillable = [
        'reference_transfert',
        'operateur_transfert_id',
        'type_operation',
        'montant_principal',
        'devise_origine',
        'devise_destination',
        'montant_converti',
        'taux_change',
        'frais_operateur',
        'montant_total',
        'code_secret',
        'informations_expediteur',
        'informations_destinataire',
        'piece_identite_type',
        'piece_identite_numero',
        'piece_identite_fichier',
        'statut_transfert',
        'date_envoi',
        'date_reception',
        'date_expiration',
        'succursale_id',
        'caisse_id',
        'caisse_physique_id',
        'caissier_id',
        'superviseur_id',
    ];

    protected $casts = [
        'montant_principal'         => 'decimal:4',
        'montant_converti'          => 'decimal:4',
        'taux_change'               => 'decimal:4',
        'frais_operateur'           => 'decimal:4',
        'montant_total'             => 'decimal:4',
        'informations_expediteur'   => 'array',
        'informations_destinataire' => 'array',
        'date_envoi'                => 'datetime',
        'date_reception'            => 'datetime',
        'date_expiration'           => 'datetime',
        'date_creation'             => 'datetime',
    ];

    // ============================================================
    // RELATIONS
    // ============================================================

    public function operateur()
    {
        return $this->belongsTo(OperateursInternational::class, 'operateur_transfert_id');
    }

    public function succursale()
    {
        return $this->belongsTo(Succursale::class, 'succursale_id');
    }

    public function caisse()
    {
        return $this->belongsTo(Caisses::class, 'caisse_id');
    }

    public function caissier()
    {
        return $this->belongsTo(Utilisateur::class, 'caissier_id');
    }

    public function superviseur()
    {
        return $this->belongsTo(Utilisateur::class, 'superviseur_id');
    }

    // ============================================================
    // SCOPES
    // ============================================================

    public function scopeEnvoi($query)
    {
        return $query->where('type_operation', 'ENVOI');
    }

    public function scopeReception($query)
    {
        return $query->where('type_operation', 'RECEPTION');
    }

    public function scopeParStatut($query, string $statut)
    {
        return $query->where('statut_transfert', $statut);
    }

    public function scopeExpire($query)
    {
        return $query->where('date_expiration', '<', now())
                     ->whereNotIn('statut_transfert', ['RECU', 'ANNULE', 'REMBOURSE']);
    }

    // ============================================================
    // LOGIQUE MÉTIER
    // ============================================================

    // Générer une référence unique
    public static function genererReference(string $codeOperateur): string
    {
        $year     = date('Y');
        $month    = date('m');
        $nextId   = (self::max('id') ?? 0) + 1;
        $paddedId = str_pad($nextId, 5, '0', STR_PAD_LEFT);

        return "TRF-{$codeOperateur}-{$year}-{$month}-{$paddedId}";
    }

    // Vérifier si le transfert peut passer à l'étape suivante
    public function peutPasser(string $versStatut): bool
    {
        $transitions = [
            'PAYE'       => ['INITIE'],
            'DISPONIBLE' => ['PAYE'],
            'RECU'       => ['DISPONIBLE'],
        ];

        return \in_array($this->statut_transfert, $transitions[$versStatut] ?? []);
    }

    // Vérifier si le transfert est expiré
    public function estExpire(): bool
    {
        return $this->date_expiration !== null
            && \Carbon\Carbon::parse($this->date_expiration)->isPast()
            && !\in_array($this->statut_transfert, ['RECU', 'ANNULE', 'REMBOURSE']);
    }

    // Calculer le montant total avec frais
    public static function calculerTotal(float $montant, float $frais): float
    {
        return round($montant + $frais, 4);
    }
}