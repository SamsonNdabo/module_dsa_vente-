<?php

namespace App\Http\Controllers\Comptabilite;

use App\Models\Resultat;
use App\Models\Bilan;
use App\Models\EcrituresComptable;
use App\Models\Entite;
use App\Models\Succursale;
use App\Models\PeriodesComptable;
use App\Services\BilanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Http\Controllers\Controller;

class ResultatController extends Controller
{
    const COMPTE_RESULTAT_BENEFICE = '131';
    const COMPTE_RESULTAT_PERTE     = '139';
    const COMPTE_REPORT_CREDITEUR  = '121';
    const COMPTE_REPORT_DEBITEUR   = '129';
    const COMPTE_CAPITAL           = '10';

    protected BilanService $bilanService;

    public function __construct(BilanService $bilanService)
    {
        $this->bilanService = $bilanService;
    }

    // =========================================================================
    // PAGE PRINCIPALE
    // =========================================================================
    public function index()
    {
        $entites = Entite::where('is_active', 1)->orderBy('code', 'asc')->get();
        $periodes = PeriodesComptable::orderBy('date_debut', 'desc')->get();
        $periodeEnCours = PeriodesComptable::where('is_cloture', false)
            ->orderBy('date_debut', 'asc')
            ->first();

        return view('sections.resultat', compact('entites', 'periodes', 'periodeEnCours'));
    }

