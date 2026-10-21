<?php

namespace App\Http\Controllers\Comptabilite;

use App\Models\FluxTresorerieSection;
use App\Models\FluxTresorerieArchive;
use App\Models\PeriodesComptable;
use App\Models\Entite;
use App\Models\Succursale;
use App\Models\EcrituresComptable;
use App\Models\PlanComptable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use App\Http\Controllers\Controller;

class FluxTresorerieController extends Controller
{
    /**
     * Nombre de décimales pour tous les arrondis
     */
    const DECIMALES = 4;

    /**
     * Helper centralisé : arrondit tout à DECIMALES décimales
     */
    private function arrondir($valeur): float
    {
        return round((float) $valeur, self::DECIMALES);
    }

    // =============================================
    // VUES PRINCIPALES
    // =============================================

    public function index()
    {
        $entites = Entite::where('is_active', 1)->orderBy('code', 'asc')->get();
        $periodes = PeriodesComptable::orderBy('date_debut', 'desc')->get();
        $periodeEnCours = PeriodesComptable::where('statut', 'ouverte')
            ->orderBy('date_debut', 'asc')
            ->first();
        $sections = FluxTresorerieSection::actives()->ordonnees()->get();

        return response()->json([
            'success' => true,
            'entites' => $entites,
            'periodes' => $periodes,
            'periodeEnCours' => $periodeEnCours,
            'sections' => $sections,
        ]);
    }

    public function dashboard()
    {
        return response()->json([
            'success' => true,
            'partial' => 'dashboard',
        ]);
    }

    public function suivi()
    {
        return response()->json([
            'success' => true,
            'partial' => 'suivi',
        ]);
    }

    public function configuration()
    {
        $sections = FluxTresorerieSection::orderBy('ordre')->get();

        return response()->json([
            'success' => true,
            'sections' => $sections
        ]);
    }

    // =============================================
    // API - CALCUL DU FLUX DE TRÉSORERIE
    // =============================================

