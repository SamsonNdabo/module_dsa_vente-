<?php

namespace App\Http\Controllers\Campagne;

use App\Http\Controllers\Controller;
use App\Models\Campagne\Campagne;
use App\Models\Campagne\CampagneAgentStock;
use App\Models\Campagne\CampagneOperation;
use App\Models\Campagne\CommissionGeneree;
use App\Services\Campagne\CampagneService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CampagneController extends Controller
{
    public function __construct(private CampagneService $service) {}

    // ============================================================
    // CRUD
    // ============================================================

    public function index(Request $request)
    {
        $filtres = $request->only([
            'statut', 'type_campagne', 'id_partenaire',
            'date_debut', 'date_fin', 'search', 'id_agent', 'id_zone',
        ]);

        $campagnes = $this->service->filtrerCampagnes($filtres)
            ->withCount(['zones', 'produits', 'agents'])
            ->with([
                'zones:id,nom',
                'produits:id,nom',
                'agents' => function ($query) {
                    $query->select('agents_dsa.id', 'agents_dsa.utilisateur_id')
                          ->with('utilisateur:id,nom_complet');
                }
            ])
            ->paginate(20);

        return response()->json($campagnes);
    }

    public function create()
    {
        return response()->json(['message' => 'Formulaire de création'], 200);
    }

    public function getTypesCampagne()
    {
        return response()->json(Campagne::getTypesDisponibles());
    }

    // ============================================================
    // STORE
    // ============================================================
    public function store(Request $request)
    {
        Log::info('Campagne payload (store):', $request->all());

        try {
            $data = $request->validate($this->reglesValidation());
        } catch (ValidationException $e) {
            Log::error('Validation campagne (store) échouée:', $e->errors());
            throw $e;
        }

        try {
            $campagne = $this->service->creerCampagne($data, Auth::user());

            return response()->json([
                'message'  => 'Campagne créée avec succès.',
                'campagne' => $campagne,
            ], 201);

        } catch (\Exception $e) {
            Log::error('Erreur création campagne:', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    // ============================================================
    // SHOW (CORRIGÉ DÉFINITIVEMENT)
    // ============================================================
    public function show(Request $request, Campagne $campagne)
    {
        try {
            // 1. Chargement des relations de base
            $campagne->load([
                'partenaire',
                'zones' => function ($query) {
                    $query->withPivot('id_superviseur');
                },
                'produits' => function ($query) {
                    $query->withPivot(
                        'objectif_quantite',
                        'objectif_montant',
                        'objectifs', // ✅ JSON
                        'prix_special',
                        'offre_speciale',
                        'valeur_offre'
                    );
                },
                'agents' => function ($query) {
                    $query->withPivot(
                        'id', // ✅ On a besoin de l'ID du pivot
                        'id_zone',
                        'id_superviseur',
                        'date_debut_affectation',
                        'date_fin_affectation',
                        'statut_affectation'
                    )->with([
                        'utilisateur:id,nom_complet,email,telephone',
                        'zone:id,nom'
                    ]);
                },
                'regles',
            ]);

            // 2. Chargement manuel des stocks (car la relation n'existe pas sur AgentDsa)
            $affectationIds = $campagne->agents->pluck('pivot.id')->filter();

            $stocks = CampagneAgentStock::whereIn('id_campagne_agent', $affectationIds)
                ->with('produit')
                ->get()
                ->groupBy('id_campagne_agent');

            // 3. Injection des stocks dans le pivot de chaque agent
            foreach ($campagne->agents as $agent) {
                $agent->pivot->stocks = $stocks->get($agent->pivot->id, collect());
            }

            // 4. Formatage des dates
            $campagneArray = $campagne->toArray();
            $campagneArray['date_debut'] = $campagne->date_debut->format('d/m/Y');
            $campagneArray['date_fin']   = $campagne->date_fin->format('d/m/Y');

            return response()->json([
                'campagne'              => $campagneArray,
                'stats'                 => $this->service->statistiquesCampagne($campagne->id),
                'comparaison_agents'    => $this->service->comparaisonParAgent($campagne->id),
                'comparaison_zones'     => $this->service->comparaisonParZone($campagne->id),
                'comparaison_produits'  => $this->service->comparaisonParProduit($campagne->id),
                'evolution_zones'       => $this->service->evolutionParZone($campagne->id),
                'evolution_produits'    => $this->service->evolutionParProduit($campagne->id),
                'evolution_agents'      => $this->service->evolutionParAgent($campagne->id),
                'globalChartData'       => $this->service->evolutionGlobale($campagne->id),
                'top_agents_par_type'   => $this->service->topAgentsParType($campagne->id),
                'regles'                => $campagne->regles,
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur détails campagne:', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function edit(Campagne $campagne)
    {
        return response()->json(['campagne' => $campagne], 200);
    }

    // ============================================================
    // UPDATE
    // ============================================================
    public function update(Request $request, Campagne $campagne)
    {
        Log::info('Campagne payload (update):', [
            'id'   => $campagne->id,
            'data' => $request->all(),
        ]);

        try {
            $data = $request->validate($this->reglesValidation(true, $campagne));
        } catch (ValidationException $e) {
            Log::error('Validation campagne (update) échouée:', $e->errors());
            throw $e;
        }

        try {
            $campagne = $this->service->mettreAJourCampagne($campagne, $data);

            return response()->json([
                'message'  => 'Campagne mise à jour.',
                'campagne' => $campagne,
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur mise à jour campagne:', ['message' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function destroy(Request $request, Campagne $campagne)
    {
        try {
            $this->service->supprimerCampagne($campagne);
            return response()->json(['message' => 'Campagne supprimée.']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    // ============================================================
    // CYCLE DE VIE
    // ============================================================

    public function valider(Request $request, Campagne $campagne)
    {
        try {
            $this->service->validerCampagne($campagne, Auth::user());
            return $this->reponse($request, 'Campagne validée.', $campagne);
        } catch (\Exception $e) {
            return $this->erreur($request, $e->getMessage());
        }
    }

    public function activer(Request $request, Campagne $campagne)
    {
        try {
            $this->service->activerCampagne($campagne);
            return $this->reponse($request, 'Campagne activée.', $campagne);
        } catch (\Exception $e) {
            return $this->erreur($request, $e->getMessage());
        }
    }

    public function pause(Request $request, Campagne $campagne)
    {
        try {
            $this->service->mettreEnPause($campagne);
            return $this->reponse($request, 'Campagne mise en pause.', $campagne);
        } catch (\Exception $e) {
            return $this->erreur($request, $e->getMessage());
        }
    }

    public function reprendre(Request $request, Campagne $campagne)
    {
        try {
            $this->service->reprendreCampagne($campagne);
            return $this->reponse($request, 'Campagne reprise.', $campagne);
        } catch (\Exception $e) {
            return $this->erreur($request, $e->getMessage());
        }
    }

    public function cloturer(Request $request, Campagne $campagne)
    {
        try {
            $this->service->cloturerCampagne($campagne);
            return $this->reponse($request, 'Campagne clôturée.', $campagne);
        } catch (\Exception $e) {
            return $this->erreur($request, $e->getMessage());
        }
    }

    // ============================================================
    // AGENTS
    // ============================================================

    public function affecterAgent(Request $request, Campagne $campagne)
    {
        $data = $request->validate([
            'id_agent'       => 'required|exists:agents_dsa,id',
            'id_zone'        => 'required|exists:zones,id',
            'id_superviseur' => 'nullable|exists:utilisateurs,id',
            'stocks'                     => 'nullable|array',
            'stocks.*.id_produit'        => 'required|exists:produit,id',
            'stocks.*.quantite_remise'   => 'nullable|integer|min:0',
            'stocks.*.objectifs'         => 'nullable|array',
            'stocks.*.objectif_quantite' => 'nullable|integer|min:0',
            'stocks.*.objectif_client'   => 'nullable|integer|min:0',
            'stocks.*.objectif_visite'   => 'nullable|integer|min:0',
            'stocks.*.objectif_plainte'  => 'nullable|integer|min:0',
            'stocks.*.objectif_suggestion' => 'nullable|integer|min:0',
            'stocks.*.objectif_montant'  => 'nullable|numeric|min:0',
        ]);

        try {
            $affectation = $this->service->affecterAgent(
                $campagne,
                $data['id_agent'],
                $data['id_zone'],
                $data['id_superviseur'] ?? null,
                $data['stocks'] ?? []
            );

            return response()->json([
                'message'     => 'Agent affecté.',
                'affectation' => $affectation,
            ], 201);
        } catch (\Exception $e) {
            return $this->erreur($request, $e->getMessage());
        }
    }

    public function retirerAgent(Request $request, Campagne $campagne, int $idAgent)
    {
        try {
            $this->service->retirerAgent($campagne, $idAgent);
            return $this->reponse($request, 'Agent retiré.', $campagne);
        } catch (\Exception $e) {
            return $this->erreur($request, $e->getMessage());
        }
    }

    // ============================================================
    // RÈGLES
    // ============================================================

    public function ajouterRegle(Request $request, Campagne $campagne)
    {
        $data = $request->validate([
            'type_regle'          => 'required|in:OBJECTIF,COMMISSION,BONUS',
            'type_indicateur'     => 'required|string|max:50',
            'id_produit'          => 'nullable|exists:produit,id',
            'id_agent'            => 'nullable|exists:agents_dsa,id',
            'id_zone'             => 'nullable|integer|exists:zones,id',
            'valeur_cible'        => 'nullable|numeric|min:0',
            'mode_calcul'         => 'nullable|in:FIXE,POURCENTAGE',
            'valeur_commission'   => 'nullable|numeric|min:0',
            'statut_eligible'     => 'nullable|in:CONFIRMEE,LIVREE,ACTIVEE',
            'condition_bonus'     => 'nullable|array',
            'montant_bonus'       => 'nullable|numeric|min:0',
            'date_debut_validite' => 'nullable|date',
            'date_fin_validite'   => 'nullable|date',
        ]);

        try {
            $regle = $this->service->ajouterRegle($campagne, $data);
            return response()->json([
                'message' => 'Règle ajoutée.',
                'regle'   => $regle,
            ], 201);
        } catch (\Exception $e) {
            return $this->erreur($request, $e->getMessage());
        }
    }

    // ============================================================
    // STATISTIQUES & COMPARAISONS
    // ============================================================

    public function statistiques(Request $request, Campagne $campagne)
    {
        return response()->json($this->service->statistiquesCampagne($campagne->id));
    }

    public function comparaisonParAgent(Request $request, Campagne $campagne)
    {
        return response()->json($this->service->comparaisonParAgent($campagne->id));
    }

    public function comparaisonParProduit(Request $request, Campagne $campagne)
    {
        return response()->json($this->service->comparaisonParProduit($campagne->id));
    }

    public function comparaisonParZone(Request $request, Campagne $campagne)
    {
        return response()->json($this->service->comparaisonParZone($campagne->id));
    }

    public function classement(Request $request, Campagne $campagne)
    {
        $limit = $request->input('limit', 10);
        return response()->json($this->service->classementAgents($campagne->id, $limit));
    }

    public function export(Request $request, Campagne $campagne)
    {
        return response()->json(['message' => 'L\'export sera disponible prochainement.'], 200);
    }

    // ============================================================
    // OPERATIONS
    // ============================================================

    public function enregistrerOperation(Request $request)
    {
        $data = $request->validate([
            'id_campagne'          => 'required|exists:campagnes,id',
            'type_operation'       => 'required|in:VENTE,TRANSACTION_EPARGNE,KYC,VISITE,COMMANDE,CREATION_CLIENT,PLAINTE,SUGGESTION',
            'id_operation_source'  => 'required|string|max:100',
            'id_agent'             => 'required|exists:agents_dsa,id',
            'id_produit'           => 'nullable|exists:produit,id',
            'montant_operation'    => 'nullable|numeric|min:0',
            'quantite_operation'   => 'nullable|integer|min:1',
            'date_operation'       => 'nullable|date',
        ]);

        try {
            $operation = $this->service->enregistrerOperation($data);

            if (!$operation) {
                return response()->json([
                    'error' => 'Campagne inactive ou agent non affecté.',
                ], 422);
            }

            return response()->json([
                'message'   => 'Opération enregistrée avec succès.',
                'operation' => $operation,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Erreur enregistrement opération:', ['message' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function listerOperations(Request $request)
    {
        $request->validate(['id_campagne' => 'required|exists:campagnes,id']);
        $filtres = $request->only([
            'type_operation', 'id_agent', 'id_produit',
            'date_debut', 'date_fin', 'statut_commission',
        ]);
        $operations = $this->service->filtrerOperations($request->id_campagne, $filtres)
            ->paginate(20);
        return response()->json($operations);
    }

    // ============================================================
    // COMMISSIONS
    // ============================================================

    public function validerCommission(Request $request, CommissionGeneree $commission)
    {
        try {
            $commission = $this->service->validerCommission($commission, Auth::user());
            return $this->reponse($request, 'Commission validée.', $commission);
        } catch (\Exception $e) {
            return $this->erreur($request, $e->getMessage());
        }
    }

    public function payerCommission(Request $request, CommissionGeneree $commission)
    {
        try {
            $commission = $this->service->payerCommission(
                $commission,
                Auth::user(),
                $request->input('commentaire')
            );
            return $this->reponse($request, 'Commission payée.', $commission);
        } catch (\Exception $e) {
            return $this->erreur($request, $e->getMessage());
        }
    }

    public function annulerCommission(Request $request, CommissionGeneree $commission)
    {
        $request->validate(['motif' => 'required|string']);

        try {
            $commission = $this->service->annulerCommission(
                $commission,
                Auth::user(),
                $request->motif
            );
            return $this->reponse($request, 'Commission annulée.', $commission);
        } catch (\Exception $e) {
            return $this->erreur($request, $e->getMessage());
        }
    }

    // ============================================================
    // API MOBILE AGENT
    // ============================================================

    public function mobileDashboard(Request $request)
    {
        $idAgent = $request->user()->id;
        return response()->json($this->service->dashboardAgent($idAgent));
    }

    // ============================================================
    // HELPERS
    // ============================================================

    private function reponse(Request $request, string $message, $data = null)
    {
        return response()->json(['message' => $message, 'data' => $data]);
    }

    private function erreur(Request $request, string $message)
    {
        return response()->json(['error' => $message], 422);
    }

    // ============================================================
    // RÈGLES CENTRALISÉES (store + update)
    // ============================================================
    private function reglesValidation(bool $isUpdate = false, ?Campagne $campagne = null): array
    {
        $sometimes = $isUpdate ? 'sometimes|' : '';

        $codeRule = 'string|max:50|unique:campagnes,code';
        if ($isUpdate && $campagne) {
            $codeRule = 'string|max:50|unique:campagnes,code,' . $campagne->id;
        }
        $codeRule = ($isUpdate ? 'sometimes|' : 'required|') . $codeRule;

        return [
            'code'            => $codeRule,
            'nom'             => $sometimes . 'required|string|max:150',
            'description'     => 'nullable|string',
            'id_partenaire'   => 'nullable|exists:partenaires,id',
            'type_campagne'   => $sometimes . 'required|in:VENTE,EPARGNE,KYC,MIXTE,VISITE,PLAINTE,SUGGESTION,CREATION_CLIENT,COMMANDE',
            'date_debut'      => $sometimes . 'required|date',
            'date_fin'        => $sometimes . 'required|date|after:date_debut',

            // --- PRODUITS ---
            'produits'                      => 'nullable|array',
            'produits.*.id_produit'         => 'required|exists:produit,id',
            'produits.*.objectifs'          => 'nullable|array', 
            'produits.*.objectif_quantite'  => 'nullable|integer|min:0',
            'produits.*.objectif_montant'   => 'nullable|numeric|min:0',
            'produits.*.objectif_client'    => 'nullable|integer|min:0',
            'produits.*.objectif_visite'    => 'nullable|integer|min:0',
            'produits.*.objectif_plainte'   => 'nullable|integer|min:0',
            'produits.*.objectif_suggestion'=> 'nullable|integer|min:0',
            'produits.*.prix_special'       => 'nullable|numeric|min:0',
            'produits.*.offre_speciale'     => 'nullable|string|max:100',
            'produits.*.valeur_offre'       => 'nullable|numeric|min:0',

            // --- ZONES ---
            'zones'                  => 'nullable|array',
            'zones.*.id_zone'        => 'required|integer|exists:zones,id',
            'zones.*.id_superviseur' => 'nullable|exists:utilisateurs,id',

            // --- AGENTS ---
            'agents'                  => 'nullable|array|min:1',
            'agents.*.id_agent'       => 'required|exists:agents_dsa,id',
            'agents.*.id_zone'        => 'required|exists:zones,id',
            'agents.*.id_superviseur' => 'nullable|exists:utilisateurs,id',

            // --- STOCKS (OBJECTIFS AGENTS) ---
            'agents.*.stocks'                      => 'nullable|array',
            'agents.*.stocks.*.id_produit'         => 'required|exists:produit,id',
            'agents.*.stocks.*.objectifs'          => 'nullable|array', 
            'agents.*.stocks.*.quantite_remise'    => 'nullable|integer|min:0',
            'agents.*.stocks.*.objectif_quantite'  => 'nullable|integer|min:0',
            'agents.*.stocks.*.objectif_client'    => 'nullable|integer|min:0',
            'agents.*.stocks.*.objectif_visite'    => 'nullable|integer|min:0',
            'agents.*.stocks.*.objectif_plainte'   => 'nullable|integer|min:0',
            'agents.*.stocks.*.objectif_suggestion'=> 'nullable|integer|min:0',
            'agents.*.stocks.*.objectif_montant'   => 'nullable|numeric|min:0',

            // --- RÈGLES ---
            'regles'                           => 'nullable|array',
            'regles.*.id_produit'              => 'nullable|exists:produit,id',
            'regles.*.id_agent'                => 'nullable|exists:agents_dsa,id',
            'regles.*.id_zone'                 => 'nullable|exists:zones,id',
            'regles.*.type_regle'              => 'required|in:OBJECTIF,COMMISSION,BONUS',
            'regles.*.type_indicateur'         => 'required|string|max:50',
            'regles.*.valeur_cible'            => 'nullable|numeric|min:0',
            'regles.*.mode_calcul'             => 'nullable|in:FIXE,POURCENTAGE',
            'regles.*.valeur_commission'       => 'nullable|numeric|min:0',
            'regles.*.statut_eligible'         => 'nullable|in:CONFIRMEE,LIVREE,ACTIVEE',
            'regles.*.condition_bonus'         => 'nullable|array',
            'regles.*.montant_bonus'           => 'nullable|numeric|min:0',
            'regles.*.date_debut_validite'     => 'nullable|date',
            'regles.*.date_fin_validite'       => 'nullable|date',
        ];
    }
}