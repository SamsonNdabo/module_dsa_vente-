<?php

namespace App\Services\BudgetComptable;

use App\Models\BudgetSection;
use App\Models\BudgetRubrique;
use App\Models\BudgetPrevision;
use App\Models\PeriodesComptable;
use Illuminate\Support\Facades\Log;

class BudgetAlertService
{
    /**
     *  Service de calcul principal
     *    → C'est LUI qui fait TOUS les calculs
     *    → BudgetAlertService NE RECALCULE RIEN
     */
    private $calculService;

    public function __construct()
    {
        $this->calculService = new BudgetCalculService();
    }

    // =========================================================================
    // DÉTECTION TYPE SECTION
    // =========================================================================

    /**
     *  Détecte si une section est de type REVENU ou CHARGE
     */
    private function detecterTypeSection($libelleSection): string
    {
        $lib = mb_strtolower(trim($libelleSection ?? ''), 'UTF-8');

        // Mots-clés REVENUS
        $motsRevenu = [
            'revenu', 'produit', 'recette', 'vente', 'encaissement',
            'commission', 'apport', 'financement', 'subvention', 'don',
        ];
        foreach ($motsRevenu as $mot) {
            if (str_contains($lib, $mot)) {
                return 'revenu';
            }
        }

        // Mots-clés CHARGES
        $motsCharge = [
            'charge', 'depense', 'dépense', 'achat', 'frais',
            'sortie', 'decaissement', 'décaissement',
        ];
        foreach ($motsCharge as $mot) {
            if (str_contains($lib, $mot)) {
                return 'charge';
            }
        }

        Log::warning('BudgetAlertService: Type section non détecté, fallback = charge', [
            'libelle_section' => $libelleSection,
        ]);

        return 'charge';
    }

    // =========================================================================
    // STATISTIQUES PRINCIPALES
    // =========================================================================

