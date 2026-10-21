<?php

namespace App\Http\Controllers\Comptabilite;

use App\Models\EcrituresComptable;
use App\Models\Entite;
use App\Models\Succursale;
use App\Models\PlanComptable;
use App\Models\OperationsJournal;
use App\Models\PeriodesComptable;
use App\Models\OperationsType;
use App\Models\Utilisateur;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use App\Http\Controllers\Controller;

class JournalController extends Controller
{
    const DATE_COLUMN = 'date_ecriture';
    const CACHE_TTL = 300;

    private $periodeEnCoursCache = null;
    private $comptesPlanCache = null;

    // =========================================================================
    // CACHES
    // =========================================================================

    private function getComptesPlanCache()
    {
        if ($this->comptesPlanCache) return $this->comptesPlanCache;

        $this->comptesPlanCache = PlanComptable::select('numero', 'nom', 'position_bilan', 'position_tfr')
            ->where('is_active', true)
            ->get()
            ->keyBy('numero');

        return $this->comptesPlanCache;
    }

    private function corrigerPositionEcriture($ecriture): void
    {
        $compteNumero = $ecriture->compte_numero;
        $debit = (float)($ecriture->debit ?? 0);
        $credit = (float)($ecriture->credit ?? 0);

        if ($debit > 0 || $credit > 0) {
            return;
        }

        $comptesPlan = $this->getComptesPlanCache();
        $compte = $comptesPlan[$compteNumero] ?? null;

        if ($compte) {
            $positionBilan = $compte->position_bilan ?? null;
            $positionTfr = $compte->position_tfr ?? null;

            if ($positionBilan === 'actif') {
                $ecriture->debit = 0.001;
                $ecriture->credit = 0;
            } elseif ($positionBilan === 'passif') {
                $ecriture->debit = 0;
                $ecriture->credit = 0.001;
            } elseif ($positionTfr === 'charge') {
                $ecriture->debit = 0.001;
                $ecriture->credit = 0;
            } elseif ($positionTfr === 'produit') {
                $ecriture->debit = 0;
                $ecriture->credit = 0.001;
            }
        } else {
            $classe = substr($compteNumero, 0, 1);

            if (in_array($classe, ['2', '3', '5'])) {
                $ecriture->debit = 0.001;
                $ecriture->credit = 0;
            } elseif ($classe === '1') {
                $ecriture->debit = 0;
                $ecriture->credit = 0.001;
            } elseif ($classe === '6') {
                $ecriture->debit = 0.001;
                $ecriture->credit = 0;
            } elseif ($classe === '7') {
                $ecriture->debit = 0;
                $ecriture->credit = 0.001;
            } elseif ($classe === '4') {
                $ecriture->debit = 0;
                $ecriture->credit = 0.001;
            }
        }
    }

    private function getPeriodeEnCoursFromDB()
    {
        if ($this->periodeEnCoursCache) return $this->periodeEnCoursCache;

        $periode = PeriodesComptable::where('date_debut', '<=', now())
            ->where('date_fin', '>=', now())
            ->where('statut', 'ouverte')
            ->orderBy('date_debut', 'asc')
            ->first();

        if (!$periode) {
            $periode = PeriodesComptable::where('statut', 'ouverte')
                ->orderBy('date_debut', 'asc')
                ->first();
        }

        $this->periodeEnCoursCache = $periode;

        return $this->periodeEnCoursCache;
    }

    // =========================================================================
    // 🔥 CLÉ UNIQUE D'UNE OPÉRATION (utilisée partout pour compter ET grouper)
    // =========================================================================
    //
    // Une "opération" = un ensemble de lignes qui partagent la même clé.
    // On utilise la même logique partout pour garantir la cohérence :
    //   parent_ecriture_id → numero_ecriture → operation_journal_id → id
    //
    private function cleOperation($ecriture): string
    {
        // Priorité : parent_ecriture_id (pour les écritures liées à une même opération)
        if (!empty($ecriture->parent_ecriture_id)) {
            return 'parent-' . $ecriture->parent_ecriture_id;
        }

        // Ensuite : numero_ecriture (identifiant unique de l'écriture comptable)
        if (!empty($ecriture->numero_ecriture)) {
            return 'num-' . $ecriture->numero_ecriture;
        }

        // Ensuite : operation_journal_id (opération métier)
        if (!empty($ecriture->operation_journal_id)) {
            return 'op-' . $ecriture->operation_journal_id;
        }

        // Fallback ultime : l'id de la ligne (ne devrait jamais arriver)
        return 'id-' . ($ecriture->id ?? uniqid('', true));
    }

    private function countOperationsDistinctes($ecritures): int
    {
        $vues = [];
        foreach ($ecritures as $ec) {
            $vues[$this->cleOperation($ec)] = true;
        }
        return count($vues);
    }

    // =========================================================================
    // INDEX
    // =========================================================================

    public function index(Request $request)
    {
        $entites = Cache::remember('entites_list', self::CACHE_TTL, function () {
            return Entite::select('id', 'code', 'nom', 'raison_sociale')
                ->orderBy('code', 'asc')
                ->get();
        });

        $periodes = Cache::remember('periodes_list', self::CACHE_TTL, function () {
            return PeriodesComptable::select('id', 'code', 'libelle', 'date_debut', 'date_fin', 'statut')
                ->orderBy('date_debut', 'desc')
                ->get();
        });

        $typesOperations = Cache::remember('types_operations_list', self::CACHE_TTL, function () {
            return OperationsType::select('id', 'code', 'nom')
                ->orderBy('nom', 'asc')
                ->get();
        });

        $comptes = Cache::remember('comptes_plan_list', self::CACHE_TTL, function () {
            return PlanComptable::select('id', 'numero', 'nom')
                ->where('is_active', true)
                ->orderBy('numero', 'asc')
                ->get();
        });

        return view('sections.journal', compact('entites', 'periodes', 'typesOperations', 'comptes'));
    }

