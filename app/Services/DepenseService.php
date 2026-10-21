<?php

namespace App\Services;

use App\Models\Depense;
use App\Models\Devise;
use App\Models\ExchangeRate;
use Illuminate\Support\Facades\DB;

class DepenseService
{
    /**
     * Convertit un montant dans une devise donnée en USD.
     */
    public function convertirEnUsd(float $montant, int $deviseId, int $succursaleId): float
    {
        $usdId = Devise::where('code', 'USD')->value('id');
        if (!$usdId) {
            throw new \Exception('Devise USD introuvable.');
        }
        if ($deviseId == $usdId) {
            return $montant;
        }

        $sourceCode = Devise::find($deviseId)?->code;
        $usdCode = 'USD';
        if (!$sourceCode) {
            throw new \Exception('Devise source introuvable.');
        }

        $tauxData = ExchangeRate::getTauxActif($usdCode, $sourceCode, null, $succursaleId);

        if (!$tauxData) {
            throw new \Exception("Aucun taux de change trouvé pour la paire {$sourceCode}/USD.");
        }

        $rate = $tauxData['rate'];
        $sens = $tauxData['sens'];

        if ($sens === 'direct') {
            // source -> USD : on utilise le taux d'achat (pour convertir devise en USD)
            $taux = $rate->getTauxAchat('direct');
            return round($montant / $taux, 4);
        } else {
            // inverse = USD -> source : on utilise l'inverse du taux de vente
            $taux = $rate->getTauxVente('inverse');
            return round($montant * $taux, 4);
        }
    }

    /**
     * Vérifie que le montant d'une dépense ne dépasse pas le plafond.
     */
    public function verifierPlafondDepense(float $montant, int $deviseId, int $succursaleId): void
    {
        $plafond = config('depenses.plafond_depense', 50);
        $montantUsd = $this->convertirEnUsd($montant, $deviseId, $succursaleId);

        if ($montantUsd > $plafond) {
            throw new \Exception("Le montant de la dépense ($montantUsd USD) dépasse le plafond autorisé de $plafond USD.");
        }
    }

    /**
     * Vérifie que le total des dépenses du jour pour une succursale ne dépasse pas le plafond journalier.
     */
    public function verifierPlafondJournalier(int $succursaleId, float $montant, int $deviseId): void
    {
        $plafondJournalier = config('depenses.plafond_journalier', 200);
        $montantUsd = $this->convertirEnUsd($montant, $deviseId, $succursaleId);

        $totalJourUsd = Depense::where('succursale_id', $succursaleId)
            ->whereDate('created_at', today())
            ->whereIn('statut', ['VALIDE', 'EN_ATTENTE'])
            ->sum('montant_usd'); // ✅ utilisation de la colonne stockée

        if (($totalJourUsd + $montantUsd) > $plafondJournalier) {
            throw new \Exception("Le total des dépenses du jour ({$totalJourUsd} USD) dépasse le plafond journalier de $plafondJournalier USD avec cette nouvelle dépense.");
        }
    }
}