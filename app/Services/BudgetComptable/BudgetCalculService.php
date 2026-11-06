<?php

namespace App\Services\BudgetComptable;

use App\Models\BudgetSection;
use App\Models\BudgetRubrique;
use App\Models\BudgetPrevision;
use App\Models\BudgetSituation;
use App\Models\PeriodesComptable;
use App\Models\EcrituresComptable;
use App\Models\PlanComptable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use Exception;

class BudgetCalculService
{
    // =========================================================================
    // HELPERS PRIVÉS
    // =========================================================================

    /**
     *  Parse les comptes_racines en acceptant TOUS les séparateurs
     */
    private function parserComptesRacines(?string $comptesRacines): array
    {
        if (empty($comptesRacines)) {
            return [];
        }

        $normalise = str_replace(['.', ';', '|', ' ', "\t", "\n"], ',', $comptesRacines);

        return array_values(array_filter(
            array_map('trim', explode(',', $normalise)),
            fn($v) => $v !== ''
        ));
    }

    /**
     *  Détermine si un compte est un DÉBIT (charge/actif) ou CRÉDIT (produit/passif)
     */
    private function estCompteDebiteur(?string $positionTfr, ?string $numero): bool
    {
        if ($positionTfr === 'charge') return true;
        if ($positionTfr === 'produit') return false;

        if ($numero) {
            $premier = substr($numero, 0, 1);
            if ($premier === '7') return false;
            if (in_array($premier, ['1', '2', '3', '4', '5', '6'])) return true;
        }

        return true;
    }

    /**
     *  CALCULE le solde d'un compte individuellement (logique Grand Livre)
     */
    private function calculerSoldeCompte(float $debit, float $credit, bool $estDebiteur): float
    {
        return $estDebiteur ? ($debit - $credit) : ($credit - $debit);
    }

    /**
     *  Récupère TOUTES les écritures de la période, indexées par mois et compte
     *
     *  ⚠️ LOGIQUE GRAND LIVRE :
     *  - INCLUT le BILAN INITIAL, À-NOUVEAU, OUVERTURE (comme le Grand Livre)
     *  - INCLUT tous les mouvements normaux
     *  - EXCLUT UNIQUEMENT les CLÔTURES
     *
     *  → Le résultat est IDENTIQUE au Grand Livre
     */
    private function chargerEcrituresIndexees(int $periodeId, string $dateDebut, string $dateFin, ?int $entiteId = null): array
    {
        $query = EcrituresComptable::where('periode_id', $periodeId)
            //  EXCLUT UNIQUEMENT les clôtures
            //    INCLUT : BILAN INITIAL, À-NOUVEAU, OUVERTURE, mouvements
            ->where(function ($q) {
                $q->where('reference', 'NOT LIKE', 'CLÔTURE%')
                  ->where('reference', 'NOT LIKE', 'CLOTURE%')
                  ->orWhereNull('reference');
            })
            ->whereBetween('date_ecriture', [$dateDebut, $dateFin]);

        if ($entiteId) {
            $query->where('entite_id', $entiteId);
        }

        $ecritures = $query->toBase()->get(['compte_numero', 'debit', 'credit', 'date_ecriture']);

        // Indexation : mois -> compte -> ['debit', 'credit']
        $map = [];
        foreach ($ecritures as $e) {
            $m = (int) date('m', strtotime($e->date_ecriture));
            $compte = trim((string) ($e->compte_numero ?? ''));
            if ($compte === '') continue;

            if (!isset($map[$m][$compte])) {
                $map[$m][$compte] = ['debit' => 0.0, 'credit' => 0.0];
            }
            $map[$m][$compte]['debit']  += (float) $e->debit;
            $map[$m][$compte]['credit'] += (float) $e->credit;
        }

        return $map;
    }

    // =========================================================================
    // LECTURE & CALCULS DU SUIVI BUDGÉTAIRE
    // =========================================================================

