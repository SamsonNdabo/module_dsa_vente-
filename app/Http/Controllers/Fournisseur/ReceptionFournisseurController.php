<?php

namespace App\Http\Controllers\Fournisseur;

use App\Http\Controllers\Controller;
use App\Models\CommandeFournisseur;
use App\Models\ReceptionFournisseur;
use App\Models\LigneCommandeFournisseur;
use App\Services\ReceptionFournisseurService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReceptionFournisseurController extends Controller
{
    protected ReceptionFournisseurService $receptionService;

    // Constantes
    private const ROLES_FULL_ACCESS = ['SUPER_ADMIN', 'CHEF_OPERATIONS', 'AUDITEUR'];

    public function __construct(ReceptionFournisseurService $receptionService)
    {
        $this->receptionService = $receptionService;
    }

    /**
     * Afficher la liste des réceptions
     * Permission: view-receptions
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-receptions')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour voir les réceptions'
            ], 403);
        }

        $query = ReceptionFournisseur::select([
            'id', 'reference', 'commande_id', 'fournisseur_id', 'depot_destination_id',
            'date_reception', 'num_bon_livraison', 'num_facture_fournisseur',
            'transporteur', 'statut', 'total_valeur_ht', 'total_valeur_ttc',
            'cree_par_id', 'valide_par_id', 'date_validation',
            'created_at', 'updated_at'
        ])->with([
            'commande' => function($q) {
                $q->select('id', 'reference', 'statut');
            },
            'fournisseur' => function($q) {
                $q->select('id', 'nom', 'code_fournisseur');
            },
            'depotDestination' => function($q) {
                $q->select('id', 'succursale_id', 'is_depot')
                  ->with(['succursale' => function($sq) {
                      $sq->select('id', 'nom', 'code');
                  }]);
            },
            'creePar' => function($q) {
                $q->select('id', 'nom_complet');
            }
        ]);

        $this->applySuccursaleFilter($query, $user);

        // Filtres
        if ($request->filled('statut')) {
            $query->where('statut', $request->statut);
        }

        if ($request->filled('commande_id')) {
            $query->where('commande_id', $request->commande_id);
        }

        if ($request->filled('fournisseur_id')) {
            $query->where('fournisseur_id', $request->fournisseur_id);
        }

        if ($request->filled('depot_id')) {
            $query->where('depot_destination_id', $request->depot_id);
        }

        if ($request->filled('date_debut')) {
            $query->whereDate('date_reception', '>=', $request->date_debut);
        }

        if ($request->filled('date_fin')) {
            $query->whereDate('date_reception', '<=', $request->date_fin);
        }

        $perPage = $request->get('per_page', 20);
        $receptions = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $receptions
        ]);
    }

    /**
     * Afficher une réception
     * Permission: view-receptions
     */
    public function show($id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-receptions')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour voir les réceptions'
            ], 403);
        }

        $reception = ReceptionFournisseur::select([
            'id', 'reference', 'commande_id', 'fournisseur_id', 'depot_destination_id',
            'date_reception', 'num_bon_livraison', 'num_facture_fournisseur',
            'date_facture', 'transporteur', 'nom_livreur', 'vehicule_immatriculation',
            'statut', 'total_valeur_ht', 'total_valeur_tva', 'total_valeur_ttc',
            'notes', 'cree_par_id', 'valide_par_id', 'date_validation',
            'metadata', 'created_at', 'updated_at'
        ])->with([
            'commande' => function($q) {
                $q->select('id', 'reference', 'statut');
            },
            'fournisseur' => function($q) {
                $q->select('id', 'nom', 'code_fournisseur', 'email', 'telephone');
            },
            'depotDestination' => function($q) {
                $q->select('id', 'succursale_id', 'is_depot', 'emplacement')
                  ->with(['succursale' => function($sq) {
                      $sq->select('id', 'nom', 'code', 'adresse');
                  }]);
            },
            'lignes' => function($q) {
                $q->select('id', 'reception_id', 'commande_ligne_id', 'produit_id',
                          'quantite_attendue', 'quantite_recue', 'quantite_casse',
                          'quantite_perdue', 'quantite_non_conforme',
                          'prix_unitaire_ht', 'taux_tva', 'remise',
                          'valeur_ht', 'valeur_tva', 'valeur_ttc',
                          'date_expiration_lot', 'numero_lot', 'lot_id', 'notes')
                  ->with(['produit' => function($pq) {
                      $pq->select('id', 'nom', 'code_barre', 'type');
                  }]);
            },
            'creePar' => function($q) {
                $q->select('id', 'nom_complet', 'email');
            },
            'validePar' => function($q) {
                $q->select('id', 'nom_complet', 'email');
            }
        ])->findOrFail($id);

        if (!$this->canAccessReception($user, $reception)) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas accès à cette réception'
            ], 403);
        }

        $stats = $this->getReceptionStats($reception);

        return response()->json([
            'success' => true,
            'data' => $reception,
            'stats' => $stats
        ]);
    }

    /**
     * Lignes disponibles pour réception
     * Permission: view-receptions
     */
    public function lignesDisponibles($commandeId)
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-receptions')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour voir les lignes de commande'
            ], 403);
        }

        $commande = CommandeFournisseur::with([
            'lignes.produit' => function($q) {
                $q->select('id', 'nom', 'code_barre');
            }
        ])->findOrFail($commandeId);

        if (!$this->canAccessCommande($user, $commande)) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas accès à cette commande'
            ], 403);
        }

        $lignes = $commande->lignes()
            ->where('quantite', '>', DB::raw('COALESCE(quantite_recue, 0)'))
            ->get()
            ->map(fn($ligne) => [
                'id' => $ligne->id,
                'produit' => [
                    'id' => $ligne->produit->id,
                    'nom' => $ligne->produit->nom,
                    'code_barre' => $ligne->produit->code_barre
                ],
                'quantite_commandee' => $ligne->quantite,
                'quantite_recue' => $ligne->quantite_recue ?? 0,
                'quantite_restante' => $ligne->quantite - ($ligne->quantite_recue ?? 0)
            ]);

        return response()->json([
            'success' => true,
            'data' => $lignes
        ]);
    }

    /**
     * Créer une réception
     * Permission: create-receptions
     */
    public function store(Request $request)
    {
        $request->validate([
            'commande_id' => 'required|exists:commandes_fournisseurs,id',
            
            // Informations de livraison
            'num_bon_livraison' => 'nullable|string|max:100',
            'num_facture_fournisseur' => 'nullable|string|max:100',
            'date_facture' => 'nullable|date',
            'transporteur' => 'nullable|string|max:100',
            'nom_livreur' => 'nullable|string|max:100',
            'vehicule_immatriculation' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
            
            // Lignes de réception
            'lignes' => 'required|array|min:1',
            'lignes.*.ligne_commande_id' => 'required|exists:lignes_commandes_fournisseurs,id',
            'lignes.*.quantite_recue' => 'required|integer|min:0',
            'lignes.*.quantite_casse' => 'nullable|integer|min:0',
            'lignes.*.quantite_perdue' => 'nullable|integer|min:0',
            'lignes.*.quantite_non_conforme' => 'nullable|integer|min:0',
            'lignes.*.prix_unitaire_ht' => 'nullable|numeric|min:0',
            'lignes.*.taux_tva' => 'nullable|numeric|min:0|max:100',
            'lignes.*.remise' => 'nullable|numeric|min:0|max:100',
            'lignes.*.date_expiration' => 'nullable|date',
            'lignes.*.numero_lot' => 'nullable|string|max:100',
            'lignes.*.notes' => 'nullable|string',
            
            // ============================================================
            // VALIDATION IMEI (NOUVEAU)
            // ============================================================
            'lignes.*.imeis' => 'nullable|array',
            'lignes.*.imeis.*' => 'required|string|regex:/^\d{15,17}$/',
            
            // Paiement
            'paiement' => 'nullable|array',
            'paiement.mode_paiement' => 'required_with:paiement|string|in:ESPECES,VIREMENT,CHEQUE,CARTE',
            'paiement.reference_externe' => 'nullable|string|max:191',
        ]);

        $commande = CommandeFournisseur::findOrFail($request->commande_id);
        
        $reception = $this->receptionService->creer(
            $request->all(),
            $commande,
            auth()->id()
        );

        return response()->json([
            'success' => true,
            'message' => 'Réception créée avec succès',
            'data' => $reception,
        ], 201);
    }

    /**
     * Valider une réception
     * Permission: validate-receptions
     */
    public function valider($id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('validate-receptions')) {
            return response()->json(['success' => false, 'message' => 'Permission refusée'], 403);
        }

        try {
            $reception = ReceptionFournisseur::findOrFail($id);

            if (!$this->canAccessReception($user, $reception)) {
                return response()->json(['success' => false, 'message' => 'Accès refusé'], 403);
            }

            $result = $this->receptionService->valider($reception, $user->id);

            return response()->json([
                'success' => true,
                'message' => 'Réception validée avec succès',
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur validation réception', [
                'reception_id' => $id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Annuler une réception
     * Permission: cancel-receptions
     */
    public function annuler(Request $request, $id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('cancel-receptions')) {
            return response()->json(['success' => false, 'message' => 'Permission refusée'], 403);
        }

        $validator = Validator::make($request->all(), [
            'motif' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $reception = ReceptionFournisseur::findOrFail($id);

            if (!$this->canAccessReception($user, $reception)) {
                return response()->json(['success' => false, 'message' => 'Accès refusé'], 403);
            }

            $reception = $this->receptionService->annuler($reception, $request->motif, $user->id);

            return response()->json([
                'success' => true,
                'message' => 'Réception annulée',
                'data' => $reception,
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur annulation réception', [
                'reception_id' => $id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    // ============================================================
    // MÉTHODES PRIVÉES
    // ============================================================

    /**
     * Applique le filtre par succursale
     */
    private function applySuccursaleFilter(&$query, $user): void
    {
        if (in_array($user->role?->code, self::ROLES_FULL_ACCESS) ||
            $user->hasPermission('manage-all-users') ||
            $user->hasPermission('view-all-stock')) {
            return;
        }

        $query->whereHas('depotDestination.succursale', function ($q) use ($user) {
            $q->where('id', $user->succursale_id);
        });
    }

    /**
     * Statistiques d'une réception
     */
    private function getReceptionStats($reception): array
    {
        return [
            'total_quantite_recue' => $reception->lignes->sum('quantite_recue'),
            'total_quantite_conforme' => $reception->lignes->sum(function($ligne) {
                return $ligne->quantite_recue - $ligne->quantite_casse - $ligne->quantite_perdue - $ligne->quantite_non_conforme;
            }),
            'total_ecarts' => $reception->lignes->sum(function($ligne) {
                return $ligne->quantite_casse + $ligne->quantite_perdue + $ligne->quantite_non_conforme;
            }),
            'total_valeur_ht' => $reception->lignes->sum('valeur_ht'),
            'total_valeur_tva' => $reception->lignes->sum('valeur_tva'),
            'total_valeur_ttc' => $reception->lignes->sum('valeur_ttc'),
            'a_des_ecarts' => $reception->lignes->contains(fn($l) => $l->hasEcart())
        ];
    }

    /**
     * Vérifie si l'utilisateur peut accéder à une commande
     */
    private function canAccessCommande($user, $commande): bool
    {
        if (in_array($user->role?->code, self::ROLES_FULL_ACCESS) ||
            $user->hasPermission('manage-all-users') ||
            $user->hasPermission('view-all-stock')) {
            return true;
        }
        return $commande->depotDestination?->succursale_id == $user->succursale_id;
    }

    /**
     * Vérifie si l'utilisateur peut accéder à une réception
     */
    private function canAccessReception($user, $reception): bool
    {
        if (in_array($user->role?->code, self::ROLES_FULL_ACCESS) ||
            $user->hasPermission('manage-all-users') ||
            $user->hasPermission('view-all-stock')) {
            return true;
        }
        return $reception->depotDestination?->succursale_id == $user->succursale_id;
    }
}