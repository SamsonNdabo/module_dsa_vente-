<?php

namespace App\Http\Controllers\Comptabilite;

use App\Models\PlanComptable;
use App\Models\CompteClass;
use App\Models\Entite;
use App\Models\EcrituresComptable;
use App\Models\PeriodesComptable;
use App\Services\BilanService;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Inertia\Inertia;

use App\Http\Controllers\Controller;

class DashboardController extends Controller
{
    const DATE_COLUMN = 'date_ecriture';
    const DECIMALES = 4;

    protected $bilanService;

    public function __construct(BilanService $bilanService)
    {
        $this->bilanService = $bilanService;
    }

    public function index()
    {
        $now = Carbon::now();

        $classes = CompteClass::orderBy('numero', 'asc')->get();
        $comptes = PlanComptable::with('compte_class')->orderBy('numero', 'asc')->get();
        $comptesParClasse = $this->grouperComptesParClasse($comptes);
        $entites = Entite::orderBy('code', 'asc')->get();

        $periodeEnCours = $this->getPeriodeEnCours($now);
        $periodes = PeriodesComptable::orderBy('date_debut', 'desc')->limit(12)->get();

        $dateDebut = $now->copy()->startOfMonth()->toDateString();
        $dateFin = $now->toDateString();

        $dashboardData = $this->collectDashboardData($periodeEnCours, $dateDebut, $dateFin);

        return Inertia::render('Dashboard', array_merge([
            'classes'            => $classes,
            'comptesParClasse'   => $comptesParClasse,
            'entites'            => $entites,
            'periodeEnCours'     => $periodeEnCours,
            'periodes'           => $periodes,
            'dateDebut'          => $dateDebut,
            'dateFin'            => $dateFin,
            'dernieresEcritures' => $this->getDernieresEcritures(),
        ], $dashboardData));
    }

