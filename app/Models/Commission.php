<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class Commission extends Model
{
    protected $table = 'commission';

    protected $fillable = [
        'id_transaction_global',
        'succursale_id',
        'montant_total',
        'devise_id',
        'module',
        'operateur',
        'caissier_id',
        'statut',
    ];

    protected $casts = [
        'montant_local'        => 'decimal:4',
        'commission_starehe'   => 'float',
        'montant_devise_pivot' => 'decimal:4',
        'date_transaction'     => 'date',
    ];
    // ============================================================
    // RELATIONS
    // ============================================================

    public function transactionGlobale()
    {
        return $this->belongsTo(Transaction_Globale::class, 'id_transaction_global');
    }
    
    public function transaction()
    {
        return $this->belongsTo(Transaction_Globale::class, 'id_transaction_global');
    }

    public function devise()
    {
        return $this->belongsTo(Devise::class, 'devise_id');
    }

    public function services()
    {
        return $this->hasMany(CommissionService::class, 'commission_id');
    }

    public function succursale()
    {
        return $this->belongsTo(Succursale::class, 'succursale_id');
    }
    
    public function caissier()
    {
        return $this->belongsTo(Utilisateur::class, 'caissier_id');
    }
    
    // ============================================================
    // SCOPES
    // ============================================================

    public function scopeEnAttente($query)
    {
        return $query->where('statut', 'EN_ATTENTE');
    }

    public function scopePayee($query)
    {
        return $query->where('statut', 'PAYEE');
    }

    // ============================================================
    // LOGIQUE MÉTIER
    // ============================================================

    /**
     * Crée une commission (avec ses services enfants) pour une transaction
     * Mobile Money / Transfert International / Transfert Bancaire.
     * Retourne null si aucun service n'est applicable.
     */
    public static function creerPourTransaction(
        Transaction_Globale $transaction,
        string $statut = 'EN_ATTENTE'
    ): ?self {
        $services = self::calculerServices($transaction);
        if (empty($services)) {
            return null;
        }

        // ✅ Cast explicite en string (cohérent avec le cast decimal:4)
        $montantTotal = (string) array_sum(array_column($services, 'montant'));

        $meta = self::resoudreMetadonnees($transaction);

        $commission = self::create([
            'id_transaction_global' => $transaction->id,
            'montant_total'         => $montantTotal,  // ✅ string
            'devise_id'             => $transaction->devise_id,
            'statut'                => $statut,
            'succursale_id'         => $transaction->id_succursale,
            'module'                => $meta['module'],
            'operateur'             => $meta['operateur'],
            'caissier_id'           => $meta['caissier_id'],
        ]);

        foreach ($services as &$service) {
            $service['commission_id'] = $commission->id;
        }
        unset($service);
        CommissionService::insert($services);

        $transaction->update([
            'commission_starehe' => $montantTotal,
        ]);

        return $commission->load('services');
    }

    /**
     * Construit la liste des services (configs actives + change éventuel).
     */
    private static function calculerServices(Transaction_Globale $transaction): array
    {
        $services = [];

        // --- Configs actives pour ce type de transaction ---
        $configs = CommissionConfig::getConfigsActives(
            $transaction->type,
            $transaction->id_succursale
        );

        foreach ($configs as $config) {
            $calcul = $config->calculer(
                (float) $transaction->montant_local,
                $config->valeur_effective ?? null
            );

            $services[] = [
                'commission_config_id' => $config->id,
                'service'              => $calcul['service'],
                'pourcentage'          => $calcul['pourcentage'],
                'montant'              => $calcul['montant'],
                'created_at'           => now(),
                'updated_at'           => now(),
            ];
        }

        // --- Commission de change éventuelle ---
        if ($transaction->operation_change_id) {
            $operationChange = OperationChange::find($transaction->operation_change_id);

            if ($operationChange && (float) $operationChange->commission > 0) {
                $tauxAchat   = (float) $operationChange->taux_achat;
                $pourcentage = $tauxAchat > 0
                    ? round((((float) $operationChange->taux_vente - $tauxAchat) / $tauxAchat) * 100, 4)
                    : 0;

                $services[] = [
                    'commission_config_id' => null,
                    'service'              => 'CHANGE',
                    'pourcentage'          => $pourcentage,
                    'montant'              => $operationChange->commission,
                    'created_at'           => now(),
                    'updated_at'           => now(),
                ];
            }
        }

        return $services;
    }

    /**
     * Détermine module / operateur / caissier en fonction du type de transaction.
     */
    private static function resoudreMetadonnees(Transaction_Globale $transaction): array
    {
        $module      = null;
        $operateur   = null;
        $caissierId  = $transaction->id_creator; // fallback

        switch (true) {
            // ---------- MOBILE MONEY ----------
            case in_array($transaction->type, ['MOBILE_MONEY', 'MOBILE_MONEY_RETRAIT'], true):
                $module = 'TRANSACTION';

                $mobile = Transaction_Mobile::where('id_transaction_global', $transaction->id)->first();

                if ($mobile) {
                    $operateur = Operateur_Mobile::getNameById($mobile->id_operateur_mobile);
                    $caissierId  = $mobile->id_creator ?? $caissierId;
                } else {
                    Log::warning("Commission : aucune Transaction_Mobile pour id_transaction_global={$transaction->id}");
                }
                break;

            // ---------- TRANSFERT INTERNATIONAL ----------
            case in_array($transaction->type, ['TRANSFERT_INTERNATIONAL', 'TRANSFERT_INTERNATIONAL_PAIEMENT'], true):
                $module = 'TRANSACTION';

                $transfert = TransfertInternational::find($transaction->transfert_international_id);

                if ($transfert) {
                    $operateur = OperateursInternational::getNameById($transfert->operateur_transfert_id);
                    $caissierId  = $transfert->caissier_id ?? $caissierId;
                } else {
                    Log::warning("Commission : aucun TransfertInternational pour id={$transaction->transfert_international_id}");
                }
                break;

            // ---------- TRANSFERT BANCAIRE ----------
            case in_array($transaction->type, ['TRANSFERT_BANCAIRE', 'TRANSFERT_BANCAIRE_RETRAIT'], true):
                $module = 'TRANSACTION';

                // NB : la transaction globale stocke l'ID du transfert bancaire
                // dans la colonne transfert_international_id (pas de colonne dédiée).
                $transfert = TransfertBancaire::find($transaction->transfert_international_id);

                if ($transfert) {
                    $operateur = OperateursInternational::getNameById($transfert->id_operateur_bancaire);
                    $caissierId  = $transfert->caissier_id ?? $caissierId;
                } else {
                    Log::warning("Commission : aucun TransfertBancaire pour id={$transaction->transfert_international_id}");
                }
                break;
        }

        return [
            'module'      => $module,
            'operateur'   => $operateur,
            'caissier_id' => $caissierId,
        ];
    }

    /**
     * Crée une commission pour une opération de change pure.
     */
    public static function creerCommissionChange(Transaction_Globale $transaction): ?self
    {
        if ($transaction->type !== 'CHANGE') return null;
        if (!$transaction->operation_change_id) return null;

        $operation = OperationChange::find($transaction->operation_change_id);
        if (!$operation) return null;

        $commission = (float) $operation->commission;
        if ($commission <= 0) return null;

        $cdfId = Devise::where('code', 'CDF')->value('id');
        if (!$cdfId) return null;

        $caissierId = $operation->operateur_id ?? $transaction->id_creator ?? null;

        $commissionModel = self::create([
            'id_transaction_global' => $transaction->id,
            'montant_total'         => $commission,
            'devise_id'             => $cdfId,
            'statut'                => 'PAYEE',
            'module'                => 'CHANGE',
            'operateur'             => null,
            'caissier_id'           => $caissierId,
        ]);

        CommissionService::create([
            'commission_id'        => $commissionModel->id,
            'commission_config_id' => null,
            'service'              => 'CHANGE',
            'pourcentage'          => 0,
            'montant'              => $commission,
        ]);

        $transaction->update([
            'commission_starehe' => $commission,
        ]);

        return $commissionModel->load('services');
    }

    // ============================================================
    // MÉTIER — ÉTAT
    // ============================================================

    public function recalculerTotal(): bool
    {
        return $this->update([
            'montant_total' => $this->services()->sum('montant'),
        ]);
    }

    public function payer(): bool
    {
        $this->statut = 'PAYEE';
        return $this->save();
    }

    public function annuler(): bool
    {
        $this->statut = 'ANNULEE';
        return $this->save();
    }

}