<?php
// app/Models/KycDocument.php

namespace App\Models\Kyc;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class KycDocument extends Model
{
    use HasFactory;

    protected $table = 'kyc_documents';

    const TYPES = ['CIN', 'PASSEPORT', 'ATTESTATION'];
    const STATUTS = ['EN_ATTENTE', 'VERIFIE', 'REJETE'];

    protected $fillable = [
        'client_id',
        'type_document',
        'numero_document',
        'file_path',
        'statut',
        'motif_rejet',
        'verifie_par_id'
    ];

    protected $appends = ['file_url', 'statut_label'];

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function verifiePar()
    {
        return $this->belongsTo(Utilisateur::class, 'verifie_par_id');
    }

    public function getFileUrlAttribute(): ?string
    {
        if (!$this->file_path) return null;
        return Storage::url($this->file_path);
    }

    public function getStatutLabelAttribute(): string
    {
        $labels = [
            'EN_ATTENTE' => 'En attente',
            'VERIFIE' => 'Vérifié',
            'REJETE' => 'Rejeté'
        ];
        return $labels[$this->statut] ?? $this->statut;
    }

    public function verifier(int $userId, ?string $motifRejet = null): void
    {
        if ($motifRejet) {
            $this->statut = 'REJETE';
            $this->motif_rejet = $motifRejet;
        } else {
            $this->statut = 'VERIFIE';
            $this->motif_rejet = null;
        }
        
        $this->verifie_par_id = $userId;
        $this->save();
    }

    public function scopeEnAttente($query)
    {
        return $query->where('statut', 'EN_ATTENTE');
    }

    public function scopeVerifies($query)
    {
        return $query->where('statut', 'VERIFIE');
    }
}