<?php

namespace App\Http\Controllers\Comptabilite;

use Illuminate\Http\Request;
use App\Services\BudgetComptable\BudgetAlertService;
use App\Services\BudgetComptable\BudgetFichierService;
use App\Services\BudgetComptable\BudgetCalculService;
use App\Services\NotificationService;
use App\Models\Entite;
use App\Models\PeriodesComptable;
use App\Models\BudgetSection;
use App\Jobs\EnregistrerSituationJob;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;

class SuiviBudgetaireController extends Controller
{
    private BudgetAlertService $alertService;
    private BudgetFichierService $fichierService;
    private BudgetCalculService $calculService;
    private NotificationService $notificationService;

    public function __construct(
        BudgetAlertService $alertService,
        BudgetFichierService $fichierService,
        BudgetCalculService $calculService,
        NotificationService $notificationService
    ) {
        $this->alertService = $alertService;
        $this->fichierService = $fichierService;
        $this->calculService = $calculService;
        $this->notificationService = $notificationService;
    }

    // =========================================================================
    // HELPERS PRIVÉS
    // =========================================================================

    /**
     * Trouve la période active selon la priorité
     */
    private function resolvePeriode($periodeId = null): ?PeriodesComptable
    {
        if ($periodeId) {
            $p = PeriodesComptable::find($periodeId);
            if ($p) {
                return $p;
            }
        }

        return PeriodesComptable::where('statut', 'ouverte')
                ->orderBy('date_debut', 'desc')
                ->first()
            ?? PeriodesComptable::where('statut', 'cloture_provisoire')
                ->orderBy('date_debut', 'desc')
                ->first()
            ?? PeriodesComptable::orderBy('date_debut', 'desc')->first();
    }

    /**
     * Headers HTTP pour forcer le téléchargement d'un fichier Excel
     */
    private function excelHeaders(string $filename): array
    {
        return [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'max-age=0, no-cache, no-store, must-revalidate',
            'Pragma' => 'public',
        ];
    }

    // =========================================================================
    // PAGE PRINCIPALE
    // =========================================================================
    public function index()
    {
        $entites = Entite::where('is_active', 1)->orderBy('code', 'asc')->get();
        $periodes = PeriodesComptable::orderBy('date_debut', 'desc')->get();
        $sections = BudgetSection::actives()->ordonnees()
            ->with(['rubriques' => fn($q) => $q->actives()->ordonnees()])
            ->get();

        return response()->json([
            'success' => true,
            'entites' => $entites,
            'periodes' => $periodes,
            'sections' => $sections,
        ]);
    }

    // =========================================================================
    // PARTIE 1 : CONFIGURATION
    // =========================================================================
    public function getSections()
    {
        return response()->json($this->calculService->getSections());
    }

    public function storeSection(Request $request)
    {
        $r = $this->calculService->storeSection($request->all());
        return response()->json($r, $r['success'] ? 200 : 422);
    }

    public function updateSection(Request $request, $id)
    {
        return response()->json(
            $this->calculService->updateSection(
                $id,
                $request->only(['code', 'libelle', 'type_section', 'ordre', 'actif'])
            )
        );
    }

    public function deleteSection($id)
    {
        return response()->json($this->calculService->deleteSection($id));
    }

    public function storeRubrique(Request $request)
    {
        $r = $this->calculService->storeRubrique($request->all());
        return response()->json($r, $r['success'] ? 200 : 422);
    }

    public function updateRubrique(Request $request, $id)
    {
        return response()->json(
            $this->calculService->updateRubrique(
                $id,
                $request->only([
                    'section_id',
                    'parent_id',
                    'code',
                    'libelle',
                    'comptes_racines',
                    'ordre',
                    'actif',
                ])
            )
        );
    }

    public function deleteRubrique($id)
    {
        return response()->json($this->calculService->deleteRubrique($id));
    }

    // =========================================================================
    // PARTIE 2 : PLANIFICATION
    // =========================================================================
    public function getPrevisions(Request $request)
    {
        return response()->json($this->calculService->getPrevisions($request->periode_id));
    }