    /**
     *  Analyse les statistiques pour le dashboard
     *
     *  ⚠️ IMPORTANT :
     *  ─────────────────────────────────────────────────────────
     *  Ce service N'EFFECTUE AUCUN CALCUL COMPTABLE.
     *  Il utilise UNIQUEMENT les montants retournés par
     *  `BudgetCalculService::calculer()`.
     *
     *  → Garantit la cohérence avec le tableau de suivi
     *  → Garantit la cohérence avec le Grand Livre
     *  ─────────────────────────────────────────────────────────
     */
    public function getStatistiques($periodeId, $entiteId = null): array
    {
        $periode = $this->findPeriode($periodeId);
        if (!$periode) {
            Log::warning('BudgetAlertService: Période non trouvée', ['periode_id' => $periodeId]);
            return $this->getStatistiquesVides($periodeId);
        }

        //  APPEL UNIQUE au service de calcul
        $result = $this->calculService->calculer($periodeId, $entiteId, 'annuel', null);

        if (!$result['success'] || empty($result['data']['lignes'])) {
            Log::warning('BudgetAlertService: Aucune ligne calculée', [
                'periode_id' => $periodeId,
                'success' => $result['success'] ?? false,
            ]);
            return $this->getStatistiquesVides($periodeId);
        }

        $lignes = $result['data']['lignes'];

        // ============================================================
        // AGRÉGATION DES MONTANTS (SANS RECALCUL)
        // On lit UNIQUEMENT `total.prevision` et `total.realisation`
        // déjà calculés par BudgetCalculService
        // ============================================================

        $totalChargesPrevu = 0.0;
        $totalChargesRealise = 0.0;
        $totalRevenusPrevu = 0.0;
        $totalRevenusRealise = 0.0;

        $rubriquesStats = [];
        $sectionsStats = [];
        $moisActuel = (int) date('m');

        $sectionCourante = null;
        $sectionType = 'charge';
        $sectionRealiseTotal = 0.0;
        $sectionPrevuTotal = 0.0;
        $sectionRubriques = [];

        foreach ($lignes as $ligne) {
            //  Nouvelle section
            if ($ligne['type'] === 'section') {
                if ($sectionCourante !== null && !empty($sectionRubriques)) {
                    $sectionsStats[] = [
                        'libelle' => $sectionCourante,
                        'type' => $sectionType,
                        'prevu_total' => round($sectionPrevuTotal, 4),
                        'realise_total' => round($sectionRealiseTotal, 4),
                        'rubriques' => $sectionRubriques,
                    ];
                }
                $sectionCourante = $ligne['libelle'];
                $sectionType = $this->detecterTypeSection($ligne['libelle']);
                $sectionRealiseTotal = 0.0;
                $sectionPrevuTotal = 0.0;
                $sectionRubriques = [];
                continue;
            }

            //  Ignorer les parents (déjà comptés via enfants)
            if ($ligne['type'] === 'parent') continue;

            //  LIRE les montants DÉJÀ CALCULÉS par BudgetCalculService
            //    NE PAS RECALCULER à partir des écritures
            $totalPrevu = abs($ligne['total']['prevision'] ?? 0);
            $totalRealise = abs($ligne['total']['realisation'] ?? 0);
            $ecart = $totalPrevu - $totalRealise;
            $pourcentage = $totalPrevu > 0
                ? round(($totalRealise / $totalPrevu) * 100, 1)
                : ($totalRealise > 0 ? 100 : 0);

            //  Ventilation charges / revenus
            if ($sectionType === 'revenu') {
                $totalRevenusPrevu += $totalPrevu;
                $totalRevenusRealise += $totalRealise;
            } else {
                $totalChargesPrevu += $totalPrevu;
                $totalChargesRealise += $totalRealise;
            }

            $rubrique = [
                'code' => $ligne['code'] ?? '',
                'libelle' => $ligne['libelle'] ?? '',
                'section_type' => $sectionType,
                'section_libelle' => $sectionCourante,
                'prevu' => round($totalPrevu, 4),
                'realise' => round($totalRealise, 4),
                'ecart' => round($ecart, 4),
                'pourcentage' => $pourcentage,
            ];

            $rubriquesStats[] = $rubrique;
            $sectionRubriques[] = $rubrique;
            $sectionRealiseTotal += $totalRealise;
            $sectionPrevuTotal += $totalPrevu;
        }

        //  Sauvegarder la dernière section
        if ($sectionCourante !== null && !empty($sectionRubriques)) {
            $sectionsStats[] = [
                'libelle' => $sectionCourante,
                'type' => $sectionType,
                'prevu_total' => round($sectionPrevuTotal, 4),
                'realise_total' => round($sectionRealiseTotal, 4),
                'rubriques' => $sectionRubriques,
            ];
        }

        Log::info('BudgetAlertService - Totaux finaux (déjà calculés par CalculService)', [
            'total_charges_prevu' => $totalChargesPrevu,
            'total_charges_realise' => $totalChargesRealise,
            'total_revenus_prevu' => $totalRevenusPrevu,
            'total_revenus_realise' => $totalRevenusRealise,
            'nb_rubriques_stats' => count($rubriquesStats),
        ]);

        $kpi = $this->getKpiCards(
            $totalChargesPrevu, $totalChargesRealise,
            $totalRevenusPrevu, $totalRevenusRealise,
            $moisActuel
        );

        $alertes = $this->getAlertes($rubriquesStats);
        $resultatNet = $this->getResultatNet($totalRevenusRealise, $totalChargesRealise);

        return [
            'periode' => [
                'id' => $periode->id,
                'code' => $periode->code,
                'libelle' => $periode->libelle,
                'is_cloture' => in_array($periode->statut, ['cloture_provisoire', 'cloture_definitive']),
            ],
            'mois_actuel' => $moisActuel,
            'top5' => $this->getTop5($rubriquesStats, $sectionsStats),
            'kpi' => $kpi,
            'alertes' => $alertes,
            'nb_alertes_rouges' => count(array_filter($alertes, fn($a) => ($a['niveau'] ?? '') === 'rouge')),
            'nb_alertes_oranges' => count(array_filter($alertes, fn($a) => ($a['niveau'] ?? '') === 'orange')),
            'nb_alertes_vertes' => count(array_filter($alertes, fn($a) => ($a['niveau'] ?? '') === 'vert')),
            'resultat_net' => $resultatNet,
            'ecarts' => $this->getEcarts($rubriquesStats),
            'evolution_mensuelle' => $this->getEvolution($lignes),
            'resume' => $this->getResume($rubriquesStats, $sectionsStats),
        ];
    }