    // =========================================================================
    // FILTRES
    // =========================================================================

    private function applyFilters($query, Request $request)
    {
        $periodeId = $request->input('periode_id');
        if (!empty($periodeId) && $periodeId !== 'all') {
            if ($periodeId === 'en-cours') {
                $periodeEnCours = $this->getPeriodeEnCoursFromDB();
                if ($periodeEnCours) {
                    $query->where('periode_id', $periodeEnCours->id);
                }
            } else {
                $query->where('periode_id', $periodeId);
            }
        }

        $filterType = $request->input('filter_type', 'all');
        $dateDebut = $request->input('date_debut');
        $dateFin = $request->input('date_fin');
        $useCustomDates = filter_var($request->input('use_custom_dates', false), FILTER_VALIDATE_BOOLEAN);

        $this->applyTimeFilter($query, $filterType, $dateDebut, $dateFin, $useCustomDates);

        $entiteId = $request->input('entite_id');
        if ($entiteId && $entiteId !== 'all') {
            $query->where('entite_id', $entiteId);
        }

        $succursaleId = $request->input('succursale_id');
        if ($succursaleId && $succursaleId !== 'all') {
            $query->where('succursale_id', $succursaleId);
        }

        $typeId = $request->input('operation_type_id');
        if ($typeId && $typeId !== 'all') {
            $query->whereHas('operations_journal', function ($q) use ($typeId) {
                $q->where('operation_type_id', $typeId)->select('id');
            });
        }

        $compte = $request->input('compte_numero');
        if ($compte && $compte !== 'all') {
            $query->where('compte_numero', $compte);
        }

        return $query;
    }

    private function applyTimeFilter($query, string $filterType, $dateDebut = null, $dateFin = null, $useCustomDates = false): void
    {
        $now = Carbon::now();

        if ($useCustomDates && ($dateDebut || $dateFin)) {
            if ($dateDebut && $dateFin) {
                $query->whereBetween(self::DATE_COLUMN, [$dateDebut, $dateFin]);
            } elseif ($dateDebut) {
                $query->whereDate(self::DATE_COLUMN, '>=', $dateDebut);
            } elseif ($dateFin) {
                $query->whereDate(self::DATE_COLUMN, '<=', $dateFin);
            }
            return;
        }

        $dateRanges = [
            'today' => [$now->toDateString(), $now->toDateString()],
            'yesterday' => [$now->copy()->subDay()->toDateString(), $now->copy()->subDay()->toDateString()],
            'last3days' => [$now->copy()->subDays(3)->toDateString(), $now->toDateString()],
            'this_week' => [$now->copy()->startOfWeek()->toDateString(), $now->copy()->endOfWeek()->toDateString()],
            'last_week' => [$now->copy()->subWeek()->startOfWeek()->toDateString(), $now->copy()->subWeek()->endOfWeek()->toDateString()],
            'last7days' => [$now->copy()->subDays(7)->toDateString(), $now->toDateString()],
            'this_month' => [$now->copy()->startOfMonth()->toDateString(), $now->copy()->endOfMonth()->toDateString()],
            'last_month' => [$now->copy()->subMonth()->startOfMonth()->toDateString(), $now->copy()->subMonth()->endOfMonth()->toDateString()],
            'last30days' => [$now->copy()->subDays(30)->toDateString(), $now->toDateString()],
        ];

        if (isset($dateRanges[$filterType])) {
            [$start, $end] = $dateRanges[$filterType];
            $query->whereBetween(self::DATE_COLUMN, [$start, $end]);
            return;
        }

        switch ($filterType) {
            case 'this_year':
                $query->whereYear(self::DATE_COLUMN, $now->year);
                break;
            case 'all':
                break;
            case 'custom':
                if ($dateDebut && $dateFin) {
                    $query->whereBetween(self::DATE_COLUMN, [$dateDebut, $dateFin]);
                } elseif ($dateDebut) {
                    $query->whereDate(self::DATE_COLUMN, '>=', $dateDebut);
                } elseif ($dateFin) {
                    $query->whereDate(self::DATE_COLUMN, '<=', $dateFin);
                }
                break;
        }
    }

    // =========================================================================
    // SANITIZE
    // =========================================================================