    public function storePrevision(Request $request)
    {
        $r = $this->calculService->storePrevision($request->all());
        return response()->json($r, $r['success'] ? 200 : 422);
    }

    public function storePrevisionsBatch(Request $request)
    {
        $result = $this->calculService->storePrevisionsBatch($request->all());

        if ($result['success']) {
            $periode = PeriodesComptable::find($request->periode_id);

            $this->notificationService->notifierAvecEmail(
                auth()->id() ?? 1,
                '📊 Nouvelles prévisions budgétaires',
                count($request->previsions ?? []) . ' prévision(s) enregistrée(s) pour ' . ($periode->code ?? 'N/A'),
                [
                    'type' => 'budget_alert',
                    'severity' => 'warning',
                    'icon' => '⏳',
                    'action_label' => 'Voir le détail',
                    'action_url' => '/suivi-budgetaire#planification',
                    'action_required' => true,
                ]
            );
        }

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function dupliquerBudget(Request $request)
    {
        $r = $this->calculService->dupliquerBudget($request->all());
        return response()->json($r, $r['success'] ? 200 : 422);
    }

    // =========================================================================
    // COMPTER LES PRÉVISIONS EN ATTENTE
    // =========================================================================
    public function countEnAttente(Request $request)
    {
        $periodeId = $request->query('periode_id');

        if (!$periodeId) {
            return response()->json([
                'success' => false,
                'count' => 0,
                'message' => 'Période requise',
            ], 400);
        }

        try {
            $count = \App\Models\BudgetPrevision::where('periode_id', $periodeId)
                ->where('statut', 'en_attente')
                ->count();

            return response()->json([
                'success' => true,
                'count' => $count,
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur countEnAttente', [
                'error' => $e->getMessage(),
                'periode_id' => $periodeId,
            ]);

            return response()->json([
                'success' => false,
                'count' => 0,
                'message' => 'Erreur lors du comptage',
            ], 500);
        }
    }

    // =========================================================================
    // PARTIE 3 : SUIVI
    // =========================================================================
    public function calculer(Request $request)
    {
        $r = $this->calculService->calculer(
            $request->periode_id,
            $request->filled('entite_id') ? (int) $request->entite_id : null,
            $request->vue ?? 'annuel',
            $request->mois ? (int) $request->mois : null
        );

        return response()->json($r, 200);
    }

    // =========================================================================
    // EXPORT
    // =========================================================================
    public function export(Request $request)
    {
        $cr = $this->calculService->calculer(
            $request->periode_id,
            $request->filled('entite_id') ? (int) $request->entite_id : null,
            $request->vue ?? 'annuel',
            $request->mois ? (int) $request->mois : null
        );

        if (!$cr['success']) {
            return response()->json($cr, 404);
        }

        $periode = $this->resolvePeriode($request->periode_id);

        if (!$periode) {
            return response()->json([
                'success' => false,
                'message' => "Aucun exercice comptable n'est disponible pour l'export.",
            ], 404);
        }

        $er = $this->fichierService->exporterSuivi(
            $cr['data']['lignes'],
            $periode,
            $request->vue ?? 'annuel',
            $request->mois ? (int) $request->mois : null
        );

        if (!$er['success']) {
            return response()->json($er, 500);
        }

        return response()->streamDownload(function () use ($er) {
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($er['spreadsheet']);
            $writer->setPreCalculateFormulas(true);
            $writer->save('php://output');
        }, $er['filename'], $this->excelHeaders($er['filename']));
    }

    // =========================================================================
    // MODÈLE
    // =========================================================================
    public function telechargerModele(Request $request)
    {
        $periode = $this->resolvePeriode($request->periode_id);

        if (!$periode) {
            return response()->json([
                'success' => false,
                'message' => "Aucun exercice comptable n'est disponible.",
            ], 404);
        }

        $r = $this->fichierService->telechargerModele($periode->id);

        if (!$r['success']) {
            return response()->json($r, 404);
        }

        return response()->streamDownload(function () use ($r) {
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($r['spreadsheet']);
            $writer->setPreCalculateFormulas(true);
            $writer->save('php://output');
        }, $r['filename'], $this->excelHeaders($r['filename']));
    }

    // =========================================================================
    // IMPORTER
    // =========================================================================
    public function importer(Request $request)
    {
        $request->validate([
            'fichier' => 'required|file|mimes:xlsx,xls|max:10240',
            'periode_id' => 'required|exists:periodes_comptables,id',
        ]);

        $result = $this->fichierService->importerFichier(
            $request->file('fichier'),
            $request->periode_id
        );

        if ($result['success']) {
            EnregistrerSituationJob::dispatch($request->periode_id, auth()->id());

            $periode = PeriodesComptable::find($request->periode_id);

            $this->notificationService->notifierEmailSeulement(
                '📥 Importation budgétaire réussie',
                "Fichier importé pour " . ($periode->code ?? 'N/A') . ".\n"
                    . ($result['details']['previsions'] ?? 0) . " prévision(s).",
                auth()->id()
            );
        }

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    // =========================================================================
    // SITUATION
    // =========================================================================
    public function enregistrerSituation(Request $request)
    {
        $request->validate(['periode_id' => 'required|exists:periodes_comptables,id']);
        return response()->json($this->calculService->enregistrerSituation($request->periode_id));
    }

    // =========================================================================
    // API
    // =========================================================================
    public function getPeriodes()
    {
        return response()->json($this->calculService->getPeriodes());
    }

    public function statistiques(Request $request)
    {
        $periodeId = $request->input('periode_id');

        if (!$periodeId) {
            return response()->json([
                'success' => false,
                'message' => 'Période requise',
            ], 400);
        }

        try {
            $d = $this->alertService->getStatistiques(
                $periodeId,
                $request->filled('entite_id') ? (int) $request->entite_id : null
            );

            if (empty($d)) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'message' => 'Aucune donnée disponible pour cette période',
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => $d,
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur statistiques budget', [
                'error' => $e->getMessage(),
                'periode_id' => $periodeId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du chargement des statistiques',
            ], 500);
        }
    }

