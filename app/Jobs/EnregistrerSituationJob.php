<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\BudgetComptable\BudgetCalculService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class EnregistrerSituationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600; // 10 minutes max
    public int $tries = 1;

    private string $progressKey;

    public function __construct(
        private int $periodeId,
        private ?int $userId = null
    ) {
        $this->progressKey = 'situation_progress_' . $this->periodeId . '_' . uniqid();
    }

    /**
     * Execute the job.
     */
    public function handle(BudgetCalculService $calculService): void
    {
        $startTime = microtime(true);
        
        Log::info('🔄 Job EnregistrerSituation démarré', [
            'periode_id' => $this->periodeId,
            'progress_key' => $this->progressKey,
            'user_id' => $this->userId,
        ]);

        // Initialiser la progression
        $this->updateProgress(0, 'Démarrage du calcul...', 'running');

        try {
            // Étape 1 : Chargement des données (0-20%)
            $this->updateProgress(5, 'Chargement de la période comptable...', 'running');
            
            $periode = \App\Models\PeriodesComptable::findOrFail($this->periodeId);
            
            $this->updateProgress(10, 'Chargement des écritures comptables...', 'running');
            
            $ecritures = $this->chargerEcritures($this->periodeId, $periode);
            
            $this->updateProgress(20, 'Chargement des rubriques budgétaires...', 'running');

            // Étape 2 : Traitement des rubriques (20-80%)
            $rubriques = \App\Models\BudgetRubrique::actives()->with('section')->get();
            $totalRubriques = $rubriques->count();
            
            if ($totalRubriques === 0) {
                $this->updateProgress(100, 'Aucune rubrique à traiter.', 'completed');
                return;
            }

            $totalEnregistrements = 0;
            $situationsCrees = 0;
            $situationsMAJ = 0;

            \Illuminate\Support\Facades\DB::beginTransaction();

            $index = 0;
            foreach ($rubriques as $rubrique) {
                $index++;
                
                // Calculer le pourcentage (20% à 80%)
                $pourcentage = 20 + round(($index / $totalRubriques) * 60);
                
                // Mettre à jour la progression toutes les 5 rubriques
                if ($index % 5 === 0 || $index === 1 || $index === $totalRubriques) {
                    $this->updateProgress(
                        $pourcentage,
                        "Traitement de la rubrique {$index}/{$totalRubriques} : " . ($rubrique->libelle ?? 'N/A'),
                        'running'
                    );
                }

                // Traiter la rubrique (12 mois)
                $result = $this->traiterRubrique($rubrique, $ecritures, $this->periodeId);
                $totalEnregistrements += $result['enregistrements'];
                $situationsCrees += $result['crees'];
                $situationsMAJ += $result['maj'];
            }

            \Illuminate\Support\Facades\DB::commit();

            // Étape 3 : Finalisation (80-100%)
            $this->updateProgress(90, 'Finalisation et vérification...', 'running');
            
            // Appeler la méthode originale pour la cohérence
            $resultFinal = $calculService->enregistrerSituation($this->periodeId);
            
            $duration = round(microtime(true) - $startTime, 2);
            
            $this->updateProgress(100, 'Calcul terminé !', 'completed', [
                'total' => $resultFinal['total'] ?? $totalEnregistrements,
                'crees' => $resultFinal['crees'] ?? $situationsCrees,
                'maj' => $resultFinal['mis_a_jour'] ?? $situationsMAJ,
                'duree' => $duration,
            ]);
            
            Log::info(' Job EnregistrerSituation terminé', [
                'periode_id' => $this->periodeId,
                'duree_secondes' => $duration,
                'total_enregistrements' => $resultFinal['total'] ?? $totalEnregistrements,
            ]);
            
        } catch (\Exception $e) {
            $duration = round(microtime(true) - $startTime, 2);
            
            $this->updateProgress(0, 'Erreur : ' . $e->getMessage(), 'error', [
                'error' => $e->getMessage(),
            ]);
            
            Log::error('❌ Job EnregistrerSituation échoué', [
                'periode_id' => $this->periodeId,
                'duree_secondes' => $duration,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Charger les écritures comptables
     */
    private function chargerEcritures(int $periodeId, $periode)
    {
        $dateDebut = $periode->date_debut instanceof \Carbon\Carbon 
            ? $periode->date_debut->format('Y-m-d') 
            : $periode->date_debut;
        $dateFin = $periode->date_fin instanceof \Carbon\Carbon 
            ? $periode->date_fin->format('Y-m-d') 
            : $periode->date_fin;

        return \App\Models\EcrituresComptable::where('periode_id', $periodeId)
            ->where('reference', 'NOT LIKE', 'CLÔTURE%')
            ->where('reference', 'NOT LIKE', 'BILAN%')
            ->where('reference', '!=', 'À-NOUVEAU')
            ->whereBetween('date_ecriture', [$dateDebut, $dateFin])
            ->toBase()
            ->get(['compte_numero', 'debit', 'credit', 'date_ecriture']);
    }

    /**
     * Traiter une rubrique pour les 12 mois
     */
    private function traiterRubrique($rubrique, $ecritures, int $periodeId): array
    {
        $section = $rubrique->section;
        if (!$section) {
            return ['enregistrements' => 0, 'crees' => 0, 'maj' => 0];
        }

        $racines = $rubrique->comptes_racines_array;
        $situationPrecedente = 0;
        $totalEnregistrements = 0;
        $crees = 0;
        $maj = 0;

        for ($mois = 1; $mois <= 12; $mois++) {
            $situationInitiale = $situationPrecedente;
            $realise = 0.0;

            if (!empty($racines)) {
                $ecrituresMois = $ecritures->filter(function ($e) use ($mois, $racines) {
                    $moisEcriture = (int) date('m', strtotime($e->date_ecriture));
                    if ($moisEcriture !== $mois) return false;
                    $compte = $e->compte_numero ?? '';
                    foreach ($racines as $racine) {
                        if (str_starts_with($compte, trim($racine))) return true;
                    }
                    return false;
                });

                if ($section->type_section === 'revenu') {
                    $realise = round((float) $ecrituresMois->sum('credit') - (float) $ecrituresMois->sum('debit'), 2);
                } else {
                    $realise = round((float) $ecrituresMois->sum('debit') - (float) $ecrituresMois->sum('credit'), 2);
                }
            }

            $situationFinale = ($section->type_section === 'revenu')
                ? $situationInitiale + $realise
                : $situationInitiale - $realise;

            $existe = \App\Models\BudgetSituation::where([
                'rubrique_id' => $rubrique->id,
                'periode_id' => $periodeId,
                'mois' => $mois,
            ])->exists();

            \App\Models\BudgetSituation::updateOrCreate(
                ['rubrique_id' => $rubrique->id, 'periode_id' => $periodeId, 'mois' => $mois],
                ['situation_initiale' => $situationInitiale, 'situation_finale' => $situationFinale]
            );

            if ($existe) $maj++; else $crees++;
            $situationPrecedente = $situationFinale;
            $totalEnregistrements++;
        }

        return [
            'enregistrements' => $totalEnregistrements,
            'crees' => $crees,
            'maj' => $maj,
        ];
    }

    /**
     * Mettre à jour la progression dans le cache
     */
    private function updateProgress(int $percentage, string $message, string $status, array $extra = []): void
    {
        $progressData = array_merge([
            'percentage' => $percentage,
            'message' => $message,
            'status' => $status, // running, completed, error
            'timestamp' => now()->toDateTimeString(),
            'periode_id' => $this->periodeId,
            'progress_key' => $this->progressKey,
        ], $extra);

        // Stocker dans le cache pour 10 minutes
        Cache::put($this->progressKey, $progressData, 600);
        
        // Stocker aussi une clé globale pour cette période
        Cache::put('situation_progress_periode_' . $this->periodeId, $progressData, 600);

        Log::info("📊 Progression: {$percentage}% - {$message}", $progressData);
    }

    /**
     * Récupérer la clé de progression
     */
    public function getProgressKey(): string
    {
        return $this->progressKey;
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $this->updateProgress(0, 'Erreur critique : ' . $exception->getMessage(), 'error', [
            'error' => $exception->getMessage(),
        ]);
        
        Log::critical('🚨 Job EnregistrerSituation en échec définitif', [
            'periode_id' => $this->periodeId,
            'error' => $exception->getMessage(),
        ]);
    }
}