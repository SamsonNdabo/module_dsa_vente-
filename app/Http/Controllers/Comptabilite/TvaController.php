<?php

namespace App\Http\Controllers\Comptabilite;

use App\Models\TvaComptable;
use App\Models\EcrituresComptable;
use App\Models\PeriodesComptable;
use App\Models\Entite;
use App\Events\EcritureComptableChanged;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use App\Http\Controllers\Controller;

class TvaController extends Controller
{
    const COMPTE_TVA_COLLECTEE  = '443';
    const COMPTE_TVA_DEDUCTIBLE = '445';
    const COMPTE_TVA_DUE        = '4441';
    const COMPTE_CREDIT_TVA     = '4449';

    /** Tolérance pour considérer un montant comme nul (arrondi comptable) */
    const TOLERANCE_ZERO = 0.01;

    private static $moisLabels = [
        1 => 'Janvier', 2 => 'Fevrier', 3 => 'Mars', 4 => 'Avril',
        5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Aout',
        9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Decembre'
    ];

    public static function getVariablesForDashboard(): array
    {
        return [
            'entites' => Entite::where('is_active', 1)->orderBy('code', 'asc')->get(),
            'periodes' => PeriodesComptable::orderBy('date_debut', 'desc')->get(),
            'periodeEnCours' => PeriodesComptable::where('is_cloture', false)->orderBy('date_debut', 'asc')->first(),
            'months' => self::$moisLabels,
            'currentMonth' => (int) date('m'),
            'currentYear' => (int) date('Y'),
            'years' => range(2024, (int) date('Y') + 1),
        ];
    }

    public function index()
    {
        return view('sections.tva', self::getVariablesForDashboard());
    }

