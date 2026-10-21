<?php

namespace App\Http\Controllers\Comptabilite;

use App\Models\ImmobilisationsComptable;
use App\Models\Entite;
use App\Models\Succursale;
use App\Models\PeriodesComptable;
use App\Models\PlanComptable;
use App\Services\AmortissementService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Controller;

class AmortissementController extends Controller
{
    protected AmortissementService $amortissementService;

    public function __construct(AmortissementService $amortissementService)
    {
        $this->amortissementService = $amortissementService;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  LISTE DES IMMOBILISATIONS
    // ═══════════════════════════════════════════════════════════════════

    public function index()
    {
        $entites         = Entite::where('is_active', 1)->orderBy('code', 'asc')->get();
        $periodes        = PeriodesComptable::orderBy('date_debut', 'desc')->get();
        $immobilisations = ImmobilisationsComptable::with(['entite', 'succursale', 'operationJournal'])->get();

        $immobilisations->each(fn ($immo) => $this->enrichirImmobilisation($immo));

        return response()->json([
            'success'         => true,
            'entites'         => $entites,
            'periodes'        => $periodes,
            'immobilisations' => $immobilisations,
        ]);
    }

    public function getImmobilisations(Request $request)
    {
        $immobilisations = ImmobilisationsComptable::with(['entite', 'succursale', 'operationJournal'])
            ->when($request->entite_id, fn($q) => $q->parEntite($request->entite_id))
            ->when($request->succursale_id, fn($q) => $q->parSuccursale($request->succursale_id))
            ->when($request->statut === 'en_cours', fn($q) => $q->enCours())
            ->when($request->statut === 'amorties', fn($q) => $q->amorties())
            ->orderBy('date_acquisition', 'desc')
            ->get();

        $immobilisations->each(fn ($immo) => $this->enrichirImmobilisation($immo));

        return response()->json(['success' => true, 'immobilisations' => $immobilisations]);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  CRÉATION RAPIDE (avec validation comptes)
    // ═══════════════════════════════════════════════════════════════════

    public function storeRapide(Request $request): JsonResponse
    {
        // ─── 1. Validation des règles de base ───
        $validator = Validator::make($request->all(), [
            'code'                      => 'required|string|max:50|unique:immobilisation_comptable,code',
            'reference'                 => 'nullable|string|max:255',
            'libelle'                   => 'required|string|max:255',
            'compte_acquisition'        => 'required|string|max:20',
            'compte_amortissement'      => 'required|string|max:20',
            'compte_dotation'           => 'required|string|max:20',
            'date_acquisition'          => 'required|date',
            'date_debut_utilisation'    => 'required|date',
            'date_debut_amortissement'  => 'nullable|date',
            'valeur_acquisition'        => 'required|numeric|min:0.01',
            'duree_vie'                 => 'required|numeric|min:0.1',
            'duree_unite'               => 'required|string|in:annees,mois',
            'valeur_residuelle'         => 'nullable|numeric|min:0',
            'mode_amortissement'        => 'nullable|string|in:lineaire,degressif',
            'entite_id'                 => 'required|exists:entites,id',
            'succursale_id'             => 'nullable|exists:succursales,id',
        ], $this->messagesValidation());

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Certains champs du formulaire sont invalides.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // ─── 2. 🔥 VALIDATION MÉTIER : Les 3 comptes existent ? ───
        $erreurCompte = $this->validerComptesComptables($request);
        if ($erreurCompte) {
            return response()->json([
                'success' => false,
                'message' => $erreurCompte,
            ], 422);
        }

        // ─── 3. Création ───
        try {
            $calculs = $this->preparerDonneesAmortissement($request);

            $immo = ImmobilisationsComptable::create([
                'code'                       => $request->code,
                'reference'                  => $request->reference ?? null,
                'libelle'                    => $request->libelle,
                'compte_acquisition'         => $request->compte_acquisition,
                'compte_amortissement'       => $request->compte_amortissement,
                'compte_dotation'            => $request->compte_dotation,
                'date_acquisition'           => $calculs['date_acquisition'],
                'date_debut_utilisation'     => $calculs['date_debut_utilisation'],
                'date_debut_amortissement'   => $calculs['date_debut_amortissement'],
                'date_fin_amortissement'     => $calculs['date_fin_amortissement'],
                'valeur_acquisition'         => $calculs['valeur_acquisition'],
                'duree_vie'                  => $calculs['duree_vie'],
                'duree_unite'                => $calculs['duree_unite'],
                'duree_mois'                 => $calculs['duree_mois'],
                'valeur_residuelle'          => $calculs['valeur_residuelle'],
                'taux_amortissement'         => $calculs['taux_amortissement'],
                'montant_mensuel'            => $calculs['montant_mensuel'],
                'mode_amortissement'         => $request->mode_amortissement ?? 'lineaire',
                'montant_amorti_cumule'      => 0,
                'valeur_nette_comptable'     => $calculs['valeur_acquisition'],
                'dernier_mois_amorti'        => null,
                'mois_restants'              => $calculs['duree_mois'],
                'est_amorti'                 => false,
                'est_actif'                  => true,
                'entite_id'                  => $request->entite_id,
                'succursale_id'              => $request->succursale_id,
            ]);

            $this->declencherAmortissementAutomatique($immo);

            return response()->json([
                'success' => true,
                'message' => 'Immobilisation créée avec succès.',
                'immo'    => array_merge($immo->fresh()->toArray(), [
                    'date_fin_amortissement' => $calculs['date_fin_amortissement'],
                    'montant_mensuel'        => $calculs['montant_mensuel'],
                ]),
            ], 201);

        } catch (\Throwable $e) {
            Log::error('AMORT CONTROLLER — échec storeRapide', [
                'code'    => $request->code ?? null,
                'message' => $e->getMessage(),
                'code_ex' => $e->getCode(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $this->traduireErreur($e),
            ], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  CRÉATION CLASSIQUE (avec validation comptes)
    // ═══════════════════════════════════════════════════════════════════

    public function store(Request $request)
    {
        // ─── 1. Validation des règles de base ───
        $validator = Validator::make($request->all(), [
            'code'                    => 'required|string|max:50|unique:immobilisation_comptable,code',
            'libelle'                 => 'required|string|max:255',
            'compte_acquisition'      => 'required|string|max:20',
            'compte_amortissement'    => 'required|string|max:20',
            'compte_dotation'         => 'required|string|max:20',
            'valeur_acquisition'      => 'required|numeric|min:0.01',
            'date_acquisition'        => 'required|date',
            'date_debut_utilisation'  => 'required|date',
            'duree_vie'               => 'required|numeric|min:0.1',
            'duree_unite'             => 'required|string|in:annees,mois',
            'valeur_residuelle'       => 'nullable|numeric|min:0',
            'entite_id'               => 'required|exists:entites,id',
            'succursale_id'           => 'nullable|exists:succursales,id',
        ], $this->messagesValidation());

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Certains champs du formulaire sont invalides.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // ─── 2. 🔥 VALIDATION MÉTIER : Les 3 comptes existent ? ───
        $erreurCompte = $this->validerComptesComptables($request);
        if ($erreurCompte) {
            return response()->json([
                'success' => false,
                'message' => $erreurCompte,
            ], 422);
        }

        // ─── 3. Création ───
        try {
            $calculs = $this->preparerDonneesAmortissement($request);

            $immo = ImmobilisationsComptable::create([
                'code'                       => $request->code,
                'reference'                  => $request->reference ?? null,
                'libelle'                    => $request->libelle,
                'compte_acquisition'         => $request->compte_acquisition,
                'compte_amortissement'       => $request->compte_amortissement,
                'compte_dotation'            => $request->compte_dotation,
                'date_acquisition'           => $calculs['date_acquisition'],
                'date_debut_utilisation'     => $calculs['date_debut_utilisation'],
                'date_debut_amortissement'   => $calculs['date_debut_amortissement'],
                'date_fin_amortissement'     => $calculs['date_fin_amortissement'],
                'valeur_acquisition'         => $calculs['valeur_acquisition'],
                'duree_vie'                  => $calculs['duree_vie'],
                'duree_unite'                => $calculs['duree_unite'],
                'duree_mois'                 => $calculs['duree_mois'],
                'valeur_residuelle'          => $calculs['valeur_residuelle'],
                'taux_amortissement'         => $calculs['taux_amortissement'],
                'montant_mensuel'            => $calculs['montant_mensuel'],
                'mode_amortissement'         => $request->mode_amortissement ?? 'lineaire',
                'montant_amorti_cumule'      => 0,
                'valeur_nette_comptable'     => $calculs['valeur_acquisition'],
                'dernier_mois_amorti'        => null,
                'mois_restants'              => $calculs['duree_mois'],
                'est_amorti'                 => false,
                'est_actif'                  => true,
                'entite_id'                  => $request->entite_id,
                'succursale_id'              => $request->succursale_id,
            ]);

            $this->declencherAmortissementAutomatique($immo);

            return response()->json([
                'success'        => true,
                'message'        => 'Immobilisation créée avec succès.',
                'immobilisation' => array_merge(
                    $immo->fresh()->load(['entite', 'succursale'])->toArray(),
                    [
                        'date_fin_amortissement' => $calculs['date_fin_amortissement'],
                        'montant_mensuel'        => $calculs['montant_mensuel'],
                    ]
                ),
            ]);
        } catch (\Throwable $e) {
            Log::error('AMORT CONTROLLER — échec store', [
                'code'    => $request->code ?? null,
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $this->traduireErreur($e),
            ], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  MISE À JOUR (avec validation comptes)
    // ═══════════════════════════════════════════════════════════════════

    public function update(Request $request, $id)
    {
        $immo = ImmobilisationsComptable::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'code'                    => 'sometimes|string|max:50|unique:immobilisation_comptable,code,' . $id,
            'libelle'                 => 'sometimes|string|max:255',
            'compte_acquisition'      => 'sometimes|string|max:20',
            'compte_amortissement'    => 'sometimes|string|max:20',
            'compte_dotation'         => 'sometimes|string|max:20',
            'valeur_acquisition'      => 'sometimes|numeric|min:0.01',
            'date_acquisition'        => 'sometimes|date',
            'date_debut_utilisation'  => 'sometimes|date',
            'duree_vie'               => 'sometimes|numeric|min:0.1',
            'duree_unite'             => 'sometimes|string|in:annees,mois',
            'valeur_residuelle'       => 'nullable|numeric|min:0',
            'est_actif'               => 'nullable|boolean',
            'entite_id'               => 'nullable|exists:entites,id',
            'succursale_id'           => 'nullable|exists:succursales,id',
        ], $this->messagesValidation());

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Certains champs du formulaire sont invalides.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // 🔥 VALIDATION MÉTIER : Les comptes envoyés existent-ils ?
        $erreurCompte = $this->validerComptesComptables($request, $immo);
        if ($erreurCompte) {
            return response()->json([
                'success' => false,
                'message' => $erreurCompte,
            ], 422);
        }

        try {
            $data = $request->only([
                'code', 'libelle', 'compte_acquisition', 'compte_amortissement',
                'compte_dotation', 'valeur_acquisition', 'date_acquisition',
                'date_debut_utilisation', 'duree_vie', 'duree_unite',
                'valeur_residuelle', 'est_actif', 'entite_id', 'succursale_id'
            ]);

            if ($request->has('duree_vie') || $request->has('duree_unite')) {
                $dureeUnite = $request->duree_unite ?? $immo->duree_unite ?? 'annees';
                $dureeVie   = (float) ($request->duree_vie ?? $immo->duree_vie);

                $dureeMois = $dureeUnite === 'annees'
                    ? (int) round($dureeVie * 12)
                    : (int) round($dureeVie);

                $data['duree_mois']         = max(1, $dureeMois);
                $data['taux_amortissement'] = round(100 / max(1, $dureeMois), 4);
                $data['montant_mensuel']    = $this->calculerMontantMensuel($immo);

                if ($immo->montant_amorti_cumule <= 0) {
                    $data['mois_restants']          = max(1, $dureeMois);
                    $data['valeur_nette_comptable'] = $data['valeur_acquisition'] ?? $immo->valeur_acquisition;
                }
            }

            if ($request->has('date_debut_utilisation')) {
                $data['date_debut_amortissement'] = $request->date_debut_utilisation;
            }

            $immo->update($data);
            $immoFresh = $immo->fresh();

            $dateFinRecalculee = $this->calculerDateFinAmortissement($immoFresh);
            if ($dateFinRecalculee) {
                $immoFresh->update(['date_fin_amortissement' => $dateFinRecalculee]);
            }

            $immoFresh = $immoFresh->fresh()->load(['entite', 'succursale']);

            return response()->json([
                'success'        => true,
                'message'        => 'Immobilisation mise à jour avec succès.',
                'immobilisation' => array_merge(
                    $immoFresh->toArray(),
                    [
                        'date_fin_amortissement' => $this->calculerDateFinAmortissement($immoFresh),
                        'montant_mensuel'        => $this->calculerMontantMensuel($immoFresh),
                    ]
                ),
            ]);
        } catch (\Throwable $e) {
            Log::error('AMORT CONTROLLER — échec update', [
                'immo_id' => $id,
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $this->traduireErreur($e),
            ], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  SUPPRESSION
    // ═══════════════════════════════════════════════════════════════════

    public function destroy($id)
    {
        $immo = ImmobilisationsComptable::findOrFail($id);

        if ($immo->operationJournal()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer cette immobilisation : des écritures d\'amortissement existent déjà.',
            ], 422);
        }

        $immo->delete();
        return response()->json(['success' => true, 'message' => 'Immobilisation supprimée.']);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  EXÉCUTION DE L'AMORTISSEMENT
    // ═══════════════════════════════════════════════════════════════════

    public function executerAmortissement(Request $request)
    {
        $entiteId = $request->entite_id ? (int) $request->entite_id : null;

        try {
            $resultat = $this->amortissementService->executerAmortissementMensuel($entiteId);
            return response()->json($resultat);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('AMORT CONTROLLER — échec executerAmortissement', [
                'entite_id' => $entiteId,
                'message'   => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $this->traduireErreur($e),
            ], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  UTILITAIRES
    // ═══════════════════════════════════════════════════════════════════

    public function getSuccursales($entiteId)
    {
        return response()->json([
            'success'     => true,
            'succursales' => Succursale::where('entite_id', $entiteId)
                ->where('est_active', 1)
                ->orderBy('code', 'asc')
                ->get()
        ]);
    }

    public function getPeriodes()
    {
        return response()->json([
            'success'  => true,
            'periodes' => PeriodesComptable::orderBy('date_debut', 'desc')
                ->get(['id', 'code', 'libelle', 'date_debut', 'date_fin', 'is_cloture', 'statut'])
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  🔥 VALIDATION MÉTIER : COMPTES COMPTABLES
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Vérifie que les 3 comptes saisis existent dans le plan comptable.
     * Retourne un message d'erreur CLAIR si un compte est invalide, sinon null.
     */
    private function validerComptesComptables(
        Request $request,
        ?ImmobilisationsComptable $immo = null
    ): ?string {
        // Récupérer les comptes (nouveaux OU existants)
        $comptes = [
            'compte_acquisition' => [
                'numero'  => $request->compte_acquisition   ?? $immo?->compte_acquisition,
                'libelle' => "Compte d'acquisition (classe 2)",
            ],
            'compte_amortissement' => [
                'numero'  => $request->compte_amortissement ?? $immo?->compte_amortissement,
                'libelle' => "Compte d'amortissement (classe 28)",
            ],
            'compte_dotation' => [
                'numero'  => $request->compte_dotation      ?? $immo?->compte_dotation,
                'libelle' => "Compte de dotation (classe 68)",
            ],
        ];

        $erreurs = [];

        foreach ($comptes as $champ => $info) {
            $numero = $info['numero'];
            $libelle = $info['libelle'];

            if (empty($numero)) {
                $erreurs[] = "• {$libelle} : champ vide";
                continue;
            }

            $existe = PlanComptable::where('numero', $numero)->exists();

            if (!$existe) {
                $erreurs[] = "• {$libelle} : le compte '{$numero}' n'existe pas dans le plan comptable";
            }
        }

        if (!empty($erreurs)) {
            return "❌ Comptes comptables invalides.\n\n"
                 . "Les comptes suivants n'existent pas dans le plan comptable :\n\n"
                 . implode("\n", $erreurs) . "\n\n"
                 . "📋 Actions à faire :\n"
                 . "1. Allez dans Configuration → Plan comptable\n"
                 . "2. Ajoutez les comptes manquants (ex: 2444, 28444, 6813)\n"
                 . "3. Ou modifiez cette immobilisation pour utiliser des comptes existants";
        }

        return null;
    }

    /**
     * Messages de validation personnalisés en français.
     */
    private function messagesValidation(): array
    {
        return [
            'code.required'                   => 'Le code de l\'immobilisation est obligatoire.',
            'code.unique'                     => 'Ce code est déjà utilisé par une autre immobilisation.',
            'code.max'                        => 'Le code ne doit pas dépasser 50 caractères.',
            'libelle.required'                => 'Le libellé est obligatoire.',
            'compte_acquisition.required'     => 'Le compte d\'acquisition est obligatoire.',
            'compte_amortissement.required'   => 'Le compte d\'amortissement est obligatoire.',
            'compte_dotation.required'        => 'Le compte de dotation est obligatoire.',
            'date_acquisition.required'       => 'La date d\'acquisition est obligatoire.',
            'date_acquisition.date'           => 'La date d\'acquisition est invalide.',
            'date_debut_utilisation.required' => 'La date de début d\'utilisation est obligatoire.',
            'date_debut_utilisation.date'     => 'La date de début d\'utilisation est invalide.',
            'valeur_acquisition.required'     => 'La valeur d\'acquisition est obligatoire.',
            'valeur_acquisition.numeric'      => 'La valeur d\'acquisition doit être un nombre.',
            'valeur_acquisition.min'          => 'La valeur d\'acquisition doit être supérieure à 0.',
            'duree_vie.required'              => 'La durée de vie est obligatoire.',
            'duree_vie.numeric'               => 'La durée de vie doit être un nombre.',
            'duree_vie.min'                   => 'La durée de vie doit être supérieure à 0.',
            'duree_unite.required'            => 'L\'unité de durée est obligatoire.',
            'duree_unite.in'                  => 'L\'unité de durée doit être "annees" ou "mois".',
            'entite_id.required'              => 'L\'entité est obligatoire.',
            'entite_id.exists'                => 'L\'entité sélectionnée n\'existe pas.',
            'succursale_id.exists'            => 'La succursale sélectionnée n\'existe pas.',
        ];
    }

    /**
     * ═══════════════════════════════════════════════════════════════════
     *  🔥 TRADUCTION DES ERREURS — HONNÊTE (ne ment plus)
     * ═══════════════════════════════════════════════════════════════════
     */
    private function traduireErreur(\Throwable $e): string
    {
        $msg = strtolower($e->getMessage());

        // 1. Clé étrangère violée → dire QUELLE colonne
        if (str_contains($msg, 'foreign key constraint fails')) {
            if (str_contains($msg, 'compte_numero')) {
                return "❌ Un compte comptable utilisé n'existe pas dans le plan comptable.\n\n"
                     . "📋 Actions à faire :\n"
                     . "1. Allez dans Configuration → Plan comptable\n"
                     . "2. Ajoutez les comptes manquants\n"
                     . "3. Relancez l'opération";
            }
            if (str_contains($msg, 'periode_id')) {
                return "❌ La période comptable associée n'existe pas en base.\n\n"
                     . "📋 Vérifiez Configuration → Périodes comptables.";
            }
            if (str_contains($msg, 'entite_id')) {
                return "❌ L'entité sélectionnée n'existe pas en base.";
            }
            if (str_contains($msg, 'succursale_id')) {
                return "❌ La succursale sélectionnée n'existe pas en base.";
            }
            return "❌ Contrainte de base de données violée. Détail : " . $e->getMessage();
        }

        // 2. Doublon
        if (str_contains($msg, 'duplicate entry')) {
            return "❌ Ce code d'immobilisation existe déjà. Choisissez un autre code.";
        }

        // 3. Champ obligatoire vide
        if (str_contains($msg, 'cannot be null')) {
            return "❌ Un champ obligatoire est vide. Vérifiez tous les champs du formulaire.";
        }

        // 4. Colonne inconnue
        if (str_contains($msg, 'unknown column')) {
            return "❌ Erreur de structure de base de données (colonne inconnue). Contactez votre administrateur.";
        }

        // 5. Table manquante
        if (str_contains($msg, "doesn't exist")) {
            return "❌ Erreur de structure de base de données (table manquante). Contactez votre administrateur.";
        }

        // 6. Fallback : inclure le vrai message (utile pour debug)
        return "❌ Une erreur est survenue : " . $e->getMessage();
    }

    // ═══════════════════════════════════════════════════════════════════
    //  MÉTHODES DE CALCUL
    // ═══════════════════════════════════════════════════════════════════

    private function preparerDonneesAmortissement(Request $request): array
    {
        $valeurAcquisition = (float) $request->valeur_acquisition;
        $valeurResiduelle  = (float) ($request->valeur_residuelle ?? 0);
        $dureeVie          = (float) $request->duree_vie;
        $dureeUnite        = $request->duree_unite ?? 'annees';

        $dureeMois = $dureeUnite === 'annees'
            ? (int) round($dureeVie * 12)
            : (int) round($dureeVie);

        if ($dureeMois <= 0) {
            $dureeMois = 1;
        }

        $tauxAmortissement = min(round(100 / $dureeMois, 4), 9999.9999);
        $amortissable      = max(0, $valeurAcquisition - $valeurResiduelle);
        $montantMensuel    = round($amortissable / $dureeMois, 4);

        $dateDebutUtilisation   = Carbon::parse($request->date_debut_utilisation);
        $dateDebutAmortissement = $request->date_debut_amortissement
            ? Carbon::parse($request->date_debut_amortissement)
            : $dateDebutUtilisation->copy();

        $dateFinAmortissement = $dateDebutAmortissement->copy()->addMonths($dureeMois);

        return [
            'date_acquisition'         => $request->date_acquisition,
            'date_debut_utilisation'   => $dateDebutUtilisation->toDateString(),
            'date_debut_amortissement' => $dateDebutAmortissement->toDateString(),
            'date_fin_amortissement'   => $dateFinAmortissement->toDateString(),
            'valeur_acquisition'       => $valeurAcquisition,
            'valeur_residuelle'        => $valeurResiduelle,
            'duree_vie'                => $dureeVie,
            'duree_unite'              => $dureeUnite,
            'duree_mois'               => $dureeMois,
            'taux_amortissement'       => $tauxAmortissement,
            'montant_mensuel'          => $montantMensuel,
        ];
    }

    /**
     * 🔥 Déclenche l'amortissement automatique — mais LOG l'erreur au lieu
     *    de l'avaler silencieusement (sinon on ne comprend rien ensuite).
     */
    private function declencherAmortissementAutomatique(ImmobilisationsComptable $immo): void
    {
        try {
            $this->amortissementService->executerAmortissementMensuel($immo->entite_id);
            $immo->refresh();
        } catch (\Throwable $e) {
            Log::warning('AMORT CONTROLLER — amortissement auto échoué (non bloquant)', [
                'immo_id'  => $immo->id,
                'code'     => $immo->code,
                'message'  => $e->getMessage(),
                'file'     => $e->getFile(),
                'line'     => $e->getLine(),
            ]);
            // On ne relance pas : l'amortissement manuel pourra être fait plus tard.
        }
    }

    private function enrichirImmobilisation(ImmobilisationsComptable $immo): void
    {
        $immo->date_fin_amortissement = $this->calculerDateFinAmortissement($immo);
        $immo->montant_mensuel        = $this->calculerMontantMensuel($immo);
        $immo->duree_libelle          = $this->formaterDuree($immo);
        $immo->progression            = $immo->valeur_acquisition > 0
            ? round(($immo->montant_amorti_cumule / $immo->valeur_acquisition) * 100, 2)
            : 0;
    }

    private function calculerDateFinAmortissement(ImmobilisationsComptable $immo): ?string
    {
        $dateDebut = $immo->date_debut_amortissement
            ?? $immo->date_debut_utilisation
            ?? $immo->date_acquisition;

        if (!$dateDebut || !$immo->duree_mois || $immo->duree_mois <= 0) {
            return null;
        }

        return Carbon::parse($dateDebut)
            ->copy()
            ->addMonths((int) $immo->duree_mois)
            ->toDateString();
    }

    private function calculerMontantMensuel(ImmobilisationsComptable $immo): float
    {
        if (!$immo->duree_mois || $immo->duree_mois <= 0) {
            return 0.0;
        }

        $amortissable = (float) $immo->valeur_acquisition - (float) $immo->valeur_residuelle;

        return round(max(0, $amortissable) / (int) $immo->duree_mois, 4);
    }

    private function formaterDuree(ImmobilisationsComptable $immo): string
    {
        if (!$immo->duree_mois) return '-';

        if ($immo->duree_unite === 'annees' && $immo->duree_mois % 12 === 0) {
            $ans = $immo->duree_mois / 12;
            return $ans . ' an' . ($ans > 1 ? 's' : '') . ' (' . $immo->duree_mois . ' mois)';
        }

        return $immo->duree_mois . ' mois';
    }
}