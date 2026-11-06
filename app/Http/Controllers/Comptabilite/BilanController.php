<?php

namespace App\Http\Controllers\Comptabilite;

use App\Models\Bilan;
use App\Models\BilanLigne;
use App\Models\PeriodesComptable;
use App\Models\Entite;
use App\Models\Succursale;
use App\Models\PlanComptable;
use App\Models\EcrituresComptable;
use App\Services\BilanService;
use App\Services\ClotureExerciceComptable;
use App\Events\BilanChanged;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Http\Controllers\Controller;

class BilanController extends Controller
{
    protected BilanService $bilanService;
    protected ClotureExerciceComptable $clotureService;

    public function __construct(BilanService $bilanService, ClotureExerciceComptable $clotureService)
    {
        $this->bilanService = $bilanService;
        $this->clotureService = $clotureService;
    }

    // =========================================================================
    // PAGE PRINCIPALE
    // =========================================================================

    public function index(Request $request)
    {
        $entites = Entite::orderBy('code', 'asc')->get();
        $periodes = PeriodesComptable::orderBy('date_debut', 'desc')->get();
        $entiteId = $request->entite_id;
        $periodeId = $request->periode_id;
        $type = $request->type_bilan ?? 'all';

        $bilans = Bilan::with(['periode_comptable', 'entite', 'succursale'])
            ->when($entiteId && $entiteId !== 'all', fn($q) => $q->where('entite_id', $entiteId))
            ->when($periodeId && $periodeId !== 'all', fn($q) => $q->where('periode_id', $periodeId))
            ->when($type && $type !== 'all', fn($q) => $q->type($type))
            ->orderBy('date_bilan', 'desc')
            ->paginate(15);

        return view('sections.bilan', compact('bilans', 'entites', 'periodes', 'entiteId', 'periodeId', 'type'));
    }

    // =========================================================================
    // API - LISTE & DÉTAIL
    // =========================================================================

