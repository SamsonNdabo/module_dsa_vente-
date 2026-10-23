<?php
// app/Models/KycDossier.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KycDossier extends Model
{
    protected $table = 'kyc_dossiers';

    protected $fillable = [
        'demande_id', 'client_id', 'statut', 'signature_ref',
        'decision_at', 'decision_par',
    ];

    protected $casts = ['decision_at' => 'datetime'];

    public function demande(): BelongsTo
    {
        return $this->belongsTo(Demande::class, 'demande_id');
    }

    // Relation client
    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');   // ← était ClientDsa
    }

    public function pieces(): HasMany
    {
        return $this->hasMany(KycPiece::class, 'kyc_dossier_id');
    }

    public function decideur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'decision_par');
    }
}