    public function getSections(): array
    {
        try {
            $sections = BudgetSection::actives()
                ->ordonnees()
                ->with(['rubriques' => function ($q) {
                    $q->actives()->ordonnees()->with('enfants');
                }])
                ->get()
                ->each(function ($section) {
                    $section->rubriques->each(function ($rubrique) {
                        $rubrique->append('est_parent');
                    });
                });

            return ['success' => true, 'sections' => $sections];
        } catch (Exception $e) {
            Log::error('Erreur sections : ' . $e->getMessage());
            return ['success' => false, 'message' => 'Impossible de charger les sections.'];
        }
    }

    public function getPrevisions(?int $periodeId = null): array
    {
        try {
            $periode = $this->findPeriode($periodeId);
            if (!$periode) {
                return ['success' => false, 'message' => 'Aucune période comptable ouverte trouvée.'];
            }

            $previsions = BudgetPrevision::parPeriode($periode->id)
                ->get()
                ->groupBy('rubrique_id')
                ->map(function ($items) {
                    $result = [];
                    foreach ($items as $item) {
                        $result[$item->mois] = [
                            'id'           => $item->id,
                            'montant'      => round(abs((float) $item->montant), 4),
                            'mois'         => (int) $item->mois,
                            'statut'       => $item->statut,
                            'statut_icone' => $item->statut_icone ?? null,
                        ];
                    }
                    return $result;
                });

            return [
                'success'    => true,
                'periode_id' => $periode->id,
                'previsions' => $previsions
            ];
        } catch (Exception $e) {
            Log::error('Erreur prévisions : ' . $e->getMessage());
            return ['success' => false, 'message' => 'Erreur chargement prévisions.'];
        }
    }

