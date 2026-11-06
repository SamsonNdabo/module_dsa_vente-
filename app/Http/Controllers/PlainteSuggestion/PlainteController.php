<?php

namespace App\Http\Controllers\PlainteSuggestion;

use App\Http\Controllers\Controller;
use App\Http\Requests\PlainteSuggestion\PlainteSuggestionRequest;
use App\Models\PlainteSuggestion\Plainte;
use App\Models\Utilisateur;
use App\Services\PlainteSuggestion\PlainteExportService;
use App\Services\PlainteSuggestion\PlainteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PlainteController extends Controller
{
    public function __construct(
        protected PlainteService $service,
        protected PlainteExportService $exportService,
    ) {}

    // =========================================================
    // INDEX - Liste filtrée
    // =========================================================
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $this->service->lister($request->all())
        );
    }

    // =========================================================
    // STORE
    // =========================================================
    public function store(PlainteSuggestionRequest $request): JsonResponse
    {
        $plainte = $this->service->creer($request->validated(), Auth::id());

        return response()->json([
            'message' => 'Plainte créée avec succès.',
            'plainte' => $plainte->load(['client', 'utilisateur']),
        ], 201);
    }

    // =========================================================
    // SHOW — accepte ID (int) OU référence (string "PL-2026-XXXX")
    // =========================================================
    public function show($id): JsonResponse
    {
        $plainte = $this->resoudrePlainte($id);

        return response()->json(
            $this->service->trouver($plainte->id)
        );
    }

    // =========================================================
    // UPDATE
    // =========================================================
    public function update(PlainteSuggestionRequest $request, $id): JsonResponse
    {
        $plainte = $this->resoudrePlainte($id);
        $plainte = $this->service->mettreAJour($plainte, $request->validated());

        return response()->json([
            'message' => 'Plainte mise à jour.',
            'plainte' => $plainte,
        ]);
    }

    // =========================================================
    // DESTROY
    // =========================================================
    public function destroy($id): JsonResponse
    {
        $plainte = $this->resoudrePlainte($id);
        $this->service->supprimer($plainte);

        return response()->json(['message' => 'Plainte supprimée.']);
    }

    // =========================================================
    // AFFECTER
    // =========================================================
    public function affecter(PlainteSuggestionRequest $request, $id): JsonResponse
    {
        $plainte     = $this->resoudrePlainte($id);
        $utilisateur = Utilisateur::findOrFail($request->utilisateur_id);

        $plainte = $this->service->affecter(
            $plainte,
            $utilisateur,
            $request->commentaire,
            Auth::id()
        );

        return response()->json([
            'message' => "Plainte affectée à {$utilisateur->nom_complet}.",
            'plainte' => $plainte->load(['utilisateur', 'historiques']),
        ]);
    }

    // =========================================================
    // CHANGER STATUT
    // =========================================================
    public function changerStatut(PlainteSuggestionRequest $request, $id): JsonResponse
    {
        $plainte = $this->resoudrePlainte($id);

        $plainte = $this->service->changerStatut(
            $plainte,
            $request->statut,
            $request->commentaire_resolution,
            Auth::id()
        );

        return response()->json([
            'message' => "Statut changé en {$request->statut}.",
            'plainte' => $plainte->load(['utilisateur', 'historiques']),
        ]);
    }

    // =========================================================
    // KPI GLOBAL
    // =========================================================
    public function kpi(Request $request): JsonResponse
    {
        return response()->json(
            $this->service->kpi($request->all())
        );
    }

    // =========================================================
    // KPI DÉTAILLÉ
    // =========================================================
    public function kpiDetail(Request $request): JsonResponse
    {
        $request->validate([
            'type'   => ['required', 'in:zone,agent,partenaire'],
            'valeur' => ['required', 'string'],
        ]);

        $query = Plainte::query();

        switch ($request->type) {
            case 'zone':
                $query->where('zone', $request->valeur);
                break;
            case 'agent':
                $query->where('utilisateur_id', $request->valeur);
                break;
            case 'partenaire':
                $query->whereHas('client', fn ($q) =>
                    $q->where('categorie_client', $request->valeur)
                );
                break;
        }

        $stats = (clone $query)->selectRaw("
            COUNT(*) AS nombre_total,
            ROUND(AVG(delai_resolution_heures), 2) AS delai_moyen_heures,
            SUM(CASE WHEN statut IN ('NOUVELLE','EN_COURS') THEN 1 ELSE 0 END) AS ouvertes,
            SUM(CASE WHEN statut IN ('RESOLUE','REJETEE') THEN 1 ELSE 0 END) AS cloturees
        ")->first();

        $details = $query->with(['client', 'utilisateur'])
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'type'    => $request->type,
            'valeur'  => $request->valeur,
            'stats'   => $stats,
            'details' => $details,
        ]);
    }

    // =========================================================
    // 📜 HISTORIQUE GLOBAL — Toutes les actions de toutes les plaintes
    // Route : GET /api/plaintes/historique
    // =========================================================
    public function historique(Request $request): JsonResponse
    {
        // -----------------------------------------------------
        // Étape 1 : Requête de base avec relations
        // -----------------------------------------------------
        $query = Plainte::with([
            'historiques.utilisateur:id,nom_complet',
            'historiques.ancienUtilisateur:id,nom_complet',
            'historiques.nouveauUtilisateur:id,nom_complet',
        ]);

        // -----------------------------------------------------
        // Étape 2 : Filtres optionnels sur la PLAINTE
        // -----------------------------------------------------
        if ($request->filled('annee')) {
            $query->whereYear('created_at', (int) $request->annee);
        }

        if ($request->filled('statut')) {
            $query->where('statut', $request->statut);
        }

        if ($request->filled('priorite')) {
            $query->where('priorite', $request->priorite);
        }

        if ($request->filled('zone')) {
            $query->where('zone', $request->zone);
        }

        // -----------------------------------------------------
        // Étape 3 : Récupération des plaintes
        // -----------------------------------------------------
        $plaintes = $query->orderByDesc('created_at')->get();

        // -----------------------------------------------------
        // Étape 4 : Aplatir tous les historiques
        // -----------------------------------------------------
        $historiques = [];

        foreach ($plaintes as $plainte) {
            foreach ($plainte->historiques as $historique) {
                $historiques[] = [
                    'id'             => $historique->id,
                    'action'         => $historique->action,
                    'commentaire'    => $historique->commentaire,
                    'ancien_statut'  => $historique->ancien_statut,
                    'nouveau_statut' => $historique->nouveau_statut,
                    'created_at'     => $historique->created_at?->toISOString(),
                    'utilisateur'    => $historique->utilisateur ? [
                        'id'          => $historique->utilisateur->id,
                        'nom_complet' => $historique->utilisateur->nom_complet,
                    ] : null,
                    'plainte' => [
                        'id'          => $plainte->id,
                        'reference'   => $plainte->reference,
                        'objet'       => $plainte->objet,
                        'statut'      => $plainte->statut,
                        'priorite'    => $plainte->priorite,
                        'zone'        => $plainte->zone,
                        'description' => $plainte->description,
                    ],
                ];
            }
        }

        // -----------------------------------------------------
        // Étape 5 : FILTRES SUR DATE D'HISTORIQUE (après aplatissement)
        // -----------------------------------------------------
        if ($request->filled('date_debut') || $request->filled('date_fin')) {
            $debut = null;
            $fin   = null;

            if ($request->filled('date_debut')) {
                $debut = strtotime($request->date_debut . ' 00:00:00');
            }

            if ($request->filled('date_fin')) {
                $fin = strtotime($request->date_fin . ' 23:59:59');
            }

            $historiquesFiltres = [];

            foreach ($historiques as $historique) {
                // Si l'historique n'a pas de date → on l'exclut
                if (empty($historique['created_at'])) {
                    continue;
                }

                $timestamp = strtotime($historique['created_at']);

                // Vérifie la date de début
                if ($debut !== null && $timestamp < $debut) {
                    continue;
                }

                // Vérifie la date de fin
                if ($fin !== null && $timestamp > $fin) {
                    continue;
                }

                $historiquesFiltres[] = $historique;
            }

            $historiques = $historiquesFiltres;
        }

        // -----------------------------------------------------
        // Étape 6 : Trier par date décroissante
        // -----------------------------------------------------
        usort($historiques, function ($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });

        // -----------------------------------------------------
        // Étape 7 : Retourner la réponse
        // -----------------------------------------------------
        return response()->json([
            'data'  => array_values($historiques),
            'total' => count($historiques),
        ]);
    }

    // =========================================================
    // EXPORT PDF - Avec graphiques via QuickChart
    // =========================================================
    public function exportPdf(Request $request)
    {
        $filtres = $request->all();
        $data = $this->service->kpi($filtres);

        if (!class_exists('\Barryvdh\DomPDF\Facade\Pdf')) {
            return response()->json([
                'message' => 'Installation requise : composer require barryvdh/laravel-dompdf',
                'data' => $data,
            ], 501);
        }

        // -----------------------------------------------------
        // Graphique 1 : Bar Chart (Plaintes par zone)
        // -----------------------------------------------------
        $labels   = [];
        $totaux   = [];
        $resolues = [];

        foreach (($data['par_zone'] ?? []) as $zone) {
            $labels[]    = $zone->zone;
            $totaux[]    = $zone->nombre_total;
            $resolues[]  = $zone->resolues ?? $zone->cloturees ?? 0;
        }

        $barConfig = [
            'type' => 'bar',
            'data' => [
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => 'Total',
                        'data' => $totaux,
                        'backgroundColor' => '#3b82f6',
                    ],
                    [
                        'label' => 'Résolues',
                        'data' => $resolues,
                        'backgroundColor' => '#10b981',
                    ],
                ],
            ],
            'options' => [
                'plugins' => ['legend' => ['position' => 'top']],
                'scales' => ['y' => ['beginAtZero' => true]],
            ],
        ];

        $barUrl = 'https://quickchart.io/chart?w=500&h=280&bkg=white&c=' . urlencode(json_encode($barConfig));

        // -----------------------------------------------------
        // Graphique 2 : Doughnut (Par statut)
        // -----------------------------------------------------
        $statutMap = [];

        foreach (($data['par_statut'] ?? []) as $statut) {
            $statutMap[$statut->statut] = $statut->nombre_total;
        }

        $doughnutConfig = [
            'type' => 'doughnut',
            'data' => [
                'labels' => ['Nouvelles', 'En cours', 'Résolues', 'Rejetées'],
                'datasets' => [
                    [
                        'data' => [
                            $statutMap['NOUVELLE'] ?? 0,
                            $statutMap['EN_COURS'] ?? 0,
                            $statutMap['RESOLUE'] ?? 0,
                            $statutMap['REJETEE'] ?? 0,
                        ],
                        'backgroundColor' => ['#3b82f6', '#f59e0b', '#10b981', '#ef4444'],
                        'borderWidth' => 0,
                    ],
                ],
            ],
            'options' => [
                'plugins' => ['legend' => ['position' => 'right']],
                'cutout' => '65%',
            ],
        ];

        $doughnutUrl = 'https://quickchart.io/chart?w=500&h=280&bkg=white&c=' . urlencode(json_encode($doughnutConfig));

        // -----------------------------------------------------
        // Graphique 3 : Line Chart (Évolution mensuelle)
        // -----------------------------------------------------
        $mois = ['Jan', 'Fév', 'Mars', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sept', 'Oct', 'Nov', 'Déc'];

        $nouvellesArr = [];
        $enCoursArr   = [];
        $resoluesArr  = [];

        $parMois = $data['par_mois'] ?? [];

        for ($m = 1; $m <= 12; $m++) {
            $row = $parMois[$m] ?? null;

            if (is_array($row)) {
                $nouvellesArr[] = $row['nouvelles'] ?? 0;
                $enCoursArr[]   = $row['en_cours'] ?? 0;
                $resoluesArr[]  = $row['resolues'] ?? 0;
            } elseif (is_object($row)) {
                $nouvellesArr[] = $row->nouvelles ?? 0;
                $enCoursArr[]   = $row->en_cours ?? 0;
                $resoluesArr[]  = $row->resolues ?? 0;
            } else {
                $nouvellesArr[] = 0;
                $enCoursArr[]   = 0;
                $resoluesArr[]  = 0;
            }
        }

        $lineConfig = [
            'type' => 'line',
            'data' => [
                'labels' => $mois,
                'datasets' => [
                    [
                        'label' => 'Nouvelles',
                        'data' => $nouvellesArr,
                        'borderColor' => '#f59e0b',
                        'backgroundColor' => 'rgba(245,158,11,0.1)',
                        'fill' => false,
                        'tension' => 0.4,
                    ],
                    [
                        'label' => 'En cours',
                        'data' => $enCoursArr,
                        'borderColor' => '#10b981',
                        'backgroundColor' => 'rgba(16,185,129,0.1)',
                        'fill' => false,
                        'tension' => 0.4,
                    ],
                    [
                        'label' => 'Résolues',
                        'data' => $resoluesArr,
                        'borderColor' => '#3b82f6',
                        'backgroundColor' => 'rgba(59,130,246,0.1)',
                        'fill' => false,
                        'tension' => 0.4,
                    ],
                ],
            ],
            'options' => [
                'plugins' => ['legend' => ['position' => 'bottom']],
                'scales' => ['y' => ['beginAtZero' => true]],
            ],
        ];

        $lineUrl = 'https://quickchart.io/chart?w=1000&h=300&bkg=white&c=' . urlencode(json_encode($lineConfig));

        // -----------------------------------------------------
        // Génération du PDF
        // -----------------------------------------------------
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('plaintes.rapport', [
            'data' => $data,
            'filtres' => $filtres,
            'charts' => [
                'bar_zones'       => $barUrl,
                'line_evolution'  => $lineUrl,
                'doughnut_statut' => $doughnutUrl,
            ],
            'date_generation' => now()->format('d/m/Y H:i'),
        ]);

        $pdf->setOptions([
            'isRemoteEnabled'      => true,
            'isHtml5ParserEnabled' => true,
            'defaultFont'          => 'DejaVu Sans',
        ]);

        $output = $pdf->output();

        return response($output, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="rapport-plaintes-' . now()->format('Y-m-d') . '.pdf"',
            'Content-Length' => strlen($output),
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With',
            'Access-Control-Expose-Headers' => 'Content-Disposition, Content-Type, Content-Length',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'public',
        ]);
    }

    // =========================================================
    // EXPORT EXCEL - Avec PhpSpreadsheet
    // =========================================================
    public function exportExcel(Request $request)
    {
        $filtres = $request->all();

        try {
            $data = $this->service->kpi($filtres);
            $result = $this->exportService->genererKpi($data, $filtres);

            if (!$result['success']) {
                \Log::error('Erreur génération Excel', [
                    'message' => $result['message'] ?? 'inconnue',
                    'filtres' => $filtres,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $result['message'] ?? 'Erreur lors de la génération du fichier.',
                ], 500);
            }

            $tempFile = tempnam(sys_get_temp_dir(), 'rapport_') . '.xlsx';

            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($result['spreadsheet']);
            $writer->setIncludeCharts(true);
            $writer->setPreCalculateFormulas(true);
            $writer->save($tempFile);

            if (!file_exists($tempFile) || filesize($tempFile) === 0) {
                \Log::error('Fichier Excel vide', ['temp' => $tempFile]);

                return response()->json([
                    'success' => false,
                    'message' => 'Le fichier généré est vide.',
                ], 500);
            }

            return response()->download(
                $tempFile,
                $result['filename'],
                [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'Access-Control-Allow-Origin' => '*',
                    'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
                    'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With',
                    'Access-Control-Expose-Headers' => 'Content-Disposition, Content-Type, Content-Length',
                    'Cache-Control' => 'no-cache, no-store, must-revalidate',
                    'Pragma' => 'public',
                ]
            )->deleteFileAfterSend(true);

        } catch (\Throwable $e) {
            \Log::error('Exception export Excel', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur : ' . $e->getMessage(),
            ], 500);
        }
    }

    // =========================================================
    // HELPER : Résoudre une plainte par ID (int) OU référence (string)
    // =========================================================
    private function resoudrePlainte($id): Plainte
    {
        // Si c'est un nombre pur (ex: "2", "15") → cherche par ID
        if (is_numeric($id) && ctype_digit((string) $id)) {
            return Plainte::findOrFail((int) $id);
        }

        // Sinon → cherche par référence (ex: "PL-2026-0002")
        return Plainte::where('reference', $id)->firstOrFail();
    }

    // =========================================================
    // HELPER - Headers HTTP pour Excel
    // =========================================================
    private function excelHeaders(string $filename): array
    {
        return [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With',
            'Access-Control-Expose-Headers' => 'Content-Disposition',
            'Cache-Control' => 'max-age=0, no-cache, no-store, must-revalidate',
            'Pragma' => 'public',
        ];
    }
}