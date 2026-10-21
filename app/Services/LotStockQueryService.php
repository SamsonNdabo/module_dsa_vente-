<?php
// app/Services/Stock/LotStockQueryService.php

namespace App\Services;

use App\Models\LotStock;
use App\Models\StockSuccursale;

class LotStockQueryService
{
    /**
     * Valorisation FIFO complète du stock
     */
    public function valorisationFIFO(StockSuccursale $stock): array
    {
        $lots = LotStock::where('stock_succursale_id', $stock->id)
            ->where('statut', 'ACTIF')
            ->where('quantite_restante', '>', 0)
            ->orderBy('created_at', 'asc')
            ->get();

        $totalQuantite = 0;
        $totalValeur = 0;

        foreach ($lots as $lot) {
            $totalQuantite += $lot->quantite_restante;
            $totalValeur += $lot->quantite_restante * $lot->cout_unitaire;
        }

        return [
            'methode' => 'FIFO',
            'quantite_totale' => $totalQuantite,
            'valeur_totale' => $totalValeur,
            'prix_moyen' => $totalQuantite > 0 ? round($totalValeur / $totalQuantite, 2) : 0,
            'lots' => $lots->map(function ($lot) {
                return [
                    'lot_id' => $lot->id,
                    'numero_lot' => $lot->numero_lot,
                    'date_expiration' => $lot->date_expiration?->format('d/m/Y'),
                    'quantite' => $lot->quantite_restante,
                    'cout_unitaire' => $lot->cout_unitaire,
                    'valeur' => $lot->quantite_restante * $lot->cout_unitaire,
                ];
            }),
        ];
    }

    /**
     * État détaillé des lots (pour affichage)
     */
    public function etatFIFO(StockSuccursale $stock): array
    {
        return $this->valorisationFIFO($stock);
    }
}