    private function broadcastTva(string $action, array $data): void
    {
        try {
            broadcast(new EcritureComptableChanged('tva.' . $action, new EcrituresComptable($data)))->toOthers();
        } catch (\Exception $e) {
            Log::warning('Broadcast TVA echoue', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function logContext(array $extra = []): array
    {
        return array_merge([
            'user_id'    => Auth::id(),
            'user_name'  => Auth::user()?->name,
            'ip'         => request()->ip(),
            'user_agent' => request()->userAgent(),
            'timestamp'  => now()->toIso8601String(),
        ], $extra);
    }

    /**
     * ✅ Formate un montant pour les logs (avec $ et signe)
     */
    private function fmt(float $montant): string
    {
        return number_format($montant, 2, ',', ' ') . ' $';
    }

    /**
     * ✅ Construit un bloc de log "AVANT / APRÈS" pour une écriture
     */
    private function logEcritureBilan(string $titre, array $lignes): void
    {
        $totalDebit = 0;
        $totalCredit = 0;
        $detail = [];

        foreach ($lignes as $l) {
            $totalDebit += $l['debit'];
            $totalCredit += $l['credit'];
            $detail[] = sprintf(
                '  %s %-6s | Débit: %12s | Crédit: %12s | %s',
                $l['sens'] === 'D' ? '📤' : '📥',
                $l['compte'],
                $this->fmt($l['debit']),
                $this->fmt($l['credit']),
                $l['libelle']
            );
        }

        $equilibre = abs($totalDebit - $totalCredit) < self::TOLERANCE_ZERO ? '✅ ÉQUILIBRÉ' : '❌ DÉSÉQUILIBRÉ';

        Log::info($titre, $this->logContext([
            'lignes' => $detail,
            'total_debit' => $this->fmt($totalDebit),
            'total_credit' => $this->fmt($totalCredit),
            'equilibre' => $equilibre,
        ]));
    }

    // =========================================================================
    // CALCUL TVA
    // =========================================================================

    public function calculer(Request $request)
    {
        $request->validate([
            'periode_id' => 'nullable|exists:periodes_comptables,id',
            'entite_id' => 'nullable|exists:entites,id',
            'succursale_id' => 'nullable|exists:succursales,id',
            'mois' => 'nullable|integer|min:1|max:12',
            'annee' => 'nullable|integer|min:2000|max:2100',
        ]);

        $entiteId = $request->entite_id;
        $succursaleId = $request->succursale_id;
        $mois = (int)($request->mois ?? date('m'));
        $annee = (int)($request->annee ?? date('Y'));
        $moisFormat = $annee . '-' . str_pad($mois, 2, '0', STR_PAD_LEFT);

        $periode = $request->periode_id ? PeriodesComptable::find($request->periode_id) : null;

        if ($periode) {
            $dateDebut = Carbon::parse($periode->date_debut)->startOfDay();
            $dateFin = Carbon::parse($periode->date_fin)->endOfDay();
            $labelPeriode = $periode->code . ' - ' . $periode->libelle;
        } else {
            $dateDebut = Carbon::create($annee, $mois, 1)->startOfMonth();
            $dateFin = Carbon::create($annee, $mois, 1)->endOfMonth();
            $labelPeriode = self::$moisLabels[$mois] . ' ' . $annee;
        }

        Log::info('Calcul TVA demande', $this->logContext([
            'periode_id'    => $request->periode_id,
            'entite_id'     => $entiteId,
            'succursale_id' => $succursaleId,
            'mois'          => $moisFormat,
            'date_debut'    => $dateDebut->toDateString(),
            'date_fin'      => $dateFin->toDateString(),
        ]));

        $solde443 = $this->getSoldeCompteParDates(self::COMPTE_TVA_COLLECTEE, $dateDebut, $dateFin, $entiteId, $succursaleId);
        $solde445 = $this->getSoldeCompteParDates(self::COMPTE_TVA_DEDUCTIBLE, $dateDebut, $dateFin, $entiteId, $succursaleId);

        $creditAnterieur = $this->getCreditAnterieurParMois($moisFormat, $entiteId, $succursaleId);

        $difference = $solde443 - $solde445 - $creditAnterieur;
        $montant4441 = $difference > 0 ? round($difference, 2) : 0;
        $montant4449 = $difference < 0 ? round(abs($difference), 2) : 0;

        $tvaReg = TvaComptable::where('mois', $moisFormat)
            ->where('entite_id', $entiteId)
            ->where('succursale_id', $succursaleId)
            ->first();

        $entites = [];
        if (!$entiteId) {
            $entites = $this->calculerDetailParEntite($dateDebut, $dateFin, $moisFormat, $succursaleId);
        }

        Log::info('Calcul TVA termine', $this->logContext([
            'mois'                 => $moisFormat,
            'entite_id'            => $entiteId,
            'tva_collectee_443'    => $this->fmt($solde443),
            'tva_deductible_445'   => $this->fmt($solde445),
            'credit_anterieur'     => $this->fmt($creditAnterieur),
            'difference'           => $this->fmt($difference),
            'tva_due_4441'         => $this->fmt($montant4441),
            'credit_tva_4449'      => $this->fmt($montant4449),
            'deja_regularise'      => (bool)($tvaReg && $tvaReg->statut === 'regularise'),
            'nb_entites'           => count($entites),
        ]));

        return response()->json(['success' => true, 'data' => [
            'periode' => $labelPeriode,
            'mois' => $moisFormat,
            'tva_collectee_443' => round($solde443, 2),
            'tva_deductible_445' => round($solde445, 2),
            'credit_tva_anterieur' => round($creditAnterieur, 2),
            'tva_due_4441' => $montant4441,
            'credit_tva_4449' => $montant4449,
            'deja_regularise' => $tvaReg && $tvaReg->statut === 'regularise',
            'statut' => $tvaReg ? $tvaReg->statut : 'en_attente',
            'tva_id' => $tvaReg ? $tvaReg->id : null,
            'entites' => $entites,
        ]]);
    }

    /**
     * ✅ CORRECTION : On masque les entités sans aucun solde à régulariser
     *    (sauf si elles sont déjà régularisées, pour permettre la suppression)
     */
    private function calculerDetailParEntite(CarbonInterface $dateDebut, CarbonInterface $dateFin, $moisFormat, $succursaleId = null): array
    {
        $entites = Entite::where('is_active', 1)->orderBy('nom')->get();
        $details = [];
        $nbMasquees = 0;

        foreach ($entites as $entite) {
            $s443 = $this->getSoldeCompteParDates(self::COMPTE_TVA_COLLECTEE, $dateDebut, $dateFin, $entite->id, $succursaleId);
            $s445 = $this->getSoldeCompteParDates(self::COMPTE_TVA_DEDUCTIBLE, $dateDebut, $dateFin, $entite->id, $succursaleId);
            $crAnt = $this->getCreditAnterieurParMois($moisFormat, $entite->id, $succursaleId);
            $diff = $s443 - $s445 - $crAnt;

            $tvaReg = TvaComptable::where('mois', $moisFormat)
                ->where('entite_id', $entite->id)
                ->first();

            // ✅ CORRECTION : Ne pas exposer une entité sans aucun solde
            //    SAUF si elle est déjà régularisée (pour permettre la suppression)
            $aRienARegulariser = (
                abs($s443) < self::TOLERANCE_ZERO &&
                abs($s445) < self::TOLERANCE_ZERO &&
                abs($crAnt) < self::TOLERANCE_ZERO
            );

            if ($aRienARegulariser && !$tvaReg) {
                $nbMasquees++;
                continue; // On saute cette entité
            }

            $details[] = [
                'entite_id' => $entite->id,
                'nom_entite' => $entite->nom,
                'tva_collectee_443' => round($s443, 2),
                'tva_deductible_445' => round($s445, 2),
                'credit_tva_anterieur' => round($crAnt, 2),
                'tva_due_4441' => $diff > 0 ? round($diff, 2) : 0,
                'credit_tva_4449' => $diff < 0 ? round(abs($diff), 2) : 0,
                'deja_regularise' => $tvaReg && $tvaReg->statut === 'regularise',
                'tva_id' => $tvaReg ? $tvaReg->id : null,
            ];
        }

        if ($nbMasquees > 0) {
            Log::info('Entites masquees (rien a regulariser)', $this->logContext([
                'mois'         => $moisFormat,
                'nb_masquees'  => $nbMasquees,
                'nb_affichees' => count($details),
            ]));
        }

        return $details;
    }

    // =========================================================================
    // RÉGULARISATION TVA
    // =========================================================================

    public function regulariser(Request $request)
    {
        $request->validate([
            'periode_id' => 'required|exists:periodes_comptables,id',
            'entite_id' => 'nullable|exists:entites,id',
            'succursale_id' => 'nullable|exists:succursales,id',
            'mois' => 'nullable|string',
            'date_regularisation' => 'required|date',
            'libelle' => 'nullable|string|max:255',
        ]);

        $periodeId = $request->periode_id;
        $entiteId = $request->entite_id;
        $succursaleId = $request->succursale_id;
        $dateRegularisation = $request->date_regularisation;

        Log::info('════════════════════════════════════════════════════════════', $this->logContext([
            'ETAPE' => 'DEBUT REGULARISATION TVA',
        ]));

        Log::info('=== DEBUT REGULARISATION TVA ===', $this->logContext([
            'periode_id'          => $periodeId,
            'entite_id'           => $entiteId,
            'succursale_id'       => $succursaleId,
            'date_regularisation' => $dateRegularisation,
            'mois_demande'        => $request->mois,
        ]));

        $periode = PeriodesComptable::findOrFail($periodeId);

        if ($periode->statut === 'cloture_definitive' || $periode->is_cloture) {
            Log::warning('❌ Regularisation TVA refusee : periode cloturee', $this->logContext([
                'periode_id'     => $periodeId,
                'periode_code'   => $periode->code,
                'periode_statut' => $periode->statut,
                'is_cloture'     => $periode->is_cloture,
            ]));

            return response()->json(['success' => false, 'message' => 'Periode cloturee definitivement.'], 422);
        }

        if (
            Carbon::parse($dateRegularisation)->lt(Carbon::parse($periode->date_debut)) ||
            Carbon::parse($dateRegularisation)->gt(Carbon::parse($periode->date_fin))
        ) {
            Log::warning('❌ Regularisation TVA refusee : date hors periode', $this->logContext([
                'date_regularisation' => $dateRegularisation,
                'periode_debut'       => $periode->date_debut,
                'periode_fin'         => $periode->date_fin,
            ]));

            return response()->json([
                'success' => false,
                'message' => 'La date de regularisation doit appartenir a la periode selectionnee.'
            ], 422);
        }

        $moisPeriode = $request->mois ?? Carbon::parse($periode->date_debut)->format('Y-m');

        // ✅ Vérification de l'existence d'une régularisation déjà faite pour ce mois/entité
        $tvaExistante = TvaComptable::where('periode_id', $periodeId)
            ->where('entite_id', $entiteId)
            ->where('succursale_id', $succursaleId)
            ->where('mois', $moisPeriode)
            ->first();

        // ✅ Vérification qu'il n'y a pas un manquant avant d'écraser
        if ($tvaExistante) {
            Log::info('⚠️  Regularisation DEJA EXISTANTE pour ce mois/entite', $this->logContext([
                'tva_id'                => $tvaExistante->id,
                'mois'                  => $moisPeriode,
                'entite_id'             => $entiteId,
                'ancien_tva_collectee'  => $this->fmt((float)$tvaExistante->tva_collectee_443),
                'ancien_tva_deductible' => $this->fmt((float)$tvaExistante->tva_deductible_445),
                'ancien_tva_due_4441'   => $this->fmt((float)$tvaExistante->tva_due_4441),
                'ancien_credit_4449'    => $this->fmt((float)$tvaExistante->credit_tva_final_4449),
                'ancien_statut'         => $tvaExistante->statut,
            ]));
        }

        try {
            DB::beginTransaction();

            Log::info('✅ Transaction DB ouverte', $this->logContext([
                'periode_id'   => $periodeId,
                'mois_periode' => $moisPeriode,
                'entite_id'    => $entiteId,
            ]));

            $date = Carbon::createFromFormat('Y-m', $moisPeriode);
            $dateDebut = $date->copy()->startOfMonth();
            $dateFin = $date->copy()->endOfMonth();

            // =============================================================
            // ÉTAPE 1 : SUPPRESSION DES ANCIENNES ÉCRITURES DE RÉGUL
            // =============================================================
            Log::info('┌─ ÉTAPE 1 : Suppression anciennes ecritures de regularisation', $this->logContext([
                'periode_id'   => $periodeId,
                'mois_periode' => $moisPeriode,
                'entite_id'    => $entiteId,
            ]));

            $nbSupprimeesAvant = $this->supprimerEcrituresRegularisation($periodeId, $moisPeriode, $entiteId, $succursaleId);

            Log::info('└─ Résultat : anciennes ecritures supprimees', $this->logContext([
                'nb_supprimees' => $nbSupprimeesAvant,
            ]));

            // =============================================================
            // ÉTAPE 2 : CALCUL DES SOLDES RÉELS
            // =============================================================
            Log::info('┌─ ÉTAPE 2 : Calcul des soldes 443 / 445', $this->logContext([
                'date_debut' => $dateDebut->toDateString(),
                'date_fin'   => $dateFin->toDateString(),
            ]));

            $solde443 = $this->getSoldeCompteParDates(self::COMPTE_TVA_COLLECTEE, $dateDebut, $dateFin, $entiteId, $succursaleId);
            $solde445 = $this->getSoldeCompteParDates(self::COMPTE_TVA_DEDUCTIBLE, $dateDebut, $dateFin, $entiteId, $succursaleId);
            $creditAnterieur = $this->getCreditAnterieurParMois($moisPeriode, $entiteId, $succursaleId);

            $difference = $solde443 - $solde445 - $creditAnterieur;

            // ✅ LOG : Détail du calcul
            Log::info('│  📊 MONTANTS AVANT RÉGULARISATION', $this->logContext([
                'mois'                        => $moisPeriode,
                'entite_id'                   => $entiteId,
                'compte_443_collectee'        => $this->fmt($solde443),
                'compte_445_deductible'       => $this->fmt($solde445),
                'credit_anterieur_4449'       => $this->fmt($creditAnterieur),
                '────────────────────────────' => '─────────',
                'formule'                     => '443 - 445 - Crédit antérieur',
                'difference_nette'            => $this->fmt($difference),
                'type'                        => $difference > self::TOLERANCE_ZERO
                                                    ? '📕 TVA À PAYER (4441)'
                                                    : ($difference < -self::TOLERANCE_ZERO
                                                        ? '📗 CRÉDIT DE TVA (4449)'
                                                        : '⏸️  RIEN À RÉGULARISER'),
            ]));

            // =============================================================
            // ✅ CORRECTION CRITIQUE : REFUS SI DIFFERENCE == 0
            // =============================================================
            if (abs($difference) < self::TOLERANCE_ZERO) {
                DB::rollBack();

                Log::warning('❌ ARRÊT : Rien à régulariser (solde net nul)', $this->logContext([
                    'mois'                  => $moisPeriode,
                    'entite_id'             => $entiteId,
                    'solde_443'             => $this->fmt($solde443),
                    'solde_445'             => $this->fmt($solde445),
                    'credit_anterieur'      => $this->fmt($creditAnterieur),
                    'difference'            => $this->fmt($difference),
                    'raison'                => 'Les comptes 443 et 445 sont déjà soldés, ou le solde net est nul.',
                ]));

                $message = 'Rien a regulariser : les comptes 443 et 445 sont deja soldes '
                         . 'ou le solde net est nul. '
                         . 'Solde 443 : ' . number_format($solde443, 2) . ' $ | '
                         . 'Solde 445 : ' . number_format($solde445, 2) . ' $ | '
                         . 'Credit anterieur : ' . number_format($creditAnterieur, 2) . ' $ | '
                         . 'Difference : ' . number_format($difference, 2) . ' $';

                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'data'    => [
                        'solde_443'        => round($solde443, 2),
                        'solde_445'        => round($solde445, 2),
                        'credit_anterieur' => round($creditAnterieur, 2),
                        'difference'       => round($difference, 2),
                    ]
                ], 422);
            }

            Log::info('└─ Soldes récupérés avec succès', $this->logContext([
                'action' => 'CONTINUER LA RÉGULARISATION',
            ]));

            // =============================================================
            // ÉTAPE 3 : CONSTRUCTION DE L'ÉCRITURE ÉQUILIBRÉE
            // =============================================================
            $entite = $entiteId ? Entite::find($entiteId) : null;
            $entiteNom = $entite ? ($entite->code . ' - ' . $entite->nom) : 'Toutes entites';
            $numeroEcriture = 'TVA-' . $periode->code . '-REG-' . ($entiteId ? 'E' . $entiteId . '-' : '') . date('YmdHis');

            Log::info('┌─ ÉTAPE 3 : Construction de l ecriture comptable', $this->logContext([
                'numero_ecriture' => $numeroEcriture,
                'entite_nom'      => $entiteNom,
                'type_operation'  => $difference > 0 ? 'TVA_DUE' : 'CREDIT_TVA',
            ]));

            // Tableau pour tracer les lignes créées
            $lignesCreees = [];

            if ($difference > 0) {
                // ============================================================
                // CAS 1 : TVA DUE (4441)
                // ============================================================
                $montant4441 = round($difference, 2);
                $montant4449 = 0;
                $libelle = $request->libelle ?? "Regul. TVA Due - " . $entiteNom;
                $reference = 'REGUL. TVA ' . $moisPeriode . ' - ' . $entiteNom;

                // Débit 443 : solde la TVA collectée
                $this->creerLigneEcriture($periodeId, $dateRegularisation, self::COMPTE_TVA_COLLECTEE, $libelle, $solde443, 0, $numeroEcriture, $reference, $entiteId, $succursaleId, 0);
                $lignesCreees[] = ['sens' => 'D', 'compte' => '443', 'debit' => $solde443, 'credit' => 0, 'libelle' => 'Solde TVA collectée'];

                // Crédit 445 : solde la TVA déductible
                $this->creerLigneEcriture($periodeId, $dateRegularisation, self::COMPTE_TVA_DEDUCTIBLE, $libelle, 0, $solde445, $numeroEcriture, $reference, $entiteId, $succursaleId, 1);
                $lignesCreees[] = ['sens' => 'C', 'compte' => '445', 'debit' => 0, 'credit' => $solde445, 'libelle' => 'Solde TVA déductible'];

                // Crédit 4449 : solde du crédit antérieur (si > 0)
                if ($creditAnterieur > 0) {
                    $this->creerLigneEcriture($periodeId, $dateRegularisation, self::COMPTE_CREDIT_TVA, $libelle, 0, $creditAnterieur, $numeroEcriture, $reference, $entiteId, $succursaleId, 2);
                    $lignesCreees[] = ['sens' => 'C', 'compte' => '4449', 'debit' => 0, 'credit' => $creditAnterieur, 'libelle' => 'Solde crédit TVA antérieur'];
                }

                // Crédit 4441 : constate la TVA due
                $this->creerLigneEcriture($periodeId, $dateRegularisation, self::COMPTE_TVA_DUE, $libelle, 0, $montant4441, $numeroEcriture, $reference, $entiteId, $succursaleId, 3);
                $lignesCreees[] = ['sens' => 'C', 'compte' => '4441', 'debit' => 0, 'credit' => $montant4441, 'libelle' => 'Constatation TVA due'];

                Log::info('│  📕 ÉCRITURE TVA DUE (4441) CRÉÉE', $this->logContext([
                    'type'              => 'TVA_DUE',
                    'numero_ecriture'   => $numeroEcriture,
                    'total_debit'       => $this->fmt($solde443),
                    'total_credit'      => $this->fmt($solde445 + $creditAnterieur + $montant4441),
                ]));

            } else {
                // ============================================================
                // CAS 2 : CRÉDIT DE TVA (4449)
                // ============================================================
                $montant4441 = 0;
                $montant4449 = round(abs($difference), 2);
                $libelle = $request->libelle ?? "Regul. Credit TVA - " . $entiteNom;
                $reference = 'REGUL. TVA ' . $moisPeriode . ' - ' . $entiteNom;

                // Débit 443 : solde la TVA collectée (uniquement si > 0)
                if ($solde443 > self::TOLERANCE_ZERO) {
                    $this->creerLigneEcriture($periodeId, $dateRegularisation, self::COMPTE_TVA_COLLECTEE, $libelle, $solde443, 0, $numeroEcriture, $reference, $entiteId, $succursaleId, 0);
                    $lignesCreees[] = ['sens' => 'D', 'compte' => '443', 'debit' => $solde443, 'credit' => 0, 'libelle' => 'Solde TVA collectée'];
                }

                // Débit 4449 : constate le nouveau crédit de TVA
                $this->creerLigneEcriture($periodeId, $dateRegularisation, self::COMPTE_CREDIT_TVA, $libelle, $montant4449, 0, $numeroEcriture, $reference, $entiteId, $succursaleId, 1);
                $lignesCreees[] = ['sens' => 'D', 'compte' => '4449', 'debit' => $montant4449, 'credit' => 0, 'libelle' => 'Nouveau crédit TVA'];

                // Crédit 445 : solde la TVA déductible
                $this->creerLigneEcriture($periodeId, $dateRegularisation, self::COMPTE_TVA_DEDUCTIBLE, $libelle, 0, $solde445, $numeroEcriture, $reference, $entiteId, $succursaleId, 2);
                $lignesCreees[] = ['sens' => 'C', 'compte' => '445', 'debit' => 0, 'credit' => $solde445, 'libelle' => 'Solde TVA déductible'];

                // Crédit 4449 : solde du crédit antérieur (si > 0)
                if ($creditAnterieur > 0) {
                    $this->creerLigneEcriture($periodeId, $dateRegularisation, self::COMPTE_CREDIT_TVA, $libelle, 0, $creditAnterieur, $numeroEcriture, $reference, $entiteId, $succursaleId, 3);
                    $lignesCreees[] = ['sens' => 'C', 'compte' => '4449', 'debit' => 0, 'credit' => $creditAnterieur, 'libelle' => 'Solde crédit TVA antérieur'];
                }

                Log::info('│  📗 ÉCRITURE CRÉDIT TVA (4449) CRÉÉE', $this->logContext([
                    'type'              => 'CREDIT_TVA',
                    'numero_ecriture'   => $numeroEcriture,
                    'total_debit'       => $this->fmt($solde443 + $montant4449),
                    'total_credit'      => $this->fmt($solde445 + $creditAnterieur),
                ]));
            }

            // ✅ LOG : Détail complet du bilan comptable (Partie Double)
            $this->logEcritureBilan('│  📋 BILAN COMPTABLE DE L ÉCRITURE', $lignesCreees);

            // =============================================================
            // ÉTAPE 4 : ENREGISTREMENT DANS tva_comptables (ÉCRASEMENT)
            // =============================================================
            Log::info('┌─ ÉTAPE 4 : Enregistrement dans tva_comptables', $this->logContext([
                'operation' => $tvaExistante ? 'UPDATE (écrasement)' : 'CREATE',
            ]));

            $dataTva = [
                'periode_id' => $periodeId,
                'entite_id' => $entiteId,
                'succursale_id' => $succursaleId,
                'mois' => $moisPeriode,
                'libelle' => $libelle,
                'tva_collectee_443' => round($solde443, 2),
                'tva_deductible_445' => round($solde445, 2),
                'credit_tva_anterieur_4449' => round($creditAnterieur, 2),
                'tva_calculee' => round($difference, 2),
                'tva_due_4441' => round($montant4441, 2),
                'credit_tva_final_4449' => round($montant4449, 2),
                'statut' => 'regularise',
                'date_regularisation' => $dateRegularisation,
            ];

            if ($tvaExistante) {
                $ancienId = $tvaExistante->id;
                $tvaExistante->update($dataTva);

                Log::info('└─ Regularisation mise a jour (ecrasement)', $this->logContext([
                    'tva_id'                => $ancienId,
                    'operation'             => 'UPDATE',
                    'nouveau_tva_collectee' => $this->fmt($solde443),
                    'nouveau_tva_deductible'=> $this->fmt($solde445),
                    'nouveau_tva_due_4441'  => $this->fmt($montant4441),
                    'nouveau_credit_4449'   => $this->fmt($montant4449),
                    'nouveau_statut'        => 'regularise',
                ]));
            } else {
                $nouvelleTva = TvaComptable::create($dataTva);

                Log::info('└─ Regularisation creee', $this->logContext([
                    'tva_id'                => $nouvelleTva->id,
                    'operation'             => 'CREATE',
                    'tva_collectee'         => $this->fmt($solde443),
                    'tva_deductible'        => $this->fmt($solde445),
                    'tva_due_4441'          => $this->fmt($montant4441),
                    'credit_final_4449'     => $this->fmt($montant4449),
                    'statut'                => 'regularise',
                ]));
            }

            DB::commit();

            Log::info('✅ Transaction DB commit OK', $this->logContext([
                'periode_id'   => $periodeId,
                'mois_periode' => $moisPeriode,
                'entite_id'    => $entiteId,
            ]));

            $this->broadcastTva('regularise', $dataTva);

            // =============================================================
            // ✅ LOG FINAL : RÉCAPITULATIF AVANT / APRÈS
            // =============================================================
            Log::info('════════════════════════════════════════════════════════════', $this->logContext([
                'ETAPE' => 'FIN REGULARISATION TVA (SUCCES)',
                'PERIODE' => $periode->code,
                'MOIS' => $moisPeriode,
                'ENTITE' => $entiteNom,
                'NUMERO_ECRITURE' => $numeroEcriture,
                '──────────────────────────────' => '──────────',
                'AVANT_REGULARISATION' => [
                    'compte_443_collectee' => $this->fmt($solde443),
                    'compte_445_deductible' => $this->fmt($solde445),
                    'credit_anterieur_4449' => $this->fmt($creditAnterieur),
                    'difference_nette'      => $this->fmt($difference),
                ],
                'APRES_REGULARISATION' => [
                    'compte_443' => $this->fmt(0),
                    'compte_445' => $this->fmt(0),
                    'compte_4441' => $this->fmt($montant4441),
                    'compte_4449' => $this->fmt($montant4449),
                ],
                'ECRITURES_CREEES' => $lignesCreees,
            ]));

            Log::info('════════════════════════════════════════════════════════════', $this->logContext([
                'ETAPE' => 'FIN REGULARISATION TVA (SUCCES)',
            ]));

            return response()->json([
                'success' => true,
                'message' => 'TVA regularisee avec succes !',
                'data' => [
                    'tva_due_4441' => round($montant4441, 2),
                    'credit_tva_4449' => round($montant4449, 2),
                    'credit_anterieur_solde' => round($creditAnterieur, 2),
                    'numero_ecriture' => $numeroEcriture,
                    'avant' => [
                        'solde_443' => round($solde443, 2),
                        'solde_445' => round($solde445, 2),
                        'credit_anterieur' => round($creditAnterieur, 2),
                        'difference' => round($difference, 2),
                    ],
                    'apres' => [
                        'solde_443' => 0,
                        'solde_445' => 0,
                        'solde_4441' => round($montant4441, 2),
                        'solde_4449' => round($montant4449, 2),
                    ],
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('❌ ERREUR REGULARISATION TVA', $this->logContext([
                'periode_id'          => $periodeId,
                'entite_id'           => $entiteId,
                'mois_periode'        => $moisPeriode,
                'date_regularisation' => $dateRegularisation,
                'message'             => $e->getMessage(),
                'code'                => $e->getCode(),
                'file'                => $e->getFile(),
                'line'                => $e->getLine(),
                'trace'               => $e->getTraceAsString(),
            ]));

            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // SUPPRESSION CIBLÉE DES ÉCRITURES DE RÉGULARISATION
    // =========================================================================

    private function supprimerEcrituresRegularisation($periodeId, $moisPeriode, $entiteId, $succursaleId): int
    {
        $reference = 'REGUL. TVA ' . $moisPeriode . '%';

        $query = EcrituresComptable::where('periode_id', $periodeId)
            ->where('reference', 'LIKE', $reference)
            ->where('compte_numero', 'REGEXP', '^(443|445|4441|4449)');

        if ($entiteId !== null) {
            $query->where('entite_id', $entiteId);
        } else {
            $query->whereNull('entite_id');
        }

        if ($succursaleId !== null) {
            $query->where('succursale_id', $succursaleId);
        } else {
            $query->whereNull('succursale_id');
        }

        $nb = $query->count();

        if ($nb > 0) {
            $query->delete();
        }

        return $nb;
    }

    // =========================================================================
    // CRÉATION LIGNE ÉCRITURE
    // =========================================================================

    private function creerLigneEcriture($periodeId, $date, $compte, $libelle, $debit, $credit, $numero, $reference, $entiteId, $succursaleId, $ordre)
    {
        $ecriture = EcrituresComptable::create([
            'periode_id' => $periodeId,
            'date_ecriture' => $date,
            'compte_numero' => $compte,
            'libelle' => $libelle,
            'debit' => round($debit, 2),
            'credit' => round($credit, 2),
            'numero_ecriture' => $numero,
            'reference' => $reference,
            'entite_id' => $entiteId,
            'succursale_id' => $succursaleId,
            'sens_ecriture' => 'principal',
            'ordre_affichage' => $ordre,
        ]);

        Log::debug('│     ✍️  Ligne créée', $this->logContext([
            'ecriture_id'   => $ecriture->id,
            'compte'        => $compte,
            'sens'          => $debit > 0 ? '📤 DÉBIT' : '📥 CRÉDIT',
            'montant'       => $debit > 0 ? $this->fmt($debit) : $this->fmt($credit),
            'ordre'         => $ordre,
        ]));

        return $ecriture;
    }

    // =========================================================================
    // SUPPRESSION D'UNE RÉGULARISATION
    // =========================================================================

    public function supprimer($id)
    {
        $tva = TvaComptable::findOrFail($id);
        $periode = PeriodesComptable::find($tva->periode_id);

        Log::info('=== DEBUT SUPPRESSION REGULARISATION TVA ===', $this->logContext([
            'tva_id'      => $id,
            'mois'        => $tva->mois,
            'entite_id'   => $tva->entite_id,
            'periode_id'  => $tva->periode_id,
        ]));

        if ($periode && $periode->is_cloture) {
            Log::warning('Suppression TVA refusee : periode cloturee', $this->logContext([
                'tva_id'        => $id,
                'periode_id'    => $tva->periode_id,
                'periode_code'  => $periode->code,
            ]));

            return response()->json(['success' => false, 'message' => 'Periode cloturee.'], 422);
        }

        try {
            DB::transaction(function () use ($tva, $id) {
                $nbSupprimees = $this->supprimerEcrituresRegularisation(
                    $tva->periode_id,
                    $tva->mois,
                    $tva->entite_id,
                    $tva->succursale_id
                );

                Log::info('Ecritures de regularisation supprimees', $this->logContext([
                    'tva_id'        => $id,
                    'nb_ecritures'  => $nbSupprimees,
                    'mois'          => $tva->mois,
                    'entite_id'     => $tva->entite_id,
                ]));

                $tva->delete();
            });

            Log::info('=== FIN SUPPRESSION REGULARISATION TVA (SUCCES) ===', $this->logContext([
                'tva_id' => $id,
                'mois'   => $tva->mois,
            ]));

            return response()->json(['success' => true, 'message' => 'Regularisation supprimee !']);

        } catch (\Exception $e) {
            Log::error('=== ERREUR SUPPRESSION REGULARISATION TVA ===', $this->logContext([
                'tva_id'  => $id,
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => $e->getTraceAsString(),
            ]));

            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // HISTORIQUE
    // =========================================================================

    public function historique(Request $request)
    {
        $query = TvaComptable::with(['periode:id,code,libelle', 'entite:id,code,nom'])
            ->orderBy('created_at', 'desc');

        if ($request->entite_id) $query->where('entite_id', $request->entite_id);
        if ($request->periode_id) $query->where('periode_id', $request->periode_id);

        $historique = $query->get()->map(function ($tva) {
            return [
                'id' => $tva->id,
                'mois' => $tva->mois,
                'mois_label' => $this->formatMoisLabel($tva->mois),
                'entite' => $tva->entite ? $tva->entite->nom : 'Toutes',
                'tva_collectee_443' => round($tva->tva_collectee_443, 2),
                'tva_deductible_445' => round($tva->tva_deductible_445, 2),
                'tva_due_4441' => round($tva->tva_due_4441, 2),
                'credit_tva_4449' => round($tva->credit_tva_final_4449, 2),
                'statut' => $tva->statut,
                'statut_label' => $tva->statut === 'regularise' ? 'Regularise' : $tva->statut,
            ];
        });

        Log::debug('Consultation historique TVA', $this->logContext([
            'entite_id'  => $request->entite_id,
            'periode_id' => $request->periode_id,
            'nb_resultats' => $historique->count(),
        ]));

        return response()->json(['success' => true, 'data' => $historique]);
    }

    // =========================================================================
    // DÉTAIL MOUVEMENTS
    // =========================================================================

    public function detailMouvements(Request $request)
    {
        $periode = $request->periode_id ? PeriodesComptable::find($request->periode_id) : null;

        if ($periode) {
            $dateDebut = Carbon::parse($periode->date_debut)->toDateString();
            $dateFin = Carbon::parse($periode->date_fin)->toDateString();
        } else {
            $mois = (int)($request->mois ?? date('m'));
            $annee = (int)($request->annee ?? date('Y'));
            $dateDebut = Carbon::create($annee, $mois, 1)->startOfMonth()->toDateString();
            $dateFin = Carbon::create($annee, $mois, 1)->endOfMonth()->toDateString();
        }

        $comptes = [
            self::COMPTE_TVA_COLLECTEE => 'TVA Collectee',
            self::COMPTE_TVA_DEDUCTIBLE => 'TVA Deductible',
            self::COMPTE_TVA_DUE => 'TVA Due',
            self::COMPTE_CREDIT_TVA => 'Credit TVA',
        ];

        $result = [];
        foreach ($comptes as $compte => $nom) {
            $q = EcrituresComptable::where('compte_numero', 'LIKE', $compte . '%')
                ->whereDate('date_ecriture', '>=', $dateDebut)
                ->whereDate('date_ecriture', '<=', $dateFin)
                ->orderBy('date_ecriture');

            if ($request->entite_id) $q->where('entite_id', $request->entite_id);
            if ($request->succursale_id) $q->where('succursale_id', $request->succursale_id);

            $mouvs = $q->get()->map(function ($e) {
                $dateEcriture = $e->date_ecriture;
                $dateFormatted = $dateEcriture instanceof CarbonInterface
                    ? $dateEcriture->format('d/m/Y')
                    : $dateEcriture;
                return [
                    'date_ecriture' => $dateFormatted,
                    'libelle' => $e->libelle,
                    'reference' => $e->reference,
                    'debit' => round((float)$e->debit, 2),
                    'credit' => round((float)$e->credit, 2),
                ];
            })->toArray();

            $td = round(array_sum(array_column($mouvs, 'debit')), 2);
            $tc = round(array_sum(array_column($mouvs, 'credit')), 2);

            $result[] = [
                'numero' => $compte,
                'nom' => $nom,
                'mouvements' => $mouvs,
                'total_debit' => $td,
                'total_credit' => $tc,
                'solde' => round($td - $tc, 2),
            ];
        }

        return response()->json(['success' => true, 'data' => $result]);
    }

    public function evolution(Request $request)
    {
        $annee = $request->annee ?? date('Y');
        $query = TvaComptable::where('mois', 'LIKE', $annee . '-%')->orderBy('mois', 'asc');
        if ($request->entite_id) $query->where('entite_id', $request->entite_id);
        $regs = $query->get();

        $evolution = [];
        for ($m = 1; $m <= 12; $m++) {
            $key = $annee . '-' . str_pad($m, 2, '0', STR_PAD_LEFT);
            $reg = $regs->firstWhere('mois', $key);
            $evolution[] = [
                'mois_label' => self::$moisLabels[$m],
                'tva_collectee' => $reg ? round($reg->tva_collectee_443, 2) : 0,
                'tva_deductible' => $reg ? round($reg->tva_deductible_445, 2) : 0,
                'montant_4441' => $reg ? round($reg->tva_due_4441, 2) : 0,
                'montant_4449' => $reg ? round($reg->credit_tva_final_4449, 2) : 0,
                'statut' => $reg ? $reg->statut : 'aucun',
            ];
        }

        return response()->json(['success' => true, 'data' => [
            'annee' => $annee,
            'evolution' => $evolution,
            'total_4441' => round($regs->sum('tva_due_4441'), 2),
            'total_4449' => round($regs->sum('credit_tva_final_4449'), 2),
            'total_collectee' => round($regs->sum('tva_collectee_443'), 2),
            'total_deductible' => round($regs->sum('tva_deductible_445'), 2),
            'nb_regularisations' => $regs->count(),
        ]]);
    }

    public function export(Request $request)
    {
        $query = TvaComptable::with(['periode', 'entite']);
        if ($request->mois) $query->where('mois', 'LIKE', $request->annee . '-' . str_pad($request->mois, 2, '0', STR_PAD_LEFT));
        if ($request->annee) $query->where('mois', 'LIKE', $request->annee . '-%');
        $data = $query->orderBy('mois', 'desc')->get();

        $csv = "Mois;Entite;Collectee 443;Deductible 445;Due 4441;Credit 4449;Statut\n";
        foreach ($data as $t) {
            $csv .= implode(';', [
                $this->formatMoisLabel($t->mois),
                $t->entite?->nom ?? 'Toutes',
                number_format($t->tva_collectee_443, 2, '.', ''),
                number_format($t->tva_deductible_445, 2, '.', ''),
                number_format($t->tva_due_4441, 2, '.', ''),
                number_format($t->credit_tva_final_4449, 2, '.', ''),
                $t->statut
            ]) . "\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="TVA_' . ($request->annee ?? date('Y')) . '.csv"'
        ]);
    }

    // =========================================================================
    // MÉTHODES PRIVÉES
    // =========================================================================

    private function getSoldeCompteParDates(string $compte, CarbonInterface $dateDebut, CarbonInterface $dateFin, $entiteId, $succursaleId): float
    {
        $query = EcrituresComptable::where('compte_numero', 'LIKE', $compte . '%')
            ->whereDate('date_ecriture', '>=', $dateDebut->toDateString())
            ->whereDate('date_ecriture', '<=', $dateFin->toDateString())
            ->where(function ($q) {
                $q->where('reference', 'NOT LIKE', 'REGUL. TVA%')
                  ->orWhereNull('reference');
            });

        if ($entiteId) $query->where('entite_id', $entiteId);
        if ($succursaleId) $query->where('succursale_id', $succursaleId);

        $debit = (clone $query)->sum('debit');
        $credit = (clone $query)->sum('credit');

        return in_array($compte, [self::COMPTE_TVA_COLLECTEE, self::COMPTE_TVA_DUE])
            ? $credit - $debit
            : $debit - $credit;
    }

    private function getCreditAnterieurParMois(string $mois, $entiteId, $succursaleId): float
    {
        $query = TvaComptable::where('mois', '<', $mois)
            ->where('statut', '!=', 'annule');

        if ($entiteId) $query->where('entite_id', $entiteId);
        if ($succursaleId) $query->where('succursale_id', $succursaleId);

        $dernierMois = $query->orderBy('mois', 'desc')->first();

        $credit = $dernierMois ? (float)$dernierMois->credit_tva_final_4449 : 0.0;

        Log::debug('Credit anterieur recupere', $this->logContext([
            'mois_actuel'         => $mois,
            'entite_id'           => $entiteId,
            'dernier_mois_trouve' => $dernierMois?->mois,
            'credit_anterieur'    => $this->fmt($credit),
        ]));

        return $credit;
    }

    private function formatMoisLabel(string $mois): string
    {
        $parts = explode('-', $mois);
        $m = (int)($parts[1] ?? 0);
        return ($m >= 1 && $m <= 12)
            ? self::$moisLabels[$m] . ' ' . ($parts[0] ?? '')
            : $mois;
    }
}