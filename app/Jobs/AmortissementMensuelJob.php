<?php

namespace App\Jobs;

use App\Services\AmortissementService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique; // 👈 AJOUTÉ
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AmortissementMensuelJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected ?int $entiteId;

    public $tries = 3;
    public $backoff = 60;
    public $timeout = 300;
    public $uniqueFor = 3600; // 1 heure

    public function __construct(?int $entiteId = null)
    {
        $this->entiteId = $entiteId;
    }

    /**
     * Identifiant unique pour le dispatching
     */
    public function uniqueId(): string
    {
        return 'amortissement-mensuel-' . ($this->entiteId ?? 'toutes') . '-' . now()->format('Y-m');
    }

    public function handle(AmortissementService $amortissementService): void
    {
        $lockKey = 'amortissement_lock_' . ($this->entiteId ?? 'all');
        
        // 🔒 Verrou de runtime (sécurité complémentaire)
        $lock = Cache::lock($lockKey, $this->timeout);

        if (!$lock->get()) {
            Log::warning('🔒 Amortissement déjà en cours d\'exécution, job ignoré', [
                'entite_id' => $this->entiteId ?? 'toutes',
                'date'      => now()->format('Y-m-d H:i:s'),
            ]);
            return;
        }

        try {
            Log::info('🕐 Début du job d\'amortissement mensuel', [
                'entite_id' => $this->entiteId ?? 'toutes',
                'date'      => now()->format('Y-m-d H:i:s'),
                'tentative' => $this->attempts(),
            ]);

            $resultat = $amortissementService->executerAmortissementMensuel($this->entiteId);

            if ($resultat['success']) {
                $nbAmortis     = collect($resultat['details'])->where('montant', '>', 0)->count();
                $nbDejaAmortis = collect($resultat['details'])->where('montant', 0)->count();

                Log::info('✅ Job amortissement terminé avec succès', [
                    'entite_id'       => $this->entiteId ?? 'toutes',
                    'nb_amortis'      => $nbAmortis,
                    'nb_deja_amortis' => $nbDejaAmortis,
                    'total'           => count($resultat['details'] ?? []),
                ]);
            } else {
                Log::warning('⚠️ Job amortissement terminé avec avertissement', [
                    'entite_id' => $this->entiteId ?? 'toutes',
                    'message'   => $resultat['message'],
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('❌ Erreur lors du job d\'amortissement', [
                'entite_id' => $this->entiteId ?? 'toutes',
                'error'     => $e->getMessage(),
                'tentative' => $this->attempts(),
            ]);

            if ($this->attempts() >= $this->tries) {
                Log::critical('🚨 ÉCHEC DÉFINITIF du job d\'amortissement', [
                    'entite_id' => $this->entiteId ?? 'toutes',
                ]);
            }

            throw $e;
        } finally {
            $lock->release();
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::critical('💀 Job amortissement mensuel ABANDONNÉ', [
            'entite_id' => $this->entiteId ?? 'toutes',
            'error'     => $exception->getMessage(),
        ]);
    }
}