    // =========================================================================
    // STRUCTURES VIDES
    // =========================================================================

    private function getStatistiquesVides($periodeId = null): array
    {
        $periode = $periodeId ? PeriodesComptable::find($periodeId) : null;

        return [
            'periode' => $periode ? [
                'id' => $periode->id,
                'code' => $periode->code,
                'libelle' => $periode->libelle,
                'is_cloture' => in_array($periode->statut ?? '', ['cloture_provisoire', 'cloture_definitive']),
            ] : null,
            'mois_actuel' => (int) date('m'),
            'top5' => [],
            'kpi' => $this->getKpiCards(0, 0, 0, 0, (int) date('m')),
            'alertes' => [],
            'nb_alertes_rouges' => 0,
            'nb_alertes_oranges' => 0,
            'nb_alertes_vertes' => 0,
            'resultat_net' => [
                'montant' => 0,
                'statut' => 'beneficiaire',
                'revenus_total' => 0,
                'charges_total' => 0,
            ],
            'ecarts' => [
                'favorables' => [],
                'defavorables' => [],
            ],
            'evolution_mensuelle' => $this->getEvolutionVide(),
            'resume' => [
                'nb_rubriques' => 0,
                'nb_sections' => 0,
                'total_prevu' => 0,
                'total_realise' => 0,
                'taux_realisation' => 0,
            ],
        ];
    }

    private function getEvolutionVide(): array
    {
        $evolution = [];
        for ($m = 1; $m <= 12; $m++) {
            $evolution[] = [
                'mois' => $m,
                'prevu_mensuel' => 0,
                'realise_mensuel' => 0,
                'prevu_cumul' => 0,
                'realise_cumul' => 0,
                'ecart_cumul' => 0,
            ];
        }
        return $evolution;
    }

    private function findPeriode($periodeId)
    {
        if (!empty($periodeId)) {
            return PeriodesComptable::find((int) $periodeId);
        }

        return PeriodesComptable::where('statut', 'ouverte')
            ->orderBy('date_debut', 'asc')
            ->first();
    }

    // =========================================================================
    // CALCUL DES STATS (uniquement à partir des montants déjà calculés)
    // =========================================================================

    /**
     *  Top 5 rubriques
     *    Utilise uniquement `realise` déjà calculé
     */
    private function getTop5($rubriquesStats, $sectionsStats): array
    {
        $top5 = $rubriquesStats;

        usort($top5, fn($a, $b) => $b['realise'] <=> $a['realise']);

        return array_slice($top5, 0, 5);
    }

    /**
     *  KPI Cards
     *    Utilise uniquement les totaux déjà calculés
     */
    private function getKpiCards($totalChargesPrevu, $totalChargesRealise, $totalRevenusPrevu, $totalRevenusRealise, $moisActuel): array
    {
        $tauxCharges = $totalChargesPrevu > 0
            ? round(($totalChargesRealise / $totalChargesPrevu) * 100, 1)
            : ($totalChargesRealise > 0 ? 100 : 0);

        $tauxRevenus = $totalRevenusPrevu > 0
            ? round(($totalRevenusRealise / $totalRevenusPrevu) * 100, 1)
            : ($totalRevenusRealise > 0 ? 100 : 0);

        $tauxTemporel = round(($moisActuel / 12) * 100, 1);

        return [
            'charges' => [
                'label' => 'Taux Charges',
                'prevu' => round($totalChargesPrevu, 4),
                'realise' => round($totalChargesRealise, 4),
                'pourcentage' => $tauxCharges,
                'couleur' => $tauxCharges < 90 ? 'green' : ($tauxCharges > 100 ? 'red' : 'orange'),
                'icone' => $tauxCharges < 90 ? '' : ($tauxCharges > 100 ? '🔴' : '🟡'),
            ],
            'revenus' => [
                'label' => 'Taux Revenus',
                'prevu' => round($totalRevenusPrevu, 4),
                'realise' => round($totalRevenusRealise, 4),
                'pourcentage' => $tauxRevenus,
                'couleur' => $tauxRevenus < 80 ? 'red' : ($tauxRevenus >= 100 ? 'green' : 'orange'),
                'icone' => $tauxRevenus < 80 ? '🔴' : ($tauxRevenus >= 100 ? '' : '🟡'),
            ],
            'temporel' => [
                'label' => 'Exécution Temporelle',
                'mois_ecoules' => $moisActuel,
                'mois_total' => 12,
                'pourcentage' => $tauxTemporel,
                'message' => $tauxCharges > $tauxTemporel
                    ? '⚠️ Dépenses plus rapides que le temps'
                    : ' Rythme normal',
            ],
        ];
    }

