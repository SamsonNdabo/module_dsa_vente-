<?php

namespace App\Http\Controllers\Comptabilite;

use App\Models\PlanComptable;
use App\Models\PlanComptableArchive;
use App\Models\CompteClass;
use App\Models\EcrituresComptable;
use App\Traits\FiltersComptables;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;

class PlanComptableController extends Controller
{
    use FiltersComptables;

    // Cache classes
    private $classesCache = null;

    // =========================================================================
    // PAGES PRINCIPALES
    // ✅ SEUL CHANGEMENT : view() -> response()->json()
    // =========================================================================
    public function index()
    {
        $classes = $this->getClassesActives();
        $comptes = PlanComptable::with('compte_class')->orderBy('numero', 'asc')->get();
        $comptesParClasse = $this->grouperComptesParClasse($comptes);
        $archives = PlanComptableArchive::with(['supprimePar', 'classe'])
            ->orderBy('deleted_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'classes' => $classes,
            'comptes_par_classe' => $comptesParClasse,
            'archives' => $archives,
        ]);
    }

    // =========================================================================
    // API - COMPTES
    // =========================================================================

    public function getComptes(Request $request)
    {
        $query = PlanComptable::with('compte_class');

        if ($request->filled('classe_id') && $request->classe_id !== 'all' && $request->classe_id !== '') {
            $query->where('classe_id', (int)$request->classe_id);
        }

        if ($request->filled('search') && trim($request->search) !== '') {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('numero', 'LIKE', "%{$search}%")
                  ->orWhere('nom', 'LIKE', "%{$search}%");
            });
        }

        return response()->json(
            $query->orderBy('numero', 'asc')->get()
        );
    }

    public function getClasses()
    {
        return response()->json($this->getClassesActives());
    }

    public function show($id)
    {
        return response()->json(
            PlanComptable::with('compte_class')->findOrFail($id)
        );
    }

    public function filter(Request $request)
    {
        $query = PlanComptable::with('compte_class');

        if ($request->filled('classe_id') && $request->classe_id !== 'all' && $request->classe_id !== '') {
            $query->where('classe_id', (int)$request->classe_id);
        }

        if ($request->filled('search') && trim($request->search) !== '') {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('numero', 'LIKE', "%{$search}%")
                  ->orWhere('nom', 'LIKE', "%{$search}%");
            });
        }

        return response()->json(
            $query->orderBy('numero', 'asc')->get()
        );
    }

    public function checkBeforeDelete($id)
    {
        $compte = PlanComptable::findOrFail($id);

        $nbEcritures = EcrituresComptable::where('compte_numero', $compte->numero)->count();

        $nbBilans = DB::table('bilan_lignes')
            ->where('compte_numero', $compte->numero)
            ->count();

        $result = [
            'can_delete' => ($nbEcritures === 0 && $nbBilans === 0),
            'has_ecritures' => $nbEcritures > 0,
            'has_bilans' => $nbBilans > 0,
            'ecritures_count' => $nbEcritures,
            'bilans_count' => $nbBilans,
            'message' => ''
        ];

        if ($nbEcritures > 0) {
            $result['message'] = "Ce compte est utilisé dans {$nbEcritures} écriture(s) comptable(s). Suppression impossible.";
        } elseif ($nbBilans > 0) {
            $result['message'] = "Ce compte est référencé dans {$nbBilans} bilan(s). Suppression impossible.";
        } else {
            $result['message'] = 'Ce compte peut être supprimé en toute sécurité.';
        }

        if ($result['can_delete'] && PlanComptable::where('classe_id', $compte->classe_id)->count() === 1) {
            $classe = CompteClass::find($compte->classe_id);
            if ($classe && (int) $classe->numero > 7) {
                $result['will_delete_class'] = true;
                $result['class_name'] = $classe->nom;
                $result['class_number'] = $classe->numero;
                $result['message'] .= ' La classe sera également supprimée car elle deviendra vide.';
            }
        }

        return response()->json($result);
    }

    // =========================================================================
    // CRUD COMPTES
    // =========================================================================

    public function store(Request $request)
    {
        $request->validate([
            'numero' => 'required|string|unique:plan_comptable,numero',
            'nom' => 'required|string',
            'classe_id' => 'required|exists:compte_classes,id',
            'position_bilan' => 'nullable|in:actif,passif,hors_bilan',
            'position_tfr' => 'nullable|in:charge,produit,tiers',
            'fonctionnement_debit' => 'required|in:+,-',
            'fonctionnement_credit' => 'required|in:+,-'
        ]);

        $isFirst = PlanComptable::where('classe_id', $request->classe_id)->count() === 0;

        $compte = PlanComptable::create([
            'numero' => strtoupper(trim($request->numero)),
            'nom' => strtoupper(trim($request->nom)),
            'classe_id' => $request->classe_id,
            'position_bilan' => $request->position_bilan,
            'position_tfr' => $request->position_tfr,
            'fonctionnement_debit' => $request->fonctionnement_debit,
            'fonctionnement_credit' => $request->fonctionnement_credit,
            'is_active' => true
        ]);

        $message = 'COMPTE AJOUTÉ AVEC SUCCÈS';
        if ($isFirst) {
            $this->mettreAJourClasse($request->classe_id, $request);
            $message .= '. LA CLASSE A ÉTÉ CONFIGURÉE.';
        }

        $this->classesCache = null;

        return response()->json([
            'success' => true,
            'compte' => $compte->load('compte_class'),
            'message' => $message
        ]);
    }

    public function update(Request $request, $id)
    {
        $compte = PlanComptable::findOrFail($id);

        $request->validate([
            'numero' => 'required|string|unique:plan_comptable,numero,' . $id,
            'nom' => 'required|string',
            'classe_id' => 'required|exists:compte_classes,id',
            'position_bilan' => 'nullable|in:actif,passif,hors_bilan',
            'position_tfr' => 'nullable|in:charge,produit,tiers',
            'fonctionnement_debit' => 'required|in:+,-',
            'fonctionnement_credit' => 'required|in:+,-'
        ]);

        $oldClasseId = $compte->classe_id;
        $oldNumero = $compte->numero;
        $newNumero = strtoupper(trim($request->numero));

        $compte->update([
            'numero' => $newNumero,
            'nom' => strtoupper(trim($request->nom)),
            'classe_id' => $request->classe_id,
            'position_bilan' => $request->position_bilan,
            'position_tfr' => $request->position_tfr,
            'fonctionnement_debit' => $request->fonctionnement_debit,
            'fonctionnement_credit' => $request->fonctionnement_credit,
        ]);

        if ($oldNumero !== $newNumero) {
            EcrituresComptable::where('compte_numero', $oldNumero)
                ->update(['compte_numero' => $newNumero]);

            DB::table('bilan_lignes')
                ->where('compte_numero', $oldNumero)
                ->update(['compte_numero' => $newNumero]);
        }

        $message = 'COMPTE MODIFIÉ AVEC SUCCÈS';
        if ($oldClasseId != $request->classe_id) {
            $this->gererChangementClasse($oldClasseId, $request->classe_id, $compte);
            $message .= '. CLASSE MISE À JOUR.';
        }

        $this->classesCache = null;

        return response()->json([
            'success' => true,
            'compte' => $compte->load('compte_class'),
            'message' => $message
        ]);
    }

    public function destroy($id)
    {
        try {
            $compte = PlanComptable::findOrFail($id);

            $nbEcritures = EcrituresComptable::where('compte_numero', $compte->numero)->count();
            $nbBilans = DB::table('bilan_lignes')
                ->where('compte_numero', $compte->numero)
                ->count();

            if ($nbEcritures > 0) {
                return response()->json([
                    'success' => false,
                    'message' => "SUPPRESSION IMPOSSIBLE : Le compte {$compte->numero} est utilisé dans {$nbEcritures} écriture(s) comptable(s). Veuillez d'abord supprimer ou réaffecter ces écritures.",
                    'ecritures_count' => $nbEcritures
                ], 422);
            }

            if ($nbBilans > 0) {
                return response()->json([
                    'success' => false,
                    'message' => "SUPPRESSION IMPOSSIBLE : Le compte {$compte->numero} est référencé dans {$nbBilans} ligne(s) de bilan. Veuillez d'abord supprimer ces bilans.",
                    'bilans_count' => $nbBilans
                ], 422);
            }

            DB::beginTransaction();

            $classeId = $compte->classe_id;
            $compte->deleteWithArchive();
            $classeSupprimee = $this->supprimerClasseSiVide($classeId);

            DB::commit();

            $this->classesCache = null;

            $message = 'COMPTE SUPPRIMÉ ET ARCHIVÉ AVEC SUCCÈS';
            if ($classeSupprimee) {
                $message .= '. LA CLASSE VIDE A ÉGALEMENT ÉTÉ SUPPRIMÉE.';
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'classe_supprimee' => $classeSupprimee
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur suppression compte', [
                'compte_id' => $id,
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'ERREUR : ' . $e->getMessage()
            ], 500);
        }
    }

    // =========================================================================
    // CRUD CLASSES
    // =========================================================================

    public function createClasse(Request $request)
    {
        $request->validate([
            'numero' => 'required|string|unique:compte_classes,numero',
            'nom' => 'required|string',
            'type' => 'required|in:bilan,gestion',
            'sens_debit' => 'required|in:augmente,diminue',
            'sens_credit' => 'required|in:augmente,diminue'
        ]);

        $classe = CompteClass::create([
            'numero' => strtoupper(trim($request->numero)),
            'nom' => strtoupper(trim($request->nom)),
            'type' => $request->type,
            'sens_debit' => $request->sens_debit,
            'sens_credit' => $request->sens_credit
        ]);

        $this->classesCache = null;

        return response()->json([
            'success' => true,
            'classe' => $classe,
            'message' => 'CLASSE CRÉÉE'
        ]);
    }

    // =========================================================================
    // ARCHIVES
    // =========================================================================

    public function getArchives()
    {
        return response()->json(
            PlanComptableArchive::with(['supprimePar', 'classe'])
                ->orderBy('deleted_at', 'desc')
                ->get()
        );
    }

    public function showArchive($id)
    {
        return response()->json(
            PlanComptableArchive::with(['supprimePar', 'classe'])->findOrFail($id)
        );
    }

    public function filterArchives(Request $request)
    {
        $query = PlanComptableArchive::with(['supprimePar', 'classe']);

        if ($request->deleted_by) {
            $query->where('deleted_by', $request->deleted_by);
        }
        if ($request->date_debut) {
            $query->where('deleted_at', '>=', $request->date_debut);
        }
        if ($request->date_fin) {
            $query->where('deleted_at', '<=', $request->date_fin . ' 23:59:59');
        }
        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('numero', 'LIKE', "%{$request->search}%")
                  ->orWhere('nom', 'LIKE', "%{$request->search}%");
            });
        }

        return response()->json(
            $query->orderBy('deleted_at', 'desc')->get()
        );
    }

    public function restore($id)
    {
        try {
            DB::beginTransaction();

            $archive = PlanComptableArchive::findOrFail($id);

            if (PlanComptable::where('numero', $archive->numero)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => "Le numéro {$archive->numero} existe déjà dans le plan comptable actif."
                ], 422);
            }

            $compte = PlanComptable::create([
                'numero' => $archive->numero,
                'nom' => $archive->nom,
                'classe_id' => $archive->classe_id,
                'position_bilan' => $archive->position_bilan,
                'position_tfr' => $archive->position_tfr,
                'fonctionnement_debit' => $archive->fonctionnement_debit,
                'fonctionnement_credit' => $archive->fonctionnement_credit,
                'is_active' => true,
                'created_at' => $archive->created_at,
                'updated_at' => now()
            ]);

            $this->restaurerEcrituresOrphelines($archive->numero);

            $archive->delete();

            DB::commit();
            $this->classesCache = null;

            return response()->json([
                'success' => true,
                'compte' => $compte->load('compte_class'),
                'message' => "COMPTE {$archive->numero} RESTAURÉ AVEC SUCCÈS."
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'ERREUR : ' . $e->getMessage()
            ], 500);
        }
    }

    public function deleteArchive($id)
    {
        PlanComptableArchive::findOrFail($id)->delete();
        return response()->json([
            'success' => true,
            'message' => 'ARCHIVE SUPPRIMÉE DÉFINITIVEMENT.'
        ]);
    }

    public function getArchiveStats()
    {
        return response()->json([
            'total_archives' => PlanComptableArchive::count(),
            'archives_ce_mois' => PlanComptableArchive::whereMonth('deleted_at', now()->month)
                ->whereYear('deleted_at', now()->year)
                ->count()
        ]);
    }

    // =========================================================================
    // EXPORT EXCEL - PLAN COMPTABLE
    // =========================================================================

    public function exportExcel()
    {
        try {
            $classes = CompteClass::orderBy('numero', 'asc')->get();
            $comptesParClasse = $this->grouperComptesParClasse(
                PlanComptable::with('compte_class')->orderBy('numero', 'asc')->get()
            );

            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);
            $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_PORTRAIT);
            $sheet->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);
            $sheet->getPageMargins()->setTop(0.5)->setBottom(0.5)->setLeft(0.5)->setRight(0.5);

            $couleurs = [
                'titre' => '1A237E',
                'header' => '37474F',
                'header_bg' => 'ECEFF1',
                'classe_bg' => 'E8EAF6',
                'classe_text' => '283593',
                'bordure' => 'CFD8DC',
                'actif' => 'E8F5E9',
                'passif' => 'FFF3E0',
                'charge' => 'FFEBEE',
                'produit' => 'E8F5E9',
                'tiers' => 'F3E5F5',
            ];

            $sheet->setCellValue('A1', 'PLAN COMPTABLE');
            $sheet->mergeCells('A1:G1');
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(18)->getColor()->setRGB($couleurs['titre']);
            $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getRowDimension('1')->setRowHeight(35);

            $sheet->setCellValue('A2', 'Export du ' . now()->format('d/m/Y à H:i'));
            $sheet->mergeCells('A2:G2');
            $sheet->getStyle('A2')->getFont()->setSize(9)->getColor()->setRGB('78909C');
            $sheet->getStyle('A2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getRowDimension('2')->setRowHeight(20);

            $totalComptes = PlanComptable::count();
            $sheet->setCellValue('A3', "Total : {$totalComptes} comptes | " . count($classes) . " classes");
            $sheet->mergeCells('A3:G3');
            $sheet->getStyle('A3')->getFont()->setSize(8)->getColor()->setRGB('90A4AE');
            $sheet->getStyle('A3')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

            $row = 5;

            $headerRow = $row;
            $headers = ['N° Compte', 'Intitulé du compte', 'Classe', 'Type', 'Bilan', 'TFR', 'Fonctionnement'];
            $cols = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];

            foreach ($headers as $i => $h) {
                $sheet->setCellValue($cols[$i] . $headerRow, $h);
            }

            $headerStyle = $sheet->getStyle("A{$headerRow}:G{$headerRow}");
            $headerStyle->getFont()->setBold(true)->setSize(10)->getColor()->setRGB('FFFFFF');
            $headerStyle->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($couleurs['header']);
            $headerStyle->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $headerStyle->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
            $sheet->getRowDimension($headerRow)->setRowHeight(25);
            $sheet->freezePane('A' . ($headerRow + 1));

            $row++;

            foreach ($classes as $classe) {
                $sheet->setCellValue("A{$row}", 'CLASSE ' . $classe->numero . ' - ' . strtoupper($classe->nom));
                $sheet->mergeCells("A{$row}:G{$row}");
                $sheet->getStyle("A{$row}:G{$row}")->getFont()->setBold(true)->setSize(11)->getColor()->setRGB($couleurs['classe_text']);
                $sheet->getStyle("A{$row}:G{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($couleurs['classe_bg']);
                $sheet->getStyle("A{$row}:G{$row}")->getBorders()->getBottom()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM)->getColor()->setRGB($couleurs['classe_text']);
                $sheet->getRowDimension($row)->setRowHeight(22);
                $row++;

                $comptes = $comptesParClasse[$classe->id]['comptes'] ?? [];
                $index = 0;

                foreach ($comptes as $compte) {
                    $bgColor = ($index % 2 == 0) ? 'FAFAFA' : 'FFFFFF';

                    $positionColor = $this->getPositionColor($compte);
                    if ($positionColor) {
                        $sheet->getStyle("E{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($positionColor);
                        $sheet->getStyle("F{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($positionColor);
                    }

                    $sheet->getStyle("A{$row}:G{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($bgColor);

                    $sheet->setCellValue("A{$row}", $compte->numero);
                    $sheet->setCellValue("B{$row}", $compte->nom);
                    $sheet->setCellValue("C{$row}", $classe->numero . ' - ' . $classe->nom);
                    $sheet->setCellValue("D{$row}", $classe->type === 'bilan' ? 'Bilan' : 'Gestion');
                    $sheet->setCellValue("E{$row}", $compte->position_bilan ? strtoupper($compte->position_bilan) : '-');
                    $sheet->setCellValue("F{$row}", $compte->position_tfr ? strtoupper($compte->position_tfr) : '-');
                    $sheet->setCellValue("G{$row}", 'Débit: ' . ($compte->fonctionnement_debit === '+' ? 'Augmente' : 'Diminue') . ' | Crédit: ' . ($compte->fonctionnement_credit === '+' ? 'Augmente' : 'Diminue'));

                    $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                    $sheet->getStyle("C{$row}:F{$row}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

                    $sheet->getRowDimension($row)->setRowHeight(18);
                    $row++;
                    $index++;
                }

                $row++;
            }

            $borderStyle = [
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                        'color' => ['rgb' => $couleurs['bordure']]
                    ]
                ]
            ];
            $lastDataRow = $row - 2;
            $sheet->getStyle("A{$headerRow}:G{$lastDataRow}")->applyFromArray($borderStyle);

            $sheet->getColumnDimension('A')->setWidth(15);
            $sheet->getColumnDimension('B')->setWidth(45);
            $sheet->getColumnDimension('C')->setWidth(25);
            $sheet->getColumnDimension('D')->setWidth(12);
            $sheet->getColumnDimension('E')->setWidth(12);
            $sheet->getColumnDimension('F')->setWidth(12);
            $sheet->getColumnDimension('G')->setWidth(35);

            $row += 2;
            $sheet->setCellValue("A{$row}", 'Document généré automatiquement - ' . config('app.name', 'STAREHE HOLDING'));
            $sheet->mergeCells("A{$row}:G{$row}");
            $sheet->getStyle("A{$row}")->getFont()->setItalic(true)->setSize(7)->getColor()->setRGB('B0BEC5');

            $filename = 'Plan_Comptable_' . date('Y-m-d_His') . '.xlsx';
            $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);

            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: max-age=0');
            header('Pragma: public');

            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');
            exit;
        } catch (\Exception $e) {
            Log::error('Export Plan Comptable error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'export: ' . $e->getMessage()
            ], 500);
        }
    }

    // =========================================================================
    // MÉTHODES PRIVÉES
    // =========================================================================

    private function getClassesActives()
    {
        if ($this->classesCache === null) {
            $this->classesCache = CompteClass::orderBy('numero', 'asc')->get();
        }
        return $this->classesCache;
    }

    private function grouperComptesParClasse($comptes): array
    {
        $grouped = [];
        foreach ($comptes as $compte) {
            if ($compte->compte_class) {
                $key = $compte->compte_class->id;
                if (!isset($grouped[$key])) {
                    $grouped[$key] = [
                        'classe' => $compte->compte_class,
                        'comptes' => []
                    ];
                }
                $grouped[$key]['comptes'][] = $compte;
            }
        }
        return $grouped;
    }

    private function getPositionColor($compte): ?string
    {
        if ($compte->position_bilan === 'actif') return 'E8F5E9';
        if ($compte->position_bilan === 'passif') return 'FFF3E0';
        if ($compte->position_tfr === 'charge') return 'FFEBEE';
        if ($compte->position_tfr === 'produit') return 'E8F5E9';
        if ($compte->position_tfr === 'tiers') return 'F3E5F5';
        return null;
    }

    private function mettreAJourClasse($classeId, $request)
    {
        $classe = CompteClass::find($classeId);
        if (!$classe || (int) $classe->numero <= 7) return;

        $classe->nom = strtoupper($classe->nom);

        if ($request->position_bilan) {
            $classe->type = 'bilan';
            $classe->sens_debit = $request->position_bilan === 'actif' ? 'augmente' : 'diminue';
            $classe->sens_credit = $request->position_bilan === 'actif' ? 'diminue' : 'augmente';
        } elseif ($request->position_tfr) {
            $classe->type = 'gestion';
            $classe->sens_debit = $request->position_tfr === 'charge' ? 'augmente' : 'diminue';
            $classe->sens_credit = $request->position_tfr === 'charge' ? 'diminue' : 'augmente';
        }

        $classe->save();
    }

    private function gererChangementClasse($oldClasseId, $newClasseId, $compte)
    {
        $this->supprimerClasseSiVide($oldClasseId);

        $classe = CompteClass::find($newClasseId);
        if (!$classe || (int) $classe->numero <= 7) return;

        $classe->nom = strtoupper($classe->nom);

        $first = PlanComptable::where('classe_id', $newClasseId)->orderBy('numero')->first();

        if ($first && $first->id == $compte->id) {
            if ($compte->position_bilan) {
                $classe->type = 'bilan';
                $classe->sens_debit = $compte->position_bilan === 'actif' ? 'augmente' : 'diminue';
                $classe->sens_credit = $compte->position_bilan === 'actif' ? 'diminue' : 'augmente';
            } elseif ($compte->position_tfr) {
                $classe->type = 'gestion';
                $classe->sens_debit = $compte->position_tfr === 'charge' ? 'augmente' : 'diminue';
                $classe->sens_credit = $compte->position_tfr === 'charge' ? 'diminue' : 'augmente';
            }
            $classe->save();
        }
    }

    private function supprimerClasseSiVide($id): bool
    {
        if (PlanComptable::where('classe_id', $id)->count() === 0) {
            $classe = CompteClass::find($id);
            if ($classe && (int) $classe->numero > 7) {
                $classe->delete();
                return true;
            }
        }
        return false;
    }

    private function restaurerEcrituresOrphelines($numeroCompte)
    {
        EcrituresComptable::whereNull('compte_numero')
            ->where('compte_numero_backup', $numeroCompte)
            ->update(['compte_numero' => $numeroCompte]);
    }
} 