    private function sanitizeForJson($data)
    {
        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                $result[$key] = $this->sanitizeForJson($value);
            }
            return $result;
        }

        if ($data instanceof \Illuminate\Database\Eloquent\Model) {
            return $this->sanitizeForJson($data->toArray());
        }

        if ($data instanceof \Illuminate\Support\Collection) {
            return $this->sanitizeForJson($data->toArray());
        }

        if ($data instanceof \Carbon\Carbon) {
            return $data->format('Y-m-d H:i:s');
        }

        if (is_string($data)) {
            return $this->cleanUTF8($data);
        }

        if (is_float($data) || is_int($data) || is_bool($data) || is_null($data)) {
            return $data;
        }

        return $this->cleanUTF8((string)$data);
    }

    private function cleanUTF8($value)
    {
        if (is_null($value)) {
            return '';
        }

        if (is_string($value)) {
            if (!mb_check_encoding($value, 'UTF-8')) {
                $detectedEncoding = mb_detect_encoding($value, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);

                if ($detectedEncoding && $detectedEncoding !== 'UTF-8') {
                    $value = mb_convert_encoding($value, 'UTF-8', $detectedEncoding);
                } else {
                    $value = mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
                }
            }

            $value = iconv('UTF-8', 'UTF-8//IGNORE', $value);
            $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);

            return trim($value);
        }

        if (is_array($value)) {
            return array_map([$this, 'cleanUTF8'], $value);
        }

        if ($value instanceof \Carbon\Carbon) {
            return $value->format('Y-m-d H:i:s');
        }

        return $value;
    }

    // =========================================================================
    // GET JOURNAL
    // =========================================================================

    public function getJournal(Request $request)
    {
        try {
            $dateCol = self::DATE_COLUMN;

            $queryForTotals = EcrituresComptable::with([
                'operations_journal:id,code_operation,libelle,operation_type_id',
                'entite:id,code,nom',
                'succursale:id,code,nom',
                'plan_comptable:id,numero,nom,position_bilan,position_tfr',
                'utilisateur:id,nom_complet,nom_utilisateur'
            ])->orderBy($dateCol, 'asc')
              ->orderBy('id', 'asc');

            $queryForTotals = $this->applyFilters($queryForTotals, $request);

            // ✅ Totaux GLOBAUX
            $totalsQuery = clone $queryForTotals;
            $allEcrituresForTotals = $totalsQuery
                ->select('id', 'debit', 'credit', 'operation_journal_id', 'parent_ecriture_id', 'numero_ecriture')
                ->get();

            $totalDebitGlobal = round((float)$allEcrituresForTotals->sum('debit'), 2);
            $totalCreditGlobal = round((float)$allEcrituresForTotals->sum('credit'), 2);

            // 🎯 Nombre d'OPÉRATIONS globales (via la clé unifiée)
            $nbTotalOperations = $this->countOperationsDistinctes($allEcrituresForTotals);

            $perPage = (int)$request->input('per_page', 100);
            $page = (int)$request->input('page', 1);

            // ✅ Écritures CUMULÉES
            $cumulativeQuery = clone $queryForTotals;
            $cumulativeEcritures = $cumulativeQuery
                ->select('id', 'debit', 'credit', 'operation_journal_id', 'parent_ecriture_id', 'numero_ecriture')
                ->limit($page * $perPage)
                ->get();

            $totalDebitCumulatif = round((float)$cumulativeEcritures->sum('debit'), 2);
            $totalCreditCumulatif = round((float)$cumulativeEcritures->sum('credit'), 2);

            // 🎯 Nombre d'OPÉRATIONS cumulées (via la clé unifiée)
            $nbEcrituresCumulatif = $this->countOperationsDistinctes($cumulativeEcritures);

            $ecritures = $queryForTotals->paginate($perPage);

            $periodeInfo = $this->getPeriodeInfo($request->input('periode_id'));

            if ($ecritures->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'operations' => [],
                    'total_debit' => 0,
                    'total_credit' => 0,
                    'nb_ecritures' => 0,
                    'nb_operations' => 0,
                    'total_debit_global' => 0,
                    'total_credit_global' => 0,
                    'nb_ecritures_global' => 0,
                    'periode' => $this->formatPeriodeInfo($periodeInfo),
                    'pagination' => [
                        'total' => 0,
                        'per_page' => $perPage,
                        'current_page' => 1,
                        'last_page' => 1,
                        'has_more_pages' => false,
                    ]
                ], 200, ['Content-Type' => 'application/json; charset=UTF-8'], JSON_UNESCAPED_UNICODE);
            }

            $operationsArray = $this->groupOperations($ecritures->getCollection(), $dateCol);
            $operationsArray = $this->sanitizeForJson($operationsArray);

            return response()->json([
                'success' => true,
                'operations' => $operationsArray,

                'total_debit' => $totalDebitCumulatif,
                'total_credit' => $totalCreditCumulatif,
                'nb_ecritures' => $nbEcrituresCumulatif,
                'nb_operations' => count($operationsArray),

                'total_debit_global' => $totalDebitGlobal,
                'total_credit_global' => $totalCreditGlobal,
                'nb_ecritures_global' => $nbTotalOperations,

                'periode' => $this->formatPeriodeInfo($periodeInfo),
                'pagination' => [
                    'total' => (int)$ecritures->total(),
                    'per_page' => (int)$ecritures->perPage(),
                    'current_page' => (int)$ecritures->currentPage(),
                    'last_page' => (int)$ecritures->lastPage(),
                    'has_more_pages' => (bool)$ecritures->hasMorePages(),
                ]
            ], 200, ['Content-Type' => 'application/json; charset=UTF-8'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        } catch (\Exception $e) {
            Log::error('Journal error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request_params' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors du chargement du journal',
                'pagination' => [
                    'total' => 0,
                    'per_page' => 100,
                    'current_page' => 1,
                    'last_page' => 1,
                    'has_more_pages' => false,
                ]
            ], 500, ['Content-Type' => 'application/json; charset=UTF-8'], JSON_UNESCAPED_UNICODE);
        }
    }

    // =========================================================================
    // UTILITAIRES
    // =========================================================================

    private function formatNomCompte($nom)
    {
        if (empty($nom)) return '';
        return ucfirst(mb_strtolower($this->cleanUTF8($nom)));
    }

    private function trierDebitsAvantCredits($lignes)
    {
        $debits = [];
        $credits = [];

        foreach ($lignes as $l) {
            $debit = (float)($l['debit'] ?? 0);
            if ($debit > 0) {
                $debits[] = $l;
            } else {
                $credits[] = $l;
            }
        }

        return array_merge($debits, $credits);
    }

    private function getActeur($ecriture): string
    {
        $acteur = '';

        if (is_object($ecriture)) {
            if ($ecriture->relationLoaded('utilisateur') && $ecriture->utilisateur) {
                $acteur = $ecriture->utilisateur->nom_complet ?? $ecriture->utilisateur->nom_utilisateur ?? '';
            } elseif (!empty($ecriture->id_utilisateur)) {
                $user = Utilisateur::select('id', 'nom_complet', 'nom_utilisateur')
                    ->find($ecriture->id_utilisateur);

                if ($user) {
                    $acteur = $user->nom_complet ?? $user->nom_utilisateur ?? '';
                }
            }
        }

        return $this->cleanUTF8($acteur);
    }

    private function formatDateValue($value): string
    {
        if (empty($value)) return '';

        if ($value instanceof Carbon) {
            return $value->format('d/m/Y');
        }

        if (is_string($value)) {
            try {
                return Carbon::parse($value)->format('d/m/Y');
            } catch (\Exception $e) {
                if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $value)) {
                    return $value;
                }
            }
        }

        return '';
    }

    /**
     * 🔥 Groupe les lignes par OPÉRATION
     * ✅ Utilise la MÊME clé que countOperationsDistinctes → cohérence garantie
     */
    private function groupOperations($ecritures, $dateCol): array
    {
        $operations = [];

        foreach ($ecritures as $ec) {
            // 🔥 Clé unifiée (identique au comptage)
            $key = $this->cleOperation($ec);

            if (!isset($operations[$key])) {
                $operations[$key] = [
                    'date' => $this->cleanUTF8($ec->$dateCol ?? ''),
                    'reference' => $this->cleanUTF8($ec->reference ?? 'Non assignée'),
                    'libelle_principal' => '',
                    'numero_ecriture' => $this->cleanUTF8($ec->numero_ecriture ?? ''),
                    'acteur' => '',
                    'blocs' => []
                ];
            }

            if (!empty($ec->reference) && $operations[$key]['reference'] === 'Non assignée') {
                $operations[$key]['reference'] = $this->cleanUTF8($ec->reference);
            }

            if (empty($operations[$key]['libelle_principal']) && !$ec->is_auto) {
                $operations[$key]['libelle_principal'] = $this->cleanUTF8($ec->libelle ?? '');
            }

            $sens = $this->cleanUTF8($ec->sens_ecriture ?? 'principal');

            if (!isset($operations[$key]['blocs'][$sens])) {
                $operations[$key]['blocs'][$sens] = [
                    'sens' => $sens,
                    'lignes' => []
                ];
            }

            $ligne = [
                'id' => $ec->id ?? null,
                'compte_numero' => $this->cleanUTF8($ec->compte_numero ?? ''),
                'libelle' => $this->cleanUTF8($ec->libelle ?? ''),
                'debit' => (float)($ec->debit ?? 0),
                'credit' => (float)($ec->credit ?? 0),
                'is_tva' => (bool)($ec->is_tva ?? false),
                'is_auto' => (bool)($ec->is_auto ?? false),
            ];

            if ($ec->relationLoaded('plan_comptable') && $ec->plan_comptable) {
                $ligne['plan_comptable'] = [
                    'id' => $ec->plan_comptable->id ?? null,
                    'numero' => $this->cleanUTF8($ec->plan_comptable->numero ?? ''),
                    'nom' => $this->cleanUTF8($ec->plan_comptable->nom ?? ''),
                    'position_bilan' => $this->cleanUTF8($ec->plan_comptable->position_bilan ?? ''),
                    'position_tfr' => $this->cleanUTF8($ec->plan_comptable->position_tfr ?? ''),
                ];
            }

            $operations[$key]['blocs'][$sens]['lignes'][] = $ligne;

            if (empty($operations[$key]['acteur'])) {
                $operations[$key]['acteur'] = $this->getActeur($ec);
            }
        }

        foreach ($operations as &$op) {
            foreach ($op['blocs'] as $sens => &$bloc) {
                $bloc['lignes'] = $this->trierDebitsAvantCredits($bloc['lignes']);
            }
        }
        unset($op, $bloc);

        return array_values($operations);
    }

    private function getPeriodeInfo($periodeId)
    {
        if (empty($periodeId) || $periodeId === 'all') return null;
        if ($periodeId === 'en-cours') return $this->getPeriodeEnCoursFromDB();

        return PeriodesComptable::find($periodeId);
    }

    private function formatPeriodeInfo($periode): ?array
    {
        if (!$periode) return null;

        return [
            'id' => $periode->id,
            'code' => $this->cleanUTF8($periode->code),
            'libelle' => $this->cleanUTF8($periode->libelle),
            'date_debut' => $this->cleanUTF8($periode->date_debut instanceof Carbon ? $periode->date_debut->format('Y-m-d') : $periode->date_debut),
            'date_fin' => $this->cleanUTF8($periode->date_fin instanceof Carbon ? $periode->date_fin->format('Y-m-d') : $periode->date_fin),
            'is_cloture' => (bool)($periode->is_cloture ?? false),
            'statut' => $this->cleanUTF8($periode->statut ?? 'ouverte'),
        ];
    }

    private function getAppliedFilters(Request $request): array
    {
        return [
            'filter_type' => $this->cleanUTF8($request->input('filter_type', 'all')),
            'use_custom_dates' => filter_var($request->input('use_custom_dates', false), FILTER_VALIDATE_BOOLEAN),
            'periode_id' => $this->cleanUTF8($request->input('periode_id')),
            'entite_id' => $this->cleanUTF8($request->input('entite_id')),
            'succursale_id' => $this->cleanUTF8($request->input('succursale_id')),
            'operation_type_id' => $this->cleanUTF8($request->input('operation_type_id')),
            'compte_numero' => $this->cleanUTF8($request->input('compte_numero')),
            'date_debut' => $this->cleanUTF8($request->input('date_debut')),
            'date_fin' => $this->cleanUTF8($request->input('date_fin')),
        ];
    }

    // =========================================================================
    // API
    // =========================================================================

    public function getPeriodes()
    {
        $periodes = PeriodesComptable::select('id', 'code', 'libelle', 'date_debut', 'date_fin', 'is_cloture', 'statut')
            ->orderBy('date_debut', 'desc')
            ->get()
            ->map(function ($p) {
                return [
                    'id' => (int)$p->id,
                    'code' => $this->cleanUTF8($p->code),
                    'libelle' => $this->cleanUTF8($p->libelle),
                    'date_debut' => $this->cleanUTF8($p->date_debut),
                    'date_fin' => $this->cleanUTF8($p->date_fin),
                    'is_cloture' => (bool)$p->is_cloture,
                    'statut' => $this->cleanUTF8($p->statut ?? 'ouverte'),
                ];
            })
            ->toArray();

        return response()->json([
            'success' => true,
            'periodes' => $periodes,
        ], 200, [
            'Content-Type' => 'application/json; charset=UTF-8'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function getPeriodeEnCours()
    {
        $periode = $this->getPeriodeEnCoursFromDB();

        return response()->json([
            'success' => true,
            'periode' => $periode ? $this->formatPeriodeInfo($periode) : null
        ], 200, [
            'Content-Type' => 'application/json; charset=UTF-8'
        ], JSON_UNESCAPED_UNICODE);
    }

    public function getSuccursalesByEntite($entite_id)
    {
        $succursales = Succursale::select('id', 'code', 'nom', 'ville')
            ->where('entite_id', $entite_id)
            ->where('est_active', 1)
            ->orderBy('code', 'asc')
            ->get()
            ->map(function ($s) {
                return [
                    'id' => (int)$s->id,
                    'code' => $this->cleanUTF8($s->code),
                    'nom' => $this->cleanUTF8($s->nom),
                    'ville' => $this->cleanUTF8($s->ville),
                ];
            });

        return response()->json($succursales, 200, [
            'Content-Type' => 'application/json; charset=UTF-8'
        ], JSON_UNESCAPED_UNICODE);
    }

    // =========================================================================
    // EXPORT JOURNAL
    // =========================================================================

    public function exportJournal(Request $request)
    {
        try {
            $password = 'Journal2024@Secure';
            $dateCol = self::DATE_COLUMN;

            $query = EcrituresComptable::with(['operations_journal', 'entite', 'succursale', 'plan_comptable', 'utilisateur'])
                ->orderBy($dateCol, 'asc')
                ->orderBy('id', 'asc');

            $query = $this->applyFilters($query, $request);
            $ecritures = $query->get();

            $ecritures = $ecritures->map(function ($ecriture) {
                foreach ($ecriture->getAttributes() as $key => $value) {
                    if (is_string($value)) {
                        $ecriture->setAttribute($key, $this->cleanUTF8($value));
                    }
                }

                $ecriture->debit = (float)$ecriture->debit;
                $ecriture->credit = (float)$ecriture->credit;

                if ($ecriture->relationLoaded('plan_comptable') && $ecriture->plan_comptable) {
                    $ecriture->plan_comptable->nom = $this->cleanUTF8($ecriture->plan_comptable->nom);
                    $ecriture->plan_comptable->numero = $this->cleanUTF8($ecriture->plan_comptable->numero);
                }

                if ($ecriture->relationLoaded('entite') && $ecriture->entite) {
                    $ecriture->entite->nom = $this->cleanUTF8($ecriture->entite->nom);
                    $ecriture->entite->code = $this->cleanUTF8($ecriture->entite->code);
                    if (isset($ecriture->entite->raison_sociale)) {
                        $ecriture->entite->raison_sociale = $this->cleanUTF8($ecriture->entite->raison_sociale);
                    }
                }

                if ($ecriture->relationLoaded('succursale') && $ecriture->succursale) {
                    $ecriture->succursale->nom = $this->cleanUTF8($ecriture->succursale->nom);
                    $ecriture->succursale->code = $this->cleanUTF8($ecriture->succursale->code);
                }

                if ($ecriture->relationLoaded('utilisateur') && $ecriture->utilisateur) {
                    $ecriture->utilisateur->nom_complet = $this->cleanUTF8($ecriture->utilisateur->nom_complet);
                    $ecriture->utilisateur->nom_utilisateur = $this->cleanUTF8($ecriture->utilisateur->nom_utilisateur);
                }

                return $ecriture;
            });

            $periodeInfo = $this->getPeriodeInfo($request->input('periode_id'));
            if ($periodeInfo) {
                $periodeInfo->code = $this->cleanUTF8($periodeInfo->code);
                $periodeInfo->libelle = $this->cleanUTF8($periodeInfo->libelle);
            }

            $entiteInfo = ($e = $request->input('entite_id')) && $e !== 'all' ? Entite::find($e) : null;
            if ($entiteInfo) {
                $entiteInfo->nom = $this->cleanUTF8($entiteInfo->nom);
                $entiteInfo->code = $this->cleanUTF8($entiteInfo->code);
                if (isset($entiteInfo->raison_sociale)) {
                    $entiteInfo->raison_sociale = $this->cleanUTF8($entiteInfo->raison_sociale);
                }
            }

            $succursaleInfo = ($s = $request->input('succursale_id')) && $s !== 'all' ? Succursale::find($s) : null;
            if ($succursaleInfo) {
                $succursaleInfo->nom = $this->cleanUTF8($succursaleInfo->nom);
                $succursaleInfo->code = $this->cleanUTF8($succursaleInfo->code);
            }

            $operations = $this->groupOperations($ecritures, $dateCol);
            $operations = $this->cleanUTF8($operations);

            $format = $request->input('format', 'excel');
            if ($format === 'csv' || !class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
                return $this->exportJournalCsv($operations, $periodeInfo, $entiteInfo, $succursaleInfo);
            }
            return $this->exportJournalExcel($operations, $periodeInfo, $entiteInfo, $succursaleInfo, $ecritures, $password);
        } catch (\Exception $e) {
            Log::error('Export Journal error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur export : ' . $this->cleanUTF8($e->getMessage())
            ], 500, [
                'Content-Type' => 'application/json; charset=UTF-8'
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    private function exportJournalExcel($operations, $periodeInfo, $entiteInfo, $succursaleInfo, $ecritures, $password)
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);
        $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);

        $bleuDoux = '4472C4';
        $bleuClair = 'D6E4F0';
        $grisBordure = 'B0B0B0';

        $sheet->setCellValue('A1', 'JOURNAL COMPTABLE')->mergeCells('A1:G1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(18)->getColor()->setRGB('2C3E50');
        $sheet->getStyle('A1')->getAlignment()->setHorizontal('center');
        $sheet->getRowDimension(1)->setRowHeight(35);

        $row = 3;
        if ($periodeInfo) {
            $periodeCode = $this->cleanUTF8($periodeInfo->code);
            $periodeLibelle = $this->cleanUTF8($periodeInfo->libelle);
            $dateDebut = $periodeInfo->date_debut instanceof \Carbon\Carbon ? $periodeInfo->date_debut->format('d/m/Y') : $periodeInfo->date_debut;
            $dateFin = $periodeInfo->date_fin instanceof \Carbon\Carbon ? $periodeInfo->date_fin->format('d/m/Y') : $periodeInfo->date_fin;

            $sheet->setCellValue("A{$row}", 'Période : ' . $periodeCode . ' - ' . $periodeLibelle . ' (Du ' . $dateDebut . ' au ' . $dateFin . ')')->mergeCells("A{$row}:G{$row}");
            $row++;
        }
        if ($entiteInfo) {
            $entiteNom = $this->cleanUTF8($entiteInfo->raison_sociale ?? $entiteInfo->nom ?? '');
            $sheet->setCellValue("A{$row}", 'Entité : ' . $entiteNom)->mergeCells("A{$row}:G{$row}");
            $row++;
        }
        if ($succursaleInfo) {
            $succursaleNom = $this->cleanUTF8($succursaleInfo->nom ?? '');
            $sheet->setCellValue("A{$row}", 'Succursale : ' . $succursaleNom)->mergeCells("A{$row}:G{$row}");
            $row++;
        }
        $sheet->setCellValue("A{$row}", 'DOCUMENT PROTÉGÉ - Export du ' . now()->format('d/m/Y à H:i'))->mergeCells("A{$row}:G{$row}");
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(8)->getColor()->setRGB('FF0000');
        $row += 2;

        $headerRow = $row;
        foreach (['N°', 'Compte (D)', 'Compte (C)', 'Libellé', 'Débit', 'Crédit', 'Acteur'] as $i => $h) {
            $sheet->setCellValue(chr(65 + $i) . $headerRow, $h);
        }
        $sheet->getStyle("A{$headerRow}:G{$headerRow}")->getFont()->setBold(true)->setSize(9)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A{$headerRow}:G{$headerRow}")->getFill()->setFillType('solid')->getStartColor()->setRGB($bleuDoux);
        $sheet->getRowDimension($headerRow)->setRowHeight(22);
        $row++;
        $dataStartRow = $row;

        $grandTotalDebit = round((float)$ecritures->sum('debit'), 4);
        $grandTotalCredit = round((float)$ecritures->sum('credit'), 4);

        $opCounter = 0;
        $ordreSens = ["avant", "principal", "apres"];

        foreach ($operations as $op) {
            $dateF = $this->formatDateValue($this->cleanUTF8($op['date'] ?? ''));
            $reference = $this->cleanUTF8($op['reference'] ?? 'Non assignée');
            $ref = mb_strlen($reference) > 35 ? mb_substr($reference, 0, 32) . '...' : $reference;
            $acteur = $this->cleanUTF8($op['acteur'] ?? '');
            $opCounter++;

            $blocsActifs = [];
            foreach ($ordreSens as $sens) {
                if (isset($op['blocs'][$sens]) && !empty($op['blocs'][$sens]['lignes'])) {
                    $blocsActifs[] = $op['blocs'][$sens];
                }
            }
            $totalBlocs = count($blocsActifs);
            $totalLignesOp = 2;
            foreach ($blocsActifs as $bloc) {
                $lignesSansTva = [];
                $lignesTva = [];
                foreach ($bloc['lignes'] as $l) {
                    if ($l['is_tva'] ?? false) $lignesTva[] = $l;
                    else $lignesSansTva[] = $l;
                }
                foreach ($lignesSansTva as $l) {
                    $totalLignesOp++;
                    $isDebit = (float)($l['debit'] ?? 0) > 0;
                    foreach ($lignesTva as $t) {
                        if (($isDebit && (float)($t['debit'] ?? 0) > 0) || (!$isDebit && (float)($t['credit'] ?? 0) > 0)) {
                            $totalLignesOp++;
                            break;
                        }
                    }
                }
                $blocLibelle = '';
                foreach ($lignesSansTva as $l) {
                    if (!empty($l['libelle'])) {
                        $blocLibelle = $l['libelle'];
                        break;
                    }
                }
                if (!empty($blocLibelle)) $totalLignesOp++;
            }
            if ($totalBlocs > 1) $totalLignesOp += ($totalBlocs - 1);

            $firstRowOp = $row;
            $lastRowOp = $firstRowOp + $totalLignesOp - 1;
            $bgOp = ($opCounter % 2 == 0) ? 'F5F7FA' : 'FFFFFF';

            $sheet->getStyle("A{$row}:G{$row}")->getFill()->setFillType('solid')->getStartColor()->setRGB($bgOp);
            $sheet->setCellValue("D{$row}", 'Réf: ' . $ref)->getStyle("D{$row}")->getFont()->setSize(10)->getColor()->setRGB('37474F');
            $sheet->getRowDimension($row)->setRowHeight(20);
            $row++;

            $sheet->getStyle("A{$row}:G{$row}")->getFill()->setFillType('solid')->getStartColor()->setRGB($bgOp);
            $sheet->setCellValue("D{$row}", $dateF)->getStyle("D{$row}")->getFont()->setItalic(true)->setSize(10)->getColor()->setRGB('546E7A');
            $sheet->getStyle("A{$row}:G{$row}")->getBorders()->getBottom()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)->getColor()->setRGB($bleuDoux);
            $sheet->getRowDimension($row)->setRowHeight(20);
            $row++;

            if ($lastRowOp > $firstRowOp) $sheet->mergeCells("G{$firstRowOp}:G{$lastRowOp}");
            $sheet->setCellValue("G{$firstRowOp}", $acteur)->getStyle("G{$firstRowOp}")->getFont()->setSize(9)->getColor()->setRGB('757575');

            $blocLetter = 'a';
            foreach ($blocsActifs as $blocIndex => $bloc) {
                $lignes = $bloc['lignes'];
                $blocNum = $totalBlocs > 1 ? $opCounter . $blocLetter : (string)$opCounter;
                if ($totalBlocs > 1) $blocLetter = chr(ord($blocLetter) + 1);
                $lignesSansTva = [];
                $lignesTva = [];
                foreach ($lignes as $l) {
                    if ($l['is_tva'] ?? false) $lignesTva[] = $l;
                    else $lignesSansTva[] = $l;
                }
                $blocLibelle = '';
                foreach ($lignesSansTva as $l) {
                    if (!empty($l['libelle'])) {
                        $blocLibelle = $l['libelle'];
                        break;
                    }
                }
                if (empty($blocLibelle) && count($lignes) > 0) $blocLibelle = $lignes[0]['libelle'] ?? '';
                $blocLibelle = $this->cleanUTF8($blocLibelle);
                $blocLibelle = $this->formatNomCompte($blocLibelle);
                $blocLibelle = mb_strlen($blocLibelle) > 35 ? mb_substr($blocLibelle, 0, 32) . '...' : $blocLibelle;
                $firstOfBloc = true;

                foreach ($lignesSansTva as $l) {
                    $compteNum = $this->cleanUTF8($l['compte_numero'] ?? '');
                    $compteNom = $this->cleanUTF8($l['plan_comptable']['nom'] ?? $l['libelle'] ?? '');
                    $compteNom = $this->formatNomCompte($compteNom);
                    $compteNom = mb_strlen($compteNom) > 30 ? mb_substr($compteNom, 0, 27) . '...' : $compteNom;
                    $debitV = (float)($l['debit'] ?? 0);
                    $creditV = (float)($l['credit'] ?? 0);
                    $isDebit = $debitV > 0;
                    $bg = ($opCounter % 2 == 0) ? 'F5F7FA' : 'FFFFFF';
                    $sheet->getStyle("A{$row}:G{$row}")->getFill()->setFillType('solid')->getStartColor()->setRGB($bg);
                    $sheet->setCellValue("A{$row}", $firstOfBloc ? $blocNum : '')->getStyle("A{$row}")->getFont()->setBold(true)->setSize(10);
                    if ($isDebit) {
                        $sheet->setCellValue("B{$row}", $compteNum)->setCellValue("C{$row}", '');
                    } else {
                        $sheet->setCellValue("B{$row}", '')->setCellValue("C{$row}", $compteNum);
                    }
                    $sheet->setCellValue("D{$row}", $compteNom)->getStyle("D{$row}")->getFont()->setSize(10);
                    $sheet->setCellValue("E{$row}", $isDebit ? $debitV : '')->setCellValue("F{$row}", !$isDebit ? $creditV : '');
                    $sheet->getStyle("E{$row}:F{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
                    $firstOfBloc = false;
                    $row++;

                    $tvaTrouvee = null;
                    foreach ($lignesTva as $t) {
                        if (($isDebit && (float)($t['debit'] ?? 0) > 0) || (!$isDebit && (float)($t['credit'] ?? 0) > 0)) {
                            $tvaTrouvee = $t;
                            break;
                        }
                    }
                    if ($tvaTrouvee) {
                        $tvNum = $this->cleanUTF8($tvaTrouvee['compte_numero'] ?? '');
                        $tvNom = $this->cleanUTF8($tvaTrouvee['plan_comptable']['nom'] ?? $tvaTrouvee['libelle'] ?? '');
                        $tvNom = $this->formatNomCompte($tvNom);
                        $tvNom = mb_strlen($tvNom) > 25 ? mb_substr($tvNom, 0, 22) . '...' : $tvNom;
                        $tvDeb = (float)($tvaTrouvee['debit'] ?? 0);
                        $tvCred = (float)($tvaTrouvee['credit'] ?? 0);
                        $sheet->getStyle("A{$row}:G{$row}")->getFill()->setFillType('solid')->getStartColor()->setRGB($bg);
                        if ($isDebit) {
                            $sheet->setCellValue("B{$row}", $tvNum)->setCellValue("C{$row}", '');
                        } else {
                            $sheet->setCellValue("B{$row}", '')->setCellValue("C{$row}", $tvNum);
                        }
                        $sheet->getStyle("B{$row}:C{$row}")->getFont()->getColor()->setRGB('888888');
                        $sheet->setCellValue("D{$row}", 'Tva - ' . $tvNom)->getStyle("D{$row}")->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB('888888');
                        $sheet->setCellValue("E{$row}", $isDebit ? $tvDeb : '')->setCellValue("F{$row}", !$isDebit ? $tvCred : '');
                        $sheet->getStyle("E{$row}:F{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
                        $row++;
                    }
                }

                if (!empty($blocLibelle)) {
                    $sheet->getStyle("A{$row}:G{$row}")->getFill()->setFillType('solid')->getStartColor()->setRGB($bleuClair);
                    $sheet->setCellValue("D{$row}", '— ' . $blocLibelle . ' —')->getStyle("D{$row}")->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB($bleuDoux);
                    $row++;
                }
                if ($blocIndex < $totalBlocs - 1) {
                    $sheet->getStyle("A{$row}:G{$row}")->getFill()->setFillType('solid')->getStartColor()->setRGB($bleuClair);
                    $sheet->setCellValue("D{$row}", 'Dito')->getStyle("D{$row}")->getFont()->setBold(true)->setSize(9)->getColor()->setRGB($bleuDoux);
                    $row++;
                }
            }
            $sheet->getStyle("A{$row}:G{$row}")->getBorders()->getBottom()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_HAIR)->getColor()->setRGB($grisBordure);
            $sheet->getRowDimension($row)->setRowHeight(2);
            $row++;
        }

        $dataEndRow = $row - 1;
        $borderRight = ['borders' => ['right' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => $grisBordure]]]];
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $col) {
            $sheet->getStyle("{$col}{$headerRow}")->applyFromArray($borderRight);
            $sheet->getStyle("{$col}{$dataStartRow}:{$col}{$dataEndRow}")->applyFromArray($borderRight);
        }

        $row++;
        $sheet->setCellValue("D{$row}", 'TOTAUX')->getStyle("D{$row}")->getFont()->setBold(true)->setSize(11);
        $sheet->setCellValue("E{$row}", $grandTotalDebit)->setCellValue("F{$row}", $grandTotalCredit);
        $sheet->getStyle("A{$row}:G{$row}")->getFont()->setBold(true)->setSize(11)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A{$row}:G{$row}")->getFill()->setFillType('solid')->getStartColor()->setRGB($bleuDoux);
        $sheet->getStyle("E{$row}:F{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getRowDimension($row)->setRowHeight(28);

        $row += 2;
        $isEq = abs($grandTotalDebit - $grandTotalCredit) < 0.01;
        $sheet->setCellValue("A{$row}", 'Nb opérations : ' . count($operations) . ' | Écritures : ' . $ecritures->count() . ($isEq ? ' Équilibré' : ' Écart: ' . number_format(abs($grandTotalDebit - $grandTotalCredit), 2)))->mergeCells("A{$row}:G{$row}");
        $sheet->getStyle("A{$row}")->getFont()->setItalic(true)->setSize(8)->getColor()->setRGB($isEq ? '2E7D32' : 'C62828');

        foreach (['A' => 6, 'B' => 16, 'C' => 16, 'D' => 42, 'E' => 16, 'F' => 16, 'G' => 18] as $c => $w) $sheet->getColumnDimension($c)->setWidth($w);
        $sheet->freezePane('A' . ($headerRow + 1));
        $sheet->getProtection()->setSheet(true)->setPassword($password);
        $spreadsheet->getSecurity()->setLockWindows(true)->setLockStructure(true)->setWorkbookPassword($password);

        $filename = 'Journal_Comptable_' . ($periodeInfo ? $this->cleanUTF8($periodeInfo->code) . '_' : '') . date('Y-m-d_His') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
        exit;
    }

    private function exportJournalCsv($operations, $periodeInfo, $entiteInfo, $succursaleInfo)
    {
        $filename = 'Journal_Comptable_' . date('Ymd_His') . '.csv';
        $headers = ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"$filename\""];
        return response()->stream(function () use ($operations, $periodeInfo, $entiteInfo, $succursaleInfo) {
            $output = fopen('php://output', 'w');
            fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($output, ['JOURNAL COMPTABLE - DOCUMENT OFFICIEL'], ';');
            if ($periodeInfo) {
                $periodeCode = $this->cleanUTF8($periodeInfo->code ?? '');
                $periodeLibelle = $this->cleanUTF8($periodeInfo->libelle ?? '');
                fputcsv($output, ['Période:', $periodeCode . ' - ' . $periodeLibelle], ';');
            }
            if ($entiteInfo) {
                $entiteNom = $this->cleanUTF8($entiteInfo->raison_sociale ?? $entiteInfo->nom ?? '');
                fputcsv($output, ['Entité:', $entiteNom], ';');
            }
            if ($succursaleInfo) {
                $succursaleNom = $this->cleanUTF8($succursaleInfo->nom ?? '');
                fputcsv($output, ['Succursale:', $succursaleNom], ';');
            }
            fputcsv($output, [''], ';');
            fputcsv($output, ['N°', 'Compte (D)', 'Compte (C)', 'Libellé', 'Débit', 'Crédit', 'Acteur'], ';');
            foreach ($operations as $op) {
                $reference = $this->cleanUTF8($op['reference'] ?? '');
                $acteur = $this->cleanUTF8($op['acteur'] ?? '');
                $date = $this->formatDateValue($this->cleanUTF8($op['date'] ?? ''));

                fputcsv($output, ['', '', '', 'Réf: ' . $reference, '', '', $acteur], ';');
                fputcsv($output, ['', '', '', $date, '', '', ''], ';');
                foreach (['avant', 'principal', 'apres'] as $sens) {
                    if (!isset($op['blocs'][$sens])) continue;
                    foreach ($op['blocs'][$sens]['lignes'] as $l) {
                        if ($l['is_tva'] ?? false) continue;
                        $isDebit = (float)($l['debit'] ?? 0) > 0;
                        $compteNum = $this->cleanUTF8($l['compte_numero'] ?? '');
                        $compteNom = $this->cleanUTF8($l['plan_comptable']['nom'] ?? $l['libelle'] ?? '');

                        fputcsv($output, [
                            '',
                            $isDebit ? $compteNum : '',
                            !$isDebit ? $compteNum : '',
                            $compteNom,
                            $isDebit ? number_format((float)($l['debit'] ?? 0), 2, '.', '') : '',
                            !$isDebit ? number_format((float)($l['credit'] ?? 0), 2, '.', '') : '',
                            ''
                        ], ';');
                    }
                }
            }
            fclose($output);
        }, 200, $headers);
    }
}