    // =========================================================================
    // 🔹 CALCULER
    // =========================================================================
    public function calculer(Request $request)
    {
        $request->validate([
            'entite_id'     => 'nullable|integer|exists:entites,id',
            'succursale_id' => 'nullable|integer|exists:succursales,id',
            'periode_id'    => 'nullable',
            'date_debut'    => 'nullable|date',
            'date_fin'      => 'nullable|date|after_or_equal:date_debut',
        ]);

        try {
            $entiteId     = $request->filled('entite_id') ? (int)$request->entite_id : null;
            $succursaleId = $request->filled('succursale_id') ? (int)$request->succursale_id : null;

            $periodeRaw = $request->periode_id;
            $dateDebut  = $request->date_debut;
            $dateFin    = $request->date_fin;

            if (empty($periodeRaw) && ($dateDebut || $dateFin)) {
                $periodeId = null;
            } elseif ($periodeRaw === 'en-cours' || empty($periodeRaw)) {
                $periodeEnCours = PeriodesComptable::where('is_cloture', false)
                    ->orderBy('date_debut', 'asc')
                    ->first();
                $periodeId = $periodeEnCours ? (int)$periodeEnCours->id : null;
            } else {
                $periodeId = (int)$periodeRaw;
            }

            $periode = $periodeId ? PeriodesComptable::find($periodeId) : null;

            $bilanData = $this->bilanService->calculerBilanFiltre([
                'periode_id'    => $periodeId,
                'entite_id'     => $entiteId,
                'succursale_id' => $succursaleId,
                'date_debut'    => $dateDebut,
                'date_fin'      => $dateFin,
                'filter_type'   => 'all',
            ]);

            $entite     = $entiteId ? Entite::find($entiteId) : null;
            $succursale = $succursaleId ? Succursale::find($succursaleId) : null;

            $nbEcritures = EcrituresComptable::where('periode_id', $periodeId)
                ->when($entiteId, fn($q) => $q->where('entite_id', $entiteId))
                ->when($succursaleId, fn($q) => $q->where('succursale_id', $succursaleId))
                ->count();

            $totalActif    = $bilanData['actif']['solde']    ?? 0;
            $totalPassif   = $bilanData['passif']['solde']   ?? 0;
            $totalCharges  = $bilanData['charges']['solde']  ?? 0;
            $totalProduits = $bilanData['produits']['solde'] ?? 0;
            $resultatNet   = $bilanData['resultat_net']      ?? 0;

            $ecartNet  = $totalActif - $totalPassif;
            $equilibre = abs($ecartNet) < 0.01;

            //  Infos sur le résultat (131 / 139)
            $estBenefice     = $resultatNet >= 0;
            $compteResultat  = $estBenefice ? self::COMPTE_RESULTAT_BENEFICE : self::COMPTE_RESULTAT_PERTE;
            $libelleResultat = $estBenefice ? 'RÉSULTAT NET : BÉNÉFICE' : 'RÉSULTAT NET : PERTE';
            $montantResultat = abs($resultatNet);

            $data = array_merge($bilanData, [
                'entite' => $entite
                    ? ['id' => $entite->id, 'code' => $entite->code, 'nom' => $entite->nom]
                    : ['id' => 0, 'code' => 'GLOBAL', 'nom' => 'Toutes'],

                'succursale' => $succursale
                    ? ['id' => $succursale->id, 'code' => $succursale->code, 'nom' => $succursale->nom]
                    : null,

                'date_debut' => $dateDebut
                    ?? ($periode ? $periode->date_debut->format('Y-m-d') : date('Y-01-01')),

                'date_fin' => $dateFin
                    ?? ($periode ? $periode->date_fin->format('Y-m-d') : date('Y-m-d')),

                'nb_ecritures' => $nbEcritures,

                'total_actif'    => $totalActif,
                'total_passif'   => $totalPassif,
                'total_charges'  => $totalCharges,
                'total_produits' => $totalProduits,
                'ecart_net'      => $ecartNet,
                'equilibre'      => $equilibre,

                //  Infos résultat 131/139
                'est_benefice'     => $estBenefice,
                'compte_resultat'  => $compteResultat,
                'libelle_resultat' => $libelleResultat,
                'montant_resultat' => $montantResultat,

                'formule_ecart' =>
                    $this->formatMoney($totalActif) . ' - ' . $this->formatMoney($totalPassif)
                    . ' = ' . $this->formatMoney($ecartNet),

                'formule_resultat' =>
                    $this->formatMoney($totalProduits) . ' - ' . $this->formatMoney($totalCharges)
                    . ' = ' . $this->formatMoney($resultatNet),

                'periode_info' => $periode ? [
                    'id'         => $periode->id,
                    'code'       => $periode->code,
                    'libelle'    => $periode->libelle,
                    'date_debut' => $periode->date_debut instanceof Carbon
                        ? $periode->date_debut->format('d/m/Y')
                        : $periode->date_debut,
                    'date_fin'   => $periode->date_fin instanceof Carbon
                        ? $periode->date_fin->format('d/m/Y')
                        : $periode->date_fin,
                    'is_cloture' => (bool) $periode->is_cloture,
                ] : null,
            ]);

            return response()->json([
                'success' => true,
                'data'    => $data,
            ], 200, ['Content-Type' => 'application/json; charset=UTF-8'], JSON_UNESCAPED_UNICODE);

        } catch (\Exception $e) {
            Log::error('Erreur calcul résultat', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur : ' . $e->getMessage(),
            ], 500);
        }
    }

    // Helper format
    private function formatMoney($v): string
    {
        return number_format((float)$v, 2, ',', ' ') . ' $';
    }

    // =========================================================================
    // 🔹 VÉRIFIER RÉSULTAT CLÔTURE
    // =========================================================================
    public function verifierResultatCloture(Request $request)
    {
        $request->validate([
            'periode_id' => 'required|exists:periodes_comptables,id',
            'entite_id'  => 'nullable|exists:entites,id',
        ]);

        $periodeId = (int)$request->periode_id;
        $entiteId  = $request->filled('entite_id') ? (int)$request->entite_id : null;

        $bilanData = $this->bilanService->calculerBilanFiltre([
            'periode_id'  => $periodeId,
            'entite_id'   => $entiteId,
            'filter_type' => 'all',
        ]);

        $resultat = $bilanData['resultat_net'] ?? 0;

        $message = $resultat >= 0
            ? " Bénéfice de " . number_format($resultat, 2) . " $"
            : " Perte de " . number_format(abs($resultat), 2) . " $";

        return response()->json([
            'success'       => true,
            'peut_cloturer' => true,
            'resultat'      => round($resultat, 4),
            'est_benefice'  => $resultat >= 0,
            'montant'       => round(abs($resultat), 4),
            'message'       => $message,
        ], 200, ['Content-Type' => 'application/json; charset=UTF-8'], JSON_UNESCAPED_UNICODE);
    }

    // =========================================================================
    // 🔹 ENREGISTRER RÉSULTAT (statique)
    // =========================================================================
    public static function enregistrerResultat(int $periodeId, ?int $entiteId = null, ?int $succursaleId = null, array $data = []): void
    {
        Resultat::where('periode_id', $periodeId)
            ->where('entite_id', $entiteId)
            ->where('is_latest', true)
            ->update(['is_latest' => false]);

        Resultat::create([
            'periode_id'      => $periodeId,
            'entite_id'       => $entiteId,
            'succursale_id'   => $succursaleId,
            'total_actif'     => $data['actif']['solde'] ?? 0,
            'total_passif'    => $data['passif']['solde'] ?? 0,
            'total_charges'   => $data['charges']['solde'] ?? 0,
            'total_produits'  => $data['produits']['solde'] ?? 0,
            'resultat_net'    => $data['resultat_net'] ?? 0,
            'type_resultat'   => ($data['resultat_net'] ?? 0) >= 0 ? 'BÉNÉFICE' : 'PERTE',
            'actif_detail'    => $data['actif']['detail'] ?? [],
            'passif_detail'   => $data['passif']['detail'] ?? [],
            'charges_detail'  => $data['charges']['detail'] ?? [],
            'produits_detail' => $data['produits']['detail'] ?? [],
            'is_latest'       => true,
            'calculated_at'   => now(),
        ]);
    }

    // =========================================================================
    // 🔹 EXPORT
    // =========================================================================
    public function export(Request $request)
    {
        try {
            $entiteId     = $request->filled('entite_id') ? (int)$request->entite_id : null;
            $succursaleId = $request->filled('succursale_id') ? (int)$request->succursale_id : null;

            $periodeRaw = $request->periode_id;
            $dateDebut  = $request->date_debut;
            $dateFin    = $request->date_fin;

            if (empty($periodeRaw) && ($dateDebut || $dateFin)) {
                $periodeId = null;
            } elseif ($periodeRaw === 'en-cours' || empty($periodeRaw)) {
                $periodeEnCours = PeriodesComptable::where('is_cloture', false)
                    ->orderBy('date_debut', 'asc')
                    ->first();
                $periodeId = $periodeEnCours ? (int)$periodeEnCours->id : null;
            } else {
                $periodeId = (int)$periodeRaw;
            }

            $periode     = $periodeId ? PeriodesComptable::find($periodeId) : null;
            $entite      = $entiteId ? Entite::find($entiteId) : null;
            $succursale  = $succursaleId ? Succursale::find($succursaleId) : null;

            $bilanData = $this->bilanService->calculerBilanFiltre([
                'periode_id'    => $periodeId,
                'entite_id'     => $entiteId,
                'succursale_id' => $succursaleId,
                'date_debut'    => $dateDebut,
                'date_fin'      => $dateFin,
                'filter_type'   => 'all',
            ]);

            $data = array_merge($bilanData, [
                'entite' => $entite
                    ? ['id' => $entite->id, 'code' => $entite->code, 'nom' => $entite->nom]
                    : ['id' => 0, 'code' => 'GLOBAL', 'nom' => 'Toutes'],

                'succursale' => $succursale
                    ? ['id' => $succursale->id, 'code' => $succursale->code, 'nom' => $succursale->nom]
                    : null,

                'date_debut' => $dateDebut
                    ?? ($periode ? $periode->date_debut->format('Y-m-d') : date('Y-01-01')),

                'date_fin' => $dateFin
                    ?? ($periode ? $periode->date_fin->format('Y-m-d') : date('Y-m-d')),
            ]);

            if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
                return response()->json([
                    'success' => false,
                    'message' => 'PhpSpreadsheet non installé. Exécutez : composer require phpoffice/phpspreadsheet',
                ], 500);
            }

            return $this->exportResultatExcel($data);

        } catch (\Exception $e) {
            Log::error('Export Resultat error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur export: ' . $e->getMessage(),
            ], 500);
        }
    }

    // =========================================================================
    //  EXPORT EXCEL — 2 FEUILLES — MISE EN PAGE COMPTABLE
    //    Feuille 1 Bilan : ACTIF (gauche) | PASSIF (droite) + Ligne 131/139
    //    Feuille 2 CR    : CHARGES (gauche) | PRODUITS (droite)
    // =========================================================================
    private function exportResultatExcel($data)
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

        $entiteNom = ($data['entite']['id'] ?? 0) > 0
            ? $data['entite']['code'] . ' - ' . $data['entite']['nom']
            : 'Toutes les entités';

        //  Résultat net + compte
        $resultatNet     = $data['resultat_net'] ?? 0;
        $estBenefice     = $resultatNet >= 0;
        $compteResultat  = $estBenefice ? self::COMPTE_RESULTAT_BENEFICE : self::COMPTE_RESULTAT_PERTE;
        $libelleResultat = $estBenefice ? 'RÉSULTAT NET : BÉNÉFICE' : 'RÉSULTAT NET : PERTE';
        $montantResultat = abs($resultatNet);

        // =====================================================================
        // FEUILLE 1 : BILAN — ACTIF (gauche) | PASSIF (droite)
        // =====================================================================
        $sheetBilan = $spreadsheet->getActiveSheet();
        $sheetBilan->setTitle('Bilan');

        // Titre principal (spans A:K) — BLEU FONCÉ
        $sheetBilan->setCellValue('A1', '📊 BILAN COMPTABLE')->mergeCells('A1:K1');
        $sheetBilan->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'FFFFFF']],
            'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
            'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => '1E3A8A']],
        ]);
        $sheetBilan->getRowDimension(1)->setRowHeight(30);

        // Infos
        $row = 3;
        $sheetBilan->setCellValue("A{$row}", 'Entité :')->setCellValue("B{$row}", $entiteNom);
        $sheetBilan->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;

        if (!empty($data['succursale'])) {
            $sheetBilan->setCellValue("A{$row}", 'Succursale :')
                ->setCellValue("B{$row}", $data['succursale']['nom'] ?? '');
            $sheetBilan->getStyle("A{$row}")->getFont()->setBold(true);
            $row++;
        }

        if (!empty($data['periode_info'])) {
            $sheetBilan->setCellValue("A{$row}", 'Exercice :')
                ->setCellValue("B{$row}", ($data['periode_info']['code'] ?? '') . ' - ' . ($data['periode_info']['libelle'] ?? ''));
            $sheetBilan->getStyle("A{$row}")->getFont()->setBold(true);
            $row++;
        }

        $sheetBilan->setCellValue("A{$row}", 'Du :')->setCellValue("B{$row}", $data['date_debut'] ?? '');
        $sheetBilan->setCellValue("D{$row}", 'Au :')->setCellValue("E{$row}", $data['date_fin'] ?? '');
        $sheetBilan->getStyle("A{$row}:E{$row}")->getFont()->setItalic(true);
        $row += 2;

        // === EN-TÊTES ACTIF (gauche A:E) + PASSIF (droite G:K) ===
        // ACTIF — BLEU CLAIR (couleur d'origine)
        $sheetBilan->setCellValue("A{$row}", 'ACTIF')->mergeCells("A{$row}:E{$row}");
        $sheetBilan->getStyle("A{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'color' => ['rgb' => '000000']],
            'alignment' => ['horizontal' => 'center'],
            'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => 'DBEAFE']],
        ]);

        // PASSIF — JAUNE CLAIR (couleur d'origine)
        $sheetBilan->setCellValue("G{$row}", 'PASSIF')->mergeCells("G{$row}:K{$row}");
        $sheetBilan->getStyle("G{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'color' => ['rgb' => '000000']],
            'alignment' => ['horizontal' => 'center'],
            'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => 'FEF3C7']],
        ]);
        $sheetBilan->getRowDimension($row)->setRowHeight(25);
        $row++;

        // En-têtes colonnes (mêmes pour les 2 côtés)
        $headers = ['N°', 'Libellé', 'Débit', 'Crédit', 'Solde'];
        foreach ($headers as $i => $h) {
            $sheetBilan->setCellValue(chr(65 + $i) . $row, $h);
            $sheetBilan->setCellValue(chr(71 + $i) . $row, $h);
        }
        $sheetBilan->getStyle("A{$row}:E{$row}")->applyFromArray([
            'font'      => ['bold' => true],
            'alignment' => ['horizontal' => 'center'],
            'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => 'F3F4F6']],
            'borders'   => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => '000000']]],
        ]);
        $sheetBilan->getStyle("G{$row}:K{$row}")->applyFromArray([
            'font'      => ['bold' => true],
            'alignment' => ['horizontal' => 'center'],
            'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => 'F3F4F6']],
            'borders'   => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => '000000']]],
        ]);
        $row++;

        // === PRÉPARATION LIGNES ===
        $actifs  = $data['actif']['detail']  ?? [];
        $passifs = $data['passif']['detail'] ?? [];

        //  AJOUTER LA LIGNE 131/139 DANS LE BON CÔTÉ
        //    Bénéfice → 131 au PASSIF
        //    Perte    → 139 à l'ACTIF
        if (abs($resultatNet) > 0.01) {
            $ligneResultat = [
                'numero'       => $compteResultat,
                'nom'          => $libelleResultat,
                'total_debit'  => $estBenefice ? 0 : $montantResultat,
                'total_credit' => $estBenefice ? $montantResultat : 0,
                'solde_net'    => $estBenefice ? $montantResultat : -$montantResultat,
            ];

            if ($estBenefice) {
                $passifs[] = $ligneResultat;   // Bénéfice → PASSIF
            } else {
                $actifs[] = $ligneResultat;    // Perte → ACTIF
            }
        }

        $maxLignes = max(count($actifs), count($passifs));

        for ($i = 0; $i < $maxLignes; $i++) {
            // ACTIF (gauche)
            if (isset($actifs[$i])) {
                $l = $actifs[$i];
                $isResultat = isset($l['numero']) && in_array($l['numero'], ['131', '139']);

                $sheetBilan->setCellValue("A{$row}", $l['numero'] ?? '');
                $sheetBilan->setCellValue("B{$row}", $l['nom'] ?? '');
                $sheetBilan->setCellValue("C{$row}", $l['total_debit'] ?? 0);
                $sheetBilan->setCellValue("D{$row}", $l['total_credit'] ?? 0);
                $sheetBilan->setCellValue("E{$row}", $l['solde_net'] ?? 0);

                $sheetBilan->getStyle("A{$row}:E{$row}")->applyFromArray([
                    'borders' => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => 'DDDDDD']]],
                ]);
                $sheetBilan->getStyle("A{$row}")->getAlignment()->setHorizontal('center');
                $sheetBilan->getStyle("E{$row}")->getFont()->setBold(true);

                //  Surligner la ligne 131/139 (vert si bénéfice, rouge si perte)
                if ($isResultat) {
                    $sheetBilan->getStyle("A{$row}:E{$row}")->applyFromArray([
                        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => $estBenefice ? 'D1FAE5' : 'FEE2E2']],
                        'font' => ['italic' => true, 'bold' => true],
                    ]);
                }

                if (($l['solde_net'] ?? 0) < 0) {
                    $sheetBilan->getStyle("E{$row}")->getFont()->getColor()->setRGB('DC2626');
                }
            }

            // PASSIF (droite)
            if (isset($passifs[$i])) {
                $l = $passifs[$i];
                $isResultat = isset($l['numero']) && in_array($l['numero'], ['131', '139']);

                $sheetBilan->setCellValue("G{$row}", $l['numero'] ?? '');
                $sheetBilan->setCellValue("H{$row}", $l['nom'] ?? '');
                $sheetBilan->setCellValue("I{$row}", $l['total_debit'] ?? 0);
                $sheetBilan->setCellValue("J{$row}", $l['total_credit'] ?? 0);
                $sheetBilan->setCellValue("K{$row}", $l['solde_net'] ?? 0);

                $sheetBilan->getStyle("G{$row}:K{$row}")->applyFromArray([
                    'borders' => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => 'DDDDDD']]],
                ]);
                $sheetBilan->getStyle("G{$row}")->getAlignment()->setHorizontal('center');
                $sheetBilan->getStyle("K{$row}")->getFont()->setBold(true);

                //  Surligner la ligne 131/139
                if ($isResultat) {
                    $sheetBilan->getStyle("G{$row}:K{$row}")->applyFromArray([
                        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => $estBenefice ? 'D1FAE5' : 'FEE2E2']],
                        'font' => ['italic' => true, 'bold' => true],
                    ]);
                }

                if (($l['solde_net'] ?? 0) < 0) {
                    $sheetBilan->getStyle("K{$row}")->getFont()->getColor()->setRGB('DC2626');
                }
            }

            $sheetBilan->getRowDimension($row)->setRowHeight(18);
            $row++;
        }

        // === TOTAUX ACTIF + PASSIF (avec 131/139 inclus) ===
        $totalActifEquilibre  = ($data['actif']['solde']  ?? 0) + (!$estBenefice ? $montantResultat : 0);
        $totalPassifEquilibre = ($data['passif']['solde'] ?? 0) + ($estBenefice ? $montantResultat : 0);

        $sheetBilan->setCellValue("D{$row}", 'TOTAL ACTIF');
        $sheetBilan->setCellValue("E{$row}", $totalActifEquilibre);

        $sheetBilan->setCellValue("J{$row}", 'TOTAL PASSIF');
        $sheetBilan->setCellValue("K{$row}", $totalPassifEquilibre);

        // Totaux — COULEURS D'ORIGINE (bleu pour ACTIF, jaune pour PASSIF)
        $sheetBilan->getStyle("A{$row}:E{$row}")->applyFromArray([
            'font'    => ['bold' => true, 'size' => 12, 'color' => ['rgb' => '1E40AF']],
            'fill'    => ['fillType' => 'solid', 'startColor' => ['rgb' => 'DBEAFE']],
            'borders' => [
                'top'    => ['borderStyle' => 'medium', 'color' => ['rgb' => '1E40AF']],
                'bottom' => ['borderStyle' => 'medium', 'color' => ['rgb' => '1E40AF']],
            ],
        ]);
        $sheetBilan->getStyle("G{$row}:K{$row}")->applyFromArray([
            'font'    => ['bold' => true, 'size' => 12, 'color' => ['rgb' => '92400E']],
            'fill'    => ['fillType' => 'solid', 'startColor' => ['rgb' => 'FEF3C7']],
            'borders' => [
                'top'    => ['borderStyle' => 'medium', 'color' => ['rgb' => '92400E']],
                'bottom' => ['borderStyle' => 'medium', 'color' => ['rgb' => '92400E']],
            ],
        ]);
        $sheetBilan->getStyle("D{$row}:E{$row}")->getAlignment()->setHorizontal('right');
        $sheetBilan->getStyle("J{$row}:K{$row}")->getAlignment()->setHorizontal('right');
        $sheetBilan->getRowDimension($row)->setRowHeight(24);
        $row++;

        //  Ligne explicative du compte 131/139
        if (abs($resultatNet) > 0.01) {
            $sheetBilan->setCellValue("A{$row}",
                $estBenefice
                    ? "💡 Le compte 131 (Bénéfice) vient du Compte de Résultat : Produits (" . number_format($data['produits']['solde'] ?? 0, 2, ',', ' ') . " $) - Charges (" . number_format($data['charges']['solde'] ?? 0, 2, ',', ' ') . " $)"
                    : "💡 Le compte 139 (Perte) vient du Compte de Résultat : Charges (" . number_format($data['charges']['solde'] ?? 0, 2, ',', ' ') . " $) - Produits (" . number_format($data['produits']['solde'] ?? 0, 2, ',', ' ') . " $)"
            )->mergeCells("A{$row}:K{$row}");
            $sheetBilan->getStyle("A{$row}")->applyFromArray([
                'font'      => ['italic' => true, 'size' => 11, 'color' => ['rgb' => $estBenefice ? '065F46' : '991B1B']],
                'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
                'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => $estBenefice ? 'ECFDF5' : 'FEF2F2']],
                'borders'   => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => $estBenefice ? '059669' : 'DC2626']]],
            ]);
            $sheetBilan->getRowDimension($row)->setRowHeight(24);
            $row++;
        }

        $row++;

        // === VÉRIFICATION ÉCART NET ===
        $ecartNet = $totalActifEquilibre - $totalPassifEquilibre;
        $equilibre = abs($ecartNet) < 0.01;

        $sheetBilan->setCellValue("A{$row}", 'VÉRIFICATION')->mergeCells("A{$row}:K{$row}");
        $sheetBilan->getStyle("A{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 12],
            'alignment' => ['horizontal' => 'center'],
            'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => 'E5E7EB']],
        ]);
        $row++;

        $sheetBilan->setCellValue("A{$row}",
            'Écart Net = Total Actif (' . number_format($totalActifEquilibre, 2, ',', ' ') . ' $) - Total Passif (' . number_format($totalPassifEquilibre, 2, ',', ' ') . ' $)'
        )->mergeCells("A{$row}:K{$row}");
        $sheetBilan->getStyle("A{$row}")->getFont()->setItalic(true);
        $row++;

        $sheetBilan->setCellValue("A{$row}", number_format($totalActifEquilibre, 2, ',', ' ') . ' $')
            ->setCellValue("C{$row}", '-')
            ->setCellValue("E{$row}", number_format($totalPassifEquilibre, 2, ',', ' ') . ' $')
            ->setCellValue("G{$row}", '=')
            ->setCellValue("I{$row}", number_format($ecartNet, 2, ',', ' ') . ' $')
            ->mergeCells("A{$row}:B{$row}")
            ->mergeCells("E{$row}:F{$row}")
            ->mergeCells("I{$row}:K{$row}");

        $sheetBilan->getStyle("A{$row}:K{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 12],
            'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
            'borders'   => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => '000000']]],
            'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => 'F9FAFB']],
        ]);
        $sheetBilan->getRowDimension($row)->setRowHeight(24);
        $row++;

        $sheetBilan->setCellValue("A{$row}",
            $equilibre
                ? ' BILAN ÉQUILIBRÉ'
                : '⚠️ DÉSÉQUILIBRE DE ' . number_format(abs($ecartNet), 2, ',', ' ') . ' $'
        )->mergeCells("A{$row}:K{$row}");
        $sheetBilan->getStyle("A{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 14, 'color' => ['rgb' => $equilibre ? '065F46' : '991B1B']],
            'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
            'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => $equilibre ? 'D1FAE5' : 'FEE2E2']],
            'borders'   => ['allBorders' => ['borderStyle' => 'medium', 'color' => ['rgb' => $equilibre ? '059669' : 'DC2626']]],
        ]);
        $sheetBilan->getRowDimension($row)->setRowHeight(30);

        // Largeurs des colonnes
        foreach ([
            'A' => 12, 'B' => 40, 'C' => 15, 'D' => 15, 'E' => 15,
            'F' => 3,
            'G' => 12, 'H' => 40, 'I' => 15, 'J' => 15, 'K' => 15,
        ] as $c => $w) {
            $sheetBilan->getColumnDimension($c)->setWidth($w);
        }
        $sheetBilan->getStyle("C5:E{$row}")->getNumberFormat()->setFormatCode('#,##0.00" $"');
        $sheetBilan->getStyle("I5:K{$row}")->getNumberFormat()->setFormatCode('#,##0.00" $"');

        // =====================================================================
        // FEUILLE 2 : COMPTE DE RÉSULTAT — CHARGES (gauche) | PRODUITS (droite)
        // =====================================================================
        $sheetCR = $spreadsheet->createSheet();
        $sheetCR->setTitle('Compte de Résultat');

        $sheetCR->setCellValue('A1', '📊 COMPTE DE RÉSULTAT')->mergeCells('A1:K1');
        $sheetCR->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'FFFFFF']],
            'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
            'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => '1E3A8A']],
        ]);
        $sheetCR->getRowDimension(1)->setRowHeight(30);

        $rowCR = 3;
        $sheetCR->setCellValue("A{$rowCR}", 'Entité :')->setCellValue("B{$rowCR}", $entiteNom);
        $sheetCR->getStyle("A{$rowCR}")->getFont()->setBold(true);
        $rowCR++;

        if (!empty($data['succursale'])) {
            $sheetCR->setCellValue("A{$rowCR}", 'Succursale :')
                ->setCellValue("B{$rowCR}", $data['succursale']['nom'] ?? '');
            $sheetCR->getStyle("A{$rowCR}")->getFont()->setBold(true);
            $rowCR++;
        }

        if (!empty($data['periode_info'])) {
            $sheetCR->setCellValue("A{$rowCR}", 'Exercice :')
                ->setCellValue("B{$rowCR}", ($data['periode_info']['code'] ?? '') . ' - ' . ($data['periode_info']['libelle'] ?? ''));
            $sheetCR->getStyle("A{$rowCR}")->getFont()->setBold(true);
            $rowCR++;
        }

        $sheetCR->setCellValue("A{$rowCR}", 'Du :')->setCellValue("B{$rowCR}", $data['date_debut'] ?? '');
        $sheetCR->setCellValue("D{$rowCR}", 'Au :')->setCellValue("E{$rowCR}", $data['date_fin'] ?? '');
        $sheetCR->getStyle("A{$rowCR}:E{$rowCR}")->getFont()->setItalic(true);
        $rowCR += 2;

        // === EN-TÊTES CHARGES (gauche A:E) + PRODUITS (droite G:K) ===
        // CHARGES — ROUGE CLAIR (couleur d'origine)
        $sheetCR->setCellValue("A{$rowCR}", 'CHARGES')->mergeCells("A{$rowCR}:E{$rowCR}");
        $sheetCR->getStyle("A{$rowCR}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'color' => ['rgb' => '000000']],
            'alignment' => ['horizontal' => 'center'],
            'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => 'FEE2E2']],
        ]);

        // PRODUITS — VERT CLAIR (couleur d'origine)
        $sheetCR->setCellValue("G{$rowCR}", 'PRODUITS')->mergeCells("G{$rowCR}:K{$rowCR}");
        $sheetCR->getStyle("G{$rowCR}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'color' => ['rgb' => '000000']],
            'alignment' => ['horizontal' => 'center'],
            'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => 'D1FAE5']],
        ]);
        $sheetCR->getRowDimension($rowCR)->setRowHeight(25);
        $rowCR++;

        foreach ($headers as $i => $h) {
            $sheetCR->setCellValue(chr(65 + $i) . $rowCR, $h);
            $sheetCR->setCellValue(chr(71 + $i) . $rowCR, $h);
        }
        $sheetCR->getStyle("A{$rowCR}:E{$rowCR}")->applyFromArray([
            'font'      => ['bold' => true],
            'alignment' => ['horizontal' => 'center'],
            'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => 'F3F4F6']],
            'borders'   => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => '000000']]],
        ]);
        $sheetCR->getStyle("G{$rowCR}:K{$rowCR}")->applyFromArray([
            'font'      => ['bold' => true],
            'alignment' => ['horizontal' => 'center'],
            'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => 'F3F4F6']],
            'borders'   => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => '000000']]],
        ]);
        $rowCR++;

        $charges  = $data['charges']['detail']  ?? [];
        $produits = $data['produits']['detail'] ?? [];

        $maxLignesCR = max(count($charges), count($produits));

        for ($i = 0; $i < $maxLignesCR; $i++) {
            if (isset($charges[$i])) {
                $l = $charges[$i];
                $sheetCR->setCellValue("A{$rowCR}", $l['numero'] ?? '');
                $sheetCR->setCellValue("B{$rowCR}", $l['nom'] ?? '');
                $sheetCR->setCellValue("C{$rowCR}", $l['total_debit'] ?? 0);
                $sheetCR->setCellValue("D{$rowCR}", $l['total_credit'] ?? 0);
                $sheetCR->setCellValue("E{$rowCR}", $l['solde_net'] ?? 0);

                $sheetCR->getStyle("A{$rowCR}:E{$rowCR}")->applyFromArray([
                    'borders' => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => 'DDDDDD']]],
                ]);
                $sheetCR->getStyle("A{$rowCR}")->getAlignment()->setHorizontal('center');
                $sheetCR->getStyle("E{$rowCR}")->getFont()->setBold(true);
            }

            if (isset($produits[$i])) {
                $l = $produits[$i];
                $sheetCR->setCellValue("G{$rowCR}", $l['numero'] ?? '');
                $sheetCR->setCellValue("H{$rowCR}", $l['nom'] ?? '');
                $sheetCR->setCellValue("I{$rowCR}", $l['total_debit'] ?? 0);
                $sheetCR->setCellValue("J{$rowCR}", $l['total_credit'] ?? 0);
                $sheetCR->setCellValue("K{$rowCR}", $l['solde_net'] ?? 0);

                $sheetCR->getStyle("G{$rowCR}:K{$rowCR}")->applyFromArray([
                    'borders' => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => 'DDDDDD']]],
                ]);
                $sheetCR->getStyle("G{$rowCR}")->getAlignment()->setHorizontal('center');
                $sheetCR->getStyle("K{$rowCR}")->getFont()->setBold(true);
            }

            $sheetCR->getRowDimension($rowCR)->setRowHeight(18);
            $rowCR++;
        }

        // === TOTAUX CHARGES + PRODUITS ===
        $totalCharges  = $data['charges']['solde']  ?? 0;
        $totalProduits = $data['produits']['solde'] ?? 0;

        $sheetCR->setCellValue("D{$rowCR}", 'TOTAL CHARGES');
        $sheetCR->setCellValue("E{$rowCR}", $totalCharges);

        $sheetCR->setCellValue("J{$rowCR}", 'TOTAL PRODUITS');
        $sheetCR->setCellValue("K{$rowCR}", $totalProduits);

        // Totaux — COULEURS D'ORIGINE (rouge pour CHARGES, vert pour PRODUITS)
        $sheetCR->getStyle("A{$rowCR}:E{$rowCR}")->applyFromArray([
            'font'    => ['bold' => true, 'size' => 12, 'color' => ['rgb' => '991B1B']],
            'fill'    => ['fillType' => 'solid', 'startColor' => ['rgb' => 'FEE2E2']],
            'borders' => [
                'top'    => ['borderStyle' => 'medium', 'color' => ['rgb' => '991B1B']],
                'bottom' => ['borderStyle' => 'medium', 'color' => ['rgb' => '991B1B']],
            ],
        ]);
        $sheetCR->getStyle("G{$rowCR}:K{$rowCR}")->applyFromArray([
            'font'    => ['bold' => true, 'size' => 12, 'color' => ['rgb' => '065F46']],
            'fill'    => ['fillType' => 'solid', 'startColor' => ['rgb' => 'D1FAE5']],
            'borders' => [
                'top'    => ['borderStyle' => 'medium', 'color' => ['rgb' => '065F46']],
                'bottom' => ['borderStyle' => 'medium', 'color' => ['rgb' => '065F46']],
            ],
        ]);
        $sheetCR->getStyle("D{$rowCR}:E{$rowCR}")->getAlignment()->setHorizontal('right');
        $sheetCR->getStyle("J{$rowCR}:K{$rowCR}")->getAlignment()->setHorizontal('right');
        $sheetCR->getRowDimension($rowCR)->setRowHeight(24);
        $rowCR += 2;

        // === RÉSULTAT NET (avec lien vers 131/139) ===
        $resultatNetCR = $totalProduits - $totalCharges;

        $sheetCR->setCellValue("A{$rowCR}", 'RÉSULTAT NET')->mergeCells("A{$rowCR}:E{$rowCR}");
        $sheetCR->getStyle("A{$rowCR}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13],
            'alignment' => ['horizontal' => 'center'],
            'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => 'E5E7EB']],
        ]);

        $sheetCR->setCellValue("G{$rowCR}", 'CALCUL')->mergeCells("G{$rowCR}:K{$rowCR}");
        $sheetCR->getStyle("G{$rowCR}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13],
            'alignment' => ['horizontal' => 'center'],
            'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => 'E5E7EB']],
        ]);
        $rowCR++;

        // Ligne : Libellé résultat (gauche) + Formule (droite)
        $sheetCR->setCellValue("A{$rowCR}", $libelleResultat)
            ->mergeCells("A{$rowCR}:E{$rowCR}");
        $sheetCR->getStyle("A{$rowCR}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 12, 'color' => ['rgb' => $estBenefice ? '065F46' : '991B1B']],
            'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
            'borders'   => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => '000000']]],
            'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => $estBenefice ? 'D1FAE5' : 'FEE2E2']],
        ]);

        $sheetCR->setCellValue("G{$rowCR}", 'Produits - Charges')
            ->mergeCells("G{$rowCR}:K{$rowCR}");
        $sheetCR->getStyle("G{$rowCR}")->applyFromArray([
            'font'      => ['italic' => true, 'size' => 11],
            'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
            'borders'   => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => '000000']]],
        ]);
        $rowCR++;

        // Ligne : Montant (gauche) + Calcul détaillé (droite)
        $sheetCR->setCellValue("A{$rowCR}", $montantResultat)
            ->setCellValue("E{$rowCR}", '$')
            ->mergeCells("A{$rowCR}:D{$rowCR}");

        $sheetCR->setCellValue("G{$rowCR}", number_format($totalProduits, 2, ',', ' ') . ' $')
            ->setCellValue("I{$rowCR}", '-')
            ->setCellValue("K{$rowCR}", number_format($totalCharges, 2, ',', ' ') . ' $')
            ->mergeCells("G{$rowCR}:H{$rowCR}");

        $sheetCR->getStyle("A{$rowCR}:K{$rowCR}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13],
            'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
            'borders'   => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => '000000']]],
            'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => $estBenefice ? 'D1FAE5' : 'FEE2E2']],
        ]);
        $sheetCR->getRowDimension($rowCR)->setRowHeight(24);
        $rowCR++;

        //  Ligne explicative : report vers 131/139
        $sheetCR->setCellValue("A{$rowCR}",
            $estBenefice
                ? "💡 Ce résultat sera reporté au compte 131 (Bénéfice) dans le PASSIF du Bilan"
                : "💡 Ce résultat sera reporté au compte 139 (Perte) dans l'ACTIF du Bilan"
        )->mergeCells("A{$rowCR}:K{$rowCR}");
        $sheetCR->getStyle("A{$rowCR}")->applyFromArray([
            'font'      => ['italic' => true, 'size' => 11, 'color' => ['rgb' => $estBenefice ? '065F46' : '991B1B']],
            'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
            'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => $estBenefice ? 'ECFDF5' : 'FEF2F2']],
            'borders'   => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => $estBenefice ? '059669' : 'DC2626']]],
        ]);
        $sheetCR->getRowDimension($rowCR)->setRowHeight(24);

        // Largeurs
        foreach ([
            'A' => 12, 'B' => 40, 'C' => 15, 'D' => 15, 'E' => 15,
            'F' => 3,
            'G' => 12, 'H' => 40, 'I' => 15, 'J' => 15, 'K' => 15,
        ] as $c => $w) {
            $sheetCR->getColumnDimension($c)->setWidth($w);
        }
        $sheetCR->getStyle("C5:E{$rowCR}")->getNumberFormat()->setFormatCode('#,##0.00" $"');
        $sheetCR->getStyle("I5:K{$rowCR}")->getNumberFormat()->setFormatCode('#,##0.00" $"');

        // Activer la 1ère feuille
        $spreadsheet->setActiveSheetIndex(0);

        // Téléchargement
        $filename = 'Resultat_' . date('Ymd_His') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
        exit;
    }

    // =========================================================================
    // 🔹 SUCCURSALES
    // =========================================================================
    public function getSuccursales($entiteId)
    {
        return response()->json([
            'success' => true,
            'data'    => Succursale::where('entite_id', $entiteId)
                ->where('est_active', 1)
                ->orderBy('code', 'asc')
                ->get(),
        ], 200, ['Content-Type' => 'application/json; charset=UTF-8'], JSON_UNESCAPED_UNICODE);
    }

    // =========================================================================
    // 🔹 PÉRIODES
    // =========================================================================
    public function getPeriodes()
    {
        return response()->json([
            'success' => true,
            'data'    => PeriodesComptable::orderBy('date_debut', 'desc')
                ->get(['id', 'code', 'libelle', 'date_debut', 'date_fin', 'is_cloture']),
        ], 200, ['Content-Type' => 'application/json; charset=UTF-8'], JSON_UNESCAPED_UNICODE);
    }

    // =========================================================================
    // 🔹 PÉRIODE EN COURS
    // =========================================================================
    public function getPeriodeEnCours()
    {
        $periode = PeriodesComptable::where('is_cloture', false)
            ->orderBy('date_debut', 'asc')
            ->first();

        return response()->json([
            'success' => true,
            'data'    => $periode ? [
                'id'         => $periode->id,
                'code'       => $periode->code,
                'libelle'    => $periode->libelle,
                'date_debut' => $periode->date_debut instanceof Carbon
                    ? $periode->date_debut->format('d/m/Y')
                    : $periode->date_debut,
                'date_fin'   => $periode->date_fin instanceof Carbon
                    ? $periode->date_fin->format('d/m/Y')
                    : $periode->date_fin,
                'is_cloture' => (bool) $periode->is_cloture,
            ] : null,
        ], 200, ['Content-Type' => 'application/json; charset=UTF-8'], JSON_UNESCAPED_UNICODE);
    }
}