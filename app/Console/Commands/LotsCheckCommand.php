<?php

namespace App\Console\Commands;

use App\Models\StockSuccursale;
use App\Services\Stock\LotStockService;
use Illuminate\Console\Command;

class LotsCheckCommand extends Command
{
    protected $signature = 'lots:check {--fix : Tenter de corriger les incohérences}';
    protected $description = 'Vérifier la cohérence entre stock et lots';

    public function handle(LotStockService $lotService)
    {
        $this->info('🔍 Vérification des lots...');

        $incoherences = 0;

        StockSuccursale::chunk(100, function ($stocks) use ($lotService, &$incoherences) {
            foreach ($stocks as $stock) {
                $result = $lotService->verifierCoherence($stock);

                if (!$result['coherent']) {
                    $this->warn("⚠️ Incohérence: Stock #{$stock->id} (Produit: {$stock->produit?->nom})");
                    $this->line("   Stock disponible: {$result['quantite_stock']}");
                    $this->line("   Total lots: {$result['quantite_lots']}");
                    $this->line("   Écart: {$result['ecart']}");

                    if ($this->option('fix')) {
                        $stock->quantite_disponible = $result['quantite_lots'];
                        $stock->save();
                        $this->info("   ✅ Corrigé.");
                    }

                    $incoherences++;
                }
            }
        });

        $this->info("✅ Vérification terminée. {$incoherences} incohérence(s).");
        return 0;
    }
}