<?php

namespace App\Services\BudgetComptable;

use App\Models\BudgetRubrique;
use App\Models\BudgetPrevision;
use App\Models\EcrituresComptable;
use App\Models\PeriodesComptable;
use App\Models\PlanComptable;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class BudgetDetailService
{
    /**
     * ✅ Détail complet d'une rubrique pour un mois donné :
     *    - Liste des écritures affectant les comptes racines
     *    - Solde par compte
     *    - Total réalisé (doit correspondre au Grand Livre)
     */
    public function detailRubrique(int $rubriqueId, int $periodeId, ?int $mois = null): array
    {
        try {
            $rubrique = BudgetRubrique::with('section')->findOrFail($rubriqueId);
            $periode  = PeriodesComptable::findOrFail($periodeId);

            $comptesRacines = array_filter(
                array_map('trim', explode(',', $rubrique->comptes_racines ?? ''))
            );

            if (empty($comptesRacines)) {
                return [
                    'success' => true,
                    'data'    => [
                        'rubrique'         => $this->formatRubrique($rubrique),
                        'periode'          => $this->formatPeriode($periode),
                        'mois'             => $mois,
                        'comptes_racines'  => [],
                        'ecritures'        => [],
                        'soldes_par_compte'=> [],
                        'total'            => [
                            'debit'   => 0.0,
                            'credit'  => 0.0,
                            'solde'   => 0.0,
                            'realise' => 0.0,
                        ],
                    ],
                ];
            }

            $dateDebut = $periode->date_debut instanceof Carbon
                ? $periode->date_debut->format('Y-m-d')
                : $periode->date_debut;
            $dateFin = $periode->date_fin instanceof Carbon
                ? $periode->date_fin->format('Y-m-d')
                : $periode->date_fin;

            // ✅ Requête identique à celle du Grand Livre et du calcul budgétaire
            $query = EcrituresComptable::where('periode_id', $periode->id)
                ->where('reference', 'NOT LIKE', 'CLÔTURE%')
                ->where('reference', 'NOT LIKE', 'CLOTURE%')
                ->where('reference', 'NOT LIKE', 'BILAN%')
                ->where('reference', 'NOT LIKE', 'À-NOUVEAU%')
                ->where('reference', 'NOT LIKE', 'A-NOUVEAU%')
                ->whereBetween('date_ecriture', [$dateDebut, $dateFin])
                ->where(function ($q) use ($comptesRacines) {
                    foreach ($comptesRacines as $racine) {
                        $q->orWhere('compte_numero', 'LIKE', $racine . '%');
                    }
                });

            if ($mois) {
                $query->whereMonth('date_ecriture', (int) $mois);
            }

            $ecritures = $query->orderBy('date_ecriture')
                ->orderBy('id')
                ->get([
                    'id',
                    'numero_ecriture',
                    'date_ecriture',
                    'compte_numero',
                    'libelle',
                    'debit',
                    'credit',
                    'reference',
                    'entite_id',
                    'succursale_id',
                    'operation_journal_id',
                ]);

            // ✅ Solde par compte + rattachement au plan comptable
            $planComptable = PlanComptable::all(['numero', 'nom', 'position_tfr', 'position_bilan'])
                ->keyBy('numero');

            $estRevenu = $this->estSectionRevenu($rubrique->section->type_section ?? '');

            $soldesParCompte = [];
            $ecrituresFormatees = [];
            $totalDebit = 0.0;
            $totalCredit = 0.0;

            foreach ($ecritures as $e) {
                $debit  = round((float) $e->debit, 4);
                $credit = round((float) $e->credit, 4);

                $totalDebit  += $debit;
                $totalCredit += $credit;

                $compte = trim($e->compte_numero);

                if (!isset($soldesParCompte[$compte])) {
                    $infoCompte = $planComptable->get($compte);
                    $soldesParCompte[$compte] = [
                        'compte'         => $compte,
                        'nom'            => $infoCompte->nom ?? null,
                        'position_tfr'   => $infoCompte->position_tfr ?? null,
                        'position_bilan' => $infoCompte->position_bilan ?? null,
                        'debit'          => 0.0,
                        'credit'         => 0.0,
                        'solde'          => 0.0,
                    ];
                }
                $soldesParCompte[$compte]['debit']  += $debit;
                $soldesParCompte[$compte]['credit'] += $credit;

                // ✅ Formatage de l'écriture
                $ecrituresFormatees[] = [
                    'id'                => $e->id,
                    'numero_ecriture'   => $e->numero_ecriture,
                    'date_ecriture'     => $e->date_ecriture instanceof Carbon
                        ? $e->date_ecriture->format('Y-m-d')
                        : $e->date_ecriture,
                    'compte_numero'     => $compte,
                    'compte_nom'        => $infoCompte->nom ?? null,
                    'libelle'           => $e->libelle,
                    'reference'         => $e->reference,
                    'debit'             => $debit,
                    'credit'            => $credit,
                    'entite_id'         => $e->entite_id,
                    'succursale_id'     => $e->succursale_id,
                    'operation_journal_id' => $e->operation_journal_id,
                ];
            }

            // ✅ Calcul du solde par compte selon la position TFR
            foreach ($soldesParCompte as $compte => &$info) {
                $positionTfr = $info['position_tfr'] ?? null;
                if ($positionTfr === 'produit') {
                    $info['solde'] = round($info['credit'] - $info['debit'], 4);
                } elseif ($positionTfr === 'charge') {
                    $info['solde'] = round($info['debit'] - $info['credit'], 4);
                } else {
                    // Fallback : dépend du type de section
                    $info['solde'] = $estRevenu
                        ? round($info['credit'] - $info['debit'], 4)
                        : round($info['debit'] - $info['credit'], 4);
                }
            }
            unset($info);

            // ✅ Total réalisé = somme des soldes positifs de tous les comptes
            $realise = 0.0;
            foreach ($soldesParCompte as $info) {
                $realise += $info['solde'];
            }
            $realise = round(abs($realise), 4);

            // ✅ Prévision validée pour ce mois
            $prevision = null;
            if ($mois) {
                $prevModel = BudgetPrevision::where('rubrique_id', $rubriqueId)
                    ->where('periode_id', $periodeId)
                    ->where('mois', (int) $mois)
                    ->where('statut', BudgetPrevision::STATUT_VALIDE)
                    ->first();
                if ($prevModel) {
                    $prevision = [
                        'id'      => $prevModel->id,
                        'montant' => round((float) $prevModel->montant, 4),
                        'mois'    => (int) $prevModel->mois,
                        'statut'  => $prevModel->statut,
                    ];
                }
            }

            return [
                'success' => true,
                'data'    => [
                    'rubrique'          => $this->formatRubrique($rubrique),
                    'periode'           => $this->formatPeriode($periode),
                    'mois'              => $mois,
                    'prevision'         => $prevision,
                    'comptes_racines'   => array_values($comptesRacines),
                    'ecritures'         => $ecrituresFormatees,
                    'soldes_par_compte' => array_values($soldesParCompte),
                    'total'             => [
                        'debit'   => round($totalDebit, 4),
                        'credit'  => round($totalCredit, 4),
                        'solde'   => round($totalDebit - $totalCredit, 4),
                        'realise' => $realise,
                    ],
                ],
            ];

        } catch (\Exception $e) {
            Log::error('Erreur détail rubrique budgétaire', [
                'rubrique_id' => $rubriqueId,
                'periode_id'  => $periodeId,
                'mois'        => $mois,
                'message'     => $e->getMessage(),
                'trace'       => $e->getTraceAsString(),
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * ✅ Vérification de cohérence : total réalisé doit correspondre au calcul budgétaire
     */
    public function verifierCoherence(int $rubriqueId, int $periodeId, ?int $mois = null): array
    {
        $detail = $this->detailRubrique($rubriqueId, $periodeId, $mois);

        if (!$detail['success']) {
            return $detail;
        }

        $realise = $detail['data']['total']['realise'];

        return [
            'success'          => true,
            'realise_depuis_ecritures' => $realise,
            'comptes'          => $detail['data']['soldes_par_compte'],
            'ecritures_count'  => count($detail['data']['ecritures']),
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

    private function formatRubrique(BudgetRubrique $rubrique): array
    {
        return [
            'id'              => $rubrique->id,
            'code'            => $rubrique->code,
            'libelle'         => $rubrique->libelle,
            'comptes_racines' => $rubrique->comptes_racines,
            'section_id'      => $rubrique->section_id,
            'section_libelle' => $rubrique->section->libelle ?? null,
            'section_type'    => $rubrique->section->type_section ?? null,
        ];
    }

    private function formatPeriode(PeriodesComptable $periode): array
    {
        return [
            'id'         => $periode->id,
            'code'       => $periode->code,
            'libelle'    => $periode->libelle,
            'date_debut' => $periode->date_debut instanceof Carbon
                ? $periode->date_debut->format('Y-m-d')
                : $periode->date_debut,
            'date_fin'   => $periode->date_fin instanceof Carbon
                ? $periode->date_fin->format('Y-m-d')
                : $periode->date_fin,
            'is_cloture' => $periode->is_cloture,
        ];
    }
}