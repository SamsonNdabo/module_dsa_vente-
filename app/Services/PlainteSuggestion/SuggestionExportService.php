<?php

namespace App\Services\PlainteSuggestion;

use App\Models\PlainteSuggestion\Suggestion;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class SuggestionExportService
{
    // ============================================================
    // PALETTE
    // ============================================================
    private const C_PRIMARY_BG    = 'BAE6FD';
    private const C_PRIMARY_TEXT  = '075985';
    private const C_SECTION_BG    = 'E0F2FE';
    private const C_SECTION_TEXT  = '0369A1';
    private const C_HEADER_TABLE  = '38BDF8';
    private const C_HEADER_TEXT   = 'FFFFFF';
    private const C_ROW_ALT       = 'F0F9FF';
    private const C_TEXT_DARK     = '0F172A';
    private const C_BORDER        = 'BAE6FD';
    private const C_ACCENT        = '0EA5E9';

    // Priorités
    private const C_CRITIQUE_BG   = 'FECDD3';
    private const C_CRITIQUE_TXT  = '9F1239';
    private const C_HAUTE_BG      = 'FED7AA';
    private const C_HAUTE_TXT     = '9A3412';
    private const C_NORMALE_BG    = 'BAE6FD';
    private const C_NORMALE_TXT   = '075985';
    private const C_BASSE_BG      = 'BBF7D0';
    private const C_BASSE_TXT     = '14532D';

    // Statuts
    private const C_STATUT_NOUVELLE_BG = 'DBEAFE';
    private const C_STATUT_ENCours_BG  = 'FEF3C7';
    private const C_STATUT_RESOLUE_BG  = 'D1FAE5';
    private const C_STATUT_REJETEE_BG  = 'FEE2E2';

    // ============================================================
    // ENTRÉE PRINCIPALE
    // ============================================================
    public function genererKpi(array $data, array $filtres = []): array
    {
        try {
            $spreadsheet = new Spreadsheet();
            $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);

            // Feuille 1 : Rapport
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Rapport');
            $sheet->setShowGridlines(true);
            $this->construireFeuilleRapport($sheet, $data, $filtres);

            // Feuille 2 : Liste complète
            $sheetListe = $spreadsheet->createSheet();
            $sheetListe->setTitle('Liste complète');
            $sheetListe->setShowGridlines(true);
            $this->construireFeuilleListe($sheetListe, $filtres);

            // Feuille 3 : Détails complets
            $sheetDetails = $spreadsheet->createSheet();
            $sheetDetails->setTitle('Détails complets');
            $sheetDetails->setShowGridlines(true);
            $this->construireFeuilleDetails($sheetDetails, $filtres);

            //  Feuille 4 : Historique
            $sheetHistorique = $spreadsheet->createSheet();
            $sheetHistorique->setTitle('Historique');
            $sheetHistorique->setShowGridlines(true);
            $this->construireFeuilleHistorique($sheetHistorique, $filtres);

            $spreadsheet->setActiveSheetIndex(0);

            $filename = 'rapport-suggestions-' . now()->format('Y-m-d') . '.xlsx';

            return [
                'success' => true,
                'spreadsheet' => $spreadsheet,
                'filename' => $filename,
            ];
        } catch (\Throwable $e) {
            \Log::error('SuggestionExportService erreur', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return [
                'success' => false,
                'message' => 'Erreur : ' . $e->getMessage(),
            ];
        }
    }

    // ============================================================
    // FEUILLE 1 : RAPPORT
    // ============================================================
    private function construireFeuilleRapport(Worksheet $sheet, array $data, array $filtres): void
    {
        $sheet->getColumnDimension('A')->setWidth(30);
        $sheet->getColumnDimension('B')->setWidth(16);
        $sheet->getColumnDimension('C')->setWidth(14);
        $sheet->getColumnDimension('D')->setWidth(14);
        $sheet->getColumnDimension('E')->setWidth(16);
        $sheet->getColumnDimension('F')->setWidth(14);
        for ($col = 'G'; $col <= 'P'; $col++) {
            $sheet->getColumnDimension($col)->setWidth(12);
        }

        $row = 1;

        // BANDEAU
        $sheet->mergeCells("A{$row}:F{$row}");
        $sheet->setCellValue("A{$row}", 'RAPPORT - SUGGESTIONS');
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 20, 'color' => ['rgb' => self::C_PRIMARY_TEXT]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::C_PRIMARY_BG]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(40);
        $row++;

        $sheet->mergeCells("A{$row}:F{$row}");
        $sheet->setCellValue("A{$row}", 'Statistiques, indicateurs et suivi des suggestions clients');
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font' => ['italic' => true, 'size' => 11, 'color' => ['rgb' => self::C_PRIMARY_TEXT]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::C_PRIMARY_BG]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(24);
        $row += 2;

        // MÉTADONNÉES
        $sheet->setCellValue("A{$row}", 'Généré le');
        $sheet->setCellValue("B{$row}", now()->format('d/m/Y H:i'));
        $sheet->setCellValue("D{$row}", 'Année');
        $sheet->setCellValue("E{$row}", $filtres['annee'] ?? 'Toutes');
        $row++;
        $sheet->setCellValue("A{$row}", 'Zone');
        $sheet->setCellValue("B{$row}", $filtres['zone'] ?? 'Toutes les zones');
        $sheet->setCellValue("D{$row}", 'Type');
        $sheet->setCellValue("E{$row}", 'Rapport global');

        $sheet->getStyle("A{$row}:F{$row}")->getAlignment()->setIndent(1);
        $sheet->getStyle("A" . ($row - 1) . ":B{$row}")->getFont()->setBold(true)->setColor(new Color(self::C_SECTION_TEXT));
        $sheet->getStyle("D" . ($row - 1) . ":D{$row}")->getFont()->setBold(true)->setColor(new Color(self::C_SECTION_TEXT));
        $row += 2;

        // STATISTIQUES GLOBALES
        $row = $this->addSectionTitle($sheet, $row, 'STATISTIQUES GLOBALES', 6);

        $global = $data['global'] ?? null;
        $total = $global->nombre_total ?? 0;
        $ouvertes = $global->ouvertes ?? 0;
        $cloturees = $global->cloturees ?? 0;
        $delai = $global->delai_moyen_heures ?? 0;
        $taux = $total > 0 ? round(($cloturees / $total) * 100) : 0;

        $this->writeKpiCard($sheet, "A{$row}", 'TOTAL SUGGESTIONS', $total, self::C_PRIMARY_BG, self::C_PRIMARY_TEXT);
        $this->writeKpiCard($sheet, "B{$row}", 'OUVERTES', $ouvertes, 'FEF3C7', '92400E');
        $this->writeKpiCard($sheet, "C{$row}", 'CLOTUREES', $cloturees, 'D1FAE5', '065F46');
        $this->writeKpiCard($sheet, "D{$row}", 'DELAI MOYEN', $delai . ' h', 'EDE9FE', '5B21B6');

        $sheet->getStyle("A{$row}:D{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension($row)->setRowHeight(22);
        $row++;
        $sheet->getStyle("A{$row}:D{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension($row)->setRowHeight(30);
        $row++;

        $this->writeKpiCard($sheet, "A{$row}", 'TAUX DE RESOLUTION', $taux . ' %', 'BBF7D0', '14532D');
        $sheet->mergeCells("B{$row}:D{$row}");
        $this->writeKpiCard($sheet, "B{$row}", 'SUGGESTIONS PRIORITAIRES (Critique + Haute)',
            ($data['par_priorite']['CRITIQUE'] ?? 0) + ($data['par_priorite']['HAUTE'] ?? 0),
            'FECDD3', '9F1239');
        $sheet->getStyle("A{$row}:D{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension($row)->setRowHeight(22);
        $row++;
        $sheet->getStyle("A{$row}:D{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension($row)->setRowHeight(30);
        $row += 2;

        // RÉPARTITION PAR PRIORITÉ
        $row = $this->addSectionTitle($sheet, $row, 'REPARTITION PAR PRIORITE', 4);
        $this->writeHeaders($sheet, $row, ['Priorité', 'Nombre', 'Pourcentage', 'Répartition']);
        $row++;

        $totalPriorites = array_sum($data['par_priorite'] ?? []);
        $prioriteStartRow = $row;

        $prioriteColors = [
            'CRITIQUE' => [self::C_CRITIQUE_BG, self::C_CRITIQUE_TXT],
            'HAUTE'    => [self::C_HAUTE_BG, self::C_HAUTE_TXT],
            'NORMALE'  => [self::C_NORMALE_BG, self::C_NORMALE_TXT],
            'BASSE'    => [self::C_BASSE_BG, self::C_BASSE_TXT],
        ];

        foreach (['CRITIQUE', 'HAUTE', 'NORMALE', 'BASSE'] as $prio) {
            $nombre = $data['par_priorite'][$prio] ?? 0;
            $pct = $totalPriorites > 0 ? round(($nombre / $totalPriorites) * 100, 1) : 0;

            $sheet->setCellValue("A{$row}", $prio);
            $sheet->setCellValue("B{$row}", $nombre);
            $sheet->setCellValue("C{$row}", $pct . '%');

            $barLength = (int) round($pct / 5);
            $sheet->setCellValue("D{$row}", str_repeat('|', $barLength));

            $sheet->getStyle("A{$row}")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => $prioriteColors[$prio][1]]],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $prioriteColors[$prio][0]]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
            $sheet->getStyle("B{$row}:C{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("D{$row}")->applyFromArray([
                'font' => ['color' => ['rgb' => $prioriteColors[$prio][1]], 'size' => 10],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
            ]);

            $row++;
        }
        $prioriteEndRow = $row - 1;

        $sheet->setCellValue("A{$row}", 'TOTAL');
        $sheet->setCellValue("B{$row}", $totalPriorites);
        $sheet->setCellValue("C{$row}", '100%');
        $sheet->getStyle("A{$row}:D{$row}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => self::C_PRIMARY_TEXT]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::C_PRIMARY_BG]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getStyle("A{$row}:D{$row}")->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM);
        $row += 2;

        // ÉVOLUTION MENSUELLE
        $row = $this->addSectionTitle($sheet, $row, 'EVOLUTION MENSUELLE', 6);
        $this->writeHeaders($sheet, $row, ['Mois', 'Nouvelles', 'En cours', 'Acceptées', 'Rejetées', 'Total']);
        $row++;

        $categorieStartRow = $row;
        $mois = ['Jan', 'Fév', 'Mars', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sept', 'Oct', 'Nov', 'Déc'];

        $parMois = $data['par_mois'] ?? $data['parMois'] ?? $data['evolution_mensuelle'] ?? [];
        if (is_object($parMois)) $parMois = json_decode(json_encode($parMois), true);

        $parMoisNorm = [];
        if (is_array($parMois)) {
            foreach ($parMois as $key => $item) {
                if (is_object($item)) $item = json_decode(json_encode($item), true);
                $moisNum = null;
                if (is_numeric($key) && $key >= 1 && $key <= 12) $moisNum = (int) $key;
                elseif (is_array($item) && isset($item['mois'])) $moisNum = (int) $item['mois'];
                elseif (is_array($item) && isset($item['month'])) $moisNum = (int) $item['month'];
                if ($moisNum !== null && $moisNum >= 1 && $moisNum <= 12) {
                    $parMoisNorm[$moisNum] = $item;
                }
            }
        }

        foreach ($mois as $index => $m) {
            $moisNum = $index + 1;
            $moisData = $parMoisNorm[$moisNum] ?? [];

            $nouvelles = 0; $enCours = 0; $resolues = 0; $rejetees = 0;

            if (is_array($moisData)) {
                $nouvelles = (int) ($moisData['nouvelles'] ?? 0);
                $enCours   = (int) ($moisData['en_cours'] ?? 0);
                $resolues  = (int) ($moisData['resolues'] ?? 0);
                $rejetees  = (int) ($moisData['rejetees'] ?? 0);
            }

            $totalMois = $nouvelles + $enCours + $resolues + $rejetees;

            $sheet->setCellValue("A{$row}", $m);
            $sheet->setCellValue("B{$row}", $nouvelles);
            $sheet->setCellValue("C{$row}", $enCours);
            $sheet->setCellValue("D{$row}", $resolues);
            $sheet->setCellValue("E{$row}", $rejetees);
            $sheet->setCellValue("F{$row}", $totalMois);

            $sheet->getStyle("A{$row}:F{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $sheet->getStyle("F{$row}")->getFont()->setBold(true)->setColor(new Color(self::C_PRIMARY_TEXT));
            $row++;
        }
        $categorieEndRow = $row - 1;
        $row += 2;

        // PERFORMANCE PAR ZONE
        $row = $this->addSectionTitle($sheet, $row, 'PERFORMANCE PAR ZONE', 4);
        $this->writeHeaders($sheet, $row, ['Zone', 'Total', 'Ouvertes', 'Clôturées']);
        $row++;

        $zoneStartRow = $row;
        foreach (($data['par_zone'] ?? []) as $z) {
            $sheet->setCellValue("A{$row}", $z->zone);
            $sheet->setCellValue("B{$row}", $z->nombre_total);
            $sheet->setCellValue("C{$row}", $z->ouvertes);
            $sheet->setCellValue("D{$row}", $z->cloturees);

            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $sheet->getStyle("A{$row}:D{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $row++;
        }
        $zoneEndRow = $row - 1;
        $row += 2;

        // PERFORMANCE PAR AGENT
        $row = $this->addSectionTitle($sheet, $row, 'PERFORMANCE PAR AGENT', 5);
        $this->writeHeaders($sheet, $row, ['Agent', 'Total', 'Ouvertes', 'Clôturées', 'Délai moyen']);
        $row++;

        foreach (($data['par_agent'] ?? []) as $a) {
            $sheet->setCellValue("A{$row}", $a->agent);
            $sheet->setCellValue("B{$row}", $a->nombre_total);
            $sheet->setCellValue("C{$row}", $a->ouvertes);
            $sheet->setCellValue("D{$row}", $a->cloturees);
            $sheet->setCellValue("E{$row}", $a->delai_moyen_heures ? $a->delai_moyen_heures . ' h' : '—');

            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $sheet->getStyle("A{$row}:E{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $row++;
        }
        $row += 2;

        // RÉPARTITION PAR PARTENAIRE
        $row = $this->addSectionTitle($sheet, $row, 'REPARTITION PAR PARTENAIRE', 4);
        $this->writeHeaders($sheet, $row, ['Partenaire', 'Total', 'Ouvertes', 'Clôturées']);
        $row++;

        foreach (($data['par_partenaire'] ?? []) as $p) {
            $sheet->setCellValue("A{$row}", $p->partenaire ?? '—');
            $sheet->setCellValue("B{$row}", $p->nombre_total);
            $sheet->setCellValue("C{$row}", $p->ouvertes);
            $sheet->setCellValue("D{$row}", $p->cloturees);

            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $sheet->getStyle("A{$row}:D{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $row++;
        }

        // GRAPHIQUES
        $this->ajouterGraphiquePriorite($sheet, $prioriteStartRow, $prioriteEndRow);
        $this->ajouterGraphiqueEvolutionCategorie($sheet, $categorieStartRow, $categorieEndRow);
        $this->ajouterGraphiqueZone($sheet, $zoneStartRow, $zoneEndRow);
    }

    // ============================================================
    // CARTE KPI
    // ============================================================
    private function writeKpiCard(Worksheet $sheet, string $cell, string $label, $value, string $bg, string $textColor): void
    {
        $col = preg_replace('/[0-9]/', '', $cell);
        $row = (int) preg_replace('/[A-Z]/', '', $cell);

        $sheet->setCellValue($cell, $label);
        $sheet->getStyle($cell)->applyFromArray([
            'font' => ['bold' => true, 'size' => 8, 'color' => ['rgb' => $textColor]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bg]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['outline' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => $textColor]]],
        ]);

        $valueCell = $col . ($row + 1);
        $sheet->setCellValue($valueCell, $value);
        $sheet->getStyle($valueCell)->applyFromArray([
            'font' => ['bold' => true, 'size' => 18, 'color' => ['rgb' => $textColor]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bg]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['outline' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => $textColor]]],
        ]);
    }

    // ================================================================
    // FEUILLE 2 : LISTE COMPLÈTE
    // ================================================================
    private function construireFeuilleListe(Worksheet $sheet, array $filtres): void
    {
        $sheet->getColumnDimension('A')->setWidth(18);
        $sheet->getColumnDimension('B')->setWidth(30);
        $sheet->getColumnDimension('C')->setWidth(45);
        $sheet->getColumnDimension('D')->setWidth(20);
        $sheet->getColumnDimension('E')->setWidth(14);
        $sheet->getColumnDimension('F')->setWidth(14);
        $sheet->getColumnDimension('G')->setWidth(14);
        $sheet->getColumnDimension('H')->setWidth(20);
        $sheet->getColumnDimension('I')->setWidth(18);

        $query = Suggestion::with(['client:id,nom_complet', 'utilisateur:id,nom_complet']);

        if (!empty($filtres['annee'])) $query->whereYear('created_at', (int) $filtres['annee']);
        if (!empty($filtres['zone'])) $query->where('zone', $filtres['zone']);

        $suggestions = $query->orderByDesc('created_at')->get();

        $row = 1;

        $sheet->mergeCells("A{$row}:I{$row}");
        $sheet->setCellValue("A{$row}", 'LISTE COMPLETE DES SUGGESTIONS');
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => self::C_PRIMARY_TEXT]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::C_PRIMARY_BG]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(36);
        $row += 2;

        $sheet->setCellValue("A{$row}", 'Total :');
        $sheet->setCellValue("B{$row}", $suggestions->count() . ' suggestion(s)');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setColor(new Color(self::C_SECTION_TEXT));
        $row += 2;

        $headers = ['Référence', 'Objet', 'Description', 'Client', 'Zone', 'Priorité', 'Statut', 'Agent', 'Date création'];

        $this->writeHeaders($sheet, $row, $headers);
        $headerRow = $row;
        $row++;

        foreach ($suggestions as $i => $s) {
            $sheet->setCellValue("A{$row}", $s->reference);
            $sheet->setCellValue("B{$row}", $s->objet);
            $sheet->setCellValue("C{$row}", $s->description ?? '');
            $sheet->setCellValue("D{$row}", $s->client?->nom_complet ?? '—');
            $sheet->setCellValue("E{$row}", $s->zone);
            $sheet->setCellValue("F{$row}", $s->priorite);
            $sheet->setCellValue("G{$row}", str_replace('_', ' ', $s->statut));
            $sheet->setCellValue("H{$row}", $s->utilisateur?->nom_complet ?? 'Non affectée');
            $sheet->setCellValue("I{$row}", $s->created_at?->format('d/m/Y H:i'));

            if ($i % 2 === 1) {
                $sheet->getStyle("A{$row}:I{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::C_ROW_ALT);
            }

            $sheet->getStyle("A{$row}:I{$row}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle("A{$row}:I{$row}")->getAlignment()->setIndent(1);
            $sheet->getRowDimension($row)->setRowHeight(20);

            $prioColors = [
                'CRITIQUE' => [self::C_CRITIQUE_BG, self::C_CRITIQUE_TXT],
                'HAUTE'    => [self::C_HAUTE_BG, self::C_HAUTE_TXT],
                'NORMALE'  => [self::C_NORMALE_BG, self::C_NORMALE_TXT],
                'BASSE'    => [self::C_BASSE_BG, self::C_BASSE_TXT],
            ];
            if (isset($prioColors[$s->priorite])) {
                $sheet->getStyle("F{$row}")->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => $prioColors[$s->priorite][1]]],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $prioColors[$s->priorite][0]]],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);
            }

            $statutColors = [
                'NOUVELLE' => [self::C_STATUT_NOUVELLE_BG, '1D4ED8'],
                'EN_COURS' => [self::C_STATUT_ENCours_BG, '92400E'],
                'RESOLUE'  => [self::C_STATUT_RESOLUE_BG, '065F46'],
                'REJETEE'  => [self::C_STATUT_REJETEE_BG, '991B1B'],
            ];
            if (isset($statutColors[$s->statut])) {
                $sheet->getStyle("G{$row}")->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => $statutColors[$s->statut][1]]],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $statutColors[$s->statut][0]]],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);
            }

            $row++;
        }

        $sheet->freezePane('A' . ($headerRow + 1));
        $sheet->setAutoFilter("A{$headerRow}:I" . ($row - 1));
    }

    // ================================================================
    // FEUILLE 3 : DÉTAILS COMPLETS
    // ================================================================
    private function construireFeuilleDetails(Worksheet $sheet, array $filtres): void
    {
        $widths = [
            'A' => 14, 'B' => 30, 'C' => 45, 'D' => 14, 'E' => 14, 'F' => 14,
            'G' => 14, 'H' => 35, 'I' => 14, 'J' => 14,
            'K' => 22, 'L' => 25, 'M' => 18, 'N' => 14,
            'O' => 22, 'P' => 25,
            'Q' => 18, 'R' => 18, 'S' => 18, 'T' => 14, 'U' => 30,
            'V' => 12, 'W' => 12,
        ];
        foreach ($widths as $col => $w) $sheet->getColumnDimension($col)->setWidth($w);

        $query = Suggestion::with(['client', 'utilisateur', 'piecesJointes', 'historiques']);

        if (!empty($filtres['annee'])) $query->whereYear('created_at', (int) $filtres['annee']);
        if (!empty($filtres['zone'])) $query->where('zone', $filtres['zone']);

        $suggestions = $query->orderByDesc('created_at')->get();

        $row = 1;

        $sheet->mergeCells("A{$row}:W{$row}");
        $sheet->setCellValue("A{$row}", 'DETAILS COMPLETS DES SUGGESTIONS');
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => self::C_PRIMARY_TEXT]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::C_PRIMARY_BG]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(36);
        $row += 2;

        $sheet->setCellValue("A{$row}", 'Total :');
        $sheet->setCellValue("B{$row}", $suggestions->count() . ' suggestion(s)');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setColor(new Color(self::C_SECTION_TEXT));
        $row += 2;

        $headers = [
            'Référence', 'Objet', 'Description', 'Catégorie', 'Priorité', 'Statut',
            'Zone', 'Adresse GPS', 'Latitude', 'Longitude',
            'Client', 'Email client', 'Téléphone client', 'Catégorie client',
            'Agent', 'Email agent',
            'Date création', 'Date affectation', 'Date résolution', 'Délai (h)',
            'Commentaire résolution', 'Pièces jointes', 'Historiques',
        ];

        $this->writeHeaders($sheet, $row, $headers);
        $headerRow = $row;
        $row++;

        foreach ($suggestions as $i => $s) {
            $sheet->setCellValue("A{$row}", $s->reference);
            $sheet->setCellValue("B{$row}", $s->objet);
            $sheet->setCellValue("C{$row}", $s->description ?? '');
            $sheet->setCellValue("D{$row}", $s->categorie ?? '—');
            $sheet->setCellValue("E{$row}", $s->priorite ?? '—');
            $sheet->setCellValue("F{$row}", str_replace('_', ' ', $s->statut ?? '—'));
            $sheet->setCellValue("G{$row}", $s->zone ?? '—');
            $sheet->setCellValue("H{$row}", $s->adresse_gps ?? '—');
            $sheet->setCellValue("I{$row}", $s->latitude ?? '—');
            $sheet->setCellValue("J{$row}", $s->longitude ?? '—');
            $sheet->setCellValue("K{$row}", $s->client?->nom_complet ?? '—');
            $sheet->setCellValue("L{$row}", $s->client?->email ?? '—');
            $sheet->setCellValue("M{$row}", $s->client?->telephone ?? '—');
            $sheet->setCellValue("N{$row}", $s->client?->categorie_client ?? '—');
            $sheet->setCellValue("O{$row}", $s->utilisateur?->nom_complet ?? 'Non affectée');
            $sheet->setCellValue("P{$row}", $s->utilisateur?->email ?? '—');
            $sheet->setCellValue("Q{$row}", $s->created_at?->format('d/m/Y H:i') ?? '—');
            $sheet->setCellValue("R{$row}", $s->date_affectation?->format('d/m/Y H:i') ?? '—');
            $sheet->setCellValue("S{$row}", $s->date_resolution?->format('d/m/Y H:i') ?? '—');
            $sheet->setCellValue("T{$row}", $s->delai_resolution_heures ?? '—');
            $sheet->setCellValue("U{$row}", $s->commentaire_resolution ?? '—');
            $sheet->setCellValue("V{$row}", $s->piecesJointes?->count() ?? 0);
            $sheet->setCellValue("W{$row}", $s->historiques?->count() ?? 0);

            if ($i % 2 === 1) {
                $sheet->getStyle("A{$row}:W{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::C_ROW_ALT);
            }

            $sheet->getStyle("A{$row}:W{$row}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle("A{$row}:W{$row}")->getAlignment()->setWrapText(true);
            $sheet->getStyle("A{$row}:W{$row}")->getAlignment()->setIndent(1);
            $sheet->getRowDimension($row)->setRowHeight(22);

            $prioColors = [
                'CRITIQUE' => [self::C_CRITIQUE_BG, self::C_CRITIQUE_TXT],
                'HAUTE'    => [self::C_HAUTE_BG, self::C_HAUTE_TXT],
                'NORMALE'  => [self::C_NORMALE_BG, self::C_NORMALE_TXT],
                'BASSE'    => [self::C_BASSE_BG, self::C_BASSE_TXT],
            ];
            if (isset($prioColors[$s->priorite])) {
                $sheet->getStyle("E{$row}")->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => $prioColors[$s->priorite][1]]],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $prioColors[$s->priorite][0]]],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);
            }

            $statutColors = [
                'NOUVELLE' => [self::C_STATUT_NOUVELLE_BG, '1D4ED8'],
                'EN_COURS' => [self::C_STATUT_ENCours_BG, '92400E'],
                'RESOLUE'  => [self::C_STATUT_RESOLUE_BG, '065F46'],
                'REJETEE'  => [self::C_STATUT_REJETEE_BG, '991B1B'],
            ];
            if (isset($statutColors[$s->statut])) {
                $sheet->getStyle("F{$row}")->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => $statutColors[$s->statut][1]]],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $statutColors[$s->statut][0]]],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);
            }

            $row++;
        }

        $sheet->freezePane('A' . ($headerRow + 1));
        $sheet->setAutoFilter("A{$headerRow}:W" . ($row - 1));
    }

    // ================================================================
    //  FEUILLE 4 : HISTORIQUE
    // ================================================================
    private function construireFeuilleHistorique(Worksheet $sheet, array $filtres): void
    {
        $widths = [
            'A' => 18, 'B' => 14, 'C' => 20, 'D' => 22, 'E' => 18,
            'F' => 35, 'G' => 14, 'H' => 14, 'I' => 35,
        ];
        foreach ($widths as $col => $w) $sheet->getColumnDimension($col)->setWidth($w);

        $query = Suggestion::with([
            'historiques.utilisateur:id,nom_complet',
            'historiques.ancienUtilisateur:id,nom_complet',
            'historiques.nouveauUtilisateur:id,nom_complet',
        ]);

        if (!empty($filtres['annee'])) $query->whereYear('created_at', (int) $filtres['annee']);
        if (!empty($filtres['zone'])) $query->where('zone', $filtres['zone']);

        $suggestions = $query->get();

        $historiques = [];
        foreach ($suggestions as $s) {
            foreach ($s->historiques as $h) {
                $historiques[] = [
                    'date' => $h->created_at,
                    'utilisateur' => $h->utilisateur?->nom_complet ?? 'Système',
                    'action' => $h->action,
                    'reference' => $s->reference,
                    'objet' => $s->objet,
                    'ancien_statut' => $h->ancien_statut ?? '—',
                    'nouveau_statut' => $h->nouveau_statut ?? '—',
                    'commentaire' => $h->commentaire ?? '—',
                ];
            }
        }

        usort($historiques, fn ($a, $b) => strtotime($b['date'] ?? 0) - strtotime($a['date'] ?? 0));

        $row = 1;

        $sheet->mergeCells("A{$row}:I{$row}");
        $sheet->setCellValue("A{$row}", 'HISTORIQUE DES ACTIONS');
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => self::C_PRIMARY_TEXT]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::C_PRIMARY_BG]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(36);
        $row += 2;

        $sheet->setCellValue("A{$row}", 'Total :');
        $sheet->setCellValue("B{$row}", count($historiques) . ' action(s)');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setColor(new Color(self::C_SECTION_TEXT));
        $row += 2;

        $headers = ['Date', 'Heure', 'Utilisateur', 'Action', 'Référence suggestion', 'Objet suggestion', 'Ancien statut', 'Nouveau statut', 'Commentaire'];
        $this->writeHeaders($sheet, $row, $headers);
        $headerRow = $row;
        $row++;

        $actionLabels = [
            'CREATION' => 'Création',
            'AFFECTATION' => 'Affectation',
            'CHANGEMENT_STATUT' => 'Changement statut',
            'COMMENTAIRE' => 'Commentaire',
            'RESOLUTION' => 'Résolution',
            'REJET' => 'Rejet',
        ];

        $actionColors = [
            'CREATION' => ['BBF7D0', '14532D'],
            'AFFECTATION' => ['EDE9FE', '5B21B6'],
            'CHANGEMENT_STATUT' => ['FEF3C7', '92400E'],
            'COMMENTAIRE' => ['DBEAFE', '1D4ED8'],
            'RESOLUTION' => ['D1FAE5', '065F46'],
            'REJET' => ['FEE2E2', '991B1B'],
        ];

        foreach ($historiques as $i => $h) {
            $date = $h['date'] ? new \DateTime($h['date']) : null;

            $sheet->setCellValue("A{$row}", $date ? $date->format('d/m/Y') : '—');
            $sheet->setCellValue("B{$row}", $date ? $date->format('H:i') : '—');
            $sheet->setCellValue("C{$row}", $h['utilisateur']);
            $sheet->setCellValue("D{$row}", $actionLabels[$h['action']] ?? $h['action']);
            $sheet->setCellValue("E{$row}", $h['reference']);
            $sheet->setCellValue("F{$row}", $h['objet']);
            $sheet->setCellValue("G{$row}", $h['ancien_statut']);
            $sheet->setCellValue("H{$row}", $h['nouveau_statut']);
            $sheet->setCellValue("I{$row}", $h['commentaire']);

            if ($i % 2 === 1) {
                $sheet->getStyle("A{$row}:I{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::C_ROW_ALT);
            }

            $sheet->getStyle("A{$row}:I{$row}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle("A{$row}:I{$row}")->getAlignment()->setWrapText(true);
            $sheet->getStyle("A{$row}:I{$row}")->getAlignment()->setIndent(1);
            $sheet->getRowDimension($row)->setRowHeight(22);

            if (isset($actionColors[$h['action']])) {
                $sheet->getStyle("D{$row}")->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => $actionColors[$h['action']][1]]],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $actionColors[$h['action']][0]]],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);
            }

            $row++;
        }

        $sheet->freezePane('A' . ($headerRow + 1));
        $sheet->setAutoFilter("A{$headerRow}:I" . ($row - 1));
    }

    // ============================================================
    // GRAPHIQUE 1 : Doughnut
    // ============================================================
    private function ajouterGraphiquePriorite(Worksheet $sheet, int $startRow, int $endRow): void
    {
        $pointCount = $endRow - $startRow + 1;

        $categories = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Rapport'!\$A\${$startRow}:\$A\${$endRow}", null, $pointCount)];
        $values = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'Rapport'!\$B\${$startRow}:\$B\${$endRow}", null, $pointCount)];

        $series = new DataSeries(DataSeries::TYPE_DOUGHNUTCHART, null, range(0, count($values) - 1), [], $categories, $values);
        $series->setPlotDirection(DataSeries::DIRECTION_COL);

        $plotArea = new PlotArea(null, [$series]);
        $legend = new Legend(Legend::POSITION_RIGHT, null, false);
        $title = $this->buildChartTitle('Répartition par priorité');

        $chart = new Chart('chart_priorite', $title, $legend, $plotArea);
        $chart->setTopLeftPosition('H2');
        $chart->setBottomRightPosition('P20');
        $sheet->addChart($chart);
    }

    // ============================================================
    // GRAPHIQUE 2 : Évolution mensuelle (Line LISSE)
    // ============================================================
    private function ajouterGraphiqueEvolutionCategorie(Worksheet $sheet, int $startRow, int $endRow): void
    {
        $pointCount = $endRow - $startRow + 1;

        $categories = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Rapport'!\$A\${$startRow}:\$A\${$endRow}", null, $pointCount)];

        $seriesLabels = [
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Rapport'!\$B\$" . ($startRow - 1), null, 1),
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Rapport'!\$C\$" . ($startRow - 1), null, 1),
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Rapport'!\$D\$" . ($startRow - 1), null, 1),
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Rapport'!\$E\$" . ($startRow - 1), null, 1),
        ];

        $values = [
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'Rapport'!\$B\${$startRow}:\$B\${$endRow}", null, $pointCount),
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'Rapport'!\$C\${$startRow}:\$C\${$endRow}", null, $pointCount),
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'Rapport'!\$D\${$startRow}:\$D\${$endRow}", null, $pointCount),
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'Rapport'!\$E\${$startRow}:\$E\${$endRow}", null, $pointCount),
        ];

        $series = new DataSeries(DataSeries::TYPE_LINECHART, DataSeries::GROUPING_STANDARD, range(0, count($values) - 1), $seriesLabels, $categories, $values);
        $series->setSmoothLine(true);

        $plotArea = new PlotArea(null, [$series]);
        $legend = new Legend(Legend::POSITION_BOTTOM, null, false);
        $title = $this->buildChartTitle('Évolution mensuelle');

        $chart = new Chart('chart_evolution', $title, $legend, $plotArea);
        $chart->setTopLeftPosition('H22');
        $chart->setBottomRightPosition('P40');
        $sheet->addChart($chart);
    }

    // ============================================================
    // GRAPHIQUE 3 : Bar
    // ============================================================
    private function ajouterGraphiqueZone(Worksheet $sheet, int $startRow, int $endRow): void
    {
        $pointCount = $endRow - $startRow + 1;

        $categories = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Rapport'!\$A\${$startRow}:\$A\${$endRow}", null, $pointCount)];

        $values = [
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'Rapport'!\$B\${$startRow}:\$B\${$endRow}", null, $pointCount),
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'Rapport'!\$D\${$startRow}:\$D\${$endRow}", null, $pointCount),
        ];

        $seriesLabels = [
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Rapport'!\$B\$" . ($startRow - 1), null, 1),
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Rapport'!\$D\$" . ($startRow - 1), null, 1),
        ];

        $series = new DataSeries(DataSeries::TYPE_BARCHART, DataSeries::GROUPING_CLUSTERED, range(0, count($values) - 1), $seriesLabels, $categories, $values);
        $series->setPlotDirection(DataSeries::DIRECTION_COL);

        $plotArea = new PlotArea(null, [$series]);
        $legend = new Legend(Legend::POSITION_BOTTOM, null, false);
        $title = $this->buildChartTitle('Performance par zone');

        $chart = new Chart('chart_zone', $title, $legend, $plotArea);
        $chart->setTopLeftPosition('H42');
        $chart->setBottomRightPosition('P60');
        $sheet->addChart($chart);
    }

    // ============================================================
    // TITRE DE GRAPHIQUE
    // ============================================================
    private function buildChartTitle(string $text): Title
    {
        $title = new Title($text);
        $font = new Font();
        $font->setSize(12)->setBold(true)->setColor(new Color(self::C_SECTION_TEXT));
        $title->setFont($font);
        return $title;
    }

    // ============================================================
    // HELPERS
    // ============================================================
    private function addSectionTitle($sheet, int $row, string $title, int $nbCols): int
    {
        $lastCol = chr(64 + $nbCols);
        $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
        $sheet->setCellValue("A{$row}", $title);
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => self::C_SECTION_TEXT]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::C_SECTION_BG]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1],
        ]);
        $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getBorders()->getBottom()
            ->setBorderStyle(Border::BORDER_MEDIUM)->setColor(new Color(self::C_ACCENT));
        $sheet->getRowDimension($row)->setRowHeight(26);
        return $row + 1;
    }

    private function writeHeaders($sheet, int $row, array $headers): void
    {
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue("{$col}{$row}", $header);
            $col++;
        }
        $lastCol = chr(64 + count($headers));
        $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => self::C_HEADER_TEXT]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::C_HEADER_TABLE]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::C_BORDER]]],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(22);
    }

    private function styleHeaderRow($sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => self::C_HEADER_TEXT]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::C_HEADER_TABLE]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::C_BORDER]]],
        ]);
    }
} 