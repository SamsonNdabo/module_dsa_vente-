<?php

namespace App\Http\Controllers\Caisse;

use App\Http\Controllers\Controller;
use App\Models\Caisses;
use App\Models\SoldeCaisse;
use App\Models\RapportOf;
use App\Models\RapportSolde;
use App\Models\RapportEcartCaisse;
use App\Models\Utilisateur;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RapportSoldesController extends Controller
{
    /**
     * Récupère les soldes consolidés de TOUTES les caisses de la succursale
     * groupés par type_caisse et par devise.
     */
    public function prepare(Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Non authentifié.'], 401);
        }

        $succursaleId = $user->succursale_id;
        if (!$succursaleId) {
            return response()->json(['success' => false, 'message' => 'Aucune succursale associée.'], 422);
        }

        $caisses = Caisses::where('succursale_id', $succursaleId)
            ->whereIn('type_caisse', ['PHYSIQUE', 'MOBILE_MONEY', 'BANCAIRE'])
            ->where('inactif', 0)
            ->get();

        if ($caisses->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Aucune caisse de type physique/mobile/bancaire trouvée.'
            ], 404);
        }

        $groupes = $caisses->groupBy('type_caisse');
        $resultat = [];

        foreach ($groupes as $type => $caissesDuType) {
            $soldesParDevise = SoldeCaisse::whereIn('caisse_id', $caissesDuType->pluck('id'))
                ->join('devise', 'solde_caisse.devise_id', '=', 'devise.id')
                ->select(
                    'devise.id as devise_id',
                    'devise.code as devise_code',
                    DB::raw('SUM(solde_caisse.solde) as total')
                )
                ->groupBy('devise.id', 'devise.code')
                ->get()
                ->map(function ($item) {
                    return [
                        'devise_id'   => $item->devise_id,
                        'devise_code' => $item->devise_code,
                        'solde'       => (float) $item->total,
                    ];
                });

            $detailsParCaisse = [];
            foreach ($caissesDuType as $caisse) {
                $soldesCaisse = SoldeCaisse::where('caisse_id', $caisse->id)
                    ->join('devise', 'solde_caisse.devise_id', '=', 'devise.id')
                    ->select(
                        'devise.id as devise_id',
                        'devise.code as devise_code',
                        'solde_caisse.solde'
                    )
                    ->get();

                foreach ($soldesCaisse as $solde) {
                    $detailsParCaisse[] = [
                        'caisse_id'   => $caisse->id,
                        'caisse_nom'  => $caisse->nom_caisse,
                        'devise_id'   => $solde->devise_id,
                        'devise_code' => $solde->devise_code,
                        'solde'       => (float) $solde->solde,
                    ];
                }
            }

            $resultat[] = [
                'type_caisse'        => $type,
                'caisses_ids'        => $caissesDuType->pluck('id')->toArray(),
                'soldes_consolides'  => $soldesParDevise,
                'details_par_caisse' => $detailsParCaisse,
            ];
        }

        return response()->json([
            'success' => true,
            'message' => 'Soldes consolidés préparés.',
            'data'    => [
                'succursale_id' => $succursaleId,
                'user_id'       => $user->id,
                'groupes'       => $resultat,
            ],
        ]);
    }

    /**
     * Enregistre un rapport AVANT ou APRÈS avec les soldes.
     * Utilise une colonne JSON 'groupes' pour stocker tous les types en une seule ligne.
     */
    public function store(Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Non authentifié.'], 401);
        }

        $succursaleId = $user->succursale_id;
        if (!$succursaleId) {
            return response()->json(['success' => false, 'message' => 'Aucune succursale associée.'], 422);
        }

        $validated = $request->validate([
            'type_rapport' => 'required|in:OUVERTURE,FERMETURE',
            'temps'        => 'required|in:AVANT,APRES',
            'motif'        => 'nullable|string',
            'groupes'      => 'required|array',
            'groupes.*.type_caisse' => 'required|in:PHYSIQUE,MOBILE_MONEY,BANCAIRE',
            'groupes.*.soldes'      => 'required|array',
            'groupes.*.soldes.*.devise_id'   => 'required|integer|exists:devise,id',
            'groupes.*.soldes.*.devise_code' => 'nullable|string',
            'groupes.*.soldes.*.solde'       => 'required|numeric|min:0',
            'groupes.*.ecarts_caisse'        => 'nullable|array',
            'groupes.*.ecarts_caisse.*.caisse_id'    => 'required|integer|exists:caisses,id',
            'groupes.*.ecarts_caisse.*.devise_id'    => 'required|integer|exists:devise,id',
            'groupes.*.ecarts_caisse.*.montant_saisi'=> 'required|numeric|min:0',
            'groupes.*.ecarts_caisse.*.motif'        => 'nullable|string',
        ]);

        // Construire le tableau JSON 'groupes' pour stocker dans la colonne JSON
        $groupesJson = [];
        foreach ($validated['groupes'] as $groupe) {
            $type = $groupe['type_caisse'];
            $groupesJson[$type] = [
                'soldes' => $groupe['soldes'],
                'ecarts_caisse' => $groupe['ecarts_caisse'] ?? [],
            ];
        }

        $rapport = null;
        DB::transaction(function () use ($user, $succursaleId, $validated, $groupesJson, &$rapport) {
            // Créer un seul rapport pour tous les types
            $rapport = RapportOf::create([
                'user_id'       => $user->id,
                'succursale_id' => $succursaleId,
                'type_rapport'  => $validated['type_rapport'],
                'temps'         => $validated['temps'],
                'motif'         => $validated['motif'] ?? null,
                'groupes'       => $groupesJson,
                'valide'        => false,
                'rejete'        => false,
            ]);

            // Si c'est APRÈS, calculer les écarts globaux en comparant avec le dernier AVANT
            if ($validated['temps'] === 'APRES') {
                $rapportAvant = RapportOf::where('succursale_id', $succursaleId)
                    ->where('type_rapport', $validated['type_rapport'])
                    ->where('temps', 'AVANT')
                    ->where('valide', 1) // seulement ceux validés (ou pas ?)
                    ->latest()
                    ->first();

                if ($rapportAvant) {
                    $ecartsGlobaux = [];
                    $groupesAvant = $rapportAvant->groupes ?? [];
                    foreach ($groupesAvant as $type => $dataAvant) {
                        $soldesAvant = collect($dataAvant['soldes'])->keyBy('devise_id');
                        $soldesApres = collect($groupesJson[$type]['soldes'] ?? [])->keyBy('devise_id');
                        foreach ($soldesAvant as $deviseId => $soldeAvant) {
                            $soldeApres = $soldesApres->get($deviseId);
                            $ecart = $soldeApres ? $soldeApres['solde'] - $soldeAvant['solde'] : -$soldeAvant['solde'];
                            $ecartsGlobaux[] = [
                                'type_caisse' => $type,
                                'devise_id'   => $deviseId,
                                'avant'       => $soldeAvant['solde'],
                                'apres'       => $soldeApres ? $soldeApres['solde'] : 0,
                                'ecart'       => $ecart,
                            ];
                        }
                    }
                    $rapport->ecart_global = json_encode($ecartsGlobaux);
                    $rapport->save();
                }
            }

            // Optionnel : stocker les soldes dans rapport_soldes pour des requêtes agrégées
            foreach ($validated['groupes'] as $groupe) {
                foreach ($groupe['soldes'] as $solde) {
                    RapportSolde::create([
                        'rapportof_id' => $rapport->id,
                        'devise_id'    => $solde['devise_id'],
                        'devise_code'  => $solde['devise_code'] ?? null,
                        'solde'        => $solde['solde'],
                        'solde_saisi'  => $validated['temps'] === 'APRES' ? $solde['solde'] : null,
                        'ecart'        => null, // sera calculé si besoin
                    ]);
                }
            }

            // Stocker les écarts par caisse dans rapport_ecarts_caisse (si APRÈS)
            if ($validated['temps'] === 'APRES') {
                foreach ($validated['groupes'] as $groupe) {
                    if (!empty($groupe['ecarts_caisse'])) {
                        foreach ($groupe['ecarts_caisse'] as $ecart) {
                            // Récupérer le solde système actuel
                            $soldeSysteme = SoldeCaisse::where('caisse_id', $ecart['caisse_id'])
                                ->where('devise_id', $ecart['devise_id'])
                                ->first();
                            $montantAttendu = $soldeSysteme ? (float) $soldeSysteme->solde : 0;
                            $montantConstate = (float) $ecart['montant_saisi'];
                            $ecartMontant = $montantConstate - $montantAttendu;

                            RapportEcartCaisse::create([
                                'rapportof_id'    => $rapport->id,
                                'caisse_id'       => $ecart['caisse_id'],
                                'devise_id'       => $ecart['devise_id'],
                                'montant_attendu' => $montantAttendu,
                                'montant_constate'=> $montantConstate,
                                'ecart'           => $ecartMontant,
                                'motif'           => $ecart['motif'] ?? null,
                                'corrige'         => false,
                            ]);
                        }
                    }
                }
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Rapport enregistré avec succès.',
            'data'    => $rapport->fresh(),
        ], 201);
    }

    /**
     * Valider un rapport APRÈS – applique les corrections aux soldes des caisses
     */
    public function valider(Request $request, $id)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Non authentifié.'], 401);
        }

        $rapport = RapportOf::with(['ecartsCaisse.caisse'])->find($id);
        if (!$rapport) {
            return response()->json(['success' => false, 'message' => 'Rapport introuvable.'], 404);
        }

        if ($rapport->temps !== 'APRES') {
            return response()->json(['success' => false, 'message' => 'Seul un rapport APRÈS peut être validé.'], 422);
        }

        if ($rapport->valide) {
            return response()->json(['success' => false, 'message' => 'Ce rapport est déjà validé.'], 422);
        }

        if ($rapport->rejete) {
            return response()->json(['success' => false, 'message' => 'Ce rapport a été rejeté.'], 422);
        }

        $validated = $request->validate([
            'notes_validation' => 'nullable|string',
        ]);

        try {
            DB::transaction(function () use ($rapport, $user, $validated) {
                // Récupérer les écarts par caisse
                foreach ($rapport->ecartsCaisse as $ecart) {
                    if ($ecart->corrige) continue;

                    $caisse = $ecart->caisse;
                    $montantEcart = (float) $ecart->ecart;
                    if ($montantEcart == 0) continue;

                    if ($montantEcart > 0) {
                        $caisse->crediter(
                            $montantEcart,
                            $ecart->devise_id,
                            "Correction rapport #{$rapport->id} - " . ($ecart->motif ?? 'Ajustement positif'),
                            "RAPPORT-{$rapport->id}",
                            $user->id
                        );
                    } else {
                        $caisse->debiter(
                            abs($montantEcart),
                            $ecart->devise_id,
                            "Correction rapport #{$rapport->id} - " . ($ecart->motif ?? 'Ajustement négatif'),
                            "RAPPORT-{$rapport->id}",
                            $user->id
                        );
                    }

                    $ecart->corrige = true;
                    $ecart->save();
                }

                $rapport->valide = true;
                $rapport->valide_par = $user->id;
                $rapport->date_validation = now();
                $rapport->motif = ($rapport->motif ? $rapport->motif . ' | ' : '') . 'Validation: ' . ($validated['notes_validation'] ?? 'Aucune note');
                $rapport->save();
            });

            return response()->json([
                'success' => true,
                'message' => 'Rapport validé et corrections appliquées.',
                'data'    => $rapport->fresh(['ecartsCaisse', 'validateur']),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la validation : ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Rejeter un rapport APRÈS
     */
    public function rejeter(Request $request, $id)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Non authentifié.'], 401);
        }

        $rapport = RapportOf::find($id);
        if (!$rapport) {
            return response()->json(['success' => false, 'message' => 'Rapport introuvable.'], 404);
        }

        if ($rapport->temps !== 'APRES') {
            return response()->json(['success' => false, 'message' => 'Seul un rapport APRÈS peut être rejeté.'], 422);
        }

        if ($rapport->valide) {
            return response()->json(['success' => false, 'message' => 'Ce rapport est déjà validé.'], 422);
        }

        if ($rapport->rejete) {
            return response()->json(['success' => false, 'message' => 'Ce rapport est déjà rejeté.'], 422);
        }

        $validated = $request->validate([
            'motif_rejet' => 'required|string',
        ]);

        $rapport->rejete = true;
        $rapport->rejete_par = $user->id;
        $rapport->date_rejet = now();
        $rapport->motif = ($rapport->motif ? $rapport->motif . ' | ' : '') . 'Rejet: ' . $validated['motif_rejet'];
        $rapport->save();

        return response()->json([
            'success' => true,
            'message' => 'Rapport rejeté avec succès.',
            'data'    => $rapport->fresh(['rejeteur']),
        ]);
    }

    /**
     * Liste des rapports
     */
public function index(Request $request)
{
    $user = auth()->user();

    $query = RapportOf::with('succursale'); // on retire user, validateur, rejeteur

    if ($user->isSuperAdmin() || $user->isChefOperation()||$user->isAuditeur()) {

        } elseif ($user->isChefAgence()) {
            $query->where('succursale_id', $user->succursale_id);
        } else {
            $query->where('user_id', $user->id);
        }

    // Filtres
    $query->when($request->filled('succursale_id'), fn($q) => $q->where('succursale_id', $request->succursale_id));
    $query->when($request->filled('type_rapport'), fn($q) => $q->where('type_rapport', $request->type_rapport));
    $query->when($request->filled('temps'), fn($q) => $q->where('temps', $request->temps));
    $query->when($request->filled('valide'), fn($q) => $q->where('valide', $request->boolean('valide')));
    $query->when($request->filled('rejete'), fn($q) => $q->where('rejete', $request->boolean('rejete')));
    $query->when($request->filled('created_at'), fn($q) => $q->whereDate('created_at', $request->created_at));

    $rapports = $query->orderBy('created_at', 'desc')
                      ->paginate($request->input('per_page', 20));

    // Collecte des IDs – colonnes correctes
    $userIds = collect()
        ->merge($rapports->pluck('user_id'))
        ->merge($rapports->pluck('valide_par'))   // au lieu de validateur_id
        ->merge($rapports->pluck('rejete_par'))   // au lieu de rejeteur_id
        ->filter()
        ->unique()
        ->toArray();

    $utilisateurs = Utilisateur::getUtilisateursByIds($userIds);

    // Injection
    $rapports->getCollection()->transform(function ($rapport) use ($utilisateurs) {
        $rapport->user_info      = $utilisateurs->get((int) $rapport->user_id);
        $rapport->validateur_info = $utilisateurs->get((int) $rapport->valide_par);
        $rapport->rejeteur_info   = $utilisateurs->get((int) $rapport->rejete_par);
        return $rapport;
    });

    return response()->json([
        'success' => true,
        'data'    => $rapports,
    ]);
}

    /**
     * Afficher un rapport spécifique
     */
    public function show($id)
    {
        $rapport = RapportOf::with(['ecartsCaisse.devise', 'ecartsCaisse.caisse', 'soldes.devise', 'user', 'validateur', 'rejeteur'])
            ->find($id);

        if (!$rapport) {
            return response()->json(['success' => false, 'message' => 'Rapport introuvable.'], 404);
        }

        $user = auth()->user();
        if ($user && $user->succursale_id && $rapport->succursale_id != $user->succursale_id) {
            return response()->json(['success' => false, 'message' => 'Accès non autorisé.'], 403);
        }

        return response()->json(['success' => true, 'data' => $rapport]);
    }

    /**
     * Supprimer un rapport (seulement si non validé et non rejeté)
     */
    public function destroy($id)
    {
        $rapport = RapportOf::find($id);
        if (!$rapport) {
            return response()->json(['success' => false, 'message' => 'Rapport introuvable.'], 404);
        }

        if ($rapport->valide) {
            return response()->json(['success' => false, 'message' => 'Impossible de supprimer un rapport validé.'], 422);
        }
        if ($rapport->rejete) {
            return response()->json(['success' => false, 'message' => 'Impossible de supprimer un rapport rejeté.'], 422);
        }

        DB::transaction(function () use ($rapport) {
            $rapport->soldes()->delete();
            $rapport->ecartsCaisse()->delete();
            $rapport->delete();
        });

        return response()->json(['success' => true, 'message' => 'Rapport supprimé.']);
    }
}