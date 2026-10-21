<?php

namespace App\Http\Controllers\Comptabilite;

use App\Models\EcrituresComptable;
use App\Models\Entite;
use App\Models\Succursale;
use App\Models\PeriodesComptable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Http\Controllers\Controller;

class BalanceController extends Controller
{
    const DATE_COLUMN = 'date_ecriture';

    private $periodeEnCoursCache = null;

    // =========================================================================
    // PAGE PRINCIPALE
    // =========================================================================

    public function index(Request $request)
    {
        $entites = Entite::orderBy('code', 'asc')->get();
        $periodes = PeriodesComptable::orderBy('date_debut', 'desc')->get();
        return view('sections.balance', compact('entites', 'periodes'));
    }

    // =========================================================================
    // CACHE PÉRIODE EN COURS
    // =========================================================================

    private function getPeriodeEnCoursFromDB()
    {
        if ($this->periodeEnCoursCache) {
            return $this->periodeEnCoursCache;
        }

        $this->periodeEnCoursCache = PeriodesComptable::where('date_debut', '<=', now())
            ->where('date_fin', '>=', now())
            ->where('statut', 'ouverte')
            ->orderBy('date_debut', 'asc')
            ->first();

        if (!$this->periodeEnCoursCache) {
            $this->periodeEnCoursCache = PeriodesComptable::where('statut', 'ouverte')
                ->orderBy('date_debut', 'asc')
                ->first();
        }

        return $this->periodeEnCoursCache;
    }

    // =========================================================================
    // FILTRES
    // =========================================================================

