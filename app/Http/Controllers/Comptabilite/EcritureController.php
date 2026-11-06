<?php

namespace App\Http\Controllers\Comptabilite;

use App\Models\OperationsJournal;
use App\Models\EcrituresComptable;
use App\Models\Entite;
use App\Models\Succursale;
use App\Models\OperationsType;
use App\Models\PlanComptable;
use App\Models\PeriodesComptable;
use App\Services\EcritureService;
use App\Models\ImmobilisationsComptable;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;

class EcritureController extends Controller
{
    protected EcritureService $ecritureService;

    public function __construct(EcritureService $ecritureService)
    {
        $this->ecritureService = $ecritureService;
    }

    // =========================================================================
    // AFFICHAGE VUES
    // =========================================================================

    public function index()
    {
        $entites = Entite::orderBy('code', 'asc')->get();
        $operationTypes = OperationsType::orderBy('code', 'asc')->get();
        $periodes = PeriodesComptable::ouvertes()->orderBy('date_debut', 'desc')->get();

        return view('sections.ecritures', compact('entites', 'operationTypes', 'periodes'));
    }

    // =========================================================================
    // ROUTES API - LECTURE ET RECUPERATION DE DONNEES
    // =========================================================================

    public function getApiEntites(): JsonResponse
    {
        return response()->json(Entite::orderBy('code', 'asc')->get());
    }

    public function getApiPeriodes(): JsonResponse
    {
        return response()->json(PeriodesComptable::orderBy('date_debut', 'desc')->get());
    }

    public function getApiOperationsTypes(Request $request): JsonResponse
    {
        $entiteId = $this->resolveEntiteId($request);
        $types = $this->fetchOperationTypesByEntite($entiteId);

        return response()->json($types);
    }

    public function getOperationTypesByEntite($entiteId): JsonResponse
    {
        $resolvedId = $this->resolveEntiteIdFromParam($entiteId);
        if (!$resolvedId) {
            return response()->json([]);
        }

        return response()->json($this->fetchOperationTypesByEntite($resolvedId));
    }

    public function getSuccursalesByEntite($entiteId): JsonResponse
    {
        $resolvedId = $this->resolveEntiteIdFromParam($entiteId);
        if (!$resolvedId) {
            return response()->json([
                'success'     => false,
                'message'     => 'Entite non trouvee',
                'succursales' => []
            ], 404);
        }

        $succursales = Succursale::where('entite_id', $resolvedId)
            ->where('est_active', 1)
            ->orderBy('code', 'asc')
            ->get();

        return response()->json(['success' => true, 'succursales' => $succursales]);
    }

    public function checkCompte(Request $request): JsonResponse
    {
        $compteNumero = trim($request->get('compte_numero'));
        if (!$compteNumero) {
            return response()->json(['exists' => false, 'message' => 'Numero de compte requis']);
        }

        $compte = PlanComptable::where('numero', $compteNumero)->first();

        return response()->json([
            'exists'  => (bool) $compte,
            'message' => $compte ? "Compte valide : {$compte->nom}" : "Le compte '{$compteNumero}' n'existe pas dans le plan comptable",
            'compte'  => $compte
        ]);
    }

    public function getOperationsByTypeAndEntite(Request $request): JsonResponse
    {
        $operationTypeId = $request->get('operation_type_id');
        $entiteId = $this->resolveEntiteId($request);

        $operations = OperationsJournal::with(['operations_autos', 'entites'])
            ->where('operation_type_id', $operationTypeId)
            ->where('is_active', 1)
            ->whereHas('entites', function($query) use ($entiteId) {
                $query->where('entites.id', $entiteId);
            })
            ->get();

        return response()->json(['success' => true, 'operations' => $operations]);
    }