    public function listeJson(Request $request)
    {
        try {
            $bilans = Bilan::with([
                'periode_comptable',
                'entite',
                'succursale',
                'bilan_lignes' => function ($query) {
                    $query->orderBy('compte_numero', 'asc'); //  TRI CROISSANT
                }
            ])
            ->when($request->entite_id && $request->entite_id !== 'all', fn($q) => $q->where('entite_id', $request->entite_id))
            ->when($request->periode_id && $request->periode_id !== 'all', fn($q) => $q->where('periode_id', $request->periode_id))
            ->when($request->type_bilan && $request->type_bilan !== 'all' && $request->type_bilan !== 'calcule', fn($q) => $q->where('type_bilan', $request->type_bilan))
            ->orderBy('date_bilan', 'desc')
            ->get();

            $bilans->transform(function ($bilan) {
                //  S'assurer que les lignes sont triées
                $bilan->bilan_lignes = $bilan->bilan_lignes->sortBy('compte_numero')->values();
                $bilan->total_actif = $bilan->bilan_lignes->where('type_compte', 'actif')->sum('montant');
                $bilan->total_passif = $bilan->bilan_lignes->where('type_compte', 'passif')->sum('montant');
                $bilan->est_verrouille = $this->bilanService->estVerrouille($bilan);
                $bilan->statut_libelle = $bilan->statut_libelle;
                return $bilan;
            });

            return response()->json([
                'success' => true,
                'data' => $bilans,
            ], 200, ['Content-Type' => 'application/json; charset=UTF-8'], JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            Log::error('Bilan listeJson error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => [],
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $bilan = Bilan::with([
                'periode_comptable',
                'entite',
                'succursale',
                'bilan_lignes' => function ($query) {
                    $query->orderBy('compte_numero', 'asc'); //  TRI CROISSANT
                }
            ])->findOrFail($id);

            //  Double sécurité : trier à nouveau en PHP
            $bilan->bilan_lignes = $bilan->bilan_lignes->sortBy('compte_numero')->values();

            $bilan->total_actif = $bilan->bilan_lignes->where('type_compte', 'actif')->sum('montant');
            $bilan->total_passif = $bilan->bilan_lignes->where('type_compte', 'passif')->sum('montant');
            $bilan->est_verrouille = $this->bilanService->estVerrouille($bilan);
            $bilan->statut_libelle = $bilan->statut_libelle;

            return response()->json(['success' => true, 'bilan' => $bilan], 200, ['Content-Type' => 'application/json; charset=UTF-8'], JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 404);
        }
    }

    public function statut($id)
    {
        try {
            $bilan = Bilan::with('periode_comptable')->findOrFail($id);
            return response()->json([
                'success' => true,
                'verrouille' => $this->bilanService->estVerrouille($bilan),
                'est_provisoire' => $bilan->est_provisoire,
                'bilan_id' => $bilan->id,
                'type' => $bilan->type_bilan,
                'statut_libelle' => $bilan->statut_libelle,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 404);
        }
    }

    public function searchCompte(Request $request)
    {
        $search = $request->search;
        if (empty($search)) return response()->json([]);

        return response()->json(
            PlanComptable::actif()
                ->bilan()
                ->where(fn($q) => $q->where('numero', 'LIKE', "%{$search}%")
                    ->orWhere('nom', 'LIKE', "%{$search}%"))
                ->orderBy('numero', 'asc') //  TRI CROISSANT
                ->limit(15)
                ->get(['numero', 'nom', 'position_bilan'])
        );
    }

    public function periodesJson()
    {
        $periodes = PeriodesComptable::orderBy('date_debut', 'desc')->get();

        return response()->json($periodes->map(function ($periode) {
            $bilanInitial = Bilan::where('periode_id', $periode->id)
                ->where('type_bilan', 'initial')
                ->first();

            $bilanFinal = Bilan::where('periode_id', $periode->id)
                ->where('type_bilan', 'fermeture')
                ->first();

            return [
                'id' => $periode->id,
                'code' => $periode->code,
                'libelle' => $periode->libelle,
                'date_debut' => $periode->date_debut,
                'date_fin' => $periode->date_fin,
                'statut' => $periode->statut ?? ($periode->is_cloture ? 'cloture_definitive' : 'ouverte'),
                'is_cloture' => $periode->is_cloture,
                'bilan_initial_id' => $bilanInitial ? $bilanInitial->id : null,
                'bilan_initial' => $bilanInitial ? true : false,
                'bilan_final_id' => $bilanFinal ? $bilanFinal->id : null,
                'bilan_final' => $bilanFinal ? true : false,
            ];
        }));
    }

    // =========================================================================
    // CRUD BILAN
    // =========================================================================

    public function store(Request $request)
    {
        $validated = $request->validate([
            'periode_id' => 'required|exists:periodes_comptables,id',
            'type_bilan' => 'required|in:initial,fermeture,provisoire',
            'date_bilan' => 'required|date',
            'entite_id' => 'required|exists:entites,id',
            'succursale_id' => 'nullable|exists:succursales,id',
            'comptes' => 'required|array|min:1',
            'comptes.*.compte_numero' => 'required|exists:plan_comptable,numero',
            'comptes.*.montant' => 'required|numeric'
        ]);

        try {
            DB::beginTransaction();

            //  TRIER les comptes par numéro croissant AVANT équilibrage
            usort($validated['comptes'], fn($a, $b) => strcmp($a['compte_numero'], $b['compte_numero']));

            $comptesEquilibres = $this->bilanService->equilibrerBilan($validated['comptes'], $validated['type_bilan']);

            //  RE-TRIER après équilibrage
            usort($comptesEquilibres, fn($a, $b) => strcmp($a['compte_numero'], $b['compte_numero']));

            if ($validated['type_bilan'] !== 'provisoire') {
                $existant = Bilan::where('periode_id', $validated['periode_id'])
                    ->where('type_bilan', $validated['type_bilan'])
                    ->where('entite_id', $validated['entite_id'])
                    ->exists();
                if ($existant) {
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => "Un bilan de type '{$validated['type_bilan']}' existe déjà."], 422);
                }
            }

            Bilan::where('is_actif', true)
                ->where('entite_id', $validated['entite_id'])
                ->where('type_bilan', $validated['type_bilan'])
                ->update(['is_actif' => false]);

            $bilan = Bilan::create([
                'periode_id'       => $validated['periode_id'],
                'type_bilan'       => $validated['type_bilan'],
                'date_bilan'       => $validated['date_bilan'],
                'entite_id'        => $validated['entite_id'],
                'succursale_id'    => $validated['succursale_id'] ?? null,
                'is_actif'         => true,
                'provient_cloture' => false,
                'est_provisoire'   => ($validated['type_bilan'] === 'provisoire'),
            ]);

            $this->bilanService->creerLignesBilan($bilan, $comptesEquilibres, $validated);
            DB::commit();
            $this->broadcastBilanEvent('created', $bilan);
            return response()->json(['success' => true, 'message' => ' Bilan créé avec succès !', 'bilan_id' => $bilan->id]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Erreur : ' . $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $bilan = Bilan::with('periode_comptable')->findOrFail($id);

        if ($this->bilanService->estVerrouille($bilan)) {
            return response()->json(['success' => false, 'message' => '🔒 Ce bilan est verrouillé.'], 422);
        }

        $validated = $request->validate([
            'comptes' => 'required|array|min:1',
            'comptes.*.compte_numero' => 'required|exists:plan_comptable,numero',
            'comptes.*.montant' => 'required|numeric'
        ]);

        try {
            DB::beginTransaction();

            usort($validated['comptes'], fn($a, $b) => strcmp($a['compte_numero'], $b['compte_numero']));

            $comptesEquilibres = $this->bilanService->equilibrerBilan($validated['comptes'], $bilan->type_bilan);

            usort($comptesEquilibres, fn($a, $b) => strcmp($a['compte_numero'], $b['compte_numero']));

            $bilan->bilan_lignes()->delete();

            foreach ($comptesEquilibres as $c) {
                $plan = PlanComptable::where('numero', $c['compte_numero'])->first();
                if (!$plan) continue;

                BilanLigne::create([
                    'bilan_id'      => $bilan->id,
                    'compte_numero' => $c['compte_numero'],
                    'compte_nom'    => $plan->nom,
                    'type_compte'   => $plan->position_bilan,
                    'montant'       => (float)$c['montant']
                ]);
            }

            $this->bilanService->synchroniserEcritures($bilan);
            DB::commit();
            $this->broadcastBilanEvent('updated', $bilan);
            return response()->json(['success' => true, 'message' => ' Bilan mis à jour !']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Erreur : ' . $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        $bilan = Bilan::findOrFail($id);

        if ($this->bilanService->estVerrouille($bilan)) {
            return response()->json(['success' => false, 'message' => '🔒 Ce bilan est verrouillé.'], 422);
        }

        try {
            DB::beginTransaction();
            EcrituresComptable::where('reference', 'LIKE', 'BILAN%')
                ->where('entite_id', $bilan->entite_id)
                ->where('periode_id', $bilan->periode_id)
                ->delete();
            $bilan->delete();
            DB::commit();
            $this->broadcastBilanEvent('deleted', $bilan);
            return response()->json(['success' => true, 'message' => '🗑️ Bilan supprimé !']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Erreur : ' . $e->getMessage()], 500);
        }
    }

    public function supprimerLigne($id)
    {
        $ligne = BilanLigne::findOrFail($id);
        $bilan = $ligne->bilan;

        if ($this->bilanService->estVerrouille($bilan)) {
            return response()->json(['success' => false, 'message' => '🔒 Bilan verrouillé.'], 422);
        }

        DB::transaction(function () use ($ligne, $bilan) {
            $ligne->delete();
            $this->bilanService->synchroniserEcritures($bilan);
        });

        $this->broadcastBilanEvent('updated', $bilan);
        return response()->json(['success' => true, 'message' => ' Ligne supprimée !']);
    }

    public function modifierLigne(Request $request, $id)
    {
        $validated = $request->validate([
            'compte_numero' => 'required|exists:plan_comptable,numero',
            'montant' => 'required|numeric'
        ]);

        $ligne = BilanLigne::findOrFail($id);
        $bilan = $ligne->bilan;

        if ($this->bilanService->estVerrouille($bilan)) {
            return response()->json(['success' => false, 'message' => '🔒 Bilan verrouillé.'], 422);
        }

        DB::transaction(function () use ($ligne, $bilan, $validated) {
            $plan = PlanComptable::where('numero', $validated['compte_numero'])->first();
            $ligne->update([
                'compte_numero' => $validated['compte_numero'],
                'compte_nom'    => $plan->nom ?? $ligne->compte_nom,
                'type_compte'   => $plan->position_bilan ?? $ligne->type_compte,
                'montant'       => (float)$validated['montant']
            ]);
            $this->bilanService->synchroniserEcritures($bilan);
        });

        $this->broadcastBilanEvent('updated', $bilan);
        return response()->json(['success' => true, 'message' => ' Ligne modifiée !']);
    }

    // =========================================================================
    // BILAN DYNAMIQUE AVEC FILTRE TEMPOREL
    // =========================================================================

    public function bilanFiltre(Request $request)
    {
        $params = [
            'periode_id' => $request->input('periode_id'),
            'entite_id' => $request->input('entite_id'),
            'succursale_id' => $request->input('succursale_id'),
            'filter_type' => $request->input('filter_type', 'exercice'),
            'date_debut' => $request->input('date_debut'),
            'date_fin' => $request->input('date_fin'),
        ];

        try {
            return response()->json([
                'success' => true,
                'data' => $this->bilanService->calculerBilanFiltre($params)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // =========================================================================
    // VÉRIFICATIONS
    // =========================================================================

    public function verifierDisponibilitePeriode($id)
    {
        try {
            $p = PeriodesComptable::findOrFail($id);

            $e = EcrituresComptable::where('periode_id', $id)
                ->where('reference', 'NOT LIKE', 'BILAN%')
                ->where('reference', 'NOT LIKE', 'À-NOUVEAU%')
                ->where('reference', 'NOT LIKE', 'CLÔTURE%')
                ->exists();

            $estVerrouille = $p->statut === 'cloture_definitive';

            return response()->json([
                'success' => true,
                'verrouille' => $estVerrouille,
                'a_des_ecritures' => $e,
                'periode_id' => (int)$id,
                'periode_code' => $p->code,
                'is_cloture' => $p->is_cloture,
                'statut' => $p->statut ?? ($p->is_cloture ? 'cloture_definitive' : 'ouverte')
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function verifierCloture(Request $request)
    {
        $request->validate(['periode_id' => 'required|exists:periodes_comptables,id']);

        try {
            $r = $this->clotureService->verifierCoherenceCloture($request->periode_id, $request->entite_id);
            return response()->json(['success' => true, ...$r]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function resultatExercice(Request $request)
    {
        $request->validate(['periode_id' => 'required|exists:periodes_comptables,id']);

        try {
            $r = $this->clotureService->calculerResultat($request->periode_id, $request->entite_id);
            return response()->json([
                'success' => true,
                'benefice' => round($r, 4),
                'est_benefice' => $r >= 0,
                'montant' => round($r, 4)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function peutCloturer($id)
    {
        try {
            $periode = PeriodesComptable::findOrFail($id);
            $entiteId = request('entite_id');
            $verification = $this->clotureService->verifierCoherenceCloture($id, $entiteId);

            $verification['statut_actuel'] = $periode->statut;
            $verification['peut_cloture_provisoire'] = $periode->statut === 'ouverte';
            $verification['peut_re_synchroniser'] = $periode->statut === 'cloture_provisoire';
            $verification['peut_cloture_definitive'] = in_array($periode->statut, ['ouverte', 'cloture_provisoire']);

            Log::info('📋 Réponse peutCloturer', ['periode_id' => $id, 'result' => $verification]);

            return response()->json($verification);
        } catch (\Exception $e) {
            Log::error('❌ Erreur peutCloturer', ['error' => $e->getMessage()]);
            return response()->json([
                'peut_cloturer' => false,
                'errors' => [$e->getMessage()]
            ], 500);
        }
    }

    public function verifierCoherence($id)
    {
        try {
            $periode = PeriodesComptable::findOrFail($id);
            $entiteId = request('entite_id');
            $resultat = $this->clotureService->verifierCoherenceCloture($id, $entiteId);
            return response()->json($resultat);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // =========================================================================
    // STATUT DE CLÔTURE
    // =========================================================================

    public function statutCloture($id)
    {
        try {
            $periode = PeriodesComptable::findOrFail($id);
            $entiteId = request('entite_id');

            $bilanFermeture = Bilan::where('periode_id', $id)
                ->where('type_bilan', 'fermeture')
                ->when($entiteId, fn($q) => $q->where('entite_id', $entiteId))
                ->first();

            $periodeSuivante = PeriodesComptable::where('date_debut', '>', $periode->date_fin)
                ->orderBy('date_debut', 'asc')
                ->first();

            $bilanInitialN1 = null;
            if ($periodeSuivante) {
                $bilanInitialN1 = Bilan::where('periode_id', $periodeSuivante->id)
                    ->where('type_bilan', 'initial')
                    ->when($entiteId, fn($q) => $q->where('entite_id', $entiteId))
                    ->first();
            }

            return response()->json([
                'success' => true,
                'periode_id' => $periode->id,
                'statut' => $periode->statut,
                'is_cloture' => $periode->is_cloture,
                'bilan_fermeture' => $bilanFermeture ? [
                    'id' => $bilanFermeture->id,
                    'est_provisoire' => $bilanFermeture->est_provisoire,
                    'est_bilan_final' => $bilanFermeture->estBilanFinal(),
                    'statut_libelle' => $bilanFermeture->statut_libelle,
                ] : null,
                'bilan_initial_n1' => $bilanInitialN1 ? [
                    'id' => $bilanInitialN1->id,
                    'est_provisoire' => $bilanInitialN1->est_provisoire,
                    'statut_libelle' => $bilanInitialN1->statut_libelle,
                ] : null,
                'peut_modifier' => $periode->statut !== 'cloture_definitive',
                'peut_re_synchroniser' => $periode->statut === 'cloture_provisoire',
                'peut_cloturer_definitivement' => in_array($periode->statut, ['ouverte', 'cloture_provisoire']),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // =========================================================================
    // CLÔTURE PROVISOIRE
    // =========================================================================

    public function clotureProvisoire(Request $request)
    {
        $request->validate([
            'periode_id' => 'required|exists:periodes_comptables,id',
            'entite_id' => 'nullable|exists:entites,id',
            'date_fermeture' => 'required|date',
        ]);

        try {
            $resultat = $this->clotureService->effectuerClotureProvisoire(
                $request->periode_id,
                $request->entite_id,
                $request->date_fermeture
            );

            return response()->json($resultat);
        } catch (\Exception $e) {
            Log::error('❌ Erreur clôture provisoire', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => '❌ Erreur : ' . $e->getMessage()
            ], 500);
        }
    }

    // =========================================================================
    // RE-SYNCHRONISATION
    // =========================================================================

    public function reSynchroniserCloture(Request $request)
    {
        $request->validate([
            'periode_id' => 'required|exists:periodes_comptables,id',
            'entite_id' => 'nullable|exists:entites,id',
            'date_fermeture' => 'required|date',
        ]);

        try {
            $resultat = $this->clotureService->reSynchroniserCloture(
                $request->periode_id,
                $request->entite_id,
                $request->date_fermeture
            );

            return response()->json($resultat);
        } catch (\Exception $e) {
            Log::error('❌ Erreur re-synchronisation', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => '❌ Erreur : ' . $e->getMessage()
            ], 500);
        }
    }

    // =========================================================================
    // CLÔTURE DÉFINITIVE
    // =========================================================================

    public function clotureDefinitive(Request $request)
    {
        $request->validate([
            'periode_id' => 'required|exists:periodes_comptables,id',
            'entite_id' => 'nullable|exists:entites,id',
            'date_fermeture' => 'required|date',
        ]);

        try {
            $resultat = $this->clotureService->effectuerClotureDefinitive(
                $request->periode_id,
                $request->entite_id,
                $request->date_fermeture
            );

            return response()->json($resultat);
        } catch (\Exception $e) {
            Log::error('❌ Erreur clôture définitive', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => '❌ Erreur : ' . $e->getMessage()
            ], 500);
        }
    }

    // =========================================================================
    // ANCIENNE MÉTHODE (conservée pour compatibilité)
    // =========================================================================

    public function genererFermeture(Request $request)
    {
        $request->validate([
            'periode_id' => 'required|exists:periodes_comptables,id',
            'entite_id' => 'nullable|exists:entites,id',
            'date_fermeture' => 'required|date'
        ]);

        $periodeId = $request->periode_id;
        $entiteId = $request->entite_id;
        $dateFermeture = $request->date_fermeture;

        $periode = PeriodesComptable::findOrFail($periodeId);

        if ($periode->statut === 'cloture_definitive') {
            return response()->json([
                'success' => false,
                'message' => '❌ Cette période est déjà clôturée définitivement.'
            ], 422);
        }

        $verification = $this->clotureService->verifierCoherenceCloture($periodeId, $entiteId);
        if (!$verification['peut_cloturer']) {
            return response()->json([
                'success' => false,
                'message' => '❌ ' . count($verification['errors']) . ' problème(s).',
                'erreurs' => $verification['errors']
            ], 422);
        }

        try {
            $resultat = $this->clotureService->effectuerClotureDefinitive($periodeId, $entiteId, $dateFermeture);
            return response()->json($resultat);
        } catch (\Exception $e) {
            Log::error('❌ ERREUR CLÔTURE', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => '❌ Erreur : ' . $e->getMessage()
            ], 500);
        }
    }

    // =========================================================================
    // DUPLICATION
    // =========================================================================

    public function dupliquer(Request $request)
    {
        $request->validate(['bilan_id' => 'required|exists:bilans,id']);

        try {
            DB::beginTransaction();

            $bilan = Bilan::with(['bilan_lignes' => function ($query) {
                $query->orderBy('compte_numero', 'asc'); //  TRI CROISSANT
            }])->findOrFail($request->bilan_id);

            $nouveauBilan = $bilan->replicate();
            $nouveauBilan->date_bilan = now();
            $nouveauBilan->is_actif = true;
            $nouveauBilan->est_provisoire = true;
            $nouveauBilan->provient_cloture = false;
            $nouveauBilan->save();

            foreach ($bilan->bilan_lignes as $ligne) {
                BilanLigne::create([
                    'bilan_id'      => $nouveauBilan->id,
                    'compte_numero' => $ligne->compte_numero,
                    'compte_nom'    => $ligne->compte_nom,
                    'type_compte'   => $ligne->type_compte,
                    'montant'       => $ligne->montant
                ]);
            }

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => ' Bilan dupliqué !',
                'bilan_id' => $nouveauBilan->id
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur : ' . $e->getMessage()
            ], 500);
        }
    }

    public function verifierIntangibilite($id)
    {
        try {
            $periode = PeriodesComptable::findOrFail($id);
            $bilanInitial = Bilan::where('periode_id', $id)
                ->where('type_bilan', 'initial')
                ->first();

            if (!$bilanInitial) {
                return response()->json([
                    'success' => true,
                    'ecart_detecte' => false,
                    'message' => 'Aucun bilan initial trouvé.'
                ]);
            }

            $periodePrecedente = PeriodesComptable::where('date_fin', '<', $periode->date_debut)
                ->orderBy('date_fin', 'desc')
                ->first();

            if (!$periodePrecedente) {
                return response()->json([
                    'success' => true,
                    'ecart_detecte' => false,
                    'message' => 'Aucune période précédente.'
                ]);
            }

            $bilanFermeture = Bilan::where('periode_id', $periodePrecedente->id)
                ->where('type_bilan', 'fermeture')
                ->first();

            if (!$bilanFermeture) {
                return response()->json([
                    'success' => true,
                    'ecart_detecte' => false,
                    'message' => 'Aucun bilan de fermeture précédent.'
                ]);
            }

            $ecart = abs(
                $bilanInitial->bilan_lignes->sum('montant') -
                $bilanFermeture->bilan_lignes->sum('montant')
            );

            return response()->json([
                'success' => true,
                'ecart_detecte' => $ecart > 0.01,
                'ecart' => round($ecart, 2)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function infoFermeturePeriode($id)
    {
        try {
            $periode = PeriodesComptable::findOrFail($id);
            $periodeSuivante = PeriodesComptable::where('date_debut', '>', $periode->date_fin)
                ->orderBy('date_debut', 'asc')
                ->first();

            $bilanInitial = Bilan::where('periode_id', $id)
                ->where('type_bilan', 'initial')
                ->first();

            $bilanFinal = Bilan::where('periode_id', $id)
                ->where('type_bilan', 'fermeture')
                ->first();

            $nbEcritures = EcrituresComptable::where('periode_id', $id)
                ->where('reference', 'NOT LIKE', 'BILAN%')
                ->where('reference', 'NOT LIKE', 'À-NOUVEAU%')
                ->where('reference', 'NOT LIKE', 'CLÔTURE%')
                ->count();

            return response()->json([
                'success' => true,
                'code' => $periode->code,
                'libelle' => $periode->libelle,
                'date_fin' => $periode->date_fin instanceof Carbon
                    ? $periode->date_fin->toDateString()
                    : $periode->date_fin,
                'date_ouverture_suggeree' => $periodeSuivante
                    ? ($periodeSuivante->date_debut instanceof Carbon
                        ? $periodeSuivante->date_debut->toDateString()
                        : $periodeSuivante->date_debut)
                    : Carbon::parse($periode->date_fin)->addDay()->toDateString(),
                'code_suggere' => $periodeSuivante ? $periodeSuivante->code : 'N/A',
                'nombre_ecritures' => $nbEcritures,
                'bilan_initial_existe' => $bilanInitial ? true : false,
                'bilan_final_existe' => $bilanFinal ? true : false,
                'is_cloture' => $periode->is_cloture,
                'statut' => $periode->statut,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function export(Request $request, $id = null)
    {
        try {
            if ($id) {
                $bilan = Bilan::with([
                    'periode_comptable',
                    'entite',
                    'succursale',
                    'bilan_lignes' => function ($query) {
                        $query->orderBy('compte_numero', 'asc'); //  TRI CROISSANT
                    }
                ])->findOrFail($id);

                if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
                    return response()->json(['success' => true, 'data' => $bilan]);
                }

                return $this->exportBilanExcel($bilan);
            }

            if ($request->type_bilan === 'calcule' || $request->filter_type) {
                return $this->exportBilanCalcule($request);
            }

            $bilans = Bilan::with([
                'periode_comptable',
                'entite',
                'succursale',
                'bilan_lignes' => function ($query) {
                    $query->orderBy('compte_numero', 'asc'); //  TRI CROISSANT
                }
            ])
            ->when($request->entite_id && $request->entite_id !== 'all', fn($q) => $q->where('entite_id', $request->entite_id))
            ->when($request->periode_id && $request->periode_id !== 'all', fn($q) => $q->where('periode_id', $request->periode_id))
            ->when($request->type_bilan && $request->type_bilan !== 'all' && $request->type_bilan !== 'calcule', fn($q) => $q->where('type_bilan', $request->type_bilan))
            ->orderBy('date_bilan', 'desc')
            ->get();

            if ($bilans->isEmpty()) {
                return $this->exportBilanCalcule($request);
            }

            if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
                return response()->json(['success' => true, 'data' => $bilans->load('bilan_lignes')]);
            }

            return $this->exportBilansMultiFeuilles($bilans);

        } catch (\Exception $e) {
            Log::error('Export Bilan error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur export'
            ], 500);
        }
    }

    private function exportBilanExcel($bilan)
    {
        // Utilisé si méthode existante ailleurs — sinon garde le multi-feuilles
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $this->remplirFeuilleBilan($sheet, $bilan);

        $filename = 'Bilan_' . ($bilan->periode_comptable->code ?? '') . '_' . date('Ymd_His') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
        exit;
    }

    private function exportBilanCalcule($request)
    {
        try {
            $params = [
                'periode_id' => $request->input('periode_id'),
                'entite_id' => $request->input('entite_id'),
                'succursale_id' => $request->input('succursale_id'),
                'filter_type' => $request->input('filter_type', 'exercice'),
                'date_debut' => $request->input('date_debut'),
                'date_fin' => $request->input('date_fin'),
            ];

            $data = $this->bilanService->calculerBilanFiltre($params);

            // Si PhpSpreadsheet pas dispo, retourner JSON
            if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
                return response()->json(['success' => true, 'data' => $data]);
            }

            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setCellValue('A1', 'BILAN CALCULÉ')->mergeCells('A1:H1');

            $filename = 'Bilan_Calcule_' . date('Ymd_His') . '.xlsx';
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
            exit;
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Export multi-feuilles : un bilan par feuille
     */
    private function exportBilansMultiFeuilles($bilans)
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

        $sheetIndex = 0;
        $first = true;

        foreach ($bilans as $bilan) {
            if ($first) {
                $sheet = $spreadsheet->getActiveSheet();
                $first = false;
            } else {
                $sheet = $spreadsheet->createSheet();
            }

            // Nom de la feuille = Entité_Type
            $entiteNom = substr($bilan->entite->nom ?? 'Global', 0, 20);
            $typeBilan = $bilan->type_bilan === 'initial'
                ? 'Initial'
                : ($bilan->type_bilan === 'fermeture' ? 'Fermeture' : 'Provisoire');
            $feuilleNom = substr($entiteNom . '_' . $typeBilan, 0, 31);
            $sheet->setTitle($feuilleNom);

            // Remplir la feuille avec ce bilan
            $this->remplirFeuilleBilan($sheet, $bilan);
        }

        // Revenir à la première feuille
        $spreadsheet->setActiveSheetIndex(0);

        $filename = 'Bilans_' . date('Ymd_His') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
        exit;
    }

    /**
     * Remplit une feuille avec un bilan
     */
    private function remplirFeuilleBilan($sheet, Bilan $bilan)
    {
        //  TRIER les lignes par numéro de compte croissant
        $actifs = $bilan->bilan_lignes
            ->where('type_compte', 'actif')
            ->sortBy('compte_numero')
            ->values();

        $passifs = $bilan->bilan_lignes
            ->where('type_compte', 'passif')
            ->sortBy('compte_numero')
            ->values();

        $totalActif = $actifs->sum('montant');
        $totalPassif = $passifs->sum('montant');
        $ecart = round($totalActif - $totalPassif, 2);

        // Équilibrer si nécessaire
        if (abs($ecart) > 0.01) {
            if ($ecart > 0) {
                $passifs->push((object)[
                    'numero' => '131',
                    'compte_nom' => 'RÉSULTAT NET : BÉNÉFICE',
                    'type_compte' => 'passif',
                    'montant' => abs($ecart)
                ]);
                $totalPassif += abs($ecart);
            } else {
                $actifs->push((object)[
                    'numero' => '139',
                    'compte_nom' => 'RÉSULTAT NET : PERTE',
                    'type_compte' => 'actif',
                    'montant' => abs($ecart)
                ]);
                $totalActif += abs($ecart);
            }

            //  RE-TRIER après ajout
            $actifs = $actifs->sortBy('numero')->values();
            $passifs = $passifs->sortBy('numero')->values();
        }

        // Titre
        $typeBilan = match($bilan->type_bilan) {
            'initial' => 'BILAN INITIAL',
            'fermeture' => $bilan->est_provisoire ? 'BILAN DE FERMETURE PROVISOIRE' : 'BILAN FINAL',
            'provisoire' => 'BILAN PROVISOIRE',
            default => 'BILAN'
        };

        $sheet->setCellValue('A1', '📊 ' . $typeBilan)->mergeCells('A1:H1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal('center');

        // Infos
        $row = 3;
        $sheet->setCellValue("A{$row}", 'Entité :')->setCellValue("B{$row}", $bilan->entite->nom ?? 'N/A');
        $row++;
        $sheet->setCellValue("A{$row}", 'Exercice :')->setCellValue("B{$row}", $bilan->periode_comptable->code ?? 'N/A');
        $row++;
        $sheet->setCellValue("A{$row}", 'Type :')->setCellValue("B{$row}", strtoupper($typeBilan));
        $row++;
        $sheet->setCellValue("A{$row}", 'Statut :')->setCellValue("B{$row}", $bilan->statut_libelle ?? 'N/A');
        $row++;
        $sheet->setCellValue("A{$row}", 'Date :')->setCellValue("B{$row}", $bilan->date_bilan instanceof Carbon ? $bilan->date_bilan->format('d/m/Y') : $bilan->date_bilan);
        $row += 2;

        // ACTIF
        $sheet->setCellValue('A' . $row, 'ACTIF')->mergeCells('A' . $row . ':D' . $row);
        $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A' . $row)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('DBEAFE');

        // PASSIF
        $sheet->setCellValue('E' . $row, 'PASSIF')->mergeCells('E' . $row . ':H' . $row);
        $sheet->getStyle('E' . $row)->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('E' . $row)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('FEF3C7');
        $row++;

        // En-têtes
        foreach (['N°', 'Intitulé', 'Type', 'Solde'] as $i => $h) {
            $sheet->setCellValue(chr(65 + $i) . $row, $h);
        }
        foreach (['N°', 'Intitulé', 'Type', 'Solde'] as $i => $h) {
            $sheet->setCellValue(chr(69 + $i) . $row, $h);
        }
        $sheet->getStyle("A{$row}:H{$row}")->getFont()->setBold(true);
        $row++;

        // Lignes
        $maxLignes = max(count($actifs), count($passifs));
        for ($i = 0; $i < $maxLignes; $i++) {
            if (isset($actifs[$i])) {
                $sheet->setCellValue("A{$row}", $actifs[$i]->compte_numero ?? $actifs[$i]['numero']);
                $sheet->setCellValue("B{$row}", $actifs[$i]->compte_nom ?? $actifs[$i]['nom']);
                $sheet->setCellValue("C{$row}", 'ACTIF');
                $sheet->setCellValue("D{$row}", $actifs[$i]->montant ?? $actifs[$i]['montant']);
            }
            if (isset($passifs[$i])) {
                $sheet->setCellValue("E{$row}", $passifs[$i]->compte_numero ?? $passifs[$i]['numero']);
                $sheet->setCellValue("F{$row}", $passifs[$i]->compte_nom ?? $passifs[$i]['nom']);
                $sheet->setCellValue("G{$row}", 'PASSIF');
                $sheet->setCellValue("H{$row}", $passifs[$i]->montant ?? $passifs[$i]['montant']);
            }
            $row++;
        }

        // Totaux
        $sheet->setCellValue("A{$row}", 'TOTAL ACTIF')->mergeCells("A{$row}:C{$row}");
        $sheet->setCellValue("D{$row}", $totalActif);
        $sheet->getStyle("A{$row}:D{$row}")->getFont()->setBold(true);
        $sheet->getStyle("A{$row}:D{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('E8F5E9');

        $sheet->setCellValue("E{$row}", 'TOTAL PASSIF')->mergeCells("E{$row}:G{$row}");
        $sheet->setCellValue("H{$row}", $totalPassif);
        $sheet->getStyle("E{$row}:H{$row}")->getFont()->setBold(true);
        $sheet->getStyle("E{$row}:H{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('E8F5E9');
        $row += 2;

        // Équilibre
        $ecartFinal = round($totalActif - $totalPassif, 2);
        $equilibre = abs($ecartFinal) < 0.01;
        $sheet->setCellValue("A{$row}", $equilibre ? ' BILAN ÉQUILIBRÉ' : '⚠️ DÉSÉQUILIBRE : ' . number_format(abs($ecartFinal), 2, ',', ' ') . ' $')->mergeCells("A{$row}:H{$row}");
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);

        // Largeurs
        foreach (['A'=>8,'B'=>30,'C'=>8,'D'=>15,'E'=>8,'F'=>30,'G'=>8,'H'=>15] as $c => $w) {
            $sheet->getColumnDimension($c)->setWidth($w);
        }
        $sheet->getStyle('D1:D' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('H1:H' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    }

    private function broadcastBilanEvent(string $action, Bilan $bilan): void
    {
        try {
            broadcast(new BilanChanged($action, [
                'id' => $bilan->id,
                'type_bilan' => $bilan->type_bilan,
                'est_provisoire' => $bilan->est_provisoire,
                'entite_id' => $bilan->entite_id,
                'periode_id' => $bilan->periode_id,
                'total_actif' => $bilan->bilan_lignes()->where('type_compte', 'actif')->sum('montant'),
                'total_passif' => $bilan->bilan_lignes()->where('type_compte', 'passif')->sum('montant'),
                'message' => match($action) {
                    'created' => ' Bilan créé',
                    'updated' => '✏️ Bilan mis à jour',
                    'deleted' => '🗑️ Bilan supprimé',
                    default => 'Bilan modifié'
                }
            ]))->toOthers();
        } catch (\Exception $e) {}
    }

    public function getTypesDisponibles(Request $request)
    {
        $periodeId = $request->get('periode_id');
        $entiteId = $request->get('entite_id');

        if (!$periodeId) {
            return response()->json(['types' => []]);
        }

        $types = app(BilanService::class)->getTypesBilanDisponibles($periodeId, $entiteId);

        return response()->json(['types' => $types]);
    }

    /**
 * Récupère un bilan spécifique par type (initial, fermeture, etc.)
 * Utilisé par le filtre "Type" du front-end
 */
public function parType(Request $request)
{
    $request->validate([
        'periode_id' => 'required|exists:periodes_comptables,id',
        'entite_id'  => 'nullable|exists:entites,id',
        'type_bilan' => 'required|string',
    ]);

    try {
        $bilan = Bilan::with([
            'periode_comptable',
            'entite',
            'succursale',
            'bilan_lignes' => function ($query) {
                $query->orderBy('compte_numero', 'asc');
            }
        ])
        ->where('periode_id', $request->periode_id)
        ->where('entite_id', $request->entite_id ?: null)
        ->where('type_bilan', $request->type_bilan)
        ->first();

        if (!$bilan) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun bilan "' . $request->type_bilan . '" trouvé pour cette période.',
            ], 404);
        }

        //  Double sécurité : trier à nouveau en PHP
        $bilan->bilan_lignes = $bilan->bilan_lignes->sortBy('compte_numero')->values();

        $bilan->total_actif = $bilan->bilan_lignes->where('type_compte', 'actif')->sum('montant');
        $bilan->total_passif = $bilan->bilan_lignes->where('type_compte', 'passif')->sum('montant');
        $bilan->est_verrouille = $this->bilanService->estVerrouille($bilan);
        $bilan->statut_libelle = $bilan->statut_libelle;

        return response()->json([
            'success' => true,
            'data'    => $bilan,
        ], 200, ['Content-Type' => 'application/json; charset=UTF-8'], JSON_UNESCAPED_UNICODE);

    } catch (\Exception $e) {
        Log::error('❌ Erreur parType', ['error' => $e->getMessage()]);
        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
        ], 500);
    }
}

    
}