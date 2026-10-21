<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Taux_Change_Succursale;
use App\Models\Devise;
use App\Models\Utilisateur;

class TauxChange extends Model
{
    protected $table = 'taux_change';

    protected $fillable = [
        'devise_source',
        'devise_cible',
        'taux_achat',
        'taux_vente',
        'ancien_taux_achat',
        'ancien_taux_vente',
        'date_taux',
        'modifie_par',
        'motif',
    ];

    protected $casts = [
        'taux_achat'        => 'decimal:4',
        'taux_vente'        => 'decimal:4',
        'ancien_taux_achat' => 'decimal:4',
        'ancien_taux_vente' => 'decimal:4',
        'date_taux'         => 'date',
    ];

    // ============================================================
    // RELATIONS
    // ============================================================

    public function deviseSource()
    {
        return $this->belongsTo(Devise::class, 'devise_source');
    }

    public function deviseCible()
    {
        return $this->belongsTo(Devise::class, 'devise_cible');
    }

    public function modificateur()
    {
        return $this->belongsTo(Utilisateur::class, 'modifie_par');
    }

    // ============================================================
    // LOGIQUE MÉTIER
    // ============================================================

    public static function convertir(
        string $deviseSourceId,
        string $deviseCibleId,
        float  $montant,
        int   $succursaleId
    ): ?array {

        // Même devise
        if ($deviseSourceId === $deviseCibleId) {
            return [
                'montant_initial'  => $montant,
                'montant_converti' => $montant,
                'taux_achat'       => 1,
                'taux_vente'       => 1,
                'taux_utilise'     => 1,
                'type_taux'        => null,
                'sens'             => 'identique',
                'source'           => 'identique',
            ];
        }

        // Utilise la cascade complète : caisse non renseignée (null) → succursale (si fournie) → global
        [$tauxAchat, $tauxVente, $sens] = \App\Models\OperationChange::getTauxApplicables(
            (int) $deviseSourceId,
            (int) $deviseCibleId,
            null,
            $succursaleId,
            null, null
        );

        if ($tauxAchat == 0 || $tauxVente == 0 || $sens === null) {
            return null;
        }

        $montantConverti = ($sens === 'direct')
            ? round($montant / $tauxVente, 4)
            : round($montant * $tauxAchat, 4);

        return [
            'montant_initial'  => $montant,
            'montant_converti' => $montantConverti,
            'taux_achat'       => $tauxAchat,
            'taux_vente'       => $tauxVente,
            'taux_utilise'     => ($sens === 'direct') ? $tauxVente : $tauxAchat,
            'type_taux'        => ($sens === 'direct') ? 'vente' : 'achat',
            'sens'             => $sens,
            'source'           => 'cascade',
        ];
    }
}