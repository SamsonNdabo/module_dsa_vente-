<?php

namespace App\Services;

use App\Models\OperateurMobileMoney;
use Illuminate\Support\Facades\Http;

class KelPayService
{
    private ?OperateurMobileMoney $operateur;

    public function __construct()
    {
        $this->operateur = OperateurMobileMoney::where('code', 'KELPAY')
            ->where('statut', 'ACTIF')
            ->first();
    }

    // ============================================================
    // Vérifier que le service est disponible
    // ============================================================

    public function estDisponible(): bool
    {
        return $this->operateur !== null;
    }

    // ============================================================
    // Initier un paiement mobile money
    // ============================================================

    public function payer(
        string $reference,
        string $numeroMobile,
        float  $montant,
        string $devise,
        string $description
    ): array {
        if (!$this->operateur) {
            return ['code' => '1', 'description' => 'Opérateur KelPay inactif ou introuvable'];
        }

        try {
            $response = Http::withHeaders($this->operateur->getHeader())
                ->post($this->operateur->base_url . '/payment.asp', [
                    'merchantcode' => $this->operateur->merchant_code,
                    'mobilenumber' => $numeroMobile,
                    'reference'    => $reference,
                    'amount'       => $montant,
                    'currency'     => $devise,
                    'description'  => $description,
                    'callbackurl'  => $this->operateur->callback_url,
                ]);

            return $response->json() ?? ['code' => '1', 'description' => 'Réponse vide de KelPay'];
        } catch (\Exception $e) {
            return ['code' => '1', 'description' => 'Erreur connexion KelPay : ' . $e->getMessage()];
        }
    }

    // ============================================================
    // Vérifier le statut d'une transaction
    // ============================================================

    public function verifierStatut(string $transactionId): array
    {
        if (!$this->operateur) {
            return ['code' => '1', 'description' => 'Opérateur KelPay inactif ou introuvable'];
        }

        try {
            $response = Http::withHeaders($this->operateur->getHeader())
                ->post($this->operateur->base_url . '/checktransaction.asp', [
                    'merchantcode'  => $this->operateur->merchant_code,
                    'transactionid' => $transactionId,
                ]);

            return $response->json() ?? ['code' => '1', 'description' => 'Réponse vide de KelPay'];
        } catch (\Exception $e) {
            return ['code' => '1', 'description' => 'Erreur connexion KelPay : ' . $e->getMessage()];
        }
    }

    // ============================================================
    // Vérifier le solde du compte marchand
    // ============================================================

    public function getBalance(string $devise): array
    {
        if (!$this->operateur) {
            return ['code' => '1', 'description' => 'Opérateur KelPay inactif ou introuvable'];
        }

        try {
            $response = Http::withHeaders($this->operateur->getHeader())
                ->post($this->operateur->base_url . '/getbalance.asp', [
                    'merchantcode' => $this->operateur->merchant_code,
                    'currency'     => $devise,
                ]);

            return $response->json() ?? ['code' => '1', 'description' => 'Réponse vide de KelPay'];
        } catch (\Exception $e) {
            return ['code' => '1', 'description' => 'Erreur connexion KelPay : ' . $e->getMessage()];
        }
    }

    // ============================================================
    // Initier un payout (envoi vers compte mobile)
    // ============================================================

    public function payout(
        string $reference,
        string $account,
        string $accountType,
        float  $montant,
        string $devise,
        string $description
    ): array {
        if (!$this->operateur) {
            return ['code' => '1', 'description' => 'Opérateur KelPay inactif ou introuvable'];
        }

        try {
            $response = Http::withHeaders($this->operateur->getHeader())
                ->post($this->operateur->base_url . '/payout.asp', [
                    'merchantcode' => $this->operateur->merchant_code,
                    'reference'    => $reference,
                    'accounttype'  => $accountType,
                    'account'      => $account,
                    'amount'       => $montant,
                    'currency'     => $devise,
                    'description'  => $description,
                    'callbackurl'  => $this->operateur->callback_url,
                ]);

            return $response->json() ?? ['code' => '1', 'description' => 'Réponse vide de KelPay'];
        } catch (\Exception $e) {
            return ['code' => '1', 'description' => 'Erreur connexion KelPay : ' . $e->getMessage()];
        }
    }

    // ============================================================
    // Interpréter la réponse KelPay Payout (SUCCESS/FAILED)
    // ============================================================

    public static function estSuccesPayout(array $response): bool
    {
        return isset($response['transactionstatus'])
            && strtoupper($response['transactionstatus']) === 'Delivered';
    }

    // ============================================================
    // Interpréter la réponse KelPay
    // ============================================================

    public static function estSucces(array $response): bool
    {
        return isset($response['code']) && $response['code'] === '0';
    }
}