    /**
     *  Alertes
     *    Utilise uniquement `prevu` et `realise` déjà calculés
     */
    private function getAlertes($rubriquesStats): array
    {
        $alertes = [];

        foreach ($rubriquesStats as $stat) {
            $niveau = null;
            $icone = '';
            $message = '';
            $typeAlerte = '';

            $prevu = abs($stat['prevu']);
            $realise = abs($stat['realise']);
            $ecart = $prevu - $realise;
            $pourcentage = $stat['pourcentage'];

            if ($stat['section_type'] === 'charge') {
                // --- CHARGES ---
                if ($realise > $prevu && $prevu > 0) {
                    $depassement = $realise - $prevu;
                    $niveau = 'rouge';
                    $icone = '🔴';
                    $typeAlerte = 'danger';
                    $message = "Risque de perte ! Dépassement budgétaire de "
                        . number_format($depassement, 2, ',', ' ') . " $ ("
                        . $pourcentage . "% consommé)";
                } elseif ($pourcentage >= 90 && $pourcentage <= 100 && $prevu > 0) {
                    $reste = $prevu - $realise;
                    $niveau = 'orange';
                    $icone = '🟡';
                    $typeAlerte = 'warning';
                    $message = "Attention, seuil critique atteint ("
                        . $pourcentage . "%). Reste disponible : "
                        . number_format(abs($reste), 2, ',', ' ') . " $";
                }
            } else {
                // --- REVENUS ---
                if ($realise >= $prevu && $prevu > 0) {
                    $surplus = $realise - $prevu;
                    $niveau = 'vert';
                    $icone = '🎉';
                    $typeAlerte = 'success';
                    if ($surplus > 0.01) {
                        $message = "Objectif dépassé ! Félicitations pour ce bénéfice supplémentaire de "
                            . number_format($surplus, 2, ',', ' ') . " $ ("
                            . $pourcentage . "% atteint)";
                    } else {
                        $message = "Félicitations ! Objectif de revenus totalement atteint (100%)";
                    }
                } elseif ($pourcentage < 80 && $prevu > 0) {
                    $manque = $prevu - $realise;
                    $niveau = 'rouge';
                    $icone = '🔴';
                    $typeAlerte = 'danger';
                    $message = "Alerte revenus : Sous-performance importante. Retard de "
                        . number_format(abs($manque), 2, ',', ' ') . " $ sur l'objectif";
                } elseif ($pourcentage >= 80 && $pourcentage < 100 && $prevu > 0) {
                    $manque = $prevu - $realise;
                    $niveau = 'orange';
                    $icone = '🟡';
                    $typeAlerte = 'warning';
                    $message = "Objectif en cours : " . $pourcentage
                        . "% réalisé. Reste encore "
                        . number_format(abs($manque), 2, ',', ' ') . " $ à encaisser";
                }
            }

            if ($niveau) {
                $alertes[] = array_merge($stat, [
                    'niveau' => $niveau,
                    'icone' => $icone,
                    'type_alerte' => $typeAlerte,
                    'message' => $message,
                    'prevu' => round($prevu, 4),
                    'realise' => round($realise, 4),
                    'ecart' => round($ecart, 4),
                ]);
            }
        }

        $priorite = ['rouge' => 1, 'orange' => 2, 'vert' => 3];
        usort($alertes, fn($a, $b) => ($priorite[$a['niveau']] ?? 99) <=> ($priorite[$b['niveau']] ?? 99));

        return array_slice($alertes, 0, 15);
    }