    /**
     *  CALCUL DU SUIVI BUDGÉTAIRE - LOGIQUE GRAND LIVRE
     *
     *  ─────────────────────────────────────────────────────────
     *  1) Table utilisée : UNIQUEMENT ecritures_comptables
     *  2) INCLUT tout (bilan initial + mouvements)
     *  3) EXCLUT UNIQUEMENT les clôtures
     *  4) Calcul PAR COMPTE individuellement
     *  5) Résultat = EXACTEMENT le Grand Livre
     *  ─────────────────────────────────────────────────────────
     */
    public function calculer(?int $periodeId = null, ?int $entiteId = null, string $vue = 'annuel', ?int $mois = null): array
    {
        $periode = $this->findPeriode($periodeId);
        if (!$periode) {
            return ['success' => false, 'message' => 'Aucun exercice comptable trouvé.'];
        }

        $dateDebut = $periode->date_debut instanceof Carbon ? $periode->date_debut->format('Y-m-d') : $periode->date_debut;
        $dateFin   = $periode->date_fin instanceof Carbon ? $periode->date_fin->format('Y-m-d') : $periode->date_fin;

        // 1) Prévisions validées
        $toutesPrevisions = BudgetPrevision::parPeriode($periode->id)
            ->valides()
            ->get()
            ->groupBy('rubrique_id')
            ->map(fn($items) => $items->keyBy('mois'));

        // 2) Plan comptable
        $planComptable = PlanComptable::all(['numero', 'position_tfr'])->keyBy('numero');

        // 3)  Écritures indexées (INCLUT bilan initial + mouvements, EXCLUT clôtures)
        $mapEcritures = $this->chargerEcrituresIndexees($periode->id, $dateDebut, $dateFin, $entiteId);

        // 4) Structure budgétaire
        $sections = BudgetSection::actives()
            ->ordonnees()
            ->with(['rubriques' => fn($q) => $q->actives()->ordonnees()->with('enfants')])
            ->get();

        $lignes = [];
        $moisAFiltrer = ($vue === 'mensuel' && $mois) ? [(int) $mois] : range(1, 12);

        foreach ($sections as $section) {
            $lignesSectionTemp = [];
            $idsTraites = [];

            foreach ($section->rubriques as $rubrique) {
                if ($rubrique->est_parent && $rubrique->enfants->isNotEmpty()) {
                    $lignesEnfantsTemp = [];

                    foreach ($rubrique->enfants as $enfant) {
                        if (!in_array($enfant->id, $idsTraites)) {
                            $ligneEnfant = $this->formatLigneEnfant($enfant, $section, $mapEcritures, $toutesPrevisions, $moisAFiltrer, $planComptable);
                            $lignesEnfantsTemp[] = $ligneEnfant;
                            $idsTraites[] = $enfant->id;
                        }
                    }

                    $ligneParent = $this->formatLigneParentDepuisEnfants($rubrique, $section, $lignesEnfantsTemp, $moisAFiltrer);
                    $lignesSectionTemp[] = $ligneParent;

                    foreach ($lignesEnfantsTemp as $ligneEnfant) {
                        $lignesSectionTemp[] = $ligneEnfant;
                    }
                } elseif (!in_array($rubrique->id, $idsTraites) && !$rubrique->parent_id) {
                    $ligneRubrique = $this->formatLigneEnfant($rubrique, $section, $mapEcritures, $toutesPrevisions, $moisAFiltrer, $planComptable);
                    $lignesSectionTemp[] = $ligneRubrique;
                    $idsTraites[] = $rubrique->id;
                }
            }

            $ligneHeaderSection = $this->formatLigneSectionHeader($section, $lignesSectionTemp, $moisAFiltrer);
            $lignes[] = $ligneHeaderSection;

            foreach ($lignesSectionTemp as $l) {
                $lignes[] = $l;
            }
        }

        return [
            'success' => true,
            'data'    => [
                'periode' => [
                    'id'         => $periode->id,
                    'code'       => $periode->code,
                    'libelle'    => $periode->libelle,
                    'is_cloture' => $periode->is_cloture,
                ],
                'vue'     => $vue,
                'mois'    => $mois,
                'lignes'  => $lignes,
            ]
        ];
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    private function formatLigneEnfant($rubrique, $section, array $mapEcritures, $toutesPrevisions, array $moisAFiltrer, $planComptable): array
    {
        $racines = $this->parserComptesRacines($rubrique->comptes_racines);
        $prevsRubrique = $toutesPrevisions->get($rubrique->id);

        $previsions   = [];
        $realisations = [];
        $ecarts       = [];
        $pourcentages = [];

        $totPrev = 0.0;
        $totReal = 0.0;

        foreach ($moisAFiltrer as $m) {
            $prev = round(abs((float) ($prevsRubrique?->get($m)?->montant ?? 0)), 4);
            $previsions[$m] = $prev;

            //  Calcul du solde : SOMME des soldes individuels
            $soldeRubrique = 0.0;

            if (!empty($racines) && isset($mapEcritures[$m])) {
                foreach ($mapEcritures[$m] as $compte => $valeurs) {
                    $match = false;
                    foreach ($racines as $racine) {
                        if (str_starts_with($compte, $racine)) {
                            $match = true;
                            break;
                        }
                    }
                    if (!$match) continue;

                    $infoCompte = $planComptable->get($compte);
                    $positionTfr = $infoCompte->position_tfr ?? null;
                    $estDebiteur = $this->estCompteDebiteur($positionTfr, $compte);

                    $soldeRubrique += $this->calculerSoldeCompte(
                        $valeurs['debit'],
                        $valeurs['credit'],
                        $estDebiteur
                    );
                }
            }

            //  Valeur absolue pour affichage
            $real = round(abs($soldeRubrique), 4);

            $realisations[$m] = $real;
            $ecarts[$m]       = round($prev - $real, 4);
            $pourcentages[$m] = $prev > 0 ? round(($real / $prev) * 100, 4) : ($real > 0 ? 100.0 : 0.0);

            $totPrev += $prev;
            $totReal += $real;
        }

        return [
            'type'         => 'enfant',
            'id'           => $rubrique->id,
            'code'         => $rubrique->code,
            'libelle'      => $rubrique->libelle,
            'parent_id'    => $rubrique->parent_id ?? null,
            'section_type' => $section->type_section,
            'previsions'   => $previsions,
            'realisations' => $realisations,
            'ecarts'       => $ecarts,
            'pourcentages' => $pourcentages,
            'total'        => [
                'prevision'   => round($totPrev, 4),
                'realisation' => round($totReal, 4),
                'ecart'       => round($totPrev - $totReal, 4),
                'pourcentage' => $totPrev > 0 ? round(($totReal / $totPrev) * 100, 4) : ($totReal > 0 ? 100.0 : 0.0),
            ]
        ];
    }

    private function formatLigneParentDepuisEnfants($parent, $section, array $lignesEnfants, array $moisAFiltrer): array
    {
        $previsions   = array_fill_keys($moisAFiltrer, 0.0);
        $realisations = array_fill_keys($moisAFiltrer, 0.0);

        foreach ($lignesEnfants as $enfant) {
            foreach ($moisAFiltrer as $m) {
                $previsions[$m]   += $enfant['previsions'][$m] ?? 0;
                $realisations[$m] += $enfant['realisations'][$m] ?? 0;
            }
        }

        $ecarts = array_fill_keys($moisAFiltrer, 0.0);
        $pourcentages = array_fill_keys($moisAFiltrer, 0.0);
        $totPrev = 0.0;
        $totReal = 0.0;

        foreach ($moisAFiltrer as $m) {
            $ecarts[$m]       = round($previsions[$m] - $realisations[$m], 4);
            $pourcentages[$m] = $previsions[$m] > 0 ? round(($realisations[$m] / $previsions[$m]) * 100, 4) : ($realisations[$m] > 0 ? 100.0 : 0.0);
            $totPrev += $previsions[$m];
            $totReal += $realisations[$m];
        }

        return [
            'type'         => 'parent',
            'id'           => $parent->id,
            'code'         => $parent->code,
            'libelle'      => $parent->libelle,
            'parent_id'    => null,
            'section_type' => $section->type_section,
            'previsions'   => $previsions,
            'realisations' => $realisations,
            'ecarts'       => $ecarts,
            'pourcentages' => $pourcentages,
            'total'        => [
                'prevision'   => round($totPrev, 4),
                'realisation' => round($totReal, 4),
                'ecart'       => round($totPrev - $totReal, 4),
                'pourcentage' => $totPrev > 0 ? round(($totReal / $totPrev) * 100, 4) : ($totReal > 0 ? 100.0 : 0.0),
            ]
        ];
    }

    private function formatLigneSectionHeader($section, array $lignesSection, array $moisAFiltrer): array
    {
        $previsions   = array_fill_keys($moisAFiltrer, 0.0);
        $realisations = array_fill_keys($moisAFiltrer, 0.0);

        foreach ($lignesSection as $ligne) {
            if ($ligne['type'] === 'parent') {
                foreach ($moisAFiltrer as $m) {
                    $previsions[$m]   += $ligne['previsions'][$m] ?? 0;
                    $realisations[$m] += $ligne['realisations'][$m] ?? 0;
                }
            } elseif ($ligne['type'] === 'enfant') {
                $aUnParent = !empty($ligne['parent_id']);
                if (!$aUnParent) {
                    foreach ($moisAFiltrer as $m) {
                        $previsions[$m]   += $ligne['previsions'][$m] ?? 0;
                        $realisations[$m] += $ligne['realisations'][$m] ?? 0;
                    }
                }
            }
        }

        $ecarts = array_fill_keys($moisAFiltrer, 0.0);
        $pourcentages = array_fill_keys($moisAFiltrer, 0.0);
        $totPrev = 0.0;
        $totReal = 0.0;

        foreach ($moisAFiltrer as $m) {
            $ecarts[$m]       = round($previsions[$m] - $realisations[$m], 4);
            $pourcentages[$m] = $previsions[$m] > 0 ? round(($realisations[$m] / $previsions[$m]) * 100, 4) : ($realisations[$m] > 0 ? 100.0 : 0.0);
            $totPrev += $previsions[$m];
            $totReal += $realisations[$m];
        }

        return [
            'type'         => 'section',
            'id'           => $section->id,
            'code'         => $section->code,
            'libelle'      => $section->libelle,
            'parent_id'    => null,
            'section_type' => $section->type_section,
            'previsions'   => $previsions,
            'realisations' => $realisations,
            'ecarts'       => $ecarts,
            'pourcentages' => $pourcentages,
            'total'        => [
                'prevision'   => round($totPrev, 4),
                'realisation' => round($totReal, 4),
                'ecart'       => round($totPrev - $totReal, 4),
                'pourcentage' => $totPrev > 0 ? round(($totReal / $totPrev) * 100, 4) : ($totReal > 0 ? 100.0 : 0.0),
            ]
        ];
    }

    private function estSectionRevenu(string $typeSection): bool
    {
        $typeLower = mb_strtolower($typeSection);
        return str_contains($typeLower, 'revenu')
            || str_contains($typeLower, 'produit')
            || str_contains($typeLower, 'recette')
            || str_contains($typeLower, 'vente')
            || str_contains($typeLower, 'encaissement');
    }

    // =========================================================================
    // CRUD SECTIONS & RUBRIQUES
    // =========================================================================

    public function storeSection(array $data): array
    {
        $validator = Validator::make($data, [
            'code'         => 'required|string|max:20|unique:budget_sections,code',
            'libelle'      => 'required|string|max:255',
            'type_section' => 'required|string|max:150',
            'ordre'        => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return ['success' => false, 'errors' => $validator->errors()];
        }

        $section = BudgetSection::create([
            'code'         => trim($data['code']),
            'libelle'      => trim($data['libelle']),
            'type_section' => trim($data['type_section']),
            'ordre'        => $data['ordre'] ?? 0,
            'actif'        => true,
        ]);

        return ['success' => true, 'section' => $section];
    }

    public function updateSection(int $id, array $data): array
    {
        $section = BudgetSection::findOrFail($id);

        $validator = Validator::make($data, [
            'code'         => 'sometimes|required|string|max:20|unique:budget_sections,code,' . $id,
            'libelle'      => 'sometimes|required|string|max:255',
            'type_section' => 'sometimes|required|string|max:150',
            'ordre'        => 'nullable|integer|min:0',
            'actif'        => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return ['success' => false, 'errors' => $validator->errors()];
        }

        $section->update($data);
        return ['success' => true, 'section' => $section->fresh()];
    }

    public function deleteSection(int $id): array
    {
        return DB::transaction(function () use ($id) {
            $section = BudgetSection::findOrFail($id);
            $section->rubriques()->delete();
            $section->delete();
            return ['success' => true, 'message' => 'Section et rubriques associées supprimées.'];
        });
    }

    public function storeRubrique(array $data): array
    {
        $validator = Validator::make($data, [
            'section_id'      => 'required|exists:budget_sections,id',
            'parent_id'       => 'nullable|exists:budget_rubriques,id',
            'code'            => 'required|string|max:20',
            'libelle'         => 'required|string|max:255',
            'comptes_racines' => 'nullable|string|max:255',
            'ordre'           => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return ['success' => false, 'errors' => $validator->errors()];
        }

        $rubrique = BudgetRubrique::create([
            'section_id'      => $data['section_id'],
            'parent_id'       => $data['parent_id'] ?? null,
            'code'            => trim($data['code']),
            'libelle'         => trim($data['libelle']),
            'comptes_racines' => isset($data['comptes_racines']) ? trim($data['comptes_racines']) : null,
            'ordre'           => $data['ordre'] ?? 0,
            'actif'           => true,
        ]);

        return ['success' => true, 'rubrique' => $rubrique];
    }

    public function updateRubrique(int $id, array $data): array
    {
        $rubrique = BudgetRubrique::findOrFail($id);

        $validator = Validator::make($data, [
            'section_id'      => 'sometimes|required|exists:budget_sections,id',
            'parent_id'       => 'nullable|exists:budget_rubriques,id',
            'code'            => 'sometimes|required|string|max:20',
            'libelle'         => 'sometimes|required|string|max:255',
            'comptes_racines' => 'nullable|string|max:255',
            'ordre'           => 'nullable|integer|min:0',
            'actif'           => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return ['success' => false, 'errors' => $validator->errors()];
        }

        $rubrique->update($data);
        return ['success' => true, 'rubrique' => $rubrique->fresh()];
    }

    public function deleteRubrique(int $id): array
    {
        return DB::transaction(function () use ($id) {
            $rubrique = BudgetRubrique::findOrFail($id);
            BudgetRubrique::where('parent_id', $id)->delete();
            $rubrique->delete();
            return ['success' => true, 'message' => 'Rubrique et sous-rubriques supprimées.'];
        });
    }

    // =========================================================================
    // PRÉVISIONS
    // =========================================================================

    public function storePrevision(array $data): array
    {
        $validator = Validator::make($data, [
            'rubrique_id' => 'required|exists:budget_rubriques,id',
            'periode_id'  => 'required|exists:periodes_comptables,id',
            'mois'        => 'required|integer|between:1,12',
            'montant'     => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return ['success' => false, 'errors' => $validator->errors()];
        }

        $prevision = BudgetPrevision::updateOrCreate(
            [
                'rubrique_id' => $data['rubrique_id'],
                'periode_id'  => $data['periode_id'],
                'mois'        => $data['mois']
            ],
            [
                'montant' => round(abs((float) $data['montant']), 4),
                'statut'  => BudgetPrevision::STATUT_EN_ATTENTE,
            ]
        );

        return ['success' => true, 'prevision' => $prevision];
    }

    public function storePrevisionsBatch(array $data): array
    {
        $validator = Validator::make($data, [
            'periode_id'               => 'required|exists:periodes_comptables,id',
            'previsions'               => 'required|array|min:1',
            'previsions.*.rubrique_id' => 'required|exists:budget_rubriques,id',
            'previsions.*.mois'        => 'required|integer|between:1,12',
            'previsions.*.montant'     => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return ['success' => false, 'errors' => $validator->errors()];
        }

        return DB::transaction(function () use ($data) {
            $nb = 0;
            foreach ($data['previsions'] as $prev) {
                BudgetPrevision::updateOrCreate(
                    [
                        'rubrique_id' => $prev['rubrique_id'],
                        'periode_id'  => $data['periode_id'],
                        'mois'        => $prev['mois']
                    ],
                    [
                        'montant' => round(abs((float) $prev['montant']), 4),
                        'statut'  => BudgetPrevision::STATUT_EN_ATTENTE,
                    ]
                );
                $nb++;
            }

            return [
                'success'       => true,
                'message'       => 'Prévisions enregistrées (en attente de validation).',
                'nb_previsions' => $nb
            ];
        });
    }

    public function validerPrevision(int $previsionId, int $validateurId): array
    {
        $prevision = BudgetPrevision::with(['rubrique.section', 'periode'])->findOrFail($previsionId);

        if ($prevision->statut === BudgetPrevision::STATUT_VALIDE) {
            return ['success' => false, 'message' => 'Prévision déjà validée.'];
        }

        return DB::transaction(function () use ($prevision, $validateurId) {
            $prevision->valider($validateurId);
            $this->mettreAJourSituation($prevision);

            Log::info(' Prévision validée', ['prevision_id' => $prevision->id]);
            return [
                'success' => true,
                'message' => ' Prévision validée.',
                'data'    => $prevision->fresh(),
            ];
        });
    }

    public function validerToutesPrevisions(int $periodeId, int $validateurId): array
    {
        return DB::transaction(function () use ($periodeId, $validateurId) {
            $previsions = BudgetPrevision::where('periode_id', $periodeId)
                ->where('statut', BudgetPrevision::STATUT_EN_ATTENTE)
                ->get();

            if ($previsions->isEmpty()) {
                return ['success' => false, 'message' => 'Aucune prévision en attente.'];
            }

            foreach ($previsions as $prevision) {
                $prevision->valider($validateurId);
                $this->mettreAJourSituation($prevision);
            }

            $nb = $previsions->count();
            Log::info(' Toutes prévisions validées', ['periode_id' => $periodeId, 'nb' => $nb]);

            return [
                'success'     => true,
                'message'     => " {$nb} prévision(s) validée(s).",
                'nb_validees' => $nb,
            ];
        });
    }

    public function rejeterPrevision(int $previsionId, int $validateurId, string $motif): array
    {
        if (empty(trim($motif))) {
            return ['success' => false, 'message' => 'Motif obligatoire.'];
        }

        $prevision = BudgetPrevision::findOrFail($previsionId);
        $prevision->rejeter($validateurId, $motif);

        Log::info('❌ Prévision rejetée', ['id' => $previsionId]);

        return [
            'success' => true,
            'message' => '❌ Prévision rejetée.',
            'data'    => $prevision->fresh(),
        ];
    }

    /**
     *  Met à jour la situation (logique Grand Livre, inclut bilan initial)
     */
    private function mettreAJourSituation(BudgetPrevision $prevision): void
    {
        $rubrique = $prevision->rubrique;
        $section  = $rubrique?->section;
        if (!$section) return;

        $racines = $this->parserComptesRacines($rubrique->comptes_racines);
        $planComptable = PlanComptable::all(['numero', 'position_tfr'])->keyBy('numero');

        $realise = 0.0;

        if (!empty($racines)) {
            //  INCLUT tout SAUF les clôtures (comme le Grand Livre)
            $ecritures = EcrituresComptable::where('periode_id', $prevision->periode_id)
                ->where(function ($q) {
                    $q->where('reference', 'NOT LIKE', 'CLÔTURE%')
                      ->where('reference', 'NOT LIKE', 'CLOTURE%')
                      ->orWhereNull('reference');
                })
                ->whereMonth('date_ecriture', $prevision->mois)
                ->where(function ($query) use ($racines) {
                    foreach ($racines as $racine) {
                        $query->orWhere('compte_numero', 'LIKE', $racine . '%');
                    }
                })
                ->get();

            //  Calcul PAR COMPTE individuellement
            foreach ($ecritures as $e) {
                $compte = trim((string) $e->compte_numero);
                $infoCompte = $planComptable->get($compte);
                $positionTfr = $infoCompte->position_tfr ?? null;
                $estDebiteur = $this->estCompteDebiteur($positionTfr, $compte);

                $realise += $this->calculerSoldeCompte(
                    (float) $e->debit,
                    (float) $e->credit,
                    $estDebiteur
                );
            }
        }

        $realise = round(abs($realise), 4);
        $prevMontant = round(abs((float) $prevision->montant), 4);
        $situationFinale = round(abs($prevMontant - $realise), 4);

        BudgetSituation::updateOrCreate(
            [
                'rubrique_id' => $prevision->rubrique_id,
                'periode_id'  => $prevision->periode_id,
                'mois'        => $prevision->mois,
            ],
            [
                'situation_initiale' => $prevMontant,
                'situation_finale'   => $situationFinale,
            ]
        );
    }

    // =========================================================================
    // SITUATION BUDGÉTAIRE GÉNÉRALE
    // =========================================================================

    /**
     *  Enregistre la situation globale (logique Grand Livre, inclut bilan initial)
     */
    public function enregistrerSituation(int $periodeId): array
    {
        $periode = PeriodesComptable::findOrFail($periodeId);

        $dateDebut = $periode->date_debut instanceof Carbon ? $periode->date_debut->format('Y-m-d') : $periode->date_debut;
        $dateFin   = $periode->date_fin instanceof Carbon ? $periode->date_fin->format('Y-m-d') : $periode->date_fin;

        $planComptable = PlanComptable::all(['numero', 'position_tfr'])->keyBy('numero');

        //  INCLUT tout SAUF les clôtures
        $mapEcritures = $this->chargerEcrituresIndexees($periodeId, $dateDebut, $dateFin);

        $rubriques = BudgetRubrique::actives()->with('section')->get();
        $totalEnregistrements = 0;
        $situationsCrees = 0;
        $situationsMAJ   = 0;

        DB::beginTransaction();

        try {
            foreach ($rubriques as $rubrique) {
                $section = $rubrique->section;
                if (!$section) continue;

                $racines = $this->parserComptesRacines($rubrique->comptes_racines);
                $situationPrecedente = 0.0;

                for ($mois = 1; $mois <= 12; $mois++) {
                    $situationInitiale = round(abs($situationPrecedente), 4);
                    $realise = 0.0;

                    if (!empty($racines) && isset($mapEcritures[$mois])) {
                        foreach ($mapEcritures[$mois] as $compte => $valeurs) {
                            $match = false;
                            foreach ($racines as $racine) {
                                if (str_starts_with($compte, $racine)) {
                                    $match = true;
                                    break;
                                }
                            }
                            if (!$match) continue;

                            $infoCompte = $planComptable->get($compte);
                            $positionTfr = $infoCompte->position_tfr ?? null;
                            $estDebiteur = $this->estCompteDebiteur($positionTfr, $compte);

                            $realise += $this->calculerSoldeCompte(
                                $valeurs['debit'],
                                $valeurs['credit'],
                                $estDebiteur
                            );
                        }
                    }

                    $realise = round(abs($realise), 4);
                    $situationFinale = round(abs($situationInitiale - $realise), 4);

                    $existe = BudgetSituation::where([
                        'rubrique_id' => $rubrique->id,
                        'periode_id'  => $periodeId,
                        'mois'        => $mois,
                    ])->exists();

                    BudgetSituation::updateOrCreate(
                        [
                            'rubrique_id' => $rubrique->id,
                            'periode_id'  => $periodeId,
                            'mois'        => $mois
                        ],
                        [
                            'situation_initiale' => $situationInitiale,
                            'situation_finale'   => $situationFinale
                        ]
                    );

                    if ($existe) $situationsMAJ++;
                    else $situationsCrees++;

                    $situationPrecedente = $situationFinale;
                    $totalEnregistrements++;
                }
            }

            DB::commit();

            Log::info(' Situation budgétaire enregistrée', [
                'periode_id' => $periodeId,
                'total'      => $totalEnregistrements,
            ]);

            return [
                'success'     => true,
                'message'     => "Situation calculée : {$totalEnregistrements} lignes ({$situationsCrees} créées, {$situationsMAJ} MAJ)",
                'total'       => $totalEnregistrements,
                'crees'       => $situationsCrees,
                'mis_a_jour'  => $situationsMAJ,
            ];

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Erreur situation budgétaire : ' . $e->getMessage());
            return ['success' => false, 'message' => 'Erreur : ' . $e->getMessage()];
        }
    }

    // =========================================================================
    // API AUXILIAIRES
    // =========================================================================

    public function getPeriodes(): array
    {
        try {
            $periodes = PeriodesComptable::orderBy('date_debut', 'desc')
                ->get(['id', 'code', 'libelle', 'date_debut', 'date_fin', 'is_cloture', 'statut']);

            return ['success' => true, 'periodes' => $periodes];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Erreur chargement périodes.'];
        }
    }

    public function dupliquerBudget(array $data): array
    {
        $validator = Validator::make($data, [
            'periode_source_id' => 'required|exists:periodes_comptables,id',
            'periode_cible_id'  => 'required|exists:periodes_comptables,id|different:periode_source_id',
        ]);

        if ($validator->fails()) {
            return ['success' => false, 'errors' => $validator->errors()];
        }

        $previsionsSource = BudgetPrevision::where('periode_id', $data['periode_source_id'])->get();

        if ($previsionsSource->isEmpty()) {
            return ['success' => false, 'message' => 'Aucune prévision à dupliquer.'];
        }

        return DB::transaction(function () use ($previsionsSource, $data) {
            $count = 0;
            foreach ($previsionsSource as $prevision) {
                BudgetPrevision::updateOrCreate(
                    [
                        'rubrique_id' => $prevision->rubrique_id,
                        'periode_id'  => $data['periode_cible_id'],
                        'mois'        => $prevision->mois,
                    ],
                    [
                        'montant' => round(abs((float) $prevision->montant), 4),
                        'statut'  => BudgetPrevision::STATUT_EN_ATTENTE,
                    ]
                );
                $count++;
            }

            Log::info('📋 Budget dupliqué', ['nb' => $count]);

            return [
                'success'       => true,
                'message'       => " {$count} prévision(s) dupliquée(s).",
                'nb_dupliquees' => $count,
            ];
        });
    }

    private function findPeriode(?int $periodeId): ?PeriodesComptable
    {
        if (!empty($periodeId)) {
            return PeriodesComptable::find($periodeId);
        }

        return PeriodesComptable::where('statut', 'ouverte')
            ->orderBy('date_debut', 'asc')
            ->first();
    }
}