    public function getDashboardData()
    {
        $now = Carbon::now();
        $periodeEnCours = $this->getPeriodeEnCours($now);

        $dateDebut = request()->query('date_debut', $now->copy()->startOfMonth()->toDateString());
        $dateFin = request()->query('date_fin', $now->toDateString());

        if ($dateDebut > $dateFin) {
            [$dateDebut, $dateFin] = [$dateFin, $dateDebut];
        }

        $payload = array_merge(
            ['success' => true],
            $this->collectDashboardData($periodeEnCours, $dateDebut, $dateFin),
            [
                'dernieres_ecritures' => $this->getDernieresEcritures(),
                'periode_en_cours'    => $periodeEnCours,
                'date_debut'          => $dateDebut,
                'date_fin'            => $dateFin,
            ]
        );

        return response()->json($payload, 200, [], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function getPeriodeEnCours(Carbon $now): ?PeriodesComptable
    {
        return PeriodesComptable::where('date_debut', '<=', $now)
            ->where('date_fin', '>=', $now)
            ->where('is_cloture', false)
            ->orderBy('date_debut', 'asc')
            ->first() ?? PeriodesComptable::orderBy('date_debut', 'desc')->first();
    }

    private function collectDashboardData(?PeriodesComptable $periode, string $dateDebut, string $dateFin): array
    {
        $params = [
            'periode_id' => $periode ? $periode->id : null,
            'date_fin' => $dateFin,
            'use_custom_dates' => true,
            'filter_type' => 'all'
        ];

        $bilanData = $this->bilanService->calculerBilanFiltre($params);

        $resultatPeriode = $this->calculerResultatPeriode($dateDebut, $dateFin, $periode);

        $classification = $this->classifierDepuisBilan($bilanData);

        return [
            'bilan_journalier'    => $this->getSyntheseMouvements($dateDebut, $dateFin),
            'kpis'                => $this->getKpis($classification),
            'bilan_equilibre'     => $this->getBilanEquilibre($classification, $resultatPeriode),
            'resultat_periode'    => $resultatPeriode,
            'evolution_mensuelle' => $this->getEvolutionMensuelle(),
            'repartition_passif'  => $this->getRepartitionPassif($classification),
            'flux_tresorerie'     => $this->getFluxTresorerieMensuel($dateDebut, $dateFin),
            'ratios'              => $this->getRatios($classification),
        ];
    }

    private function calculerResultatPeriode(string $dateDebut, string $dateFin, ?PeriodesComptable $periode = null): array
    {
        $comptesCharges = PlanComptable::where('numero', 'LIKE', '6%')
            ->whereNotNull('position_tfr')
            ->pluck('numero');

        $comptesProduits = PlanComptable::where('numero', 'LIKE', '7%')
            ->whereNotNull('position_tfr')
            ->pluck('numero');

        $charges = round((float)EcrituresComptable::whereIn('compte_numero', $comptesCharges)
            ->whereBetween(self::DATE_COLUMN, [$dateDebut, $dateFin])
            ->sum(DB::raw('debit - credit')), self::DECIMALES);

        $produits = round((float)EcrituresComptable::whereIn('compte_numero', $comptesProduits)
            ->whereBetween(self::DATE_COLUMN, [$dateDebut, $dateFin])
            ->sum(DB::raw('credit - debit')), self::DECIMALES);

        $resultat = round($produits - $charges, self::DECIMALES);

        return [
            'total_produits' => $produits,
            'total_charges'  => $charges,
            'resultat'       => $resultat,
            'type'           => $resultat >= 0 ? 'BENEFICE' : 'PERTE',
            'periode'        => $periode ? $periode->libelle : 'Période personnalisée',
        ];
    }

    /**
     * ✅ CLASSIFICATION AVEC DÉTAIL DES COMPTES
     */
    private function classifierDepuisBilan(array $bilanData): array
    {
        $classification = [
            'immobilisations' => 0.0,
            'immobilisations_incorporelles' => 0.0,
            'immobilisations_corporelles' => 0.0,
            'immobilisations_financieres' => 0.0,
            'stocks' => 0.0,
            'creances' => 0.0,
            'creances_clients' => 0.0,
            'autres_creances' => 0.0,
            'banques' => 0.0,
            'caisses' => 0.0,
            'tresorerie' => 0.0,

            'capitaux_propres' => 0.0,
            'dettes_long_terme' => 0.0,
            'dettes' => 0.0,
            'dettes_fournisseurs' => 0.0,
            'autres_dettes' => 0.0,
            'decouverts' => 0.0,
            'total_actif' => 0.0,
            'total_passif' => 0.0,

            'detail_creances' => [],
            'detail_dettes' => [],
            'detail_tresorerie' => [],
            'detail_immobilisations' => [],
        ];

        if (isset($bilanData['actif']['detail'])) {
            foreach ($bilanData['actif']['detail'] as $item) {
                $numero = $item['numero'];
                $solde = round((float)$item['solde_net'], self::DECIMALES);
                $classe = substr($numero, 0, 1);
                $nom = $item['nom'] ?? 'Compte ' . $numero;

                if ($classe === '2') {
                    $classification['immobilisations'] = round($classification['immobilisations'] + $solde, self::DECIMALES);
                    $classification['detail_immobilisations'][] = ['numero' => $numero, 'nom' => $nom, 'montant' => $solde];

                    $prefixe2 = substr($numero, 0, 2);
                    if (in_array($prefixe2, ['20', '21'])) {
                        $classification['immobilisations_incorporelles'] = round($classification['immobilisations_incorporelles'] + $solde, self::DECIMALES);
                    } elseif (in_array($prefixe2, ['22', '23', '24', '25'])) {
                        $classification['immobilisations_corporelles'] = round($classification['immobilisations_corporelles'] + $solde, self::DECIMALES);
                    } elseif (in_array($prefixe2, ['26', '27'])) {
                        $classification['immobilisations_financieres'] = round($classification['immobilisations_financieres'] + $solde, self::DECIMALES);
                    }
                } elseif ($classe === '3') {
                    $classification['stocks'] = round($classification['stocks'] + $solde, self::DECIMALES);
                } elseif ($classe === '4') {
                    if ($solde > 0) {
                        $classification['creances'] = round($classification['creances'] + $solde, self::DECIMALES);
                        $classification['detail_creances'][] = ['numero' => $numero, 'nom' => $nom, 'montant' => $solde];

                        if (strpos($numero, '411') === 0 || strpos($numero, '412') === 0) {
                            $classification['creances_clients'] = round($classification['creances_clients'] + $solde, self::DECIMALES);
                        } else {
                            $classification['autres_creances'] = round($classification['autres_creances'] + $solde, self::DECIMALES);
                        }
                    }
                } elseif ($classe === '5') {
                    if ($solde > 0) {
                        $classification['tresorerie'] = round($classification['tresorerie'] + $solde, self::DECIMALES);
                        $classification['detail_tresorerie'][] = ['numero' => $numero, 'nom' => $nom, 'montant' => $solde];

                        if (strpos($numero, '52') === 0 || strpos($numero, '53') === 0) {
                            $classification['banques'] = round($classification['banques'] + $solde, self::DECIMALES);
                        } elseif (strpos($numero, '57') === 0 || strpos($numero, '58') === 0) {
                            $classification['caisses'] = round($classification['caisses'] + $solde, self::DECIMALES);
                        }
                    }
                }
            }
        }

        if (isset($bilanData['passif']['detail'])) {
            foreach ($bilanData['passif']['detail'] as $item) {
                $numero = $item['numero'];
                $solde = round((float)$item['solde_net'], self::DECIMALES);
                $classe = substr($numero, 0, 1);
                $nom = $item['nom'] ?? 'Compte ' . $numero;

                if (in_array($numero, ['131', '139', '121', '129'])) {
                    continue;
                }

                if ($classe === '1') {
                    $prefixe1 = substr($numero, 0, 2);
                    if (in_array($prefixe1, ['10', '11', '12', '13', '14', '15'])) {
                        $classification['capitaux_propres'] = round($classification['capitaux_propres'] + $solde, self::DECIMALES);
                    } else {
                        $classification['dettes_long_terme'] = round($classification['dettes_long_terme'] + $solde, self::DECIMALES);
                    }
                } elseif ($classe === '4') {
                    if ($solde > 0) {
                        $classification['dettes'] = round($classification['dettes'] + $solde, self::DECIMALES);
                        $classification['detail_dettes'][] = ['numero' => $numero, 'nom' => $nom, 'montant' => $solde];

                        if (strpos($numero, '401') === 0 || strpos($numero, '402') === 0) {
                            $classification['dettes_fournisseurs'] = round($classification['dettes_fournisseurs'] + $solde, self::DECIMALES);
                        } else {
                            $classification['autres_dettes'] = round($classification['autres_dettes'] + $solde, self::DECIMALES);
                        }
                    }
                } elseif ($classe === '5') {
                    if ($solde > 0) {
                        $classification['decouverts'] = round($classification['decouverts'] + $solde, self::DECIMALES);
                    }
                }
            }
        }

        $classification['total_actif'] = round((float)($bilanData['actif']['solde'] ?? 0), self::DECIMALES);
        $classification['total_passif'] = round((float)($bilanData['passif']['solde'] ?? 0), self::DECIMALES);

        return $classification;
    }

    /**
     * ═══════════════════════════════════════════════════════════════════
     *  🔥 SYNTHÈSE DES MOUVEMENTS
     *  ✅ "nb_ecritures" = nombre d'ÉCRITURES DISTINCTES (pas de lignes)
     *  ✅ "nb_lignes"    = nombre total de lignes (info secondaire)
     *  ✅ Les totaux débit/crédit restent inchangés (ce sont des sommes)
     * ═══════════════════════════════════════════════════════════════════
     */
    private function getSyntheseMouvements(string $dateDebut, string $dateFin): array
    {
        // ─── Statistiques sur les LIGNES (pour les totaux débit/crédit) ───
        $stats = EcrituresComptable::whereBetween(self::DATE_COLUMN, [$dateDebut, $dateFin])
            ->selectRaw('
                COUNT(*) as nb_lignes,
                COALESCE(SUM(debit), 0) as total_debit,
                COALESCE(SUM(credit), 0) as total_credit
            ')
            ->first();

        // ─── Nombre d'ÉCRITURES DISTINCTES ───
        // Une écriture = plusieurs lignes qui partagent le même "numero_ecriture".
        // On compte donc les "numero_ecriture" distincts non NULL.
        // Si "numero_ecriture" est NULL pour certaines lignes, on se rabat
        // sur "reference" puis sur "id" pour ne pas les perdre.
        $nbEcritures = (int) EcrituresComptable::whereBetween(self::DATE_COLUMN, [$dateDebut, $dateFin])
            ->selectRaw("
                COUNT(DISTINCT COALESCE(numero_ecriture, reference, CONCAT('id-', id)))
            ")
            ->value(DB::raw("
                COUNT(DISTINCT COALESCE(numero_ecriture, reference, CONCAT('id-', id)))
            "));

        // Fallback si la requête ci-dessus retourne null (au cas où)
        if ($nbEcritures === 0 && ($stats->nb_lignes ?? 0) > 0) {
            $nbEcritures = (int) EcrituresComptable::whereBetween(self::DATE_COLUMN, [$dateDebut, $dateFin])
                ->distinct('numero_ecriture')
                ->count('numero_ecriture');
        }

        $debit  = round((float)($stats->total_debit  ?? 0), self::DECIMALES);
        $credit = round((float)($stats->total_credit ?? 0), self::DECIMALES);

        return [
            'date_debut'    => $dateDebut,
            'date_fin'      => $dateFin,
            'nb_ecritures'  => $nbEcritures,                  // ✅ Nouveau : écritures distinctes
            'nb_lignes'     => (int)($stats->nb_lignes ?? 0), // ✅ Info secondaire : lignes brutes
            'total_debit'   => $debit,
            'total_credit'  => $credit,
            'ecart'         => round($debit - $credit, self::DECIMALES),
            'equilibre'     => abs($debit - $credit) < 0.0001,
        ];
    }

    private function getKpis(array $classification): array
    {
        return [
            'immobilisations' => [
                'total'         => round($classification['immobilisations'], self::DECIMALES),
                'incorporelles' => round($classification['immobilisations_incorporelles'], self::DECIMALES),
                'corporelles'   => round($classification['immobilisations_corporelles'], self::DECIMALES),
                'financieres'   => round($classification['immobilisations_financieres'], self::DECIMALES),
                'detail'        => $classification['detail_immobilisations'],
            ],
            'liquidites' => [
                'total'  => round($classification['tresorerie'], self::DECIMALES),
                'banque' => round($classification['banques'], self::DECIMALES),
                'caisse' => round($classification['caisses'], self::DECIMALES),
                'detail' => $classification['detail_tresorerie'],
            ],
            'creances' => [
                'total'   => round($classification['creances'], self::DECIMALES),
                'clients' => round($classification['creances_clients'], self::DECIMALES),
                'autres'  => round($classification['autres_creances'], self::DECIMALES),
                'detail'  => $classification['detail_creances'],
            ],
            'dettes' => [
                'total'        => round($classification['dettes'], self::DECIMALES),
                'fournisseurs' => round($classification['dettes_fournisseurs'], self::DECIMALES),
                'autres'       => round($classification['autres_dettes'], self::DECIMALES),
                'detail'       => $classification['detail_dettes'],
            ],
        ];
    }

    private function getBilanEquilibre(array $classification, array $resultatPeriode): array
    {
        $totalActif = round($classification['total_actif'], self::DECIMALES);
        $totalPassif = round($classification['total_passif'], self::DECIMALES);
        $ecart = round($totalActif - $totalPassif, self::DECIMALES);

        return [
            'actif' => [
                'immobilise' => round($classification['immobilisations'], self::DECIMALES),
                'stocks'     => round($classification['stocks'], self::DECIMALES),
                'creances'   => round($classification['creances'], self::DECIMALES),
                'liquidites' => round($classification['tresorerie'], self::DECIMALES),
                'total'      => $totalActif,
            ],
            'passif' => [
                'capitaux_propres' => round($classification['capitaux_propres'], self::DECIMALES),
                'dettes_lt'        => round($classification['dettes_long_terme'], self::DECIMALES),
                'dettes_ct'        => round($classification['dettes'] + $classification['decouverts'], self::DECIMALES),
                'total'            => $totalPassif,
            ],
            'ecart'     => $ecart,
            'equilibre' => abs($ecart) < 0.0001,
        ];
    }

    private function getEvolutionMensuelle(): array
    {
        $start = Carbon::now()->subMonths(11)->startOfMonth();
        $end = Carbon::now()->endOfMonth();

        $comptesCharges = PlanComptable::where('numero', 'LIKE', '6%')
            ->whereNotNull('position_tfr')
            ->pluck('numero');
        $comptesProduits = PlanComptable::where('numero', 'LIKE', '7%')
            ->whereNotNull('position_tfr')
            ->pluck('numero');

        $movements = EcrituresComptable::whereBetween(self::DATE_COLUMN, [$start, $end])
            ->where(function ($q) use ($comptesCharges, $comptesProduits) {
                $q->whereIn('compte_numero', $comptesCharges)
                  ->orWhereIn('compte_numero', $comptesProduits);
            })
            ->selectRaw("
                DATE_FORMAT(" . self::DATE_COLUMN . ", '%Y-%m') as mois,
                SUM(CASE WHEN compte_numero IN (" . $comptesCharges->implode(',') . ") THEN debit - credit ELSE 0 END) as total_charges,
                SUM(CASE WHEN compte_numero IN (" . $comptesProduits->implode(',') . ") THEN credit - debit ELSE 0 END) as total_produits
            ")
            ->groupBy('mois')
            ->get()
            ->keyBy('mois');

        $evolution = [];
        for ($i = 11; $i >= 0; $i--) {
            $date = Carbon::now()->subMonths($i);
            $key = $date->format('Y-m');

            $c = round((float)($movements[$key]->total_charges ?? 0), self::DECIMALES);
            $p = round((float)($movements[$key]->total_produits ?? 0), self::DECIMALES);

            $evolution[] = [
                'mois'       => $date->translatedFormat('M Y'),
                'mois_short' => $date->format('M'),
                'charges'    => $c,
                'produits'   => $p,
                'resultat'   => round($p - $c, self::DECIMALES),
            ];
        }

        return $evolution;
    }

    private function getRepartitionPassif(array $classification): array
    {
        $capitaux = round($classification['capitaux_propres'], self::DECIMALES);
        $fournisseurs = round($classification['dettes_fournisseurs'], self::DECIMALES);
        $autres = round($classification['autres_dettes'] + $classification['dettes_long_terme'], self::DECIMALES);
        $total = round($capitaux + $fournisseurs + $autres, self::DECIMALES);

        if ($total == 0) {
            return ['capitaux_propres' => 0, 'dettes_fournisseurs' => 0, 'autres_dettes' => 0, 'total' => 0];
        }

        return [
            'capitaux_propres'     => $capitaux,
            'dettes_fournisseurs' => $fournisseurs,
            'autres_dettes'        => $autres,
            'total'                => $total,
        ];
    }

    private function getFluxTresorerieMensuel(string $dateDebut, string $dateFin): array
    {
        $comptesTresorerie = PlanComptable::where('numero', 'LIKE', '5%')
            ->where('position_bilan', 'actif')
            ->pluck('numero');

        if ($comptesTresorerie->isEmpty()) {
            return [];
        }

        return EcrituresComptable::whereBetween(self::DATE_COLUMN, [$dateDebut, $dateFin])
            ->whereIn('compte_numero', $comptesTresorerie)
            ->selectRaw("
                DATE_FORMAT(" . self::DATE_COLUMN . ", '%Y-%m') as mois,
                DATE_FORMAT(" . self::DATE_COLUMN . ", '%b %Y') as mois_libelle,
                SUM(debit) as entrees,
                SUM(credit) as sorties
            ")
            ->groupBy('mois', 'mois_libelle')
            ->orderBy('mois', 'asc')
            ->get()
            ->map(fn($item) => [
                'mois'    => $item->mois_libelle,
                'entrees' => round((float)$item->entrees, self::DECIMALES),
                'sorties' => round((float)$item->sorties, self::DECIMALES),
                'solde'   => round((float)($item->entrees - $item->sorties), self::DECIMALES),
            ])->toArray();
    }

    private function getRatios(array $classification): array
    {
        $tresorerieNette = round($classification['tresorerie'] - $classification['decouverts'], self::DECIMALES);

        $liquiditeGenerale = $classification['dettes'] > 0
            ? round(($classification['stocks'] + $classification['creances'] + $classification['tresorerie']) / $classification['dettes'], self::DECIMALES)
            : null;

        $frng = round(($classification['capitaux_propres'] + $classification['dettes_long_terme']) - $classification['immobilisations'], self::DECIMALES);

        return [
            'tresorerie_nette'     => $tresorerieNette,
            'liquidite_generale'   => $liquiditeGenerale,
            'creances_a_recouvrer' => round($classification['creances'], self::DECIMALES),
            'frng'                 => $frng,
        ];
    }

    private function getDernieresEcritures(): array
    {
        return EcrituresComptable::with(['plan_comptable', 'entite'])
            ->latest('created_at')
            ->limit(10)
            ->get()
            ->map(fn($e) => [
                'id'      => $e->id,
                'date'    => $e->date_ecriture instanceof Carbon ? $e->date_ecriture->format('d/m/Y') : $e->date_ecriture,
                'compte'  => $e->compte_numero,
                'libelle' => $e->libelle,
                'debit'   => round((float)$e->debit, self::DECIMALES),
                'credit'  => round((float)$e->credit, self::DECIMALES),
                'entite'  => $e->entite->nom ?? 'N/A',
            ])->toArray();
    }

    private function grouperComptesParClasse($comptes): array
    {
        $grouped = [];
        foreach ($comptes as $c) {
            if ($c->compte_class) {
                $k = $c->compte_class->id;
                if (!isset($grouped[$k])) {
                    $grouped[$k] = ['classe' => $c->compte_class, 'comptes' => []];
                }
                $grouped[$k]['comptes'][] = $c;
            }
        }
        return $grouped;
    }
}