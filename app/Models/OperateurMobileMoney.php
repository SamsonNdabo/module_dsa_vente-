<?php

namespace App\Models;

use Illuminate\Validation\ValidationException as LaravelValidationException;
use Illuminate\Database\Eloquent\Model;


class OperateurMobileMoney extends Model
{
    protected $table = 'operateurs_mobile_money';

    protected $fillable = [
        'code',
        'nom',
        'type_gateway',
        'merchant_code',
        'merchant_token',
        'base_url',
        'callback_url',
        'commission_defaut',
        'supporte_mobile',
        'supporte_carte',
        'mode_operation',
        'statut',
        'commission_payin',
        'commission_payout',
        'id_commande',
        'id_facture',
    ];

    protected $casts = [
        'commission_defaut' => 'decimal:4',
        'commission_payin'  => 'decimal:4',
        'commission_payout' => 'decimal:4',
        'supporte_mobile'   => 'boolean',
        'supporte_carte'    => 'boolean',
        // Champs chiffrés automatiquement
        'merchant_token'    => 'encrypted',
        'merchant_code'     => 'encrypted',
        'base_url'          => 'encrypted',
        'callback_url'      => 'encrypted',
    ];

    // ============================================================
    // SCOPES
    // ============================================================

    public function scopeActif($query)
    {
        return $query->where('statut', 'ACTIF');
    }

    public function scopeApi($query)
    {
        return $query->where('mode_operation', 'API');
    }

    public function scopeManuel($query)
    {
        return $query->where('mode_operation', 'MANUEL');
    }

    // ============================================================
    // RELATIONS
    // ============================================================

    public function commandes()
    {
        return $this->hasMany(Commande::class, 'id_operateur_mobile_money');
    }
    
    public function factures()
    {
        return $this->hasMany(Facture::class, 'id_operateur_mobile_money');
    }

    public function succursales()
    {
        return $this->hasMany(Succursale::class, 'id_operateur_mobile_money');
    }
    
    // ============================================================
    // LOGIQUE MÉTIER
    // ============================================================

    public function estActif(): bool
    {
        return $this->statut === 'ACTIF';
    }

    public function estApi(): bool
    {
        return $this->mode_operation === 'API';
    }

    public function supporteMobile(): bool
    {
        return (bool) $this->supporte_mobile;
    }

    public function supporteCarte(): bool
    {
        return (bool) $this->supporte_carte;
    }

    public function getHeader(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->merchant_token,
            'Content-Type'  => 'application/json',
        ];
    }

    // Limite de 400 USD ou l'équivalent en CDF

    public static function limiteTransfert(): float
    {
        $dollar = Devise::getIdByCode('USD');
        $CDF = Devise::getIdByCode('CDF');
        
        // On récupère le taux de change actuel entre USD et CDF
        $taux = TauxChange::where('devise_source', $CDF)
                    ->where('devise_cible', $dollar)
                    ->value('taux_achat');

        // Supposons que 1 USD = 2 800 CDF par défaut
        if (!$taux) {
            throw LaravelValidationException::withMessages(['taux' => 'Le taux de change entre USD et CDF est introuvable veillez contacter l\'administrateur.']);
        }
        return 400 * (float)$taux;
    }

}