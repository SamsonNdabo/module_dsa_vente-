<?php

namespace App\Services\BudgetComptable;

use App\Models\BudgetSection;
use App\Models\BudgetRubrique;
use App\Models\BudgetPrevision;
use App\Models\PeriodesComptable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

class BudgetFichierService
{
    private const BATCH_SIZE = 500;

    // ==========================================
    // TÉLÉCHARGER LE MODÈLE
    // ==========================================
    public function telechargerModele($periodeId = null): array
    {
        $periode = $this->findPeriodeActive($periodeId);

        if (!$periode) {
            return [
                'success' => false,
                'message' => "Aucun exercice comptable n'est disponible. Veuillez d'abord créer un exercice comptable dans le module Périodes avant de générer un modèle budgétaire.",
            ];
        }

        // Vérification : au moins une rubrique active doit exister
        $rubriques = BudgetRubrique::actives()
            ->with(['section', 'parent', 'enfants'])
            ->orderBy('section_id')
            ->orderBy('ordre')
            ->get();

        if ($rubriques->isEmpty()) {
            return [
                'success' => false,
                'message' => "Aucune rubrique budgétaire n'est configurée. Veuillez d'abord créer des sections et des rubriques dans l'onglet « Configuration » avant de générer un modèle.",
            ];
        }

        $previsions = BudgetPrevision::where('periode_id', $periode->id)
            ->get()
            ->groupBy('rubrique_id')
            ->map(fn($items) => $items->mapWithKeys(fn($item) => [$item->mois => (float) $item->montant]));

        $totauxParents = $this->calculerTotauxParents($rubriques, $periode->id);

        try {
            $spreadsheet = new Spreadsheet();

            $this->buildGuideSheet($spreadsheet->getActiveSheet(), $periode);
            $spreadsheet->getActiveSheet()->setTitle('GUIDE');

            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle('BUDGET');
            $this->buildBudgetSheet($sheet, $rubriques, $previsions, $totauxParents, $periode);

            $spreadsheet->setActiveSheetIndex(0);

            return [
                'success' => true,
                'spreadsheet' => $spreadsheet,
                'filename' => 'Budget_Previsionnel_' . $periode->code . '.xlsx',
            ];
        } catch (\Exception $e) {
            Log::error('Erreur création modèle budgétaire', [
                'periode_id' => $periode->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => "Une erreur technique est survenue lors de la création du modèle. Veuillez contacter le service informatique si le problème persiste.",
            ];
        }
    }

    // ==========================================
    // IMPORTER UN FICHIER
    // ==========================================
    public function importerFichier($file, $periodeId): array
    {
        Log::info('=== DÉBUT IMPORTATION ===', [
            'fichier' => $file->getClientOriginalName(),
            'taille' => $file->getSize(),
            'periode_id' => $periodeId,
            'timestamp' => now()->toDateTimeString(),
        ]);

        // ---- ÉTAPE 1 : Vérifier la période ----
        $periode = $this->findPeriodeActive($periodeId);

        if (!$periode) {
            return [
                'success' => false,
                'message' => "L'exercice comptable sélectionné est introuvable. Veuillez sélectionner un exercice valide dans la liste déroulante.",
            ];
        }

        if ($periode->statut === 'cloture_definitive') {
            return [
                'success' => false,
                'message' => "L'exercice « {$periode->code} » est clôturé définitivement. Il n'est plus possible d'y importer des prévisions budgétaires.",
            ];
        }

        ini_set('max_execution_time', 600);
        ini_set('memory_limit', '1024M');
        DB::disableQueryLog();

        // ---- ÉTAPE 2 : Lire le fichier Excel ----
        try {
            $spreadsheet = IOFactory::load($file->getPathname());
        } catch (\Exception $e) {
            Log::error('IMPORT: Fichier illisible', [
                'fichier' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => "Le fichier « {$file->getClientOriginalName()} » est illisible ou corrompu.",
                'details' => "Veuillez vérifier que le fichier est bien au format Excel (.xlsx ou .xls) et qu'il n'est pas endommagé.",
            ];
        }

        // ---- ÉTAPE 3 : Trouver l'onglet BUDGET ----
        $sheet = $spreadsheet->getSheetByName('BUDGET');

        if (!$sheet) {
            $ongletsTrouves = implode(', ', $spreadsheet->getSheetNames());

            Log::error('IMPORT: Onglet BUDGET introuvable', [
                'fichier' => $file->getClientOriginalName(),
                'onglets' => $spreadsheet->getSheetNames(),
            ]);

            return [
                'success' => false,
                'message' => "L'onglet « BUDGET » est introuvable dans votre fichier.",
                'details' => "Votre fichier doit contenir un onglet nommé exactement « BUDGET ». Onglets trouvés dans votre fichier : {$ongletsTrouves}. Utilisez le modèle officiel téléchargeable depuis l'application.",
            ];
        }

        // ---- ÉTAPE 4 : Vérifier qu'il y a des données ----
        $rows = $sheet->toArray();

        if (count($rows) < 4) {
            Log::error('IMPORT: Fichier vide', ['nb_lignes' => count($rows)]);

            return [
                'success' => false,
                'message' => "Le fichier ne contient aucune donnée budgétaire.",
                'details' => "L'onglet « BUDGET » est vide ou ne contient pas assez de lignes. Vérifiez que vous utilisez bien le modèle fourni par l'application.",
            ];
        }

        // ---- ÉTAPE 5 : Analyser le contenu ----
        $analyse = $this->analyserFichier($rows);

        if (!empty($analyse['erreurs'])) {
            Log::error("IMPORT: Erreurs d'analyse", [
                'fichier' => $file->getClientOriginalName(),
                'nb_erreurs' => count($analyse['erreurs']),
                'erreurs' => $analyse['erreurs'],
            ]);

            return [
                'success' => false,
                'message' => "Le fichier contient " . count($analyse['erreurs']) . " anomalie(s) qui empêche(nt) son importation.",
                'erreurs' => $analyse['erreurs'],
                'details' => "Corrigez les lignes indiquées ci-dessous dans votre fichier Excel, puis relancez l'importation.",
            ];
        }

        if (empty($analyse['importData'])) {
            Log::error('IMPORT: Aucune donnée valide');

            return [
                'success' => false,
                'message' => "Aucune donnée valide n'a été trouvée dans le fichier.",
                'details' => "Vérifiez que les colonnes « Code » (colonne C) et « Libellé » (colonne D) sont bien renseignées pour chaque rubrique.",
            ];
        }

        // ---- ÉTAPE 6 : Appliquer l'import ----
        $resultat = $this->appliquerImport(
            $analyse['importData'],
            $analyse['sectionsMap'],
            $periode->id,
            $file->getClientOriginalName()
        );

        if ($resultat['success']) {
            Log::info('=== IMPORTATION RÉUSSIE ===', [
                'fichier' => $file->getClientOriginalName(),
                'details' => $resultat['details'] ?? [],
            ]);
        } else {
            Log::error('=== ÉCHEC IMPORTATION ===', [
                'fichier' => $file->getClientOriginalName(),
                'message' => $resultat['message'] ?? 'Erreur inconnue',
            ]);
        }

        return $resultat;
    }

    // ==========================================
    // EXPORTER LE SUIVI
    // ==========================================
    public function exporterSuivi($lignes, $periode, $vue = 'annuel', $mois = null): array
    {
        try {
            $spreadsheet = new Spreadsheet();
            $moisNoms = ['', 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
            $moisAFiltrer = ($vue === 'mensuel' && $mois) ? [$mois] : range(1, 12);

            $this->buildSyntheseSheet($spreadsheet->getActiveSheet(), $lignes, $periode, $moisAFiltrer, $moisNoms);
            $spreadsheet->getActiveSheet()->setTitle('SYNTHESE');

            foreach (['PREVISIONS' => 'previsions', 'REALISATIONS' => 'realisations', 'ECARTS' => 'ecarts'] as $titre => $type) {
                $s = $spreadsheet->createSheet();
                $s->setTitle($titre);
                $this->buildDetailSheet($s, $lignes, $type, $periode, $moisAFiltrer, $moisNoms);
            }

            $spreadsheet->setActiveSheetIndex(0);

            return [
                'success' => true,
                'spreadsheet' => $spreadsheet,
                'filename' => 'Suivi_Budgetaire_' . ($periode->code ?? 'GLOBAL') . '_' . date('Ymd_His') . '.xlsx',
            ];
        } catch (\Exception $e) {
            Log::error('Erreur export suivi budgétaire', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => "Une erreur technique est survenue lors de la génération du fichier d'export. Veuillez contacter le service informatique.",
            ];
        }
    }

    // ==========================================
    // HELPERS
    // ==========================================

    private function findPeriodeActive($id = null): ?PeriodesComptable
    {
        if ($id) {
            return PeriodesComptable::find($id);
        }

        $periode = PeriodesComptable::where('statut', 'ouverte')
            ->orderBy('date_debut', 'desc')
            ->first();

        if ($periode) {
            return $periode;
        }

        $periode = PeriodesComptable::where('statut', 'cloture_provisoire')
            ->orderBy('date_debut', 'desc')
            ->first();

        if ($periode) {
            return $periode;
        }

        return PeriodesComptable::orderBy('date_debut', 'desc')->first();
    }

    private function col($i): string
    {
        return Coordinate::stringFromColumnIndex($i);
    }

    private function calculerTotauxParents($rubriques, $periodeId): array
    {
        $totaux = [];

        foreach ($rubriques as $r) {
            if ($r->parent_id === null && $r->enfants->isNotEmpty()) {
                $ids = $r->enfants->pluck('id')->toArray();

                for ($m = 1; $m <= 12; $m++) {
                    $totaux[$r->id][$m] = BudgetPrevision::where('periode_id', $periodeId)
                        ->whereIn('rubrique_id', $ids)
                        ->where('mois', $m)
                        ->sum('montant');
                }
            }
        }

        return $totaux;
    }

    private function nettoyerMontant($v): float
    {
        if (empty($v)) {
            return 0;
        }

        if (is_numeric($v)) {
            return (float) $v;
        }

        $v = str_replace(['$', '€', '£', 'CFA', 'FCFA', "\xc2\xa0", "\xa0"], '', (string) $v);

        if (strpos($v, ',') !== false && strpos($v, '.') !== false) {
            $v = (strrpos($v, '.') > strrpos($v, ','))
                ? str_replace(',', '', $v)
                : str_replace(['.', ','], ['', '.'], $v);
        } elseif (strpos($v, ',') !== false) {
            $ap = substr($v, strrpos($v, ',') + 1);
            $v = (strlen($ap) <= 2 && !str_contains($ap, ' '))
                ? str_replace(',', '.', $v)
                : str_replace(',', '', $v);
        }

        $v = preg_replace('/[^0-9.\-]/', '', str_replace(' ', '', $v));

        return is_numeric($v) ? (float) $v : 0;
    }

    /**
     *  CORRIGÉ : chaque style est appliqué séparément (Font / Fill / Borders)
     */
    private function styleHeader($sheet, $range): void
    {
        $sheet->getStyle($range)->getFont()->setBold(true);
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('F3F4F6');
        $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }

    // ==========================================
    // GUIDE
    // ==========================================
    private function buildGuideSheet($sheet, $periode): void
    {
        // Titre principal
        $sheet->setCellValue('A1', 'GUIDE - BUDGET PREVISIONNEL');
        $sheet->mergeCells('A1:E1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('DBEAFE');

        // --- STRUCTURE ---
        $sheet->setCellValue('A3', '📋 STRUCTURE');
        $sheet->getStyle('A3')->getFont()->setBold(true);

        $sheet->setCellValue('A4', 'Colonne A : Section');
        $sheet->setCellValue('A5', 'Colonne B : Type (Catégorie parente)');
        $sheet->setCellValue('A6', 'Colonne C : Code');
        $sheet->setCellValue('A7', 'Colonne D : Libellé');
        $sheet->setCellValue('A8', 'Colonne E : Comptes');
        $sheet->setCellValue('A9', 'Colonnes F à Q : Janvier à Décembre');
        $sheet->setCellValue('A10', 'Colonne R : Total (calcul automatique)');

        // --- RÈGLES ---
        $sheet->setCellValue('A12', '📌 RÈGLES');
        $sheet->getStyle('A12')->getFont()->setBold(true);

        $sheet->setCellValue('A13', '1. Les lignes grises sont des catégories. Ne pas les modifier.');
        $sheet->setCellValue('A14', '2. Les lignes blanches sont des rubriques. Saisir les montants.');
        $sheet->setCellValue('A15', '3. La colonne R se calcule automatiquement.');

        // --- HÉRITAGE ---
        $sheet->setCellValue('A17', '💡 HÉRITAGE');
        $sheet->getStyle('A17')->getFont()->setBold(true);

        $sheet->setCellValue('A18', 'Si une cellule en colonne A ou B est vide, elle hérite de la valeur de la ligne du dessus.');

        // --- EXEMPLE ---
        $sheet->setCellValue('A20', '📝 EXEMPLE');
        $sheet->getStyle('A20')->getFont()->setBold(true);

        $sheet->setCellValue('A21', 'Section');
        $sheet->setCellValue('B21', 'Type');
        $sheet->setCellValue('C21', 'Code');
        $sheet->setCellValue('D21', 'Libellé');
        $sheet->getStyle('A21:D21')->getFont()->setBold(true);
        $sheet->getStyle('A21:D21')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('E5E7EB');

        $sheet->setCellValue('A22', "CHARGES D'EXPLOITATION");
        $sheet->setCellValue('B22', 'Charges expl');
        $sheet->setCellValue('C22', 'CH-2-1-1');
        $sheet->setCellValue('D22', 'Loyer');

        $sheet->setCellValue('C23', 'CH-2-1-2');
        $sheet->setCellValue('D23', 'Logement');

        $sheet->setCellValue('C24', 'CH-2-1-3');
        $sheet->setCellValue('D24', 'Location salle');

        // --- Astuce ---
        $sheet->setCellValue('A26', ' Astuce : remplir Section et Type sur la 1ère ligne, laisser vides ensuite.');
        $sheet->getStyle('A26')->getFont()->setItalic(true);

        // --- MONTANTS ---
        $sheet->setCellValue('A28', '⚠️ MONTANTS');
        $sheet->getStyle('A28')->getFont()->setBold(true);

        $sheet->setCellValue('A29', '✓ Format accepté : 1500, 200.5, 0');
        $sheet->setCellValue('A30', '✗ Format refusé : "1 500 $", "1,500", "mille"');

        // --- Période ---
        $sheet->setCellValue('A32', '📅 Période : ' . $periode->code . ' - ' . $periode->libelle);
        $sheet->getStyle('A32')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A32')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('DBEAFE');

        // Largeur de la colonne A
        $sheet->getColumnDimension('A')->setWidth(80);
    }

    // ==========================================
    // BUDGET SHEET
    //  CORRIGÉ : plus de chaînage getFont()->...->getFill()
    // ==========================================
    private function buildBudgetSheet($sheet, $rubriques, $previsions, $totauxParents, $periode): void
    {
        $sheet->setCellValue('A1', 'BUDGET PREVISIONNEL - ' . $periode->code)->mergeCells('A1:R1');

        //  Style TITRE : 2 appels séparés
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('DBEAFE');

        $headers = ['Section', 'Type', 'Code', 'Libellé', 'Comptes', 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre', 'Total'];

        foreach ($headers as $i => $h) {
            $sheet->setCellValue($this->col($i + 1) . '3', $h);
        }

        $this->styleHeader($sheet, 'A3:R3');

        $row = 4;
        $lastSection = '';
        $lastType = '';

        foreach ($rubriques as $r) {
            if (!$r->section) {
                continue;
            }

            $sn = $r->section->libelle;
            $isP = $r->parent_id === null;
            $tv = $isP ? $r->libelle : ($r->parent->libelle ?? '');

            $sheet->setCellValue("A{$row}", $sn !== $lastSection ? $sn : '');
            $sheet->setCellValue("B{$row}", $tv !== $lastType ? $tv : '');
            $sheet->setCellValue("C{$row}", $r->code);
            $sheet->setCellValue("D{$row}", $r->libelle);
            $sheet->setCellValue("E{$row}", $r->comptes_racines ?? '');

            if ($sn !== $lastSection) $lastSection = $sn;
            if ($tv !== $lastType) $lastType = $tv;

            if ($isP) {
                //  Style PARENT : 2 appels séparés
                $sheet->getStyle("A{$row}:R{$row}")->getFont()->setBold(true);
                $sheet->getStyle("A{$row}:R{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('E8E8E8');
            }

            for ($m = 1; $m <= 12; $m++) {
                $cl = $this->col($m + 5);

                if ($isP && isset($totauxParents[$r->id])) {
                    $montant = $totauxParents[$r->id][$m] ?? 0;
                } elseif ($isP) {
                    $montant = 0;
                } else {
                    $montant = ($previsions->get($r->id) && isset($previsions->get($r->id)[$m]))
                        ? $previsions->get($r->id)[$m]
                        : 0;
                }

                $sheet->setCellValueExplicit($cl . $row, (float) $montant, DataType::TYPE_NUMERIC);
            }

            $sheet->setCellValue("R{$row}", "=SUM(F{$row}:Q{$row})");
            $row++;
        }

        for ($r = 4; $r <= 200; $r++) {
            if (!$sheet->getCell("R{$r}")->getValue()) {
                $sheet->setCellValue("R{$r}", "=SUM(F{$r}:Q{$r})");
            }
        }

        $fin = max($row, 200);

        //  Style GLOBAL : 2 appels séparés
        $sheet->getStyle("A4:R{$fin}")->getFont()->getColor()->setARGB('000000');
        $sheet->getStyle("A4:R{$fin}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        $sheet->getStyle("F4:R{$fin}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getColumnDimension('A')->setWidth(28);
        $sheet->getColumnDimension('B')->setWidth(30);
        $sheet->getColumnDimension('C')->setWidth(16);
        $sheet->getColumnDimension('D')->setWidth(38);

        for ($m = 1; $m <= 13; $m++) {
            $sheet->getColumnDimension($this->col($m + 5))->setWidth(14);
        }
    }

    // ==========================================
    // ANALYSE DU FICHIER
    // ==========================================
    private function analyserFichier($rows): array
    {
        $data = [];
        $errs = [];
        $sections = [];
        $sec = '';
        $parent = null;

        Log::info('ANALYSE: Début', ['nb_lignes' => count($rows)]);

        foreach ($rows as $i => $row) {
            if ($i < 3) {
                continue;
            }

            $l = $i + 1;
            $a = trim((string) ($row[0] ?? ''));
            $b = trim((string) ($row[1] ?? ''));
            $c = trim((string) ($row[2] ?? ''));
            $d = trim((string) ($row[3] ?? ''));
            $e = trim((string) ($row[4] ?? ''));

            if (!$a && !$b && !$c && !$d) {
                continue;
            }

            if (!$c && !$d) {
                continue;
            }

            if (!$c) {
                $errs[] = "Ligne {$l} : le CODE de la rubrique est manquant. Chaque rubrique doit obligatoirement avoir un code dans la colonne « Code ».";
                continue;
            }

            if (!$d) {
                $errs[] = "Ligne {$l} : le LIBELLÉ de la rubrique est manquant. Chaque rubrique doit obligatoirement avoir un libellé dans la colonne « Libellé ».";
                continue;
            }

            if ($a) {
                $sec = $a;
                $sections[$sec] = ['libelle' => $sec, 'type_section' => ''];
                $parent = null;
            }

            if (!$sec) {
                $errs[] = "Ligne {$l} : la SECTION (colonne A) n'est pas renseignée pour la rubrique « {$d} ». Indiquez le nom de la section sur la première ligne de chaque groupe.";
                continue;
            }

            if ($b) {
                $sections[$sec]['type_section'] = $b;
            }

            if ($b) {
                $parent = $c;
                $data[] = [
                    'type' => 'parent',
                    'section_nom' => $sec,
                    'code' => $c,
                    'libelle' => $d,
                    'comptes' => $e,
                    'ligne' => $l,
                ];
            } else {
                if (!$parent) {
                    $errs[] = "Ligne {$l} : la rubrique « {$d} » n'est rattachée à aucune catégorie. Indiquez le nom de la catégorie parente dans la colonne « Type » (B) sur une ligne précédente.";
                    continue;
                }

                $mnts = [];

                for ($m = 1; $m <= 12; $m++) {
                    $mnts[$m] = $this->nettoyerMontant($row[$m + 4] ?? 0);
                }

                $data[] = [
                    'type' => 'enfant',
                    'section_nom' => $sec,
                    'parent_code' => $parent,
                    'code' => $c,
                    'libelle' => $d,
                    'comptes' => $e,
                    'montants' => $mnts,
                    'ligne' => $l,
                ];
            }
        }

        // Vérification des parents orphelins
        $pcodes = [];

        foreach ($data as $d) {
            if ($d['type'] === 'parent') {
                $pcodes[$d['code']] = true;
            }
        }

        foreach ($data as $d) {
            if ($d['type'] === 'enfant' && $d['parent_code'] && !isset($pcodes[$d['parent_code']])) {
                $errs[] = "Ligne {$d['ligne']} : la catégorie parente « {$d['parent_code']} » est introuvable pour la rubrique « {$d['libelle']} ». Assurez-vous que la catégorie est bien définie AVANT ses rubriques dans le fichier.";
            }
        }

        // Vérification des doublons de code
        $allCodes = [];

        foreach ($data as $d) {
            $code = $d['code'];

            if (isset($allCodes[$code])) {
                $errs[] = "Le code « {$code} » est utilisé à plusieurs reprises (lignes {$allCodes[$code]} et {$d['ligne']}). Chaque rubrique doit avoir un code unique. Supprimez les doublons avant de réimporter.";
            }

            $allCodes[$code] = $d['ligne'];
        }

        Log::info('ANALYSE: Fin', [
            'nb_valides' => count($data),
            'nb_erreurs' => count($errs),
            'nb_sections' => count($sections),
        ]);

        return [
            'importData' => $data,
            'erreurs' => $errs,
            'sectionsMap' => $sections,
        ];
    }

    // ==========================================
    // APPLIQUER L'IMPORT
    // ==========================================
    private function appliquerImport($importData, $sectionsMap, $periodeId, $nomFichier = 'inconnu'): array
    {
        Log::info('IMPORT: Début application', [
            'fichier' => $nomFichier,
            'nb_sections' => count($sectionsMap),
            'nb_lignes' => count($importData),
            'periode_id' => $periodeId,
        ]);

        DB::beginTransaction();

        try {
            $nbS = 0;
            $nbR = 0;
            $nbP = 0;
            $sIds = [];
            $pIds = [];
            $batch = [];
            $avertissements = [];
            $erreursImport = [];

            BudgetSection::withoutEvents(function () use (
                $importData,
                $sectionsMap,
                $periodeId,
                $nomFichier,
                &$nbS,
                &$nbR,
                &$nbP,
                &$sIds,
                &$pIds,
                &$batch,
                &$avertissements,
                &$erreursImport
            ) {

                $nbSupprimees = BudgetPrevision::where('periode_id', $periodeId)->delete();

                Log::info('IMPORT: Anciennes prévisions supprimées', [
                    'nb_supprimees' => $nbSupprimees,
                ]);

                // --- Création/Mise à jour des sections ---
                foreach ($sectionsMap as $nom => $sec) {
                    try {
                        $code = strtoupper(substr(preg_replace('/[^a-zA-Z0-9_]/', '', $nom), 0, 20));

                        $s = BudgetSection::updateOrCreate(
                            ['code' => $code],
                            [
                                'libelle' => $nom,
                                'type_section' => $sec['type_section'] ?? '',
                                'actif' => true,
                                'ordre' => $nbS + 1,
                            ]
                        );

                        $sIds[$nom] = $s->id;
                        $nbS++;
                    } catch (\Exception $e) {
                        $erreursImport[] = "Impossible de créer la section « {$nom} ». Vérifiez qu'aucune autre section ne porte déjà ce nom dans la configuration.";
                        Log::error('IMPORT: Erreur section', [
                            'section' => $nom,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // --- Création/Mise à jour des rubriques parents ---
                foreach ($importData as $item) {
                    if ($item['type'] !== 'parent') {
                        continue;
                    }

                    $sid = $sIds[$item['section_nom']] ?? null;

                    if (!$sid) {
                        $erreursImport[] = "Ligne {$item['ligne']} : la section « {$item['section_nom']} » n'a pas pu être créée, ce qui empêche le rattachement de la catégorie « {$item['libelle']} ».";
                        continue;
                    }

                    try {
                        $r = BudgetRubrique::updateOrCreate(
                            ['code' => $item['code'], 'section_id' => $sid, 'parent_id' => null],
                            [
                                'libelle' => $item['libelle'],
                                'comptes_racines' => $item['comptes'],
                                'actif' => true,
                                'ordre' => $item['ligne'],
                            ]
                        );

                        $pIds[$item['code']] = $r->id;
                        $nbR++;
                    } catch (\Exception $e) {
                        $erreursImport[] = "Ligne {$item['ligne']} : impossible d'enregistrer la catégorie « {$item['libelle']} » (code : {$item['code']}). Vérifiez que ce code n'est pas déjà utilisé par une autre rubrique.";
                        Log::error('IMPORT: Erreur rubrique parent', [
                            'item' => $item,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // --- Création/Mise à jour des rubriques enfants + prévisions ---
                $rubriquesTraitees = [];

                foreach ($importData as $item) {
                    if ($item['type'] !== 'enfant') {
                        continue;
                    }

                    $sid = $sIds[$item['section_nom']] ?? null;
                    $pid = $pIds[$item['parent_code']] ?? null;

                    if (!$sid) {
                        $erreursImport[] = "Ligne {$item['ligne']} : la section « {$item['section_nom']} » est introuvable pour la rubrique « {$item['libelle']} ».";
                        continue;
                    }

                    if (!$pid) {
                        $erreursImport[] = "Ligne {$item['ligne']} : la catégorie parente « {$item['parent_code']} » est introuvable pour la rubrique « {$item['libelle']} ». Vérifiez que cette catégorie est bien définie plus haut dans le fichier.";
                        continue;
                    }

                    try {
                        $cleRubrique = $item['code'] . '_' . $sid . '_' . $pid;

                        if (isset($rubriquesTraitees[$cleRubrique])) {
                            $avertissements[] = "Ligne {$item['ligne']} : la rubrique « {$item['libelle']} » (code : {$item['code']}) apparaît en double. Seule la première occurrence a été conservée.";
                            continue;
                        }

                        $rubriquesTraitees[$cleRubrique] = true;

                        $r = BudgetRubrique::updateOrCreate(
                            ['code' => $item['code'], 'section_id' => $sid, 'parent_id' => $pid],
                            [
                                'libelle' => $item['libelle'],
                                'comptes_racines' => $item['comptes'],
                                'actif' => true,
                                'ordre' => $item['ligne'],
                            ]
                        );

                        $nbR++;

                        foreach ($item['montants'] as $mois => $montant) {
                            $batch[] = [
                                'rubrique_id' => $r->id,
                                'periode_id' => $periodeId,
                                'mois' => $mois,
                                'montant' => $montant,
                                'statut' => BudgetPrevision::STATUT_EN_ATTENTE,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];

                            if (count($batch) >= self::BATCH_SIZE) {
                                try {
                                    BudgetPrevision::insert($batch);
                                    $nbP += count($batch);
                                } catch (\Exception $e) {
                                    $erreursImport[] = "Erreur lors de l'enregistrement des prévisions : " . $this->traduireErreurSQL($e->getMessage());
                                    Log::error('IMPORT: Erreur batch', ['error' => $e->getMessage()]);
                                }

                                $batch = [];
                            }
                        }
                    } catch (\Exception $e) {
                        $erreursImport[] = "Ligne {$item['ligne']} : impossible d'enregistrer la rubrique « {$item['libelle']} » (code : {$item['code']}). " . $this->traduireErreurSQL($e->getMessage());
                        Log::error('IMPORT: Erreur rubrique enfant', [
                            'item' => $item,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Insertion du dernier batch
                if (!empty($batch)) {
                    try {
                        BudgetPrevision::insert($batch);
                        $nbP += count($batch);
                    } catch (\Exception $e) {
                        $erreursImport[] = "Erreur lors de l'enregistrement des prévisions : " . $this->traduireErreurSQL($e->getMessage());
                        Log::error('IMPORT: Erreur dernier batch', ['error' => $e->getMessage()]);
                    }
                }
            });

            // Si des erreurs sont survenues
            if (!empty($erreursImport)) {
                DB::rollBack();

                Log::error('=== IMPORT ANNULÉ ===', [
                    'fichier' => $nomFichier,
                    'nb_erreurs' => count($erreursImport),
                ]);

                return [
                    'success' => false,
                    'message' => "L'importation a échoué : " . count($erreursImport) . " problème(s) détecté(s). Aucune donnée n'a été enregistrée.",
                    'erreurs' => $erreursImport,
                    'details' => [
                        'sections' => $nbS,
                        'rubriques' => $nbR,
                        'previsions' => 0,
                        'avertissements' => count($avertissements),
                        'liste_erreurs' => array_merge($avertissements, $erreursImport),
                    ],
                ];
            }

            DB::commit();

            Log::info('=== IMPORT RÉUSSI ===', [
                'fichier' => $nomFichier,
                'sections' => $nbS,
                'rubriques' => $nbR,
                'previsions' => $nbP,
            ]);

            return [
                'success' => true,
                'message' => "Importation réussie : {$nbS} section(s), {$nbR} rubrique(s) et {$nbP} prévision(s) enregistrée(s).",
                'details' => [
                    'sections' => $nbS,
                    'rubriques' => $nbR,
                    'previsions' => $nbP,
                    'avertissements' => count($avertissements),
                    'liste_erreurs' => $avertissements,
                ],
            ];
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('=== EXCEPTION IMPORT ===', [
                'fichier' => $nomFichier,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => "Une erreur technique est survenue lors de l'importation. Aucune donnée n'a été enregistrée. Veuillez contacter le service informatique si le problème persiste.",
                'details' => [
                    'sections' => 0,
                    'rubriques' => 0,
                    'previsions' => 0,
                    'avertissements' => 0,
                    'liste_erreurs' => [
                        $this->traduireErreurSQL($e->getMessage()),
                    ],
                ],
            ];
        }
    }

    // ==========================================
    // TRADUCTION DES ERREURS SQL
    // ==========================================
    private function traduireErreurSQL(string $message): string
    {
        if (strpos($message, 'Duplicate entry') !== false) {
            if (strpos($message, 'idx_rubrique_periode_mois') !== false) {
                return "Un doublon a été détecté : la même rubrique possède des prévisions en double pour le même mois. Vérifiez que chaque rubrique n'apparaît qu'une seule fois dans le fichier.";
            }

            return "Un doublon a été détecté dans les données. Vérifiez que les codes des rubriques sont uniques dans le fichier.";
        }

        if (strpos($message, 'Integrity constraint violation') !== false) {
            return "Une donnée obligatoire est manquante ou incorrecte. Vérifiez que chaque ligne contient bien un code, un libellé et une section.";
        }

        if (strpos($message, 'Data too long') !== false) {
            return "Une valeur saisie est trop longue pour être enregistrée. Raccourcissez le code ou le libellé concerné (20 caractères maximum pour un code).";
        }

        if (strpos($message, 'foreign key constraint') !== false) {
            return "Une référence est invalide : une section ou une catégorie parente est manquante. Vérifiez que toutes les catégories parentes existent bien avant leurs rubriques.";
        }

        return "Une erreur est survenue lors de l'enregistrement des données. Vérifiez la structure du fichier et réessayez.";
    }

    // ==========================================
    // EXPORT - SYNTHÈSE
    //  CORRIGÉ : plus de chaînage getFont()->...->getFill()
    // ==========================================
    private function buildSyntheseSheet($sheet, $lignes, $periode, $moisAFiltrer, $moisNoms): void
    {
        $sheet->setCellValue('A1', 'SUIVI BUDGÉTAIRE - ' . ($periode->code ?? ''))->mergeCells('A1:Z1');

        //  Style TITRE : 3 appels séparés
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getFont()->getColor()->setARGB('FFFFFF');
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('1e3a5f');

        $row = 3;
        $ci = 2;

        $sheet->setCellValue("A{$row}", 'Rubrique');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);

        foreach ($moisAFiltrer as $m) {
            foreach (['Prévu' => 'dbeafe', 'Réalisé' => 'd1fae5', 'Écart' => 'fef3c7'] as $label => $color) {
                $sheet->setCellValue($this->col($ci) . $row, $moisNoms[$m] . ' ' . $label);
                $sheet->getStyle($this->col($ci++) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($color);
            }
        }

        foreach (['Total Prévu' => 'dbeafe', 'Total Réalisé' => 'd1fae5', 'Écart Total' => 'fef3c7'] as $label => $color) {
            $sheet->setCellValue($this->col($ci) . $row, $label);
            $sheet->getStyle($this->col($ci++) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($color);
        }

        $lc = $this->col($ci - 1);
        $sheet->getStyle("A{$row}:{$lc}{$row}")->getFont()->setBold(true);
        $row++;

        foreach ($lignes as $l) {
            if ($l['type'] === 'section') {
                $sheet->mergeCells("A{$row}:{$lc}{$row}")->setCellValue("A{$row}", $l['libelle']);

                //  Style SECTION : 2 appels séparés
                $sheet->getStyle("A{$row}:{$lc}{$row}")->getFont()->setBold(true);
                $sheet->getStyle("A{$row}:{$lc}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('f1f5f9');

                $row++;
                continue;
            }

            $sheet->setCellValue("A{$row}", ($l['code'] ? $l['code'] . ' - ' : '') . $l['libelle']);

            if ($l['type'] === 'parent') {
                //  Style PARENT : 2 appels séparés
                $sheet->getStyle("A{$row}:{$lc}{$row}")->getFont()->setBold(true);
                $sheet->getStyle("A{$row}:{$lc}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('f8fafc');
            }

            $col = 2;
            $tp = 0;
            $tr = 0;

            foreach ($moisAFiltrer as $m) {
                $p = $l['previsions'][$m] ?? 0;
                $r = $l['realisations'][$m] ?? 0;
                $e = ($l['section_type'] === 'revenu') ? ($r - $p) : ($p - $r);

                $tp += $p;
                $tr += $r;

                $sheet->setCellValue($this->col($col++) . $row, $p);
                $sheet->setCellValue($this->col($col++) . $row, $r);
                $sheet->setCellValue($this->col($col++) . $row, $e);
            }

            $sheet->setCellValue($this->col($col++) . $row, $tp);
            $sheet->setCellValue($this->col($col++) . $row, $tr);
            $sheet->setCellValue($this->col($col++) . $row, $tr - $tp);
            $row++;
        }

        //  Style LIGNE TOTAL : 2 appels séparés
        $sheet->getStyle("A{$row}:{$lc}{$row}")->getFont()->setBold(true);
        $sheet->getStyle("A{$row}:{$lc}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('e2e8f0');
        $sheet->setCellValue("A{$row}", 'TOTAL GÉNÉRAL');

        $col = 2;

        foreach ($moisAFiltrer as $m) {
            $mp = 0;
            $mr = 0;

            foreach ($lignes as $l) {
                if (in_array($l['type'], ['section', 'parent'])) {
                    continue;
                }

                $mp += $l['previsions'][$m] ?? 0;
                $mr += $l['realisations'][$m] ?? 0;
            }

            $sheet->setCellValue($this->col($col++) . $row, $mp);
            $sheet->setCellValue($this->col($col++) . $row, $mr);
            $sheet->setCellValue($this->col($col++) . $row, $mr - $mp);
        }

        $sheet->setCellValue($this->col($col++) . $row, array_sum(array_map(fn($l) => in_array($l['type'], ['section', 'parent']) ? 0 : array_sum($l['previsions'] ?? []), $lignes)));
        $sheet->setCellValue($this->col($col++) . $row, array_sum(array_map(fn($l) => in_array($l['type'], ['section', 'parent']) ? 0 : array_sum($l['realisations'] ?? []), $lignes)));
        $sheet->setCellValue($this->col($col) . $row, '=(' . $this->col($col - 1) . $row . '-' . $this->col($col - 2) . $row . ')');

        $sheet->getStyle("A3:{$lc}{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("B3:{$lc}{$row}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getColumnDimension('A')->setWidth(40);

        for ($i = 2; $i <= $ci; $i++) {
            $sheet->getColumnDimension($this->col($i))->setWidth(14);
        }
    }

    // ==========================================
    // EXPORT - DETAIL
    //  CORRIGÉ : plus de chaînage getFont()->...->getFill()
    // ==========================================
    private function buildDetailSheet($sheet, $lignes, $type, $periode, $moisAFiltrer, $moisNoms): void
    {
        $titres = ['previsions' => 'PRÉVISIONS', 'realisations' => 'RÉALISATIONS', 'ecarts' => 'ÉCARTS'];

        $sheet->setCellValue('A1', $titres[$type] . ' - ' . ($periode->code ?? ''))->mergeCells('A1:N1');

        //  Style TITRE : 3 appels séparés
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getFont()->getColor()->setARGB('FFFFFF');
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('1e3a5f');

        $row = 3;
        $headers = array_merge(['Code', 'Rubrique', 'Section'], array_map(fn($m) => $moisNoms[$m], $moisAFiltrer), ['TOTAL']);

        foreach ($headers as $i => $h) {
            $sheet->setCellValue($this->col($i + 1) . $row, $h);
        }

        $lc = $this->col(count($headers));
        $this->styleHeader($sheet, "A{$row}:{$lc}{$row}");
        $row++;

        foreach ($lignes as $l) {
            if ($l['type'] === 'section') {
                continue;
            }

            $sheet->setCellValue("A{$row}", $l['code'] ?? '')
                ->setCellValue("B{$row}", $l['libelle'] ?? '')
                ->setCellValue("C{$row}", $l['section_type'] ?? '');

            if ($l['type'] === 'parent') {
                //  Style PARENT : 2 appels séparés
                $sheet->getStyle("A{$row}:{$lc}{$row}")->getFont()->setBold(true);
                $sheet->getStyle("A{$row}:{$lc}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('f8fafc');
            }

            $col = 4;
            $total = 0;

            foreach ($moisAFiltrer as $m) {
                $v = match ($type) {
                    'previsions' => $l['previsions'][$m] ?? 0,
                    'realisations' => $l['realisations'][$m] ?? 0,
                    default => (($l['section_type'] ?? '') === 'revenu')
                        ? (($l['realisations'][$m] ?? 0) - ($l['previsions'][$m] ?? 0))
                        : (($l['previsions'][$m] ?? 0) - ($l['realisations'][$m] ?? 0)),
                };

                $total += $v;
                $sheet->setCellValue($this->col($col++) . $row, $v);
            }

            $sheet->setCellValue($this->col($col) . $row, $total);
            $row++;
        }

        $sheet->getStyle("A3:{$lc}{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("D3:{$lc}{$row}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getColumnDimension('A')->setWidth(18);
        $sheet->getColumnDimension('B')->setWidth(40);

        for ($i = 4; $i <= $col; $i++) {
            $sheet->getColumnDimension($this->col($i))->setWidth(14);
        }
    }
}