    public function calculer(Request $request)
    {
        try {
            $entiteId = $request->filled('entite_id') ? (int) $request->entite_id : null;
            $succursaleId = $request->filled('succursale_id') ? (int) $request->succursale_id : null;
            $periodeId = $request->periode_id;

            $periode = null;
            if (!empty($periodeId)) {
                $periode = PeriodesComptable::find((int) $periodeId);
            }

            if (!$periode) {
                $periode = PeriodesComptable::where('statut', 'ouverte')
                    ->orderBy('date_debut', 'asc')
                    ->first();
            }

            if (!$periode) {
                return response()->json([
                    'success' => true,
                    'data' => []
                ], 200);
            }

            $isCloturee = in_array($periode->statut, ['cloture_provisoire', 'cloture_definitive']);

            if ($isCloturee) {
                $archive = FluxTresorerieArchive::where('periode_id', $periode->id)
                    ->when($entiteId, fn($q) => $q->where('entite_id', $entiteId))
                    ->when($succursaleId, fn($q) => $q->where('succursale_id', $succursaleId))
                    ->first();

                if ($archive) {
                    return response()->json([
                        'success' => true,
                        'data' => array_merge(
                            ['type' => 'archive', 'periode' => $this->formatPeriode($periode)],
                            $archive->donnees_completes ?? []
                        )
                    ]);
                }

                return response()->json([
                    'success' => true,
                    'data' => []
                ], 200);
            }

            return $this->repondreCalcul($periode, $entiteId, $succursaleId);

        } catch (\Exception $e) {
            Log::error('Erreur lors du calcul du flux de trésorerie', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur serveur : ' . $e->getMessage()
            ], 500);
        }
    }

    // =============================================
    // API - DASHBOARD
    // =============================================

    public function getDashboardData(Request $request)
    {
        try {
            $entiteId = $request->filled('entite_id') ? (int) $request->entite_id : null;
            $succursaleId = $request->filled('succursale_id') ? (int) $request->succursale_id : null;
            $periodeId = $request->periode_id;

            $periode = $this->getPeriode($periodeId);
            if (!$periode) {
                return response()->json(['success' => false, 'message' => 'Aucune période trouvée'], 404);
            }

            $dateDebut = $periode->date_debut instanceof \Carbon\Carbon
                ? $periode->date_debut->format('Y-m-d') : $periode->date_debut;
            $dateFin = $periode->date_fin instanceof \Carbon\Carbon
                ? $periode->date_fin->format('Y-m-d') : $periode->date_fin;

            $entite = $entiteId ? Entite::find($entiteId) : null;
            $succursale = $succursaleId ? Succursale::find($succursaleId) : null;

            $fluxData = $this->calculerFluxTresorerie(
                $periode, $entite, $succursale, $dateDebut, $dateFin, $entiteId, $succursaleId
            );

            $dashboardData = [
                'periode' => $fluxData['periode'] ?? $this->formatPeriode($periode, $dateDebut, $dateFin),

                'tresorerie' => [
                    'solde_global' => [
                        'valeur' => $this->arrondir($fluxData['tresorerie_finale'] ?? 0),
                        'evolution' => $this->calculerEvolution($fluxData['tresorerie_initiale'] ?? 0, $fluxData['tresorerie_finale'] ?? 0),
                    ],
                    'cash_flow_operationnel' => [
                        'valeur' => $this->arrondir($fluxData['flux_exploitation'] ?? 0),
                        'evolution' => null,
                    ],
                ],

                'totaux' => $this->arrondirTotaux($fluxData['totaux'] ?? []),

                'tresorerie_initiale' => $this->arrondir($fluxData['tresorerie_initiale'] ?? 0),
                'tresorerie_finale' => $this->arrondir($fluxData['tresorerie_finale'] ?? 0),
                'flux_exploitation' => $this->arrondir($fluxData['flux_exploitation'] ?? 0),
                'flux_investissement' => $this->arrondir($fluxData['flux_investissement'] ?? 0),
                'flux_financement' => $this->arrondir($fluxData['flux_financement'] ?? 0),
                'variation_nette' => $this->arrondir($fluxData['variation_nette'] ?? 0),

                'creances_dettes' => [
                    'creances_clients' => $this->arrondir($this->calculerSoldeParPosition('actif', '41', $periode, $entiteId, $succursaleId)),
                    'dettes_fournisseurs' => $this->arrondir($this->calculerSoldeParPosition('passif', '40', $periode, $entiteId, $succursaleId)),
                    'delais_paiement' => [
                        'DSO' => $this->arrondir($this->calculerDSO($periode, $entiteId, $succursaleId)),
                        'DPO' => $this->arrondir($this->calculerDPO($periode, $entiteId, $succursaleId)),
                    ],
                ],

                'performance' => [
                    'chiffre_affaires' => $this->calculerCA($periode, $entiteId, $succursaleId),
                    'marge_brute' => $this->calculerMargeBrute($periode, $entiteId, $succursaleId),
                    'charges' => $this->arrondirCharges($this->getRepartitionCharges($periode, $entiteId, $succursaleId)),
                ],

                'structure' => $this->calculerStructureFinanciere($periode, $entiteId, $succursaleId),
            ];

            return response()->json([
                'success' => true,
                'data' => $dashboardData
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur dashboard flux trésorerie', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // =============================================
    // API - SUIVI
    // =============================================

    public function getSuiviData(Request $request)
    {
        try {
            $entiteId = $request->filled('entite_id') ? (int) $request->entite_id : null;
            $succursaleId = $request->filled('succursale_id') ? (int) $request->succursale_id : null;
            $periodeDebutId = $request->periode_debut;
            $periodeFinId = $request->periode_fin;

            if (!$periodeDebutId || !$periodeFinId) {
                return response()->json(['success' => true, 'data' => []]);
            }

            $periodeDebut = PeriodesComptable::find($periodeDebutId);
            $periodeFin = PeriodesComptable::find($periodeFinId);

            if (!$periodeDebut || !$periodeFin) {
                return response()->json(['success' => true, 'data' => []]);
            }

            $periodes = PeriodesComptable::whereBetween('date_debut', [$periodeDebut->date_debut, $periodeFin->date_fin])
                ->orderBy('date_debut', 'asc')
                ->get();

            $suiviData = [];
            foreach ($periodes as $periode) {
                $dateDebut = $periode->date_debut instanceof \Carbon\Carbon
                    ? $periode->date_debut->format('Y-m-d') : $periode->date_debut;
                $dateFin = $periode->date_fin instanceof \Carbon\Carbon
                    ? $periode->date_fin->format('Y-m-d') : $periode->date_fin;

                $fluxData = $this->calculerFluxTresorerie(
                    $periode, null, null, $dateDebut, $dateFin, $entiteId, $succursaleId
                );

                $suiviData[] = [
                    'periode' => $this->formatPeriode($periode),
                    'flux_exploitation' => $this->arrondir($fluxData['flux_exploitation'] ?? 0),
                    'flux_investissement' => $this->arrondir($fluxData['flux_investissement'] ?? 0),
                    'flux_financement' => $this->arrondir($fluxData['flux_financement'] ?? 0),
                    'variation_nette' => $this->arrondir($fluxData['variation_nette'] ?? 0),
                    'tresorerie_finale' => $this->arrondir($fluxData['tresorerie_finale'] ?? 0),
                ];
            }

            return response()->json(['success' => true, 'data' => $suiviData]);

        } catch (\Exception $e) {
            Log::error('Erreur suivi flux trésorerie', ['message' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // =============================================
    // API - CONFIGURATION
    // =============================================

    public function getConfigurationData()
    {
        try {
            $sections = FluxTresorerieSection::orderBy('ordre')->get();

            return response()->json([
                'success' => true,
                'sections' => $sections
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur chargement configuration flux trésorerie', ['message' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du chargement de la configuration',
                'sections' => []
            ], 500);
        }
    }

    public function saveSection(Request $request)
    {
        try {
            $validated = $request->validate([
                'id' => 'nullable|exists:flux_tresorerie_sections,id',
                'code' => 'required|string|max:50',
                'libelle' => 'required|string|max:255',
                'type_section' => 'required|string|max:50',
                'type_flux' => 'required|string|max:50',
                'comptes_racines' => 'nullable|string',
                'sens' => 'required|integer|in:1,-1',
                'ordre' => 'required|integer|min:1',
                'actif' => 'sometimes|boolean',
            ]);

            $data = [
                'code' => $validated['code'],
                'libelle' => $validated['libelle'],
                'type_section' => $validated['type_section'],
                'type_flux' => $validated['type_flux'],
                'comptes_racines' => $validated['comptes_racines'] ?? null,
                'sens' => $validated['sens'],
                'ordre' => $validated['ordre'],
                'actif' => $request->has('actif') ? (bool) $validated['actif'] : true,
            ];

            if ($request->filled('id')) {
                $section = FluxTresorerieSection::findOrFail($request->id);
                $section->update($data);
                $message = 'Section mise à jour avec succès';
            } else {
                $section = FluxTresorerieSection::create($data);
                $message = 'Section créée avec succès';
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'section' => $section
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur sauvegarde section flux trésorerie', ['message' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function deleteSection($id)
    {
        try {
            $section = FluxTresorerieSection::findOrFail($id);
            $section->delete();

            return response()->json([
                'success' => true,
                'message' => 'Section supprimée avec succès'
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur suppression section flux trésorerie', ['message' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function reorderSections(Request $request)
    {
        try {
            $validated = $request->validate([
                'sections' => 'required|array',
                'sections.*.id' => 'required|exists:flux_tresorerie_sections,id',
                'sections.*.ordre' => 'required|integer|min:1',
            ]);

            foreach ($validated['sections'] as $sectionData) {
                FluxTresorerieSection::where('id', $sectionData['id'])
                    ->update(['ordre' => $sectionData['ordre']]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Ordre des sections mis à jour'
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur réorganisation sections flux trésorerie', ['message' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // =============================================
    // MÉTHODES PRIVÉES DE CALCUL
    // =============================================

    private function repondreCalcul(PeriodesComptable $periode, ?int $entiteId, ?int $succursaleId)
    {
        $dateDebut = $periode->date_debut instanceof \Carbon\Carbon
            ? $periode->date_debut->format('Y-m-d') : $periode->date_debut;
        $dateFin = $periode->date_fin instanceof \Carbon\Carbon
            ? $periode->date_fin->format('Y-m-d') : $periode->date_fin;

        $entite = $entiteId ? Entite::find($entiteId) : null;
        $succursale = $succursaleId ? Succursale::find($succursaleId) : null;

        $data = $this->calculerFluxTresorerie(
            $periode, $entite, $succursale, $dateDebut, $dateFin, $entiteId, $succursaleId
        );

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * ✅ CALCUL DU FLUX DE TRÉSORERIE - LOGIQUE GRAND LIVRE + BILAN
     *
     *  PRINCIPE FONDAMENTAL :
     *  ─────────────────────────────────────────────────────────
     *  1) Les MOUVEMENTS de chaque section correspondent EXACTEMENT
     *     aux soldes du Grand Livre pour les comptes configurés.
     *
     *     → On ne filtre PAS par opération de trésorerie.
     *     → On somme directement Débit ou Crédit selon type_flux.
     *
     *  2) La TRÉSORERIE INITIALE est TOUJOURS celle du BILAN
     *     (solde comptable des comptes 5x à la date de début),
     *     JAMAIS recalculée en fonction des flux.
     *  ─────────────────────────────────────────────────────────
     */
    private function calculerFluxTresorerie(
        PeriodesComptable $periode,
        ?Entite $entite,
        ?Succursale $succursale,
        string $dateDebut,
        string $dateFin,
        ?int $entiteId,
        ?int $succursaleId
    ): array {
        $sections = FluxTresorerieSection::actives()->ordonnees()->get();

        // ✅ TRÉSORERIE INITIALE : toujours depuis le BILAN (jamais recalculée)
        $tresorerieInitiale = $this->calculerTresorerieInitialeDepuisBilan($periode, $entiteId, $succursaleId);

        // ============================================================
        // ✅ LOGIQUE GRAND LIVRE : on récupère TOUTES les écritures
        //    (sans filtrage par opération de trésorerie)
        // ============================================================
        $ecrituresQuery = EcrituresComptable::where('periode_id', $periode->id)
            ->where('reference', 'NOT LIKE', 'CLÔTURE%')
            ->where('reference', 'NOT LIKE', 'CLOTURE%')
            ->where('reference', 'NOT LIKE', 'BILAN%')
            ->where('reference', 'NOT LIKE', 'À-NOUVEAU%')
            ->where('reference', 'NOT LIKE', 'A-NOUVEAU%')
            ->whereBetween('date_ecriture', [$dateDebut, $dateFin]);

        if ($entiteId) $ecrituresQuery->where('entite_id', $entiteId);
        if ($succursaleId) $ecrituresQuery->where('succursale_id', $succursaleId);

        $ecritures = $ecrituresQuery->toBase()->get(['id', 'compte_numero', 'debit', 'credit']);

        $totaux = $this->initialiserTotaux();
        $tableau = [];

        foreach ($sections as $section) {
            $racines = $section->comptes_racines_array;

            if (empty($racines)) {
                $tableau[] = $this->formatLigne($section, 0.0);
                continue;
            }

            // ✅ Filtre les écritures dont le compte commence par une des racines
            $ecrituresSection = $ecritures->filter(function ($e) use ($racines) {
                $compte = (string) ($e->compte_numero ?? '');
                if ($compte === '') return false;

                foreach ($racines as $racine) {
                    $racine = trim((string) $racine);
                    if ($racine !== '' && str_starts_with($compte, $racine)) {
                        return true;
                    }
                }
                return false;
            });

            // ✅ Somme DIRECTE par compte (comme le Grand Livre)
            if ($section->type_flux === 'encaissement') {
                $montantSection = (float) $ecrituresSection->sum('credit');
            } else {
                $montantSection = (float) $ecrituresSection->sum('debit');
            }

            $montant = $this->arrondir($montantSection);
            $encaissement = $section->type_flux === 'encaissement' ? $montant : 0.0;
            $decaissement  = $section->type_flux === 'decaissement' ? $montant : 0.0;
            $fluxNet       = $this->arrondir($montant * $section->sens);

            if (isset($totaux[$section->type_section])) {
                $totaux[$section->type_section]['encaissement'] = $this->arrondir($totaux[$section->type_section]['encaissement'] + $encaissement);
                $totaux[$section->type_section]['decaissement'] = $this->arrondir($totaux[$section->type_section]['decaissement'] + $decaissement);
                $totaux[$section->type_section]['net'] = $this->arrondir($totaux[$section->type_section]['net'] + $fluxNet);
            }

            $tableau[] = $this->formatLigne($section, $montant);
        }

        $fluxExploitation   = $this->arrondir($totaux['exploitation']['net']);
        $fluxInvestissement = $this->arrondir($totaux['investissement']['net']);
        $fluxFinancement    = $this->arrondir($totaux['financement']['net']);
        $variationNette     = $this->arrondir($fluxExploitation + $fluxInvestissement + $fluxFinancement);

        // ✅ Trésorerie finale = trésorerie du BILAN + variation nette
        $tresorerieFinale   = $this->arrondir($tresorerieInitiale + $variationNette);

        return [
            'type'                => 'calcule',
            'entite'              => $entite
                ? ['id' => $entite->id, 'code' => $entite->code, 'nom' => $entite->nom]
                : ['id' => 0, 'code' => 'GLOBAL', 'nom' => 'Toutes'],
            'succursale'          => $succursale
                ? ['id' => $succursale->id, 'code' => $succursale->code, 'nom' => $succursale->nom]
                : null,
            'periode'             => $this->formatPeriode($periode, $dateDebut, $dateFin),
            'tresorerie_initiale' => $this->arrondir($tresorerieInitiale),
            'tableau'             => $tableau,
            'totaux'              => $totaux,
            'flux_exploitation'   => $fluxExploitation,
            'flux_investissement' => $fluxInvestissement,
            'flux_financement'    => $fluxFinancement,
            'variation_nette'     => $variationNette,
            'tresorerie_finale'   => $tresorerieFinale,
        ];
    }

    /**
     * Initialise les totaux avec arrondi à 4 décimales
     */
    private function initialiserTotaux(): array
    {
        return [
            'exploitation'   => ['encaissement' => 0.0, 'decaissement' => 0.0, 'net' => 0.0],
            'investissement' => ['encaissement' => 0.0, 'decaissement' => 0.0, 'net' => 0.0],
            'financement'    => ['encaissement' => 0.0, 'decaissement' => 0.0, 'net' => 0.0],
        ];
    }

    /**
     * Arrondit tous les totaux à 4 décimales
     */
    private function arrondirTotaux(array $totaux): array
    {
        foreach ($totaux as $section => $valeurs) {
            foreach ($valeurs as $type => $valeur) {
                $totaux[$section][$type] = $this->arrondir($valeur);
            }
        }
        return $totaux;
    }

    /**
     * Arrondit toutes les charges à 4 décimales
     */
    private function arrondirCharges(array $charges): array
    {
        foreach ($charges as $categorie => $valeur) {
            $charges[$categorie] = $this->arrondir($valeur);
        }
        return $charges;
    }

    /**
     * ✅ CALCUL DE LA TRÉSORERIE INITIALE - LOGIQUE BILAN
     *
     *  RÈGLES :
     *  ─────────────────────────────────────────────────────────
     *  1) Si la période a une période précédente avec un ARCHIVE,
     *     on utilise la trésorerie_finale de cet archive (BILAN clôturé).
     *
     *  2) Sinon, on calcule le solde comptable des comptes 5x
     *     (hors 58x) à la date de début de la période, EN INCLUANT
     *     les écritures de BILAN INITIAL / À-NOUVEAU / OUVERTURE.
     *
     *  ❌ NE JAMAIS recalculer cette valeur à partir des flux.
     *  ✅ C'est une valeur figée du BILAN.
     *  ─────────────────────────────────────────────────────────
     */
    private function calculerTresorerieInitialeDepuisBilan(
        PeriodesComptable $periode,
        ?int $entiteId,
        ?int $succursaleId
    ): float {
        // 1) Priorité : archive de la période précédente (BILAN clôturé)
        $periodePrecedente = $periode->periode_precedente;

        if ($periodePrecedente) {
            $archive = FluxTresorerieArchive::where('periode_id', $periodePrecedente->id)
                ->when($entiteId, fn($q) => $q->where('entite_id', $entiteId))
                ->when($succursaleId, fn($q) => $q->where('succursale_id', $succursaleId))
                ->first();

            if ($archive && $archive->tresorerie_finale !== null) {
                return $this->arrondir((float) $archive->tresorerie_finale);
            }
        }

        // 2) Sinon : solde comptable des comptes 5x à la date de début
        return $this->calculerSoldeTresorerieBilan($periode, $entiteId, $succursaleId);
    }

    /**
     * ✅ CALCUL DU SOLDE DE TRÉSORERIE DEPUIS LE BILAN
     *
     *  On récupère le solde d'OUVERTURE des comptes 5x en prenant :
     *  ─────────────────────────────────────────────────────────
     *  1) Les écritures AVANT la période (date_ecriture < date_debut)
     *  2) Les écritures du JOUR DE DÉBUT avec référence
     *     BILAN INITIAL / À-NOUVEAU / OUVERTURE / REPORT
     *
     *  ❌ On exclut les mouvements normaux de la période
     *  ✅ On garde uniquement les écritures d'ouverture (bilan initial)
     *
     *  Exemple avec le bilan initial :
     *    572 Caisse Shop     Débit 10 000,00
     *    101 Capital social  Crédit 10 000,00
     *    Ref: BILAN INITIAL - ... - EXERCICE 2026
     *
     *  → solde 5x = 10 000,00 ✅
     *  ─────────────────────────────────────────────────────────
     */
    private function calculerSoldeTresorerieBilan(
        PeriodesComptable $periode,
        ?int $entiteId,
        ?int $succursaleId
    ): float {
        $dateDebut = $periode->date_debut instanceof \Carbon\Carbon
            ? $periode->date_debut->format('Y-m-d')
            : $periode->date_debut;

        // ✅ Comptes de trésorerie : 51 à 57 (hors 58)
        $comptesTresorerie = PlanComptable::where('position_bilan', 'actif')
            ->where('numero', 'REGEXP', '^5[1-7]')
            ->where('numero', 'NOT LIKE', '58%')
            ->pluck('numero')
            ->toArray();

        if (empty($comptesTresorerie)) {
            // Fallback : préfixes standards SYSCOHADA
            $comptesTresorerie = ['51', '52', '53', '54', '55', '56', '57'];
        }

        // ✅ Construit la condition pour matcher les comptes 5x
        $matchComptes = function ($q) use ($comptesTresorerie) {
            foreach ($comptesTresorerie as $compte) {
                $q->orWhere('compte_numero', 'LIKE', $compte . '%');
            }
        };

        // ============================================================
        // ✅ CORRECTION PRINCIPALE :
        //    On cherche les écritures de trésorerie qui sont :
        //    - AVANT la période
        //    OU
        //    - À la date de début AVEC référence "BILAN INITIAL" / "À-NOUVEAU"
        // ============================================================
        $query = EcrituresComptable::where(function ($q) use ($dateDebut) {
            // 1) Écritures AVANT la période
            $q->where('date_ecriture', '<', $dateDebut);

            // 2) OU écritures à la date de début avec référence d'ouverture
            $q->orWhere(function ($q2) use ($dateDebut) {
                $q2->whereDate('date_ecriture', '=', $dateDebut)
                   ->where(function ($q3) {
                       $q3->where('reference', 'LIKE', '%BILAN INITIAL%')
                          ->orWhere('reference', 'LIKE', '%BILAN%INITIAL%')
                          ->orWhere('reference', 'LIKE', '%À-NOUVEAU%')
                          ->orWhere('reference', 'LIKE', '%A-NOUVEAU%')
                          ->orWhere('reference', 'LIKE', '%OUVERTURE%')
                          ->orWhere('reference', 'LIKE', '%REPORT%')
                          ->orWhere('reference', 'LIKE', '%NOUVEAU%');
                   });
            });
        })
        ->where($matchComptes);

        if ($entiteId) $query->where('entite_id', $entiteId);
        if ($succursaleId) $query->where('succursale_id', $succursaleId);

        $debit = (float) (clone $query)->sum('debit');
        $credit = (float) (clone $query)->sum('credit');
        $solde = $this->arrondir($debit - $credit);

        // ✅ Log de diagnostic
        Log::info('[FluxTresorerie] Trésorerie initiale calculée', [
            'periode_id'    => $periode->id,
            'date_debut'    => $dateDebut,
            'entite_id'     => $entiteId,
            'succursale_id' => $succursaleId,
            'debit_total'   => $debit,
            'credit_total'  => $credit,
            'solde'         => $solde,
            'comptes'       => $comptesTresorerie,
            'nb_ecritures'  => (clone $query)->count(),
        ]);

        return $solde;
    }

    /**
     * Calcul du solde avec SENS NATUREL selon la position du Plan Comptable
     */
    private function calculerSoldeParPosition(string $position, string $racine, $periode, $entiteId, $succursaleId): float
    {
        $comptes = PlanComptable::where('position_bilan', $position)
            ->where('numero', 'LIKE', $racine . '%')
            ->pluck('numero')
            ->toArray();

        if (empty($comptes)) {
            return 0.0;
        }

        $dateFin = $periode->date_fin instanceof \Carbon\Carbon
            ? $periode->date_fin->format('Y-m-d')
            : $periode->date_fin;

        $query = EcrituresComptable::where('date_ecriture', '<=', $dateFin)
            ->where(function ($q) use ($comptes) {
                foreach ($comptes as $compte) {
                    $q->orWhere('compte_numero', 'LIKE', $compte . '%');
                }
            });

        if ($entiteId) $query->where('entite_id', $entiteId);
        if ($succursaleId) $query->where('succursale_id', $succursaleId);

        $debit = (float) (clone $query)->sum('debit');
        $credit = (float) (clone $query)->sum('credit');

        return $this->arrondir($position === 'actif' ? $debit - $credit : $credit - $debit);
    }

    /**
     * Calcul du DSO (Days Sales Outstanding)
     */
    private function calculerDSO($periode, $entiteId, $succursaleId): float
    {
        $creances = $this->calculerSoldeParPosition('actif', '41', $periode, $entiteId, $succursaleId);
        $ca = $this->calculerCATotal($periode, $entiteId, $succursaleId);

        if ($ca <= 0) return 0.0;

        $dureePeriode = $this->getDureePeriode($periode);

        return $this->arrondir(($creances / $ca) * $dureePeriode);
    }

    /**
     * Calcul du DPO (Days Payable Outstanding)
     */
    private function calculerDPO($periode, $entiteId, $succursaleId): float
    {
        $dettes = $this->calculerSoldeParPosition('passif', '40', $periode, $entiteId, $succursaleId);
        $achats = $this->calculerAchatsTotal($periode, $entiteId, $succursaleId);

        if ($achats <= 0) return 0.0;

        $dureePeriode = $this->getDureePeriode($periode);

        return $this->arrondir(($dettes / $achats) * $dureePeriode);
    }

    /**
     * Durée réelle de la période en jours
     */
    private function getDureePeriode($periode): int
    {
        $dateDebut = $periode->date_debut instanceof \Carbon\Carbon
            ? $periode->date_debut : \Carbon\Carbon::parse($periode->date_debut);
        $dateFin = $periode->date_fin instanceof \Carbon\Carbon
            ? $periode->date_fin : \Carbon\Carbon::parse($periode->date_fin);

        return $dateDebut->diffInDays($dateFin) + 1;
    }

    /**
     * Calcul du Chiffre d'Affaires
     */
    private function calculerCA($periode, $entiteId, $succursaleId): array
    {
        $comptesProduits = PlanComptable::where('position_tfr', 'produit')
            ->pluck('numero')
            ->toArray();

        if (empty($comptesProduits)) {
            return ['valeur' => 0.0, 'evolution' => null];
        }

        $dateDebut = $periode->date_debut instanceof \Carbon\Carbon
            ? $periode->date_debut->format('Y-m-d') : $periode->date_debut;
        $dateFin = $periode->date_fin instanceof \Carbon\Carbon
            ? $periode->date_fin->format('Y-m-d') : $periode->date_fin;

        $query = EcrituresComptable::whereBetween('date_ecriture', [$dateDebut, $dateFin])
            ->where(function ($q) use ($comptesProduits) {
                foreach ($comptesProduits as $compte) {
                    $q->orWhere('compte_numero', 'LIKE', $compte . '%');
                }
            })
            ->where('reference', 'NOT LIKE', 'CLÔTURE%')
            ->where('reference', 'NOT LIKE', 'CLOTURE%')
            ->where('reference', 'NOT LIKE', 'BILAN%');

        if ($entiteId) $query->where('entite_id', $entiteId);
        if ($succursaleId) $query->where('succursale_id', $succursaleId);

        $valeur = $this->arrondir((float) $query->sum('credit') - (float) $query->sum('debit'));

        return ['valeur' => $valeur, 'evolution' => null];
    }

    private function calculerCATotal($periode, $entiteId, $succursaleId): float
    {
        $ca = $this->calculerCA($periode, $entiteId, $succursaleId);
        return $this->arrondir($ca['valeur']);
    }

    /**
     * Calcul du total des achats
     */
    private function calculerAchatsTotal($periode, $entiteId, $succursaleId): float
    {
        $comptesAchats = PlanComptable::where('position_tfr', 'charge')
            ->where('numero', 'LIKE', '60%')
            ->pluck('numero')
            ->toArray();

        if (empty($comptesAchats)) {
            return 0.0;
        }

        $dateDebut = $periode->date_debut instanceof \Carbon\Carbon
            ? $periode->date_debut->format('Y-m-d') : $periode->date_debut;
        $dateFin = $periode->date_fin instanceof \Carbon\Carbon
            ? $periode->date_fin->format('Y-m-d') : $periode->date_fin;

        $query = EcrituresComptable::whereBetween('date_ecriture', [$dateDebut, $dateFin])
            ->where(function ($q) use ($comptesAchats) {
                foreach ($comptesAchats as $compte) {
                    $q->orWhere('compte_numero', 'LIKE', $compte . '%');
                }
            })
            ->where('reference', 'NOT LIKE', 'CLÔTURE%')
            ->where('reference', 'NOT LIKE', 'CLOTURE%')
            ->where('reference', 'NOT LIKE', 'BILAN%');

        if ($entiteId) $query->where('entite_id', $entiteId);
        if ($succursaleId) $query->where('succursale_id', $succursaleId);

        return $this->arrondir((float) $query->sum('debit') - (float) $query->sum('credit'));
    }

    /**
     * Calcul de la marge brute
     */
    private function calculerMargeBrute($periode, $entiteId, $succursaleId): array
    {
        $ca = $this->calculerCATotal($periode, $entiteId, $succursaleId);
        $achats = $this->calculerAchatsTotal($periode, $entiteId, $succursaleId);
        $valeur = $this->arrondir($ca - $achats);

        return ['valeur' => $valeur, 'evolution' => null];
    }

    /**
     * Répartition des charges par catégorie
     */
    private function getRepartitionCharges($periode, $entiteId, $succursaleId): array
    {
        $categories = [
            'achats' => PlanComptable::where('position_tfr', 'charge')
                ->where('numero', 'LIKE', '60%')
                ->orWhere('numero', 'LIKE', '61%')
                ->pluck('numero')->toArray(),
            'services_externes' => PlanComptable::where('position_tfr', 'charge')
                ->where('numero', 'LIKE', '62%')
                ->orWhere('numero', 'LIKE', '63%')
                ->pluck('numero')->toArray(),
            'personnel' => PlanComptable::where('position_tfr', 'charge')
                ->where('numero', 'LIKE', '64%')
                ->orWhere('numero', 'LIKE', '65%')
                ->orWhere('numero', 'LIKE', '66%')
                ->pluck('numero')->toArray(),
        ];

        $dateDebut = $periode->date_debut instanceof \Carbon\Carbon
            ? $periode->date_debut->format('Y-m-d') : $periode->date_debut;
        $dateFin = $periode->date_fin instanceof \Carbon\Carbon
            ? $periode->date_fin->format('Y-m-d') : $periode->date_fin;

        $charges = [];
        foreach ($categories as $categorie => $comptes) {
            if (empty($comptes)) {
                $charges[$categorie] = 0.0;
                continue;
            }

            $query = EcrituresComptable::whereBetween('date_ecriture', [$dateDebut, $dateFin])
                ->where(function ($q) use ($comptes) {
                    foreach ($comptes as $compte) {
                        $q->orWhere('compte_numero', 'LIKE', $compte . '%');
                    }
                })
                ->where('reference', 'NOT LIKE', 'CLÔTURE%')
                ->where('reference', 'NOT LIKE', 'CLOTURE%')
                ->where('reference', 'NOT LIKE', 'BILAN%');

            if ($entiteId) $query->where('entite_id', $entiteId);
            if ($succursaleId) $query->where('succursale_id', $succursaleId);

            $charges[$categorie] = $this->arrondir((float) $query->sum('debit') - (float) $query->sum('credit'));
        }

        return $charges;
    }

    /**
     * Calcul de la structure financière
     */
    private function calculerStructureFinanciere($periode, $entiteId, $succursaleId): array
    {
        $comptesActifs = PlanComptable::where('position_bilan', 'actif')->pluck('numero')->toArray();
        $comptesPassifs = PlanComptable::where('position_bilan', 'passif')->pluck('numero')->toArray();

        $actifImmobilise = $this->calculerSoldeParCategorie($comptesActifs, ['20', '21', '22', '23', '24', '25', '26', '27'], $periode, $entiteId, $succursaleId);
        $actifCirculant = $this->calculerSoldeParCategorie($comptesActifs, ['3', '4', '5'], $periode, $entiteId, $succursaleId);

        $capitauxPropres = $this->calculerSoldeParCategorie($comptesPassifs, ['10', '11', '12', '13', '14', '15'], $periode, $entiteId, $succursaleId);
        $dettesFinancieres = $this->calculerSoldeParCategorie($comptesPassifs, ['16', '17'], $periode, $entiteId, $succursaleId);
        $dettesExploitation = $this->calculerSoldeParCategorie($comptesPassifs, ['40', '41', '42', '43', '44', '45', '46', '47'], $periode, $entiteId, $succursaleId);

        $fr = $this->arrondir($capitauxPropres + $dettesFinancieres - $actifImmobilise);

        $bfr = $this->arrondir($actifCirculant - $dettesExploitation);

        $ratio = $capitauxPropres > 0 ? $this->arrondir(($dettesFinancieres / $capitauxPropres) * 100) : 0;

        return [
            'FR' => $fr,
            'BFR' => $bfr,
            'endettement' => [
                'ratio' => $ratio,
                'dettes_financieres' => $this->arrondir($dettesFinancieres),
                'fonds_propres' => $this->arrondir($capitauxPropres),
            ],
        ];
    }

    /**
     * Calcul du solde par catégorie de comptes
     */
    private function calculerSoldeParCategorie(array $comptes, array $prefixes, $periode, $entiteId, $succursaleId): float
    {
        $dateFin = $periode->date_fin instanceof \Carbon\Carbon
            ? $periode->date_fin->format('Y-m-d')
            : $periode->date_fin;

        $query = EcrituresComptable::where('date_ecriture', '<=', $dateFin)
            ->where(function ($q) use ($prefixes) {
                foreach ($prefixes as $prefixe) {
                    $q->orWhere('compte_numero', 'LIKE', $prefixe . '%');
                }
            });

        if ($entiteId) $query->where('entite_id', $entiteId);
        if ($succursaleId) $query->where('succursale_id', $succursaleId);

        $debit = (float) (clone $query)->sum('debit');
        $credit = (float) (clone $query)->sum('credit');

        $position = $this->determinerPosition($prefixes);

        return $this->arrondir($position === 'actif' ? $debit - $credit : $credit - $debit);
    }

    /**
     * Déterminer la position (actif/passif) des comptes
     */
    private function determinerPosition(array $prefixes): string
    {
        foreach ($prefixes as $prefixe) {
            $premierChiffre = substr($prefixe, 0, 1);
            if (in_array($premierChiffre, ['1', '2', '3', '4', '5'])) {
                return 'actif';
            }
        }
        return 'passif';
    }

    /**
     * Formatage d'une ligne du tableau (arrondi 4 décimales)
     */
    private function formatLigne(FluxTresorerieSection $section, float $montant): array
    {
        $montant = $this->arrondir($montant);
        $encaissement = $section->type_flux === 'encaissement' ? $montant : 0.0;
        $decaissement  = $section->type_flux === 'decaissement' ? $montant : 0.0;
        $fluxNet       = $this->arrondir($montant * $section->sens);

        return [
            'id'              => $section->id,
            'code'            => $section->code,
            'libelle'         => $section->libelle,
            'type_section'    => $section->type_section,
            'type_flux'       => $section->type_flux,
            'encaissement'    => $this->arrondir($encaissement),
            'decaissement'    => $this->arrondir($decaissement),
            'flux_net'        => $fluxNet,
            'comptes_racines' => $section->comptes_racines,
        ];
    }

    /**
     * Formatage de la période
     */
    private function formatPeriode(PeriodesComptable $periode, ?string $dateDebut = null, ?string $dateFin = null): array
    {
        return [
            'id'         => $periode->id,
            'code'       => $periode->code,
            'libelle'    => $periode->libelle,
            'date_debut' => $dateDebut ?? $periode->date_debut,
            'date_fin'   => $dateFin ?? $periode->date_fin,
            'is_cloture' => $periode->is_cloture,
            'statut'     => $periode->statut,
        ];
    }

    private function getPeriode($periodeId)
    {
        if (!empty($periodeId)) {
            return PeriodesComptable::find((int) $periodeId);
        }
        return PeriodesComptable::where('statut', 'ouverte')
            ->orderBy('date_debut', 'asc')
            ->first();
    }

    private function calculerEvolution($ancien, $nouveau): ?float
    {
        if ($ancien == 0) return null;
        return $this->arrondir((($nouveau - $ancien) / abs($ancien)) * 100);
    }

    // =============================================
    // EXPORT EXCEL
    // =============================================

    public function export(Request $request)
    {
        try {
            $entiteId = $request->filled('entite_id') ? (int) $request->entite_id : null;
            $succursaleId = $request->filled('succursale_id') ? (int) $request->succursale_id : null;
            $periodeId = $request->periode_id;

            $periode = null;
            if (!empty($periodeId)) $periode = PeriodesComptable::find((int) $periodeId);
            if (!$periode) $periode = PeriodesComptable::where('statut', 'ouverte')->orderBy('date_debut', 'asc')->first();
            if (!$periode) return response()->json(['success' => false, 'message' => 'Aucun exercice trouvé'], 404);

            $dateDebut = $periode->date_debut instanceof \Carbon\Carbon ? $periode->date_debut->format('Y-m-d') : $periode->date_debut;
            $dateFin = $periode->date_fin instanceof \Carbon\Carbon ? $periode->date_fin->format('Y-m-d') : $periode->date_fin;
            $entite = $entiteId ? Entite::find($entiteId) : null;

            $data = $this->calculerFluxTresorerie($periode, $entite, null, $dateDebut, $dateFin, $entiteId, $succursaleId);

            $data['tresorerie_initiale'] = $this->arrondir($data['tresorerie_initiale'] ?? 0);
            $data['tresorerie_finale']   = $this->arrondir($data['tresorerie_finale'] ?? 0);
            $data['variation_nette']     = $this->arrondir($data['variation_nette'] ?? 0);

            if (isset($data['totaux']) && is_array($data['totaux'])) {
                foreach ($data['totaux'] as $section => $valeurs) {
                    foreach ($valeurs as $type => $valeur) {
                        $data['totaux'][$section][$type] = $this->arrondir($valeur);
                    }
                }
            }

            if (isset($data['tableau']) && is_array($data['tableau'])) {
                foreach ($data['tableau'] as $index => $ligne) {
                    $data['tableau'][$index]['encaissement'] = $this->arrondir($ligne['encaissement'] ?? 0);
                    $data['tableau'][$index]['decaissement'] = $this->arrondir($ligne['decaissement'] ?? 0);
                    $data['tableau'][$index]['flux_net']     = $this->arrondir($ligne['flux_net'] ?? 0);
                }
            }

            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Flux Trésorerie');

            $styleTitre     = ['font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => '1E40AF']]];
            $styleHeader    = ['font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '1E40AF']]];
            $styleSousTotal = ['font' => ['bold' => true, 'size' => 9], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'DBEAFE']]];
            $styleTotal     = ['font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '1E40AF']]];

            $sheet->setCellValue('A1', 'TABLEAU DE FLUX DE TRÉSORERIE')->mergeCells('A1:D1');
            $sheet->getStyle('A1')->applyFromArray($styleTitre)->getAlignment()->setHorizontal('center');

            $row = 3;
            $sheet->setCellValue("A{$row}", 'Entité : ' . ($data['entite']['nom'] ?? 'Toutes'))->mergeCells("A{$row}:D{$row}"); $row++;
            $sheet->setCellValue("A{$row}", 'Exercice : ' . ($data['periode']['code'] ?? '') . ' - ' . ($data['periode']['libelle'] ?? ''))->mergeCells("A{$row}:D{$row}"); $row++;
            $sheet->setCellValue("A{$row}", 'Dates : ' . ($data['periode']['date_debut'] ?? '') . ' au ' . ($data['periode']['date_fin'] ?? ''))->mergeCells("A{$row}:D{$row}"); $row += 2;

            $sheet->setCellValue("A{$row}", 'RUBRIQUE')->setCellValue("B{$row}", 'ENCAISSEMENT')->setCellValue("C{$row}", 'DÉCAISSEMENT')->setCellValue("D{$row}", 'FLUX NET');
            $sheet->getStyle("A{$row}:D{$row}")->applyFromArray($styleHeader); $row++;

            $sectionsByType = [];
            foreach ($data['tableau'] as $ligne) {
                $sectionsByType[$ligne['type_section']][] = $ligne;
            }

            $sectionsConfig = [
                'exploitation' => "FLUX LIÉS AUX ACTIVITÉS D'EXPLOITATION",
                'investissement' => 'FLUX LIÉS AUX INVESTISSEMENTS',
                'financement' => 'FLUX LIÉS AU FINANCEMENT'
            ];

            foreach ($sectionsConfig as $type => $titre) {
                $sheet->setCellValue("A{$row}", $titre)->mergeCells("A{$row}:D{$row}")->getStyle("A{$row}")->getFont()->setBold(true); $row++;

                if (isset($sectionsByType[$type])) {
                    foreach ($sectionsByType[$type] as $ligne) {
                        $sheet->setCellValue("A{$row}", $ligne['libelle'])
                            ->setCellValue("B{$row}", $ligne['encaissement'] > 0 ? round($ligne['encaissement'], 4) : '')
                            ->setCellValue("C{$row}", $ligne['decaissement'] > 0 ? round($ligne['decaissement'], 4) : '')
                            ->setCellValue("D{$row}", round($ligne['flux_net'], 4));
                        $row++;
                    }
                }

                $label = match($type) {
                    'exploitation' => "FLUX NET D'EXPLOITATION (A)",
                    'investissement' => "FLUX NET D'INVESTISSEMENT (B)",
                    'financement' => 'FLUX NET DE FINANCEMENT (C)',
                    default => ''
                };
                $sheet->setCellValue("A{$row}", $label)->mergeCells("A{$row}:C{$row}")->setCellValue("D{$row}", round($data['totaux'][$type]['net'] ?? 0, 4));
                $sheet->getStyle("A{$row}:D{$row}")->applyFromArray($styleSousTotal); $row += 2;
            }

            $sheet->setCellValue("A{$row}", 'VARIATION NETTE DE TRÉSORERIE (A + B + C)')->mergeCells("A{$row}:C{$row}")->setCellValue("D{$row}", round($data['variation_nette'], 4));
            $sheet->getStyle("A{$row}:D{$row}")->applyFromArray($styleTotal); $row++;

            // ✅ TRÉSORERIE INITIALE : valeur FIGÉE du BILAN
            $sheet->setCellValue("A{$row}", "TRÉSORERIE AU DÉBUT DE L'EXERCICE (BILAN)")->mergeCells("A{$row}:C{$row}")->setCellValue("D{$row}", round($data['tresorerie_initiale'], 4));
            $sheet->getStyle("A{$row}:D{$row}")->applyFromArray($styleSousTotal); $row++;

            // ✅ TRÉSORERIE FINALE = initiale (BILAN) + variation nette
            $sheet->setCellValue("A{$row}", "TRÉSORERIE À LA FIN DE L'EXERCICE")->mergeCells("A{$row}:C{$row}")->setCellValue("D{$row}", round($data['tresorerie_finale'], 4));
            $sheet->getStyle("A{$row}:D{$row}")->applyFromArray($styleTotal);

            $sheet->getColumnDimension('A')->setWidth(50);
            $sheet->getColumnDimension('B')->setWidth(18);
            $sheet->getColumnDimension('C')->setWidth(18);
            $sheet->getColumnDimension('D')->setWidth(18);
            $sheet->getStyle('B1:D' . $row)->getNumberFormat()->setFormatCode('#,##0.0000');

            $filename = 'Flux_Tresorerie_' . ($data['entite']['code'] ?? 'GLOBAL') . '_' . date('Ymd_His') . '.xlsx';

            return response()->streamDownload(function () use ($spreadsheet) {
                $writer = new Xlsx($spreadsheet);
                $writer->save('php://output');
            }, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'max-age=0',
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur export flux trésorerie : ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur export : ' . $e->getMessage()], 500);
        }
    }

    // =============================================
    // API UTILITAIRES
    // =============================================

    public function getSuccursales($entiteId)
    {
        return response()->json([
            'success' => true,
            'succursales' => Succursale::where('entite_id', $entiteId)->where('est_active', 1)->orderBy('code', 'asc')->get()
        ]);
    }

    public function getPeriodes()
    {
        return response()->json([
            'success' => true,
            'periodes' => PeriodesComptable::orderBy('date_debut', 'desc')->get(['id', 'code', 'libelle', 'date_debut', 'date_fin', 'is_cloture', 'statut'])
        ]);
    }

    public function getPeriodeEnCours()
    {
        return response()->json([
            'success' => true,
            'periode' => PeriodesComptable::where('statut', 'ouverte')->orderBy('date_debut', 'asc')->first()
        ]);
    }
}