    /**
     *  Résultat net
     *    Utilise uniquement les totaux déjà calculés
     */
    private function getResultatNet($totalRevenusRealise, $totalChargesRealise): array
    {
        $montant = $totalRevenusRealise - $totalChargesRealise;

        return [
            'montant' => round($montant, 4),
            'statut' => $montant >= 0 ? 'beneficiaire' : 'deficitaire',
            'revenus_total' => round($totalRevenusRealise, 4),
            'charges_total' => round($totalChargesRealise, 4),
        ];
    }

    /**
     *  Écarts favorables / défavorables
     *    Utilise uniquement `ecart` déjà calculé
     */
    private function getEcarts($rubriquesStats): array
    {
        $favorables = [];
        $defavorables = [];

        foreach ($rubriquesStats as $stat) {
            $prevu = abs($stat['prevu']);
            $realise = abs($stat['realise']);
            $ecart = $prevu - $realise;

            if ($stat['section_type'] === 'charge') {
                // Charge : écart positif = économie (favorable)
                if ($ecart > 0) {
                    $favorables[] = array_merge($stat, ['ecart_montant' => $ecart]);
                } elseif ($ecart < 0) {
                    $defavorables[] = array_merge($stat, ['ecart_montant' => abs($ecart)]);
                }
            } else {
                // Revenu : écart négatif = favorable
                if ($ecart < 0) {
                    $favorables[] = array_merge($stat, ['ecart_montant' => abs($ecart)]);
                } elseif ($ecart > 0) {
                    $defavorables[] = array_merge($stat, ['ecart_montant' => $ecart]);
                }
            }
        }

        usort($favorables, fn($a, $b) => $b['ecart_montant'] <=> $a['ecart_montant']);
        usort($defavorables, fn($a, $b) => $b['ecart_montant'] <=> $a['ecart_montant']);

        return [
            'favorables' => array_slice($favorables, 0, 3),
            'defavorables' => array_slice($defavorables, 0, 3),
        ];
    }

    /**
     *  Évolution mensuelle cumulée
     *    Utilise UNIQUEMENT les tableaux `previsions[m]` et `realisations[m]`
     *    déjà calculés par BudgetCalculService
     */
    private function getEvolution($lignes): array
    {
        $evolution = [];
        $cumulPrevu = 0.0;
        $cumulRealise = 0.0;

        for ($m = 1; $m <= 12; $m++) {
            $prevuMois = 0.0;
            $realiseMois = 0.0;

            foreach ($lignes as $ligne) {
                //  Seuls les enfants (déjà sommés dans le calculService)
                if ($ligne['type'] !== 'enfant') continue;

                //  LIRE les montants déjà calculés
                $prevuMois += abs($ligne['previsions'][$m] ?? 0);
                $realiseMois += abs($ligne['realisations'][$m] ?? 0);
            }

            $cumulPrevu += $prevuMois;
            $cumulRealise += $realiseMois;

            $evolution[] = [
                'mois' => $m,
                'prevu_mensuel' => round($prevuMois, 4),
                'realise_mensuel' => round($realiseMois, 4),
                'prevu_cumul' => round($cumulPrevu, 4),
                'realise_cumul' => round($cumulRealise, 4),
                'ecart_cumul' => round($cumulPrevu - $cumulRealise, 4),
            ];
        }

        return $evolution;
    }

    /**
     *  Résumé global
     *    Utilise uniquement les totaux déjà calculés
     */
    private function getResume($rubriquesStats, $sectionsStats): array
    {
        $totalPrevu = 0.0;
        $totalRealise = 0.0;

        foreach ($rubriquesStats as $stat) {
            $totalPrevu += abs($stat['prevu']);
            $totalRealise += abs($stat['realise']);
        }

        return [
            'nb_rubriques' => count($rubriquesStats),
            'nb_sections' => count($sectionsStats),
            'total_prevu' => round($totalPrevu, 4),
            'total_realise' => round($totalRealise, 4),
            'taux_realisation' => $totalPrevu > 0 ? round(($totalRealise / $totalPrevu) * 100, 1) : 0,
        ];
    }
}