    // =========================================================================
    // VALIDATION
    // =========================================================================
    public function validerPrevision(Request $request, $id)
    {
        $result = $this->calculService->validerPrevision($id, auth()->id() ?? 1);

        if ($result['success']) {
            $p = $result['data'] ?? null;

            $this->notificationService->notifierAvecEmail(
                auth()->id() ?? 1,
                ' Prévision validée',
                ($p->rubrique->libelle ?? 'N/A')
                    . ' - Mois ' . ($p->mois ?? 'N/A')
                    . ' : ' . number_format($p->montant ?? 0, 2, ',', ' ') . ' $',
                [
                    'type' => 'budget_alert',
                    'severity' => 'success',
                    'icon' => '',
                    'action_url' => '/suivi-budgetaire#planification',
                ]
            );
        }

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function validerToutesPrevisions(Request $request)
    {
        $request->validate(['periode_id' => 'required|exists:periodes_comptables,id']);

        Log::info('🔄 Début validation toutes prévisions', [
            'periode_id' => $request->periode_id,
        ]);

        $result = $this->calculService->validerToutesPrevisions(
            $request->periode_id,
            auth()->id() ?? 1
        );

        if ($result['success']) {
            EnregistrerSituationJob::dispatch($request->periode_id, auth()->id());

            try {
                $periode = PeriodesComptable::find($request->periode_id);

                $this->notificationService->notifierEmailSeulement(
                    ' Toutes prévisions validées',
                    ($result['nb_validees'] ?? 0)
                        . ' prévision(s) validée(s) pour ' . ($periode->code ?? 'N/A') . '.',
                    auth()->id()
                );
            } catch (\Exception $e) {
                Log::warning('⚠️ Erreur notification validation', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function rejeterPrevision(Request $request, $id)
    {
        $request->validate(['motif' => 'required|string|min:5|max:500']);

        $result = $this->calculService->rejeterPrevision(
            $id,
            auth()->id() ?? 1,
            $request->motif
        );

        if ($result['success']) {
            $p = $result['data'] ?? null;

            $this->notificationService->notifierEmailSeulement(
                '❌ Prévision rejetée',
                ($p->rubrique->libelle ?? 'N/A')
                    . ' - Mois ' . ($p->mois ?? 'N/A')
                    . ' : ' . number_format($p->montant ?? 0, 2, ',', ' ') . ' $'
                    . ' - Motif : ' . $request->motif,
                auth()->id()
            );
        }

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    // =========================================================================
    //  CORRIGÉ : STATISTIQUES PAR ENTITÉ (GRAPHIQUE DASHBOARD)
    //
    //  LOGIQUE GRAND LIVRE :
    //  - INCLUT le bilan initial + mouvements (comme le Grand Livre)
    //  - EXCLUT uniquement les clôtures
    //
    //  ⚠️ ATTENTION : Si tu veux EXCLURE le bilan initial du graphique,
    //     décommente le bloc "EXCLUT_BILAN" ci-dessous.
    // =========================================================================
    public function statistiquesParEntite(Request $request)
    {
        $periodeId = $request->input('periode_id');

        if (!$periodeId) {
            return response()->json([
                'success' => false,
                'message' => 'Période requise',
            ], 400);
        }

        try {
            $entites = Entite::where('is_active', 1)
                ->orderBy('code')
                ->get();

            if ($entites->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                ]);
            }

            $periode = PeriodesComptable::find($periodeId);

            if (!$periode) {
                return response()->json([
                    'success' => false,
                    'message' => 'Période introuvable',
                ], 404);
            }

            $data = [];

            $couleurs = [
                'rgba(59, 130, 246, 0.8)',
                'rgba(16, 185, 129, 0.8)',
                'rgba(245, 158, 11, 0.8)',
                'rgba(239, 68, 68, 0.8)',
                'rgba(139, 92, 246, 0.8)',
                'rgba(236, 72, 153, 0.8)',
                'rgba(20, 184, 166, 0.8)',
                'rgba(249, 115, 22, 0.8)',
                'rgba(99, 102, 241, 0.8)',
                'rgba(234, 179, 8, 0.8)',
            ];

            foreach ($entites as $index => $entite) {
                $realisations = [];

                for ($mois = 1; $mois <= 12; $mois++) {
                    $dateDebut = $periode->date_debut->copy()->setMonth($mois)->startOfMonth();
                    $dateFin = $periode->date_debut->copy()->setMonth($mois)->endOfMonth();

                    if ($dateDebut->lt($periode->date_debut) || $dateFin->gt($periode->date_fin)) {
                        $realisations[] = 0;
                        continue;
                    }

                    $montant = \App\Models\EcrituresComptable::where('entite_id', $entite->id)
                        ->where('periode_id', $periodeId)
                        ->whereBetween('date_ecriture', [$dateDebut, $dateFin])
                        ->where(function ($q) {
                            //  EXCLUT UNIQUEMENT les clôtures
                            $q->where('reference', 'NOT LIKE', 'CLÔTURE%')
                              ->where('reference', 'NOT LIKE', 'CLOTURE%')
                              ->orWhereNull('reference');

                            // ⚠️ EXCLUT_BILAN : décommenter ci-dessous si tu veux exclure le bilan initial
                            // $q->where('reference', 'NOT LIKE', 'BILAN%')
                            //   ->where('reference', 'NOT LIKE', 'À-NOUVEAU%')
                            //   ->where('reference', 'NOT LIKE', 'A-NOUVEAU%');
                        })
                        ->sum(\Illuminate\Support\Facades\DB::raw('debit + credit'));

                    $realisations[] = round(abs($montant), 2);
                }

                $data[] = [
                    'id' => $entite->id,
                    'nom' => $entite->nom ?? $entite->libelle ?? ("Entité " . ($index + 1)),
                    'libelle' => $entite->libelle ?? $entite->nom ?? ("Entité " . ($index + 1)),
                    'code' => $entite->code ?? '',
                    'realisations' => $realisations,
                    'couleur' => $couleurs[$index % count($couleurs)],
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur statistiques par entité', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'periode_id' => $periodeId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du chargement des données : ' . $e->getMessage(),
            ], 500);
        }
    }
}