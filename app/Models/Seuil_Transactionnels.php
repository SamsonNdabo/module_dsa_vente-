<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Transaction_Globale;  
use App\Models\Devise; 
use App\Models\Role;
use App\Traits\Notifiable; 
use Illuminate\Support\Facades\Log;

class Seuil_Transactionnels extends Model
{
    use Notifiable; 

    protected $table      = 'seuil_transactionnels';
    public    $timestamps = false;

    protected $fillable = [
        'type_transaction',
        'devise_id',
        'role_id',
        'montant_min',
        'montant_max',
        'niveau_alerte',
    ];

    protected $casts = [
        'montant_min' => 'decimal:4',
        'montant_max' => 'decimal:4',
    ];
    

    public function devise()
    {
        return $this->belongsTo(Devise::class);
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction_Globale::class, 'id_seuil_transaction');
    }

    // ============================================================
    // SCOPES
    // ============================================================

    public function scopeParType($query, string $type)
    {
        return $query->where('type_transaction', $type);
    }

    // ============================================================
    // LOGIQUE MÉTIER
    // ============================================================

    // Trouver le seuil correspondant à un montant et un type

public static function detecter(string $type, float $montant, ?int $deviseId = null, ?int $roleId = null)
{
    // 1. Recherche avec les critères (Devise spécifique OU globale si null) + intervalle de montant
    $seuil = self::where('type_transaction', $type)
        ->where('montant_min', '<=', $montant)
        ->where(function ($q) use ($montant) { //  Ajout du use ($montant) ici
            $q->where('montant_max', '>=', $montant)
              ->orWhereNull('montant_max');
        })
        ->where(function ($q) use ($deviseId) {
            if ($deviseId !== null) {
                // Cherche la devise spécifique OU un seuil global (null) valable pour toutes
                $q->where('devise_id', $deviseId)
                  ->orWhereNull('devise_id');
            } else {
                // Si la devise fournie est null, on prend uniquement les seuils globaux
                $q->whereNull('devise_id');
            }
        })
        ->where(function ($q) use ($roleId) {
            if ($roleId !== null) {
                $q->where('role_id', $roleId)
                  ->orWhereNull('role_id');
            } else {
                $q->whereNull('role_id');
            }
        })
        // Priorité aux seuils spécifiques (non null) d'abord, puis les globaux (null)
        ->orderByRaw('devise_id DESC, role_id DESC, montant_max ASC')
        ->first();
        
    if ($seuil && in_array($seuil->niveau_alerte, ['ELEVE', 'CRITIQUE'])) {
        $seuil->notifierDepassement($montant, $deviseId, $roleId, $type);
    }
    
    if ($seuil) {
        return $seuil;
    }

    // 2. Recherche de secours : s'il existe un seuil avec les critères (sans intervalle)
    $seuilCritique = self::where('type_transaction', $type)
        ->where(function ($q) use ($deviseId) {
            if ($deviseId !== null) {
                $q->where('devise_id', $deviseId)->orWhereNull('devise_id');
            } else {
                $q->whereNull('devise_id');
            }
        })
        ->where(function ($q) use ($roleId) {
            if ($roleId !== null) {
                $q->where('role_id', $roleId)->orWhereNull('role_id');
            } else {
                $q->whereNull('role_id');
            }
        })
        ->orderByRaw('devise_id DESC, role_id DESC')
        ->first();

    if ($seuilCritique) {
        // Créer une instance factice du modèle avec niveau_alerte = 'CRITIQUE'
        $fake = new self();
        $fake->id = $seuilCritique->id;
        $fake->type_transaction = $seuilCritique->type_transaction;
        $fake->devise_id = $seuilCritique->devise_id;
        $fake->role_id = $seuilCritique->role_id;
        $fake->montant_min = $seuilCritique->montant_min;
        $fake->montant_max = $seuilCritique->montant_max;
        $fake->niveau_alerte = 'CRITIQUE';
        $seuilCritique->notifierDepassement($montant, $deviseId, $roleId, $type);
        return $fake;
    }

    return null;
}


    // Savoir si un montant est critique
    public static function estCritique(
        string $type,
        float $montant,
        ?int $deviseId = null,
        ?int $roleId = null
    ): bool {
        $seuil = self::detecter($type, $montant, $deviseId, $roleId);
        return $seuil && $seuil->niveau_alerte === 'CRITIQUE';
    }

    public static function necessiteAlerte(
        string $type,
        float $montant,
        ?int $deviseId = null,
        ?int $roleId = null
    ): bool {
        $seuil = self::detecter($type, $montant, $deviseId, $roleId);
        return $seuil && in_array($seuil->niveau_alerte, ['ELEVE', 'CRITIQUE', 'MOYEN']);
    }

    // ============================================================
    // NOTIFICATION DE DÉPASSEMENT DE SEUIL
    // ============================================================

    /**
     * Notifier les chefs d'agence et super admins du dépassement de seuil
     * 
     * @param float $montant Le montant de la transaction
     * @param int|null $deviseId L'ID de la devise
     * @param int|null $roleId L'ID du rôle
     * @param string $typeOperation Le type d'opération
     * @param array $additionalData Données supplémentaires (client, référence, etc.)
     */
    public function notifierDepassement(
        float $montant,
        ?int $deviseId = null,
        ?int $roleId = null,
        string $typeOperation = 'MOBILE_MONEY',
        array $additionalData = []
    ): void {
        try {
            // Récupérer les informations de devise
            $devise = $deviseId ? Devise::find($deviseId) : null;
            $deviseCode = $devise ? $devise->code : 'N/A';

            // Déterminer le libellé de l'opération
            $operationLabels = [
                'MOBILE_MONEY' => 'Mobile Money',
                'PAYIN' => 'Dépôt (Payin)',
                'PAYOUT' => 'Retrait (Payout)',
                'DEPOT' => 'Dépôt',
                'RETRAIT' => 'Retrait',
                'CHANGE' => 'Change',
                'VENTE' => 'Vente',
                'TRANSFERT' => 'Transfert',
            ];
            $operationLabel = $operationLabels[$typeOperation] ?? $typeOperation;

            // Niveaux d'urgence pour la priorité
            $prioriteMap = [
                'CRITIQUE' => 'URGENTE',
                'ELEVE' => 'HAUTE',
                'MOYEN' => 'NORMALE',
                'FAIBLE' => 'BASSE',
            ];
            $priorite = $prioriteMap[$this->niveau_alerte] ?? 'NORMALE';

            // Construire le message
            $message = sprintf(
                "⚠️ ALERTE SEUIL - Transaction %s\n\n" .
                "Montant: %s %s\n" .
                "Type: %s\n" .
                "Seuil: %s - %s %s\n" .
                "Niveau d'alerte: %s\n" .
                "%s" .
                "%s",
                $operationLabel,
                number_format($montant, 2),
                $deviseCode,
                $typeOperation,
                number_format($this->montant_min ?? 0, 2),
                $deviseCode,
                $this->montant_max ? " - " . number_format($this->montant_max, 2) . " " . $deviseCode : "",
                $this->niveau_alerte,
                isset($additionalData['client']) ? "Client: " . $additionalData['client'] . "\n" : "",
                isset($additionalData['reference']) ? "Référence: " . $additionalData['reference'] : ""
            );

            // ============================================================
            // 1. Notifier les CHEFS D'AGENCE
            // ============================================================
            $this->notifierDestinatairesSpecifiques([
                'destinataires' => [
                    'type' => 'role',
                    'code' => 'CHEF_AGENCE'
                ],
                'type' => 'SEUIL_CRITIQUE',
                'titre' => "⚠️ Alerte seuil - " . $operationLabel,
                'message' => $message,
                'donnees' => [
                    'type_operation' => $typeOperation,
                    'montant' => $montant,
                    'devise_id' => $deviseId,
                    'devise_code' => $deviseCode,
                    'seuil_id' => $this->id,
                    'seuil_min' => $this->montant_min,
                    'seuil_max' => $this->montant_max,
                    'niveau_alerte' => $this->niveau_alerte,
                    'role_id' => $roleId,
                    'succursale_id' => $additionalData['succursale_id'] ?? null,
                    'reference' => $additionalData['reference'] ?? null,
                    'client' => $additionalData['client'] ?? null,
                    'date_transaction' => now()->toDateTimeString()
                ],
                'succursale_id' => $additionalData['succursale_id'] ?? null,
                'priorite' => $priorite
            ]);

            // ============================================================
            // 2. Notifier les SUPER ADMINS
            // ============================================================
            $this->notifierDestinatairesSpecifiques([
                'destinataires' => [
                    'type' => 'role',
                    'code' => 'SUPER_ADMIN'
                ],
                'type' => 'SEUIL_CRITIQUE',
                'titre' => "🚨 ALERTE - Seuil dépassé (" . $this->niveau_alerte . ")",
                'message' => $message,
                'donnees' => [
                    'type_operation' => $typeOperation,
                    'montant' => $montant,
                    'devise_id' => $deviseId,
                    'devise_code' => $deviseCode,
                    'seuil_id' => $this->id,
                    'seuil_min' => $this->montant_min,
                    'seuil_max' => $this->montant_max,
                    'niveau_alerte' => $this->niveau_alerte,
                    'role_id' => $roleId,
                    'succursale_id' => $additionalData['succursale_id'] ?? null,
                    'reference' => $additionalData['reference'] ?? null,
                    'client' => $additionalData['client'] ?? null,
                    'date_transaction' => now()->toDateTimeString()
                ],
                'succursale_id' => $additionalData['succursale_id'] ?? null,
                'priorite' => $priorite
            ]);

            // ============================================================
            // 3. Si niveau CRITIQUE, notifier aussi les AUDITEURS
            // ============================================================
            if ($this->niveau_alerte === 'CRITIQUE') {
                $this->notifierDestinatairesSpecifiques([
                    'destinataires' => [
                        'type' => 'role',
                        'code' => 'AUDITEUR'
                    ],
                    'type' => 'SEUIL_CRITIQUE',
                    'titre' => "🔴 URGENT - Seuil critique dépassé",
                    'message' => $message,
                    'donnees' => [
                        'type_operation' => $typeOperation,
                        'montant' => $montant,
                        'devise_id' => $deviseId,
                        'devise_code' => $deviseCode,
                        'seuil_id' => $this->id,
                        'seuil_min' => $this->montant_min,
                        'seuil_max' => $this->montant_max,
                        'niveau_alerte' => $this->niveau_alerte,
                        'role_id' => $roleId,
                        'succursale_id' => $additionalData['succursale_id'] ?? null,
                        'reference' => $additionalData['reference'] ?? null,
                        'client' => $additionalData['client'] ?? null,
                        'date_transaction' => now()->toDateTimeString()
                    ],
                    'succursale_id' => $additionalData['succursale_id'] ?? null,
                    'priorite' => 'URGENTE'
                ]);
            }

            // Log de la notification
            \Illuminate\Support\Facades\Log::info('Notification seuil dépassé envoyée', [
                'type_operation' => $typeOperation,
                'montant' => $montant,
                'devise_id' => $deviseId,
                'seuil_id' => $this->id,
                'niveau_alerte' => $this->niveau_alerte,
                'role_id' => $roleId
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Erreur lors de la notification de seuil', [
                'error' => $e->getMessage(),
                'type_operation' => $typeOperation,
                'montant' => $montant,
                'seuil_id' => $this->id ?? null
            ]);
        }
    }
}