    public function getOperationDetails($id): JsonResponse
    {
        $operation = OperationsJournal::with(['operations_autos', 'operations_type', 'entites'])
            ->findOrFail($id);

        $numerosComptes = collect([$operation->compte_debit, $operation->compte_credit]);

        if ($operation->operations_autos) {
            foreach ($operation->operations_autos as $auto) {
                $numerosComptes->push($auto->compte_debit, $auto->compte_credit);
            }
        }

        $comptesMap = PlanComptable::whereIn('numero', $numerosComptes->filter()->unique())
            ->pluck('nom', 'numero');

        $operation->compte_debit_nom  = $comptesMap[$operation->compte_debit] ?? 'Compte inconnu';
        $operation->compte_credit_nom = $comptesMap[$operation->compte_credit] ?? 'Compte inconnu';

        if ($operation->operations_autos) {
            foreach ($operation->operations_autos as $auto) {
                $auto->compte_debit_nom  = $comptesMap[$auto->compte_debit] ?? 'Compte inconnu';
                $auto->compte_credit_nom = $comptesMap[$auto->compte_credit] ?? 'Compte inconnu';
            }
        }

        //  Ajouter les informations du Plan Comptable
        $operation->compte_debit_info  = PlanComptable::where('numero', $operation->compte_debit)->first();
        $operation->compte_credit_info = PlanComptable::where('numero', $operation->compte_credit)->first();

        return response()->json([
            'success'   => true,
            'operation' => $operation,
        ]);
    }

    // =========================================================================
    // ENREGISTREMENT ET APERCU (SAISIE D'ECRITURES)
    // =========================================================================

