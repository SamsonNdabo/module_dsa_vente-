<?php

namespace App\Http\Controllers\PlainteSuggestion;

use App\Http\Controllers\Controller;
use App\Http\Requests\PlainteSuggestion\PlainteSuggestionRequest;
use App\Models\PlainteSuggestion\Suggestion;
use App\Models\Utilisateur;
use App\Services\PlainteSuggestion\SuggestionExportService;
use App\Services\PlainteSuggestion\SuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SuggestionController extends Controller
{
    public function __construct(
        protected SuggestionService $service,
        protected SuggestionExportService $exportService,
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
        $suggestion = $this->service->creer($request->validated(), Auth::id());

        return response()->json([
            'message'    => 'Suggestion créée avec succès.',
            'suggestion' => $suggestion->load(['client', 'utilisateur']),
        ], 201);
    }

    // =========================================================
    // SHOW — accepte ID (int) OU référence (string "SG-2026-XXXX")
    // =========================================================
    public function show($id): JsonResponse
    {
        $suggestion = $this->resoudreSuggestion($id);

        return response()->json(
            $this->service->trouver($suggestion->id)
        );
    }

    // =========================================================
    // UPDATE
    // =========================================================
    public function update(PlainteSuggestionRequest $request, $id): JsonResponse
    {
        $suggestion = $this->resoudreSuggestion($id);
        $suggestion = $this->service->mettreAJour($suggestion, $request->validated());

        return response()->json([
            'message'    => 'Suggestion mise à jour.',
            'suggestion' => $suggestion,
        ]);
    }

    // =========================================================
    // DESTROY
    // =========================================================
    public function destroy($id): JsonResponse
    {
        $suggestion = $this->resoudreSuggestion($id);
        $this->service->supprimer($suggestion);

        return response()->json(['message' => 'Suggestion supprimée.']);
    }

    // =========================================================
    // AFFECTER
    // =========================================================
    public function affecter(PlainteSuggestionRequest $request, $id): JsonResponse
    {
        $suggestion  = $this->resoudreSuggestion($id);
        $utilisateur = Utilisateur::findOrFail($request->utilisateur_id);

        $suggestion = $this->service->affecter(
            $suggestion,
            $utilisateur,
            $request->commentaire,
            Auth::id()
        );

        return response()->json([
            'message'    => "Suggestion affectée à {$utilisateur->nom_complet}.",
            'suggestion' => $suggestion->load(['utilisateur', 'historiques']),
        ]);
    }

    // =========================================================
    // CHANGER STATUT
    // =========================================================
    public function changerStatut(PlainteSuggestionRequest $request, $id): JsonResponse
    {
        $suggestion = $this->resoudreSuggestion($id);

        $suggestion = $this->service->changerStatut(
            $suggestion,
            $request->statut,
            $request->commentaire_resolution,
            Auth::id()
        );

        return response()->json([
            'message'    => "Statut changé en {$request->statut}.",
            'suggestion' => $suggestion->load(['utilisateur', 'historiques']),
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

        $query = Suggestion::query();

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
    // 📜 HISTORIQUE GLOBAL — Toutes les actions de toutes les suggestions
    // Route : GET /api/suggestions/historique
    // =========================================================
    public function historique(Request $request): JsonResponse
    {
        // -----------------------------------------------------
        // Étape 1 : Requête de base avec relations
        // -----------------------------------------------------
        $query = Suggestion::with([
            'historiques.utilisateur:id,nom_complet',
            'historiques.ancienUtilisateur:id,nom_complet',
            'historiques.nouveauUtilisateur:id,nom_complet',
        ]);

        // -----------------------------------------------------
        // Étape 2 : Filtres optionnels sur la SUGGESTION
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
        // Étape 3 : Récupération des suggestions
        // -----------------------------------------------------
        $suggestions = $query->orderByDesc('created_at')->get();

        // -----------------------------------------------------
        // Étape 4 : Aplatir tous les historiques
        // -----------------------------------------------------
        $historiques = [];

        foreach ($suggestions as $suggestion) {
            foreach ($suggestion->historiques as $historique) {
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
                    'suggestion' => [
                        'id'          => $suggestion->id,
                        'reference'   => $suggestion->reference,
                        'objet'       => $suggestion->objet,
                        'statut'      => $suggestion->statut,
                        'priorite'    => $suggestion->priorite,
                        'zone'        => $suggestion->zone,
                        'description' => $suggestion->description,
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
    // EXPORT PDF
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

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('suggestions.rapport', [
            'data' => $data,
            'filtres' => $filtres,
            'date_generation' => now()->format('d/m/Y H:i'),
        ]);

        return $pdf->download('rapport-suggestions-' . now()->format('Y-m-d') . '.pdf');
    }

    // =========================================================
    // EXPORT EXCEL - Avec PhpSpreadsheet + CORS
    // =========================================================
    public function exportExcel(Request $request)
    {
        $filtres = $request->all();

        try {
            $data = $this->service->kpi($filtres);
            $result = $this->exportService->genererKpi($data, $filtres);

            if (!$result['success']) {
                \Log::error('Erreur génération Excel suggestions', [
                    'message' => $result['message'] ?? 'inconnue',
                    'filtres' => $filtres,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $result['message'] ?? 'Erreur lors de la génération du fichier.',
                ], 500);
            }

            $tempFile = tempnam(sys_get_temp_dir(), 'rapport_') . '.xlsx';

            //  Écriture du fichier avec inclusion des graphiques
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($result['spreadsheet']);
            $writer->setIncludeCharts(true);
            $writer->setPreCalculateFormulas(true);
            $writer->save($tempFile);

            if (!file_exists($tempFile) || filesize($tempFile) === 0) {
                \Log::error('Fichier Excel suggestions vide', ['temp' => $tempFile]);

                return response()->json([
                    'success' => false,
                    'message' => 'Le fichier généré est vide.',
                ], 500);
            }

            //  Retour via BinaryFileResponse (Laravel préserve le binaire intact)
            return response()->download(
                $tempFile,
                $result['filename'],
                [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'Access-Control-Allow-Origin'   => '*',
                    'Access-Control-Allow-Methods'  => 'GET, POST, OPTIONS',
                    'Access-Control-Allow-Headers'  => 'Content-Type, Authorization, X-Requested-With',
                    'Access-Control-Expose-Headers' => 'Content-Disposition, Content-Type, Content-Length',
                    'Cache-Control' => 'no-cache, no-store, must-revalidate',
                    'Pragma' => 'public',
                ]
            )->deleteFileAfterSend(true);

        } catch (\Throwable $e) {
            \Log::error('Exception export Excel suggestions', [
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
    //  HELPER : Résoudre une suggestion par ID (int) OU référence (string)
    // =========================================================
    private function resoudreSuggestion($id): Suggestion
    {
        // Si c'est un nombre pur → ID
        if (is_numeric($id) && ctype_digit((string) $id)) {
            return Suggestion::findOrFail((int) $id);
        }

        // Sinon → référence (ex: "SG-2026-0002")
        return Suggestion::where('reference', $id)->firstOrFail();
    }

    // =========================================================
    // HELPER - Headers HTTP pour Excel
    // =========================================================
    private function excelHeaders(string $filename): array
    {
        return [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Access-Control-Allow-Origin'  => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With',
            'Access-Control-Expose-Headers' => 'Content-Disposition',
            'Cache-Control' => 'max-age=0, no-cache, no-store, must-revalidate',
            'Pragma' => 'public',
        ];
    }
}