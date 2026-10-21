<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JournalMouvementCaisse extends Model
{
    protected $table = 'journal_mouvements_caisse';

    protected $fillable = [
        'caisse_id',
        'devise_id',
        'type_mouvement',
        'id_type',
        'montant',
        'solde_avant',
        'solde_apres',
        'reference',
        'description',
        'utilisateur_id',
    ];

    protected $casts = [
        'montant'     => 'decimal:4',
        'solde_avant' => 'decimal:4',
        'solde_apres' => 'decimal:4',
    ];

    // ============================================================
    // RELATIONS
    // ============================================================

    public function caisse()
    {
        return $this->belongsTo(Caisses::class, 'caisse_id');
    }

    public function devise()
    {
        return $this->belongsTo(Devise::class, 'devise_id');
    }

    public function utilisateur()
    {
        return $this->belongsTo(Utilisateur::class, 'utilisateur_id');
    }

    // ============================================================
    // LOGIQUE MÉTIER
    // ============================================================

    public static function enregistrer(
        int     $caisseId,
        int     $deviseId,
        string  $typeMouvement,
        float   $montant,
        float   $soldeAvant,
        float   $soldeApres,
        ?string $reference     = null,
        ?string $description   = null,
        ?int    $utilisateurId = null,
        ?int $idType = null
    ): self {
        return self::create([
            'caisse_id'      => $caisseId,
            'devise_id'      => $deviseId,
            'type_mouvement' => $typeMouvement,
            'id_type'        => $idType,
            'montant'        => $montant,
            'solde_avant'    => $soldeAvant,
            'solde_apres'    => $soldeApres,
            'reference'      => $reference,
            'description'    => $description,
            'utilisateur_id' => $utilisateurId,
        ]);
    }
}