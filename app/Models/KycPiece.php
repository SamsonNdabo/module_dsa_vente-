<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KycPiece extends Model
{
    protected $table = 'kyc_pieces';

    protected $fillable = [
        'kyc_dossier_id', 'type_document', 'numero', 'date_expiration',
        'fichier_ref', 'mime_type', 'taille_octets',
        'statut', 'validee_at', 'validee_par', 'motif_rejet',
    ];

    protected $hidden = ['fichier_ref'];

    protected $casts = [
        'date_expiration' => 'date',
        'validee_at'      => 'datetime',
    ];

    /* ---------------- Constantes ---------------- */

    /** Types de pièces autorisés (utilisés pour la validation). */
    public const TYPES = [
        'CARTE_IDENTITE',
        'PASSEPORT',
        'CARTE_ELECTEUR',
        'PERMIS',
        'AUTRE_PIECE',
        'PHOTO_CLIENT',
        'JUSTIFICATIF_ADRESSE',
    ];

    /** Types acceptés dans une règle KYC (IDENTITE + SIGNATURE en plus). */
    public const TYPES_REGLE = [
        'CARTE_IDENTITE',
        'PASSEPORT',
        'CARTE_ELECTEUR',
        'PERMIS',
        'AUTRE_PIECE',
        'PHOTO_CLIENT',
        'JUSTIFICATIF_ADRESSE',
        'IDENTITE',
        'SIGNATURE',
    ];

    public const STATUTS = ['EN_ATTENTE', 'VALIDE', 'REJETE'];

    /* ---------------- Relations ---------------- */

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(KycDossier::class, 'kyc_dossier_id');
    }

    public function valideur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'validee_par');
    }

    /* ---------------- Helpers ---------------- */

    public function estValable(?int $validiteJours = null): bool
    {
        if ($this->date_expiration && $this->date_expiration->copy()->endOfDay()->isPast()) {
            return false;
        }
        if ($validiteJours) {
            $ref = $this->validee_at ?? $this->created_at;
            if ($ref && $ref->copy()->addDays($validiteJours)->isPast()) {
                return false;
            }
        }
        return true;
    }
}