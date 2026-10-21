<?php
// app/Console/Commands/Stock/CheckExpiration.php

namespace App\Console\Commands\Stock;

use Illuminate\Console\Command;
use App\Models\LotStock;
use Illuminate\Support\Facades\Log;

class CheckExpiration extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stock:check-expiration 
                            {--days=30 : Nombre de jours avant expiration}
                            {--succursale= : ID de la succursale}
                            {--force : Forcer l\'envoi des alertes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Vérifie les lots proches de l\'expiration (table lots_stock)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $days = (int) $this->option('days');
        $succursaleId = $this->option('succursale');
        $force = $this->option('force');

        $this->info("🔍 Vérification des lots expirant dans {$days} jours...");

        try {
            // Requête sur la table lots_stock
            $query = LotStock::where('statut', 'ACTIF')
                ->where('quantite_restante', '>', 0)
                ->whereNotNull('date_expiration')
                ->where('date_expiration', '<=', now()->addDays($days))
                ->where('date_expiration', '>', now())
                ->with(['produit', 'stockSuccursale.succursale']);

            // Filtrer par succursale si spécifiée
            if ($succursaleId) {
                $query->whereHas('stockSuccursale', function ($q) use ($succursaleId) {
                    $q->where('succursale_id', $succursaleId);
                });
            }

            $lots = $query->get();

            $this->info("📦 {$lots->count()} lots trouvés");

            if ($lots->isEmpty()) {
                $this->line('Aucun lot proche de l\'expiration.');
                return Command::SUCCESS;
            }

            // Tableau pour affichage formaté
            $tableData = [];
            foreach ($lots as $lot) {
                $jours = now()->startOfDay()->diffInDays($lot->date_expiration, false);
                $tableData[] = [
                    'ID' => $lot->id,
                    'Numéro' => $lot->numero_lot ?? 'N/A',
                    'Produit' => $lot->produit->nom ?? 'Inconnu',
                    'Succursale' => $lot->stockSuccursale->succursale->nom ?? 'N/A',
                    'Quantité' => $lot->quantite_restante,
                    'Expiration' => $lot->date_expiration->format('d/m/Y'),
                    'Jours restants' => $jours,
                ];
            }

            $this->table(
                ['ID', 'Numéro', 'Produit', 'Succursale', 'Quantité', 'Expiration', 'Jours restants'],
                $tableData
            );

            // Envoyer des alertes si --force est activé
            if ($force) {
                $this->info('⚡ Option --force activée : déclenchement des alertes.');
                // Exemple : dispatcher un job d'alerte pour ces lots
                // \App\Jobs\CheckExpiringLotsJob::dispatch($days, $succursaleId, true)->onQueue('high');
                $this->line('(Implémentez ici l\'envoi de notifications si nécessaire)');
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Erreur: ' . $e->getMessage());
            Log::error('Erreur commande stock:check-expiration', [
                'error' => $e->getMessage(),
                'options' => $this->options(),
            ]);
            return Command::FAILURE;
        }
    }
}