    private function applyFilters($query, Request $request)
    {
        // 1. Exercice comptable
        $periodeId = $request->input('periode_id');
        if (!empty($periodeId) && $periodeId !== 'all') {
            if ($periodeId === 'en-cours') {
                $p = $this->getPeriodeEnCoursFromDB();
                if ($p) {
                    $query->where('periode_id', $p->id);
                }
            } else {
                $query->where('periode_id', $periodeId);
            }
        }

        // 2. Filtre temporel ET/OU dates personnalisées
        $filterType = $request->input('filter_type', 'today');
        $dateDebut = $request->input('date_debut');
        $dateFin = $request->input('date_fin');
        $useCustomDates = $request->input('use_custom_dates', false);

        $this->applyTimeFilter($query, $filterType, $dateDebut, $dateFin, $useCustomDates);

        // 3. Entité
        $entiteId = $request->input('entite_id');
        if ($entiteId && $entiteId !== 'all') {
            $query->where('entite_id', $entiteId);
        }

        // 4. Succursale
        $succursaleId = $request->input('succursale_id');
        if ($succursaleId && $succursaleId !== 'all') {
            $query->where('succursale_id', $succursaleId);
        }

        // 5. Compte
        $compteNumero = $request->input('compte_numero');
        if ($compteNumero && $compteNumero !== 'all') {
            $query->where('compte_numero', 'LIKE', $compteNumero . '%');
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

        switch ($filterType) {
            case 'today':
                $query->whereDate(self::DATE_COLUMN, $now->toDateString());
                break;
            case 'yesterday':
                $query->whereDate(self::DATE_COLUMN, $now->copy()->subDay()->toDateString());
                break;
            case 'last3days':
                $query->whereBetween(self::DATE_COLUMN, [
                    $now->copy()->subDays(3)->toDateString(),
                    $now->toDateString()
                ]);
                break;
            case 'this_week':
                $query->whereBetween(self::DATE_COLUMN, [
                    $now->copy()->startOfWeek()->toDateString(),
                    $now->copy()->endOfWeek()->toDateString()
                ]);
                break;
            case 'last_week':
                $query->whereBetween(self::DATE_COLUMN, [
                    $now->copy()->subWeek()->startOfWeek()->toDateString(),
                    $now->copy()->subWeek()->endOfWeek()->toDateString()
                ]);
                break;
            case 'last7days':
                $query->whereBetween(self::DATE_COLUMN, [
                    $now->copy()->subDays(7)->toDateString(),
                    $now->toDateString()
                ]);
                break;
            case 'this_month':
                $query->whereBetween(self::DATE_COLUMN, [
                    $now->copy()->startOfMonth()->toDateString(),
                    $now->copy()->endOfMonth()->toDateString()
                ]);
                break;
            case 'last_month':
                $query->whereBetween(self::DATE_COLUMN, [
                    $now->copy()->subMonth()->startOfMonth()->toDateString(),
                    $now->copy()->subMonth()->endOfMonth()->toDateString()
                ]);
                break;
            case 'last30days':
                $query->whereBetween(self::DATE_COLUMN, [
                    $now->copy()->subDays(30)->toDateString(),
                    $now->toDateString()
                ]);
                break;
            case 'this_year':
                $query->whereYear(self::DATE_COLUMN, $now->year);
                break;
            case 'all':
                break;
        }
    }

    // =========================================================================
    // UTILITAIRES
    // =========================================================================

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
            'code' => $periode->code,
            'libelle' => $periode->libelle,
            'date_debut' => $periode->date_debut instanceof Carbon ? $periode->date_debut->format('d/m/Y') : $periode->date_debut,
            'date_fin' => $periode->date_fin instanceof Carbon ? $periode->date_fin->format('d/m/Y') : $periode->date_fin,
            'is_cloture' => $periode->is_cloture,
            'statut' => $periode->statut,
        ];
    }

    private function getAppliedFilters(Request $request): array
    {
        return [
            'filter_type' => $request->input('filter_type', 'today'),
            'use_custom_dates' => $request->input('use_custom_dates', false),
            'periode_id' => $request->input('periode_id'),
            'entite_id' => $request->input('entite_id'),
            'succursale_id' => $request->input('succursale_id'),
            'compte_numero' => $request->input('compte_numero'),
            'date_debut' => $request->input('date_debut'),
            'date_fin' => $request->input('date_fin'),
        ];
    }

    private function buildComptesArray($ecritures): array
    {
        $comptes = [];
        foreach ($ecritures as $ec) {
            $numero = $ec->compte_numero;
            if (!isset($comptes[$numero])) {
                $comptes[$numero] = [
                    'numero' => $numero,
                    'nom' => $ec->plan_comptable->nom ?? '',
                    'total_debit' => 0,
                    'total_credit' => 0,
                    'solde' => 0,
                    'solde_debiteur' => true
                ];
            }
            $comptes[$numero]['total_debit'] += (float)$ec->debit;
            $comptes[$numero]['total_credit'] += (float)$ec->credit;
        }

        foreach ($comptes as &$compte) {
            $solde = $compte['total_debit'] - $compte['total_credit'];
            $compte['solde'] = round(abs($solde), 4);
            $compte['solde_debiteur'] = $solde >= 0;
            $compte['total_debit'] = round($compte['total_debit'], 4);
            $compte['total_credit'] = round($compte['total_credit'], 4);
        }
        unset($compte);

        $comptesArray = array_values($comptes);
        usort($comptesArray, fn($a, $b) => strcmp($a['numero'], $b['numero']));
        return $comptesArray;
    }

    private function getActeur($ecriture): string
    {
        if ($ecriture->relationLoaded('utilisateur') && $ecriture->utilisateur) {
            $user = $ecriture->utilisateur;
            return $user->nom_complet ?? $user->nom_utilisateur ?? 'ID: ' . $user->id;
        }
        return 'Système';
    }

    /**
     * ═══════════════════════════════════════════════════════════════════
     *  🔥 COMPTE LES ÉCRITURES DISTINCTES
     * ═══════════════════════════════════════════════════════════════════
     *
     * Une écriture comptable = 2 lignes (débit + crédit) qui partagent
     * le même `numero_ecriture`. Si on fait `->count()` sur la collection,
     * on compte les LIGNES, pas les écritures.
     *
     * Cette méthode compte le nombre de `numero_ecriture` distincts
     * (avec fallback sur `reference` puis `id`).
     */
    private function countEcrituresDistinctes($ecritures): int
    {
        $vus = [];

        foreach ($ecritures as $ec) {
            // Priorité : numero_ecriture → reference → id
            $cle = $ec->numero_ecriture
                ?? $ec->reference
                ?? ('id-' . $ec->id);

            $vus[$cle] = true;
        }

        return count($vus);
    }

    // =========================================================================
    // API - BALANCE (SANS PAGINATION)
    // =========================================================================

    public function getBalance(Request $request)
    {
        try {
            // ============================================
            // 🔧 REQUÊTE DE BASE
            // ============================================
            $query = EcrituresComptable::with([
                'plan_comptable:id,numero,nom',
                'entite:id,code,nom',
                'succursale:id,code,nom'
            ])->orderBy('compte_numero', 'asc');

            $query = $this->applyFilters($query, $request);
            $ecritures = $query->get();

            $periodeInfo = $this->getPeriodeInfo($request->input('periode_id'));
            $periodeFormatted = $this->formatPeriodeInfo($periodeInfo);

            // 🔥 Comptage correct : écritures distinctes
            $nbEcritures = $this->countEcrituresDistinctes($ecritures);
            $nbLignes    = $ecritures->count();

            // 🔍 Log pour debug
            Log::info('🔍 Balance - periodeInfo', [
                'periode_id_input' => $request->input('periode_id'),
                'periode_formatted' => $periodeFormatted,
                'filter_type' => $request->input('filter_type'),
                'use_custom_dates' => $request->input('use_custom_dates'),
                'date_debut' => $request->input('date_debut'),
                'date_fin' => $request->input('date_fin'),
                'nb_ecritures' => $nbEcritures,
                'nb_lignes' => $nbLignes,
            ]);

            // ============================================
            // 📭 CAS VIDE
            // ============================================
            if ($ecritures->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'comptes' => [],
                    'total_debit_global' => 0,
                    'total_credit_global' => 0,
                    'nb_comptes' => 0,
                    'nb_ecritures' => 0,
                    'nb_lignes' => 0,
                    'periode' => $periodeFormatted,
                    'filters_applied' => $this->getAppliedFilters($request),
                ]);
            }

            // ============================================
            // 📊 CONSTRUCTION DE TOUS LES COMPTES
            // ============================================
            $comptesArray = $this->buildComptesArray($ecritures);

            $totalDebitGlobal = round((float)$ecritures->sum('debit'), 4);
            $totalCreditGlobal = round((float)$ecritures->sum('credit'), 4);

            // ============================================
            // 🎯 RÉPONSE
            // ============================================
            return response()->json([
                'success' => true,
                'comptes' => $comptesArray,
                'total_debit_global' => $totalDebitGlobal,
                'total_credit_global' => $totalCreditGlobal,
                'nb_comptes' => count($comptesArray),
                'nb_ecritures' => $nbEcritures,   // ✅ Écritures distinctes
                'nb_lignes' => $nbLignes,         // ✅ Lignes brutes (info)
                'periode' => $periodeFormatted,
                'filters_applied' => $this->getAppliedFilters($request),
            ]);
        } catch (\Exception $e) {
            Log::error('Balance error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du chargement de la balance',
                'comptes' => [],
                'total_debit_global' => 0,
                'total_credit_global' => 0,
            ]);
        }
    }

    // =========================================================================
    // EXPORT BALANCE
    // =========================================================================

    public function exportBalance(Request $request)
    {
        try {
            $query = EcrituresComptable::with([
                'plan_comptable:id,numero,nom',
                'entite:id,code,nom',
                'succursale:id,code,nom',
                'utilisateur:id,nom_complet,nom_utilisateur,email'
            ])->orderBy('compte_numero', 'asc');

            $query = $this->applyFilters($query, $request);
            $ecritures = $query->get();

            $periodeInfo = $this->getPeriodeInfo($request->input('periode_id'));
            $entiteInfo = ($e = $request->input('entite_id')) && $e !== 'all' ? Entite::find($e) : null;
            $succursaleInfo = ($s = $request->input('succursale_id')) && $s !== 'all' ? Succursale::find($s) : null;

            $comptes = [];
            foreach ($ecritures as $ec) {
                $numero = $ec->compte_numero;
                if (!isset($comptes[$numero])) {
                    $comptes[$numero] = [
                        'numero' => $numero,
                        'nom' => $ec->plan_comptable?->nom ?? 'Compte inconnu',
                        'total_debit' => 0,
                        'total_credit' => 0,
                        'acteur' => $this->getActeur($ec)
                    ];
                }
                $comptes[$numero]['total_debit'] += (float)$ec->debit;
                $comptes[$numero]['total_credit'] += (float)$ec->credit;
            }

            $comptesArray = array_values($comptes);
            usort($comptesArray, fn($a, $b) => strcmp($a['numero'], $b['numero']));

            $format = $request->input('format', 'excel');
            if ($format === 'csv' || !class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
                return $this->exportCsv($comptesArray, $periodeInfo, $entiteInfo, $succursaleInfo);
            }
            return $this->exportExcel($comptesArray, $periodeInfo, $entiteInfo, $succursaleInfo);
        } catch (\Exception $e) {
            Log::error('Export Balance error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur export: ' . $e->getMessage()], 500);
        }
    }

    private function exportExcel($comptesArray, $periodeInfo, $entiteInfo, $succursaleInfo)
    {
        $password = 'Balance2024@Secure';
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);
        $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);

        $bleuDoux = '1565C0';
        $orangeDoux = 'E65100';

        $sheet->setCellValue('A1', 'BALANCE DE VÉRIFICATION')->mergeCells('A1:G1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB('2C3E50');
        $sheet->getStyle('A1')->getAlignment()->setHorizontal('center');
        $sheet->getRowDimension(1)->setRowHeight(30);

        $row = 3;
        if ($periodeInfo) {
            $sheet->setCellValue("A{$row}", 'Exercice : ' . $periodeInfo->code . ' - ' . $periodeInfo->libelle . ' (Du ' . $periodeInfo->date_debut->format('d/m/Y') . ' au ' . $periodeInfo->date_fin->format('d/m/Y') . ')')->mergeCells("A{$row}:G{$row}");
            $row++;
        }
        if ($entiteInfo) {
            $sheet->setCellValue("A{$row}", 'Entité : ' . ($entiteInfo->raison_sociale ?? $entiteInfo->nom))->mergeCells("A{$row}:G{$row}");
            $row++;
        }
        if ($succursaleInfo) {
            $sheet->setCellValue("A{$row}", 'Succursale : ' . $succursaleInfo->nom)->mergeCells("A{$row}:G{$row}");
            $row++;
        }
        $sheet->setCellValue("A{$row}", '⚠ DOCUMENT PROTÉGÉ - Export du ' . now()->format('d/m/Y à H:i'))->mergeCells("A{$row}:G{$row}");
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(8)->getColor()->setRGB('FF0000');
        $row += 2;

        $headerRow = $row;
        $headers = ['N° Compte', 'Intitulé', 'Total Débit', 'Total Crédit', 'Solde Débiteur', 'Solde Créditeur', 'Acteur'];
        foreach ($headers as $i => $h) {
            $col = chr(65 + $i);
            $sheet->setCellValue($col . $headerRow, $h);
        }
        $sheet->getStyle("A{$headerRow}:G{$headerRow}")->getFont()->setBold(true)->setSize(9)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A{$headerRow}:G{$headerRow}")->getFill()->setFillType('solid')->getStartColor()->setRGB($bleuDoux);
        $row++;

        $totalDebit = 0;
        $totalCredit = 0;
        foreach ($comptesArray as $index => $compte) {
            $solde = $compte['total_debit'] - $compte['total_credit'];
            $totalDebit += $compte['total_debit'];
            $totalCredit += $compte['total_credit'];
            $bg = ($index % 2 == 0) ? 'F8F9FA' : 'FFFFFF';
            $sheet->getStyle("A{$row}:G{$row}")->getFill()->setFillType('solid')->getStartColor()->setRGB($bg);
            $sheet->setCellValue("A{$row}", $compte['numero']);
            $sheet->setCellValue("B{$row}", $compte['nom']);
            $sheet->setCellValue("C{$row}", $compte['total_debit']);
            $sheet->setCellValue("D{$row}", $compte['total_credit']);
            $sheet->setCellValue("E{$row}", $solde >= 0 ? $solde : 0);
            $sheet->setCellValue("F{$row}", $solde < 0 ? abs($solde) : 0);
            $sheet->setCellValue("G{$row}", $compte['acteur']);
            $sheet->getStyle("C{$row}:F{$row}")->getNumberFormat()->setFormatCode('#,##0.0000');
            $row++;
        }

        $sheet->setCellValue("A{$row}", 'TOTAUX')->mergeCells("A{$row}:B{$row}");
        $sheet->setCellValue("C{$row}", $totalDebit);
        $sheet->setCellValue("D{$row}", $totalCredit);
        $sheet->setCellValue("E{$row}", max(0, $totalDebit - $totalCredit));
        $sheet->setCellValue("F{$row}", max(0, $totalCredit - $totalDebit));
        $sheet->getStyle("A{$row}:G{$row}")->getFont()->setBold(true)->getColor()->setRGB($orangeDoux);
        $sheet->getStyle("A{$row}:G{$row}")->getFill()->setFillType('solid')->getStartColor()->setRGB('FFF3E0');
        $sheet->getStyle("C{$row}:F{$row}")->getNumberFormat()->setFormatCode('#,##0.0000');

        foreach (['A' => 15, 'B' => 40, 'C' => 18, 'D' => 18, 'E' => 18, 'F' => 18, 'G' => 25] as $c => $w) {
            $sheet->getColumnDimension($c)->setWidth($w);
        }

        $sheet->freezePane('A' . ($headerRow + 1));
        $sheet->getProtection()->setSheet(true)->setPassword($password);
        $spreadsheet->getSecurity()->setLockWindows(true)->setLockStructure(true)->setWorkbookPassword($password);

        $filename = 'Balance_' . ($periodeInfo ? $periodeInfo->code . '_' : '') . date('Y-m-d_His') . '.xlsx';
        $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
        exit;
    }

    private function exportCsv($comptesArray, $periodeInfo, $entiteInfo, $succursaleInfo)
    {
        $filename = 'Balance_' . date('Y-m-d_His') . '.csv';
        $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);
        $headers = ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"$filename\""];

        return response()->stream(function () use ($comptesArray, $periodeInfo, $entiteInfo, $succursaleInfo) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($file, ['BALANCE DE VÉRIFICATION'], ';');
            if ($periodeInfo) fputcsv($file, ['Exercice:', $periodeInfo->code . ' - ' . $periodeInfo->libelle], ';');
            if ($entiteInfo) fputcsv($file, ['Entité:', $entiteInfo->raison_sociale ?? $entiteInfo->nom], ';');
            if ($succursaleInfo) fputcsv($file, ['Succursale:', $succursaleInfo->nom], ';');
            fputcsv($file, [''], ';');
            fputcsv($file, ['N° Compte', 'Intitulé', 'Total Débit', 'Total Crédit', 'Solde Débiteur', 'Solde Créditeur', 'Acteur'], ';');
            $totalDebit = 0;
            $totalCredit = 0;
            foreach ($comptesArray as $compte) {
                $solde = $compte['total_debit'] - $compte['total_credit'];
                $totalDebit += $compte['total_debit'];
                $totalCredit += $compte['total_credit'];
                fputcsv($file, [
                    $compte['numero'],
                    $compte['nom'],
                    number_format($compte['total_debit'], 4, '.', ''),
                    number_format($compte['total_credit'], 4, '.', ''),
                    $solde >= 0 ? number_format($solde, 4, '.', '') : '',
                    $solde < 0 ? number_format(abs($solde), 4, '.', '') : '',
                    $compte['acteur']
                ], ';');
            }
            fputcsv($file, ['TOTAUX', '', number_format($totalDebit, 4, '.', ''), number_format($totalCredit, 4, '.', ''), '', '', ''], ';');
            fclose($file);
        }, 200, $headers);
    }

    // =========================================================================
    // API - SUCCURSALES & EXERCICES
    // =========================================================================

    public function getSuccursalesByEntite($entite_id)
    {
        try {
            $succursales = Succursale::where('entite_id', $entite_id)
                ->where('est_active', 1)
                ->orderBy('code', 'asc')
                ->get(['id', 'code', 'nom', 'ville']);
            return response()->json(['success' => true, 'succursales' => $succursales]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function getPeriodes()
    {
        try {
            $periodes = PeriodesComptable::orderBy('date_debut', 'desc')
                ->get(['id', 'code', 'libelle', 'date_debut', 'date_fin', 'is_cloture', 'statut']);
            return response()->json(['success' => true, 'periodes' => $periodes]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function getPeriodeEnCours()
    {
        try {
            $periode = $this->getPeriodeEnCoursFromDB();
            return response()->json([
                'success' => true,
                'periode' => $periode ? $this->formatPeriodeInfo($periode) : null
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}