    public function preview(Request $request): JsonResponse
    {
        $sanitized = $this->sanitizeRequestData($request);

        $validator = Validator::make($request->all(), [
            'operation_journal_id' => 'required|exists:operations_journal,id',
            'code'                 => 'nullable|string|max:50',
            'entite_id'            => 'nullable|exists:entites,id',
            'succursale_id'        => 'nullable|exists:succursales,id',
            'periode_id'           => 'nullable|exists:periodes_comptables,id',
            'montant'              => 'required|numeric|min:0.0001',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        if (empty($request->code) && empty($request->entite_id)) {
            return response()->json([
                'success' => false,
                'message' => "Le champ 'code' ou 'entite_id' est requis."
            ], 422);
        }

        $data = $this->buildDataArray($request, $sanitized, 'PREVIEW-' . time());
        return response()->json($this->ecritureService->previewEcriture($data));
    }

    public function store(Request $request): JsonResponse
    {
        Log::info("Tentative d'enregistrement d'ecriture", ['input' => $request->except(['auto_overrides'])]);

        $sanitized = $this->sanitizeRequestData($request);

        $validator = Validator::make($request->all(), [
            'operation_journal_id' => 'required|exists:operations_journal,id',
            'code'                 => 'nullable|string|max:50',
            'entite_id'            => 'nullable|exists:entites,id',
            'succursale_id'        => 'nullable|exists:succursales,id',
            'periode_id'           => 'nullable|exists:periodes_comptables,id',
            'date_ecriture'        => 'required|date',
            'montant'              => 'required|numeric|min:0.0001',
            'reference'            => 'required|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        if (empty($request->code) && empty($request->entite_id)) {
            return response()->json([
                'success' => false,
                'message' => "Le champ 'code' ou 'entite_id' est requis."
            ], 422);
        }

        $data = $this->buildDataArray($request, $sanitized);

        try {
            $resultat = $this->ecritureService->creerEcriture($data);

            if (!$resultat['success']) {
                return response()->json($resultat, 400);
            }

            Log::info("Ecriture comptable enregistree", ['numero' => $resultat['numero_ecriture'] ?? 'N/A']);
            return response()->json($resultat, 201);

        } catch (\Exception $e) {
            Log::error("Exception lors de la creation d'ecriture : " . $e->getMessage());
            Log::error("Trace : " . $e->getTraceAsString());
            return response()->json([
                'success' => false,
                'message' => "Erreur systeme lors de l'enregistrement comptable."
            ], 500);
        }
    }

    public function storeSimple(Request $request): JsonResponse
    {
        $sanitized = $this->sanitizeRequestData($request);

        $validator = Validator::make($request->all(), [
            'operation_libelle' => 'required|string',
            'code'              => 'nullable|string|max:50',
            'entite_id'         => 'nullable|exists:entites,id',
            'succursale_id'     => 'nullable|exists:succursales,id',
            'periode_id'        => 'nullable|exists:periodes_comptables,id',
            'date_ecriture'     => 'required|date',
            'montant'           => 'required|numeric|min:0.0001',
            'reference'         => 'required|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $data = $this->buildSimpleDataArray($request, $sanitized);

        try {
            $resultat = $this->ecritureService->creerEcritureSimple($data);

            if (!$resultat['success']) {
                return response()->json($resultat, 400);
            }

            return response()->json($resultat, 201);

        } catch (\Exception $e) {
            Log::error("Exception ecriture simple : " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => "Erreur systeme lors du traitement."
            ], 500);
        }
    }

    // =========================================================================
    // ANNULATION D'ECRITURES (CONTRE-PASSATION CONFORME)
    // =========================================================================

    public function annuler(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ecriture_id'     => 'required|exists:ecritures_comptables,id',
            'motif'           => 'required|string|min:5|max:500',
            'date_annulation' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $verification = $this->verifierFaisabiliteAnnulation($request->ecriture_id);
        if (!$verification['peut_etre_annulee']) {
            return response()->json([
                'success' => false,
                'message' => $verification['raison_blocage']
            ], 400);
        }

        try {
            $resultat = $this->ecritureService->annulerEcriture(
                $request->ecriture_id,
                $request->motif,
                $request->date_annulation ?? now()->format('Y-m-d')
            );

            if (!$resultat['success']) {
                return response()->json($resultat, 400);
            }

            return response()->json($resultat, 200);

        } catch (\Exception $e) {
            Log::error("Erreur annulation: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => "Erreur interne lors de la contre-passation."
            ], 500);
        }
    }

    public function previewAnnulation(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ecriture_id'     => 'required|exists:ecritures_comptables,id',
            'motif'           => 'nullable|string|max:500',
            'date_annulation' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $resultat = $this->ecritureService->previewAnnulation(
            $request->ecriture_id,
            $request->motif,
            $request->date_annulation ?? now()->format('Y-m-d')
        );

        return response()->json($resultat, $resultat['success'] ? 200 : 400);
    }

    public function annulerMultiple(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ecriture_ids'    => 'required|array|min:1',
            'ecriture_ids.*'  => 'required|exists:ecritures_comptables,id',
            'motif'           => 'required|string|min:5|max:500',
            'date_annulation' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $resultat = $this->ecritureService->annulerEcrituresMultiple(
            $request->ecriture_ids,
            $request->motif,
            $request->date_annulation ?? now()->format('Y-m-d')
        );

        return response()->json($resultat, $resultat['success'] ? 200 : 207);
    }

    public function peutEtreAnnulee($id): JsonResponse
    {
        $verification = $this->verifierFaisabiliteAnnulation($id);
        return response()->json($verification, $verification['success'] ? 200 : 500);
    }

    public function getHistoriqueAnnulations(Request $request): JsonResponse
    {
        $filters = $this->extractFilters($request);

        $query = EcrituresComptable::with(['operations_journal', 'entite', 'succursale', 'plan_comptable', 'periode'])
            ->where('sens_ecriture', 'annulation')
            ->orderBy('date_ecriture', 'desc')
            ->orderBy('numero_ecriture', 'asc')
            ->orderBy('ordre_affichage', 'asc');

        $this->applyFilters($query, $filters);

        $annulations = $query->get();

        return response()->json([
            'success'      => true,
            'annulations'  => $annulations,
            'grouped'      => $this->groupEcritures($annulations),
            'total'        => $annulations->count(),
            'total_debit'  => round($annulations->sum('debit'), 4),
            'total_credit' => round($annulations->sum('credit'), 4)
        ]);
    }

    // =========================================================================
    // CONSULTATION (CONSULTATION JOURNAL ET GRAND LIVRE)
    // =========================================================================

    public function getEcritures(Request $request): JsonResponse
    {
        $filters = $this->extractFilters($request);

        $query = EcrituresComptable::with(['operations_journal', 'entite', 'succursale', 'plan_comptable', 'periode'])
            ->orderBy('date_ecriture', 'desc')
            ->orderBy('numero_ecriture', 'asc')
            ->orderBy('ordre_affichage', 'asc');

        $this->applyFilters($query, $filters);
        $ecritures = $query->get();

        return response()->json([
            'success'      => true,
            'ecritures'    => $ecritures,
            'grouped'      => $this->groupEcritures($ecritures),
            'total_debit'  => round($ecritures->sum('debit'), 4),
            'total_credit' => round($ecritures->sum('credit'), 4)
        ]);
    }

    public function getGrandLivre(Request $request): JsonResponse
    {
        $compteNumero = trim($request->get('compte_numero'));
        if (!$compteNumero) {
            return response()->json([
                'success' => false,
                'message' => 'Le numero de compte est requis'
            ], 422);
        }

        $compte = PlanComptable::where('numero', $compteNumero)->first();
        if (!$compte) {
            return response()->json([
                'success' => false,
                'message' => 'Compte inexistant'
            ], 404);
        }

        $filters = $this->extractFilters($request);
        $query = EcrituresComptable::where('compte_numero', $compteNumero)
            ->with(['entite', 'succursale', 'operations_journal', 'periode'])
            ->orderBy('date_ecriture', 'asc')
            ->orderBy('ordre_affichage', 'asc');

        $this->applyFilters($query, $filters);
        $ecritures = $query->get();

        //  Utiliser le sens du compte depuis le Plan Comptable
        $sensDebit = $compte->fonctionnement_debit === '+' || $compte->position_bilan === 'actif';
        
        $solde = 0;
        $ecrituresAvecSolde = $ecritures->map(function ($ecriture) use (&$solde, $sensDebit) {
            $variation = $sensDebit
                ? ($ecriture->debit - $ecriture->credit)
                : ($ecriture->credit - $ecriture->debit);

            $solde += $variation;

            return array_merge($ecriture->toArray(), [
                'solde_progressif' => round($solde, 4)
            ]);
        });

        return response()->json([
            'success'   => true,
            'compte'    => $compte,
            'ecritures' => $ecrituresAvecSolde,
            'solde'     => round($solde, 4)
        ]);
    }

    // =========================================================================
    // METHODES PRIVEES DE CONTROLE ET UTILITAIRES
    // =========================================================================

    private function getUserId(): int
    {
        if (auth()->check()) {
            return auth()->id();
        }

        $posSession = session()->get('pos_user_id');
        if ($posSession) {
            return (int) $posSession;
        }

        if (isset($_SESSION['pos_user_id'])) {
            return (int) $_SESSION['pos_user_id'];
        }

        if (isset($_COOKIE['pos_session'])) {
            $cookieData = json_decode($_COOKIE['pos_session'], true);
            if (isset($cookieData['user_id'])) {
                return (int) $cookieData['user_id'];
            }
        }

        throw new \RuntimeException(
            "Aucun utilisateur connecte detecte. " .
            "Vous devez etre authentifie pour effectuer cette operation. " .
            "Veuillez vous reconnecter et reessayer."
        );
    }

    private function validerPeriodeComptable(int $periodeId, ?string $dateEcriture = null): array
    {
        $periode = PeriodesComptable::find($periodeId);

        if (!$periode) {
            return ['valide' => false, 'message' => "La periode comptable specifiee est introuvable."];
        }

        if (in_array($periode->statut, ['cloture_provisoire', 'cloture_definitive', 'ferme'])) {
            return [
                'valide'  => false,
                'message' => "Impossible de realiser une operation : la periode '{$periode->libelle}' est cloturee."
            ];
        }

        if ($dateEcriture) {
            if ($dateEcriture < $periode->date_debut || $dateEcriture > $periode->date_fin) {
                return [
                    'valide'  => false,
                    'message' => "La date de l'ecriture ({$dateEcriture}) est hors limites de la periode selectionnee."
                ];
            }
        }

        return ['valide' => true, 'message' => 'Periode valide'];
    }

    private function verifierFaisabiliteAnnulation($id): array
    {
        try {
            $ecriture = EcrituresComptable::with(['periode', 'entite', 'succursale'])->find($id);

            if (!$ecriture) {
                return [
                    'success'           => false,
                    'peut_etre_annulee' => false,
                    'raison_blocage'    => 'Ecriture introuvable.'
                ];
            }

            if ($ecriture->statut === 'annulee') {
                return [
                    'success'           => true,
                    'peut_etre_annulee' => false,
                    'raison_blocage'    => 'Cette ecriture a deja ete contre-passee.'
                ];
            }

            if ($ecriture->sens_ecriture === 'annulation') {
                return [
                    'success'           => true,
                    'peut_etre_annulee' => false,
                    'raison_blocage'    => "Regle comptable : Une ecriture d'annulation ne peut pas etre annulee."
                ];
            }

            if ($ecriture->periode && in_array($ecriture->periode->statut, ['cloture_definitive', 'cloture_provisoire'])) {
                return [
                    'success'           => true,
                    'peut_etre_annulee' => false,
                    'raison_blocage'    => "La periode comptable rattachee ('{$ecriture->periode->libelle}') est fermee."
                ];
            }

            return [
                'success'           => true,
                'peut_etre_annulee' => true,
                'raison_blocage'    => null,
                'ecriture'          => [
                    'id'         => $ecriture->id,
                    'numero'     => $ecriture->numero_ecriture,
                    'date'       => $ecriture->date_ecriture ? $ecriture->date_ecriture->format('d/m/Y') : 'N/A',
                    'libelle'    => $ecriture->libelle,
                    'montant'    => round($ecriture->debit ?: $ecriture->credit, 4),
                    'statut'     => $ecriture->statut,
                    'entite'     => $ecriture->entite->code ?? 'N/A',
                    'succursale' => $ecriture->succursale->code ?? 'N/A',
                    'periode'    => $ecriture->periode->libelle ?? 'N/A',
                ]
            ];

        } catch (\Exception $e) {
            Log::error("Erreur controle annulation : " . $e->getMessage());
            return [
                'success'           => false,
                'peut_etre_annulee' => false,
                'message'           => "Erreur systeme lors du controle d'annulation."
            ];
        }
    }

    private function fetchOperationTypesByEntite(?int $entiteId)
    {
        if (!$entiteId) {
            return OperationsType::orderBy('code', 'asc')->get();
        }

        return OperationsType::whereHas('operations_journals', function ($query) use ($entiteId) {
            $query->where('is_active', 1)
                  ->whereHas('entites', function($q) use ($entiteId) {
                      $q->where('entites.id', $entiteId);
                  });
        })->orderBy('code', 'asc')->get();
    }

    private function sanitizeRequestData(Request $request): array
    {
        $montant = $request->montant;
        if (is_string($montant)) {
            $montant = str_replace(',', '.', $montant);
        }
        $montant = round((float) $montant, 4);

        $autoOverrides = $request->auto_overrides ?? [];
        if (is_string($autoOverrides)) {
            $autoOverrides = json_decode($autoOverrides, true) ?? [];
        }

        foreach ($autoOverrides as &$override) {
            if (isset($override['valeur'])) {
                if (is_string($override['valeur'])) {
                    $override['valeur'] = str_replace(',', '.', $override['valeur']);
                }
                $override['valeur'] = round((float) $override['valeur'], 4);
            }
        }
        unset($override);

        return ['montant' => $montant, 'auto_overrides' => $autoOverrides];
    }

    private function buildDataArray(Request $request, array $sanitized, ?string $forceReference = null): array
    {
        $data = [
            'operation_journal_id' => $request->operation_journal_id,
            'succursale_id'        => $request->succursale_id,
            'date_ecriture'        => $request->date_ecriture ?? now()->format('Y-m-d'),
            'montant'              => $sanitized['montant'],
            'reference'            => $forceReference ?? $request->reference,
            'libelle'              => $request->libelle ?? null,
            'auto_overrides'       => $sanitized['auto_overrides'],
            'user_id'              => $this->getUserId(),
        ];
    
        if (!empty($request->periode_id)) {
            $data['periode_id'] = $request->periode_id;
        }
    
        if (!empty($request->code)) {
            $data['code'] = $request->code;
        }
    
        if (!empty($request->entite_id)) {
            $data['entite_id'] = $request->entite_id;
        }
    
        //  Ajouter les champs d'amortissement optionnels
        if ($request->has('forcer_amortissement')) {
            $data['forcer_amortissement'] = $request->forcer_amortissement;
        }
        
        if ($request->has('duree_amortissement')) {
            $data['duree_amortissement'] = $request->duree_amortissement;
        }
        
        if ($request->has('date_mise_service')) {
            $data['date_mise_service'] = $request->date_mise_service;
        }
        
        if ($request->has('valeur_residuelle')) {
            $data['valeur_residuelle'] = round((float) $request->valeur_residuelle, 4);
        }
    
        return $data;
    }
    
    private function buildSimpleDataArray(Request $request, array $sanitized): array
    {
        $data = [
            'operation_libelle' => $request->operation_libelle,
            'succursale_id'     => $request->succursale_id,
            'date_ecriture'     => $request->date_ecriture,
            'montant'           => $sanitized['montant'],
            'reference'         => $request->reference,
            'libelle'           => $request->libelle ?? null,
            'auto_overrides'    => $sanitized['auto_overrides'],
            'user_id'           => $this->getUserId(),
        ];
    
        if (!empty($request->periode_id)) {
            $data['periode_id'] = $request->periode_id;
        }
    
        if (!empty($request->code)) {
            $data['code'] = $request->code;
        }
    
        if (!empty($request->entite_id)) {
            $data['entite_id'] = $request->entite_id;
        }
    
        //  Ajouter les champs d'amortissement optionnels
        if ($request->has('forcer_amortissement')) {
            $data['forcer_amortissement'] = $request->forcer_amortissement;
        }
        
        if ($request->has('duree_amortissement')) {
            $data['duree_amortissement'] = $request->duree_amortissement;
        }
        
        if ($request->has('date_mise_service')) {
            $data['date_mise_service'] = $request->date_mise_service;
        }
        
        if ($request->has('valeur_residuelle')) {
            $data['valeur_residuelle'] = round((float) $request->valeur_residuelle, 4);
        }
    
        return $data;
    }

    private function resolveEntiteId(Request $request): ?int
    {
        $entiteId = $request->get('entite_id');
        $code = $request->get('code');

        if (!$entiteId && $code) {
            $entite = Entite::where('code', strtoupper($code))->first();
            return $entite ? $entite->id : null;
        }

        return $entiteId ? (int) $entiteId : null;
    }

    private function resolveEntiteIdFromParam($entiteId): ?int
    {
        if (!is_numeric($entiteId)) {
            $entite = Entite::where('code', strtoupper($entiteId))->first();
            return $entite ? $entite->id : null;
        }

        return (int) $entiteId;
    }

    private function extractFilters(Request $request): array
    {
        return [
            'entite_id'     => $this->resolveEntiteId($request),
            'succursale_id' => $request->get('succursale_id'),
            'periode_id'    => $request->get('periode_id'),
            'date_debut'    => $request->get('date_debut'),
            'date_fin'      => $request->get('date_fin'),
            'compte_numero' => $request->get('compte_numero')
        ];
    }

    private function applyFilters($query, array $filters): void
    {
        if (!empty($filters['entite_id']) && $filters['entite_id'] !== 'all') {
            $query->where('entite_id', $filters['entite_id']);
        }
        if (!empty($filters['succursale_id']) && $filters['succursale_id'] !== 'all') {
            $query->where('succursale_id', $filters['succursale_id']);
        }
        if (!empty($filters['periode_id']) && $filters['periode_id'] !== 'all') {
            $query->where('periode_id', $filters['periode_id']);
        }
        if (!empty($filters['date_debut'])) {
            $query->whereDate('date_ecriture', '>=', $filters['date_debut']);
        }
        if (!empty($filters['date_fin'])) {
            $query->whereDate('date_ecriture', '<=', $filters['date_fin']);
        }
        if (!empty($filters['compte_numero'])) {
            $query->where('compte_numero', $filters['compte_numero']);
        }
    }

    private function groupEcritures($ecritures): array
    {
        $grouped = [];

        foreach ($ecritures as $ecriture) {
            $num = $ecriture->numero_ecriture;

            if (!isset($grouped[$num])) {
                $grouped[$num] = [
                    'numero_ecriture' => $num,
                    'date_ecriture'   => $ecriture->date_ecriture instanceof \Carbon\Carbon
                                            ? $ecriture->date_ecriture->format('d/m/Y')
                                            : $ecriture->date_ecriture,
                    'periode'         => $ecriture->periode ? $ecriture->periode->libelle : 'N/A',
                    'lignes'          => []
                ];
            }

            $grouped[$num]['lignes'][] = $ecriture;
        }

        return array_values($grouped);
    }

    /**
     *  Vérification de la configuration d'immobilisation
     *  NON BLOQUANTE : Retourne des informations sans empêcher l'enregistrement
     */
    public function checkImmobilisationConfig(Request $request): JsonResponse
    {
        $compteDebit = $request->compte_debit ?? '';
        $compteCredit = $request->compte_credit ?? '';
        $libelle = $request->libelle ?? '';
        $reference = $request->reference ?? '';
        $operationLibelle = $request->operation_libelle ?? '';
        
        //  Vérifier si c'est un compte d'immobilisation
        $compteImmobilisation = $this->detecterCompteImmobilisation($compteDebit, $compteCredit);
        
        if (!$compteImmobilisation) {
            return response()->json([
                'success' => true,
                'configured' => true,
                'is_immobilisation' => false,
                'message' => "Ce n'est pas une immobilisation.",
            ]);
        }
        
        $comptesAVerifier = array_filter([$compteDebit, $compteCredit]);
        
        //  Vérifier si une immobilisation existe déjà
        $query = ImmobilisationsComptable::where('est_actif', true)
            ->where(function ($q) use ($comptesAVerifier) {
                foreach ($comptesAVerifier as $compte) {
                    $q->orWhere('compte_acquisition', $compte)
                      ->orWhere('compte_amortissement', $compte)
                      ->orWhere('compte_dotation', $compte);
                }
            });
        
        //  Recherche par libellé et référence
        if (!empty($libelle) && !empty($reference)) {
            $immo = (clone $query)
                ->where('libelle', $libelle)
                ->where(function ($q) use ($reference) {
                    $q->where('reference', $reference)
                      ->orWhere('code', $reference);
                })
                ->first();
            
            if ($immo) {
                return response()->json([
                    'success' => true,
                    'configured' => true,
                    'is_immobilisation' => true,
                    'immo' => [
                        'id' => $immo->id,
                        'code' => $immo->code,
                        'reference' => $immo->reference,
                        'libelle' => $immo->libelle,
                    ],
                ]);
            }
        }
        
        //  Récupérer les immobilisations existantes pour ce compte
        $immobilisationsExistantes = $query->get(['id', 'code', 'reference', 'libelle']);
        
        if ($immobilisationsExistantes->isNotEmpty()) {
            $liste = $immobilisationsExistantes->map(function ($i) {
                return $i->libelle . ' (Réf: ' . ($i->reference ?? 'N/A') . ')';
            })->implode(', ');
            
            return response()->json([
                'success' => true,
                'configured' => false,
                'is_immobilisation' => true,
                'message' => "Compte d'immobilisation détecté.\n" .
                             "Immobilisations existantes :\n{$liste}\n\n" .
                             "Vous pouvez configurer une nouvelle immobilisation ou continuer sans configuration.",
                'immobilisations_existantes' => $immobilisationsExistantes,
                'compte_immobilisation' => $compteImmobilisation,
            ]);
        }
        
        return response()->json([
            'success' => true,
            'configured' => false,
            'is_immobilisation' => true,
            'message' => "Compte d'immobilisation détecté : {$compteImmobilisation}.\n" .
                         "Aucune immobilisation active n'est configurée pour ce compte.\n" .
                         "Vous pouvez configurer une immobilisation ou continuer sans configuration.",
            'immobilisations_existantes' => [],
            'compte_immobilisation' => $compteImmobilisation,
        ]);
    }
    
    /**
     *  Détecter si un compte est une immobilisation
     */
    private function detecterCompteImmobilisation(string $compteDebit, string $compteCredit): ?string
    {
        $comptes = array_filter([$compteDebit, $compteCredit]);
        
        foreach ($comptes as $compte) {
            if ($this->isCompteImmobilisation($compte)) {
                return $compte;
            }
        }
        
        return null;
    }
    
    /**
     *  Vérifier si un compte est une immobilisation
     */
    private function isCompteImmobilisation(string $compte): bool
    {
        if (empty($compte)) return false;
        if (str_starts_with($compte, '211')) return false;
        if (str_starts_with($compte, '25')) return false;
        if (str_starts_with($compte, '26') || str_starts_with($compte, '27')) return false;
        return str_starts_with($compte, '21') || str_starts_with($compte, '22') || str_starts_with($compte, '23') || str_starts_with($compte, '24');
    }
}