<?php

namespace App\Http\Controllers\Comptabilite;

use App\Models\EcrituresComptable;
use App\Models\Entite;
use App\Models\Succursale;
use App\Models\PeriodesComptable;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Http\Controllers\Controller;

class GrandLivreController extends Controller
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
        return view('sections.grand-livre', compact('entites', 'periodes'));
    }

    // =========================================================================
    // CACHE
    // =========================================================================

    private function getPeriodeEnCoursFromDB()
    {
        if ($this->periodeEnCoursCache) return $this->periodeEnCoursCache;
        
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
                if ($p) $query->where('periode_id', $p->id);
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

        // 3. Compte
        $compteNumero = $request->input('compte_numero');
        if ($compteNumero && $compteNumero !== 'all') {
            $query->where('compte_numero', 'LIKE', $compteNumero . '%');
        }

        // 4. Entité
        $entiteId = $request->input('entite_id');
        if ($entiteId && $entiteId !== 'all') $query->where('entite_id', $entiteId);

        // 5. Succursale
        $succursaleId = $request->input('succursale_id');
        if ($succursaleId && $succursaleId !== 'all') $query->where('succursale_id', $succursaleId);

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
    
    public function getPeriodes()
    {
        $p = PeriodesComptable::orderBy('date_debut','desc')
            ->get(['id','code','libelle','date_debut','date_fin','is_cloture','statut']);
        return response()->json(['success'=>true,'periodes'=>$p]);
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

    private function formatDateValue($value): string
    {
        if (empty($value)) return '';
        if ($value instanceof Carbon) return $value->format('d/m/Y');
        if (is_string($value)) {
            try {
                return Carbon::parse($value)->format('d/m/Y');
            } catch (\Exception $e) {
                if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $value)) return $value;
            }
        }
        return '';
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
                    'ecritures' => [],
                    'total_debit' => 0, 
                    'total_credit' => 0,
                    'solde' => 0, 
                    'solde_debiteur' => true
                ];
            }
            $comptes[$numero]['ecritures'][] = $ec;
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
        if (!empty($ecriture->id_utilisateur)) {
            $user = User::find($ecriture->id_utilisateur);
            if ($user) return $user->nom_complet ?? $user->nom_utilisateur ?? 'ID: ' . $user->id;
        }
        return 'Système';
    }

    // =========================================================================
    // API - GRAND LIVRE
    // =========================================================================

    public function getGrandLivre(Request $request)
    {
        try {
            // ============================================
            // 🔧 REQUÊTE DE BASE
            // ============================================
            $query = EcrituresComptable::with([
                'plan_comptable:id,numero,nom',
                'entite:id,code,nom',
                'succursale:id,code,nom',
                'periode_comptable:id,code,libelle,is_cloture'
            ])->orderBy('compte_numero', 'asc')
            ->orderBy(self::DATE_COLUMN, 'asc')
            ->orderBy('ordre_affichage', 'asc');

            $query = $this->applyFilters($query, $request);
            $ecritures = $query->get();
            $periodeInfo = $this->getPeriodeInfo($request->input('periode_id'));

            if ($ecritures->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'comptes' => [],
                    'total_debit_global' => 0,
                    'total_credit_global' => 0,
                    'nb_comptes' => 0,
                    'nb_ecritures' => 0,
                    'periode' => $this->formatPeriodeInfo($periodeInfo),
                    'filters_applied' => $this->getAppliedFilters($request),
                    'pagination' => [
                        'total' => 0,
                        'per_page' => (int)$request->input('per_page', 20),
                        'current_page' => 1,
                        'last_page' => 1,
                        'has_more_pages' => false,
                    ],
                ]);
            }

            // ============================================
            // 📊 CONSTRUCTION DE TOUS LES COMPTES (non paginés)
            // ============================================
            $comptesArray = $this->buildComptesArray($ecritures);
            $totalComptes = count($comptesArray);

            // ============================================
            // 📑 PAGINATION SUR LES COMPTES
            // ============================================
            $perPage = (int)$request->input('per_page', 20);
            $page = max(1, (int)$request->input('page', 1));

            $offset = ($page - 1) * $perPage;
            $comptesPage = array_slice($comptesArray, $offset, $perPage);

            $lastPage = (int) ceil($totalComptes / $perPage);
            $hasMorePages = $page < $lastPage;

            // ============================================
            // 🎯 RÉPONSE
            // ============================================
            return response()->json([
                'success' => true,
                'comptes' => $comptesPage,
                'total_debit_global' => round($ecritures->sum('debit'), 4),
                'total_credit_global' => round($ecritures->sum('credit'), 4),
                'nb_comptes' => $totalComptes,
                'nb_ecritures' => $ecritures->count(),
                'periode' => $this->formatPeriodeInfo($periodeInfo),
                'filters_applied' => $this->getAppliedFilters($request),
                'pagination' => [
                    'total' => $totalComptes,
                    'per_page' => $perPage,
                    'current_page' => $page,
                    'last_page' => $lastPage,
                    'has_more_pages' => $hasMorePages,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('GrandLivre error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    // =========================================================================
    // EXPORT GRAND LIVRE
    // =========================================================================

    public function exportGrandLivre(Request $request)
    {
        try {
            $dateCol = self::DATE_COLUMN;
            $query = EcrituresComptable::with([
                'plan_comptable:id,numero,nom',
                'entite:id,code,nom',
                'succursale:id,code,nom',
                'utilisateur:id,nom_complet,nom_utilisateur,email'
            ])->orderBy('compte_numero', 'asc')
              ->orderBy($dateCol, 'asc')
              ->orderBy('ordre_affichage', 'asc');

            $query = $this->applyFilters($query, $request);
            $ecritures = $query->get();

            $periodeInfo = $this->getPeriodeInfo($request->input('periode_id'));
            $entiteInfo = ($e = $request->input('entite_id')) && $e !== 'all' ? Entite::find($e) : null;
            $succursaleInfo = ($s = $request->input('succursale_id')) && $s !== 'all' ? Succursale::find($s) : null;

            $comptes = [];
            foreach ($ecritures as $ec) {
                $n = $ec->compte_numero;
                if (!isset($comptes[$n])) {
                    $comptes[$n] = [
                        'numero' => $n, 
                        'nom' => $ec->plan_comptable?->nom ?? 'Inconnu', 
                        'ecritures' => [], 
                        'total_debit' => 0, 
                        'total_credit' => 0
                    ];
                }
                $comptes[$n]['ecritures'][] = $ec;
                $comptes[$n]['total_debit'] += (float)$ec->debit;
                $comptes[$n]['total_credit'] += (float)$ec->credit;
            }
            foreach ($comptes as &$c) { 
                $c['total_debit'] = round($c['total_debit'], 4); 
                $c['total_credit'] = round($c['total_credit'], 4); 
            }
            unset($c);
            $comptesArray = array_values($comptes);
            usort($comptesArray, fn($a, $b) => strcmp($a['numero'], $b['numero']));

            $format = $request->input('format', 'excel');
            if ($format === 'csv' || !class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
                return $this->exportCsv($comptesArray, $periodeInfo, $entiteInfo, $succursaleInfo, $dateCol);
            }
            return $this->exportExcel($comptesArray, $periodeInfo, $entiteInfo, $succursaleInfo, $dateCol, $ecritures);
        } catch (\Exception $e) {
            Log::error('Export GrandLivre error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur export: ' . $e->getMessage()], 500);
        }
    }

    private function exportExcel($comptesArray, $periodeInfo, $entiteInfo, $succursaleInfo, $dateCol, $ecritures)
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);
        $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);

        $sheet->setCellValue('A1', 'GRAND LIVRE COMPTABLE')->mergeCells('A1:H1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB('2C3E50');
        $sheet->getStyle('A1')->getAlignment()->setHorizontal('center');
        $row = 3;

        if ($periodeInfo) {
            $sheet->setCellValue("A{$row}", 'Exercice : ' . $periodeInfo->code . ' - ' . $periodeInfo->libelle . ' (Du ' . $periodeInfo->date_debut->format('d/m/Y') . ' au ' . $periodeInfo->date_fin->format('d/m/Y') . ')')->mergeCells("A{$row}:H{$row}");
            $row++;
        }
        if ($entiteInfo) { 
            $sheet->setCellValue("A{$row}", 'Entité : ' . $entiteInfo->nom)->mergeCells("A{$row}:H{$row}"); 
            $row++; 
        }
        if ($succursaleInfo) { 
            $sheet->setCellValue("A{$row}", 'Succursale : ' . $succursaleInfo->nom)->mergeCells("A{$row}:H{$row}"); 
            $row++; 
        }
        $sheet->setCellValue("A{$row}", '⚠ DOCUMENT PROTÉGÉ - Export du ' . now()->format('d/m/Y à H:i'))->mergeCells("A{$row}:H{$row}");
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(8)->getColor()->setRGB('FF0000');
        $row += 2;

        $grandTotalDebit = 0; 
        $grandTotalCredit = 0;
        
        foreach ($comptesArray as $compteIndex => $compte) {
            if ($compteIndex > 0) {
                $row++;
            }
            
            $sheet->setCellValue("A{$row}", 'COMPTE : ' . $compte['numero'] . ' - ' . $compte['nom'])->mergeCells("A{$row}:H{$row}");
            $sheet->getStyle("A{$row}:H{$row}")->getFont()->setBold(true)->setSize(11)->getColor()->setRGB('1565C0');
            $sheet->getStyle("A{$row}:H{$row}")->getFill()->setFillType('solid')->getStartColor()->setRGB('E3F2FD');
            $row++;

            $headerRow = $row;
            $headers = ['Date', 'N° Écriture', 'Libellé', 'Référence', 'Débit', 'Crédit', 'Acteur', 'Solde'];
            foreach ($headers as $i => $h) {
                $sheet->setCellValue(chr(65 + $i) . $headerRow, $h);
            }
            $sheet->getStyle("A{$headerRow}:H{$headerRow}")->getFont()->setBold(true)->setSize(9)->getColor()->setRGB('FFFFFF');
            $sheet->getStyle("A{$headerRow}:H{$headerRow}")->getFill()->setFillType('solid')->getStartColor()->setRGB('42A5F5');
            $row++;

            $soldeCumul = 0; 
            $totalDebitCompte = 0; 
            $totalCreditCompte = 0;
            
            foreach ($compte['ecritures'] as $index => $ec) {
                $d = (float)$ec->debit; 
                $c = (float)$ec->credit;
                $soldeCumul += ($d - $c); 
                $totalDebitCompte += $d; 
                $totalCreditCompte += $c;
                $bg = ($index % 2 == 0) ? 'F8F9FA' : 'FFFFFF';
                $sheet->getStyle("A{$row}:H{$row}")->getFill()->setFillType('solid')->getStartColor()->setRGB($bg);
                
                $sheet->setCellValue("A{$row}", $this->formatDateValue($ec->$dateCol ?? $ec->created_at));
                $sheet->setCellValue("B{$row}", $ec->numero_ecriture ?? '');
                $sheet->setCellValue("C{$row}", mb_substr($ec->libelle ?? '', 0, 50));
                $sheet->setCellValue("D{$row}", mb_substr($ec->reference ?? '', 0, 20));
                $sheet->setCellValue("E{$row}", $d > 0 ? $d : '');
                $sheet->setCellValue("F{$row}", $c > 0 ? $c : '');
                $sheet->setCellValue("G{$row}", $this->getActeur($ec));
                $sheet->setCellValue("H{$row}", $soldeCumul);
                $sheet->getStyle("E{$row}:H{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
                $row++;
            }
            
            $grandTotalDebit += $totalDebitCompte; 
            $grandTotalCredit += $totalCreditCompte;
            $soldeCompte = $totalDebitCompte - $totalCreditCompte;
            $sheet->getStyle("A{$row}:H{$row}")->getFont()->setBold(true);
            $sheet->getStyle("A{$row}:H{$row}")->getFill()->setFillType('solid')->getStartColor()->setRGB('E8EAF6');
            $sheet->setCellValue("C{$row}", 'TOTAL ' . $compte['numero']);
            $sheet->setCellValue("E{$row}", $totalDebitCompte);
            $sheet->setCellValue("F{$row}", $totalCreditCompte);
            $sheet->setCellValue("H{$row}", $soldeCompte);
            $sheet->getStyle("E{$row}:H{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
            $row++;
        }

        $row++;
        $sheet->setCellValue("A{$row}", 'TOTAUX GÉNÉRAUX')->mergeCells("A{$row}:D{$row}");
        $sheet->setCellValue("E{$row}", $grandTotalDebit);
        $sheet->setCellValue("F{$row}", $grandTotalCredit);
        $sheet->setCellValue("H{$row}", $grandTotalDebit - $grandTotalCredit);
        $sheet->getStyle("A{$row}:H{$row}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A{$row}:H{$row}")->getFill()->setFillType('solid')->getStartColor()->setRGB('1565C0');
        $sheet->getStyle("E{$row}:H{$row}")->getNumberFormat()->setFormatCode('#,##0.0000');
        $row += 2;
        $sheet->setCellValue("A{$row}", 'Nb comptes : ' . count($comptesArray) . ' | Écritures : ' . $ecritures->count())->mergeCells("A{$row}:E{$row}");
        $sheet->getStyle("A{$row}")->getFont()->setItalic(true)->setSize(8);

        foreach (['A'=>13,'B'=>16,'C'=>42,'D'=>18,'E'=>18,'F'=>18,'G'=>22,'H'=>20] as $c => $w) {
            $sheet->getColumnDimension($c)->setWidth($w);
        }
        
        $sheet->freezePane('A2');
        $sheet->getProtection()->setSheet(true)->setPassword('GrandLivre2024');

        $filename = 'Grand_Livre_' . ($periodeInfo ? $periodeInfo->code . '_' : '') . date('Y-m-d_His') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
        exit;
    }

    private function exportCsv($comptesArray, $periodeInfo, $entiteInfo, $succursaleInfo, $dateCol)
    {
        $filename = 'Grand_Livre_' . date('Ymd_His') . '.csv';
        return response()->stream(function () use ($comptesArray, $periodeInfo, $entiteInfo, $succursaleInfo, $dateCol) {
            $f = fopen('php://output', 'w');
            fprintf($f, chr(0xEF).chr(0xBB).chr(0xBF));
            
            fputcsv($f, ['GRAND LIVRE COMPTABLE'], ';');
            if ($periodeInfo) fputcsv($f, ['Exercice:', $periodeInfo->code.' - '.$periodeInfo->libelle], ';');
            if ($entiteInfo) fputcsv($f, ['Entité:', $entiteInfo->nom], ';');
            if ($succursaleInfo) fputcsv($f, ['Succursale:', $succursaleInfo->nom], ';');
            fputcsv($f, []);
            
            foreach ($comptesArray as $compteIndex => $compte) {
                if ($compteIndex > 0) {
                    fputcsv($f, [], ';');
                }
                
                fputcsv($f, ['COMPTE: '.$compte['numero'].' - '.$compte['nom']], ';');
                fputcsv($f, ['Date', 'N° Écriture', 'Libellé', 'Référence', 'Débit', 'Crédit', 'Acteur', 'Solde'], ';');
                
                $soldeCumul = 0;
                $totalDebitCompte = 0;
                $totalCreditCompte = 0;
                
                foreach ($compte['ecritures'] as $ec) {
                    $d = (float)$ec->debit; 
                    $c = (float)$ec->credit; 
                    $soldeCumul += ($d - $c);
                    $totalDebitCompte += $d;
                    $totalCreditCompte += $c;
                    
                    fputcsv($f, [
                        $this->formatDateValue($ec->$dateCol ?? $ec->created_at), 
                        $ec->numero_ecriture ?? '', 
                        mb_substr($ec->libelle ?? '', 0, 50), 
                        $ec->reference ?? '', 
                        number_format($d, 4, '.', ''), 
                        number_format($c, 4, '.', ''), 
                        $this->getActeur($ec), 
                        number_format($soldeCumul, 4, '.', '')
                    ], ';');
                }
                
                $soldeCompte = $totalDebitCompte - $totalCreditCompte;
                fputcsv($f, [
                    '', 
                    '', 
                    'TOTAL '.$compte['numero'], 
                    '', 
                    number_format($totalDebitCompte, 4, '.', ''), 
                    number_format($totalCreditCompte, 4, '.', ''), 
                    '', 
                    number_format($soldeCompte, 4, '.', '')
                ], ';');
            }
            
            fclose($f);
        }, 200, [
            'Content-Type'=>'text/csv; charset=UTF-8',
            'Content-Disposition'=>"attachment; filename=\"$filename\""
        ]);
    }

    // =========================================================================
    // API
    // =========================================================================

    public function getSuccursalesByEntite($entite_id)
    {
        $s = Succursale::where('entite_id', $entite_id)->where('est_active', 1)->orderBy('code')->get(['id','code','nom','ville']);
        return response()->json(['success'=>true,'succursales'=>$s]);
    }

    public function getPeriodeEnCours()
    {
        $p = $this->getPeriodeEnCoursFromDB();
        return response()->json(['success'=>true,'periode'=>$p ? $this->formatPeriodeInfo($p) : null]);
    }
}