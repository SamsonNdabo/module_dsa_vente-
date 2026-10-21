<?php
// app/Http/Controllers/Commande/CommandeController.php

namespace App\Http\Controllers\Commande;

use App\Http\Controllers\Controller;
use App\Models\CommandeClient;
use App\Models\Succursale;
use App\Models\Livraison;
use App\Services\CommandeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CommandeController extends Controller
{
    protected CommandeService $commandeService;

    public function __construct(CommandeService $commandeService)
    {
        $this->commandeService = $commandeService;
    }

    /**
     * Liste de toutes les commandes
     */
       public function index(Request $request)
    {
        $user = auth()->user();
    
        $query = CommandeClient::with([
            'client',
            'lignes.produit',
            'livraison',
            'succursaleAssignee'
        ]);

    
        if ($user->isSuperAdmin() || $user->isChefOperation()||$user->isAuditeur()) {

        } elseif ($user->isChefAgence()) {
            if (!$user->succursale_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune succursale associée'
                ], 403);
            }
    
            $query->where('succursale_assignee_id', $user->succursale_id);
        }
    
        /*
        ============================================================
        FILTRES
        ============================================================
        */
    
        if ($request->filled('statut')) {
            $query->where('statut', $request->statut);
        }
    
        if ($request->filled('statut_logistique')) {
            $query->where('statut_logistique', $request->statut_logistique);
        }
    
        if ($request->filled('client_id')) {
            $query->where('client_id', $request->client_id);
        }
    
        /*
        ============================================================
        FILTRE SUCCURSALE
        ============================================================
        SUPER_ADMIN et CHEF_OPERATIONS peuvent choisir
        n'importe quelle succursale.
        CHEF_AGENCE reste limité à la sienne.
        ============================================================
        */
        if ($request->filled('succursale_id')) {
    
            if ($user->isSuperAdmin() || $user->isChefOperation()) {
    
                $query->where('succursale_assignee_id', $request->succursale_id);
    
            } elseif ($user->isChefAgence()) {
    
                $query->where('succursale_assignee_id', $user->succursale_id);
    
            }
        }
    
        $commandes = $query
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));
    
        return response()->json([
            'success' => true,
            'data' => $commandes
        ]);
    }
    /**
     * Détails d'une commande
     */
    public function show($id)
    {
        $commande = CommandeClient::with([
            'client',
            'lignes.produit',
            'livraison',
            'succursaleAssignee',
            'assignePar'
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $commande
        ]);
    }

    /**
     * Valider le paiement
     */
    public function validerPaiement(Request $request, $id)
    {
        try {
            $request->validate([
                'reference_paiement' => 'required|string'
            ]);

            $commande = CommandeClient::findOrFail($id);

            if ($commande->statut !== 'EN_ATTENTE_PAIEMENT') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cette commande n\'est pas en attente de paiement'
                ], 422);
            }

            $this->commandeService->validerPaiement($commande, $request->reference_paiement);

            return response()->json([
                'success' => true,
                'message' => 'Paiement validé',
                'data' => $commande->fresh(['livraison', 'succursaleAssignee'])
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }




    /**
     * Démarrer la préparation
     */
    public function preparer($id)
    {
        try {
            $commande = CommandeClient::findOrFail($id);

            $this->commandeService->demarrerPreparation($commande);

            return response()->json([
                'success' => true,
                'message' => 'Commande en préparation',
                'data' => $commande->fresh(['livraison', 'succursaleAssignee'])
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Marquer comme prête
     */
    public function marquerPrete($id)
    {
        try {
            $commande = CommandeClient::findOrFail($id);

            $this->commandeService->marquerPrete($commande);

            return response()->json([
                'success' => true,
                'message' => 'Commande prête pour livraison',
                'data' => $commande->fresh(['livraison', 'succursaleAssignee'])
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    public function assigner(Request $request, $id)
    {
        try {
            $request->validate([
                'succursale_id' => 'required|exists:succursales,id'
            ]);

            $commande = CommandeClient::findOrFail($id);

            if (!$commande->peutAssigner) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cette commande ne peut pas être assignée'
                ], 422);
            }

            $this->commandeService->assignerCommande(
                $commande,
                $request->succursale_id,
                $request->user()->id
            );

            return response()->json([
                'success' => true,
                'message' => 'Commande assignée avec succès',
                'data' => $commande->fresh(['succursaleAssignee', 'assignePar', 'lignes'])
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
 * Expédier la commande + assignation obligatoire du livreur
 */
public function expedier(Request $request, $id)
{
    try {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non authentifié.'
            ], 401);
        }

        // validation livreur obligatoire
        $request->validate([
            'livreur_id' => 'required|exists:utilisateurs,id'
        ]);

        // charger commande + livraison
        $commande = CommandeClient::with('livraison')->findOrFail($id);

        // sécurité métier
        if ($commande->statut !== 'PRETE') {
            return response()->json([
                'success' => false,
                'message' => "La commande doit être PRETE avant expédition."
            ], 422);
        }

        if (!$commande->livraison) {
            return response()->json([
                'success' => false,
                'message' => "Aucune livraison associée à cette commande."
            ], 422);
        }

        // récupérer livreur valide
        $livreur = \App\Models\Utilisateur::where('id', $request->livreur_id)
            ->where('statut', 'ACTIF')
            ->whereHas('role', function ($q) {
                $q->where('code', 'LIVREUR');
            })
            ->first();

        if (!$livreur) {
            return response()->json([
                'success' => false,
                'message' => 'Livreur invalide ou inactif.'
            ], 422);
        }

        // transaction pour sécurité
        \DB::transaction(function () use ($commande, $livreur, $user) {

            // 1. assigner livreur à la livraison
            $commande->livraison->update([
                'livreur_id' => $livreur->id,
                'statut' => 'PRISE_EN_CHARGE',
                'date_pris_en_charge' => now()
            ]);

            $commande->livraison->ajouterHistorique(
                'PRISE_EN_CHARGE',
                'Livraison prise en charge par ' . $livreur->nom_complet
            );

            // 2. expédier commande
            $commande->statut = 'EN_LIVRAISON';
            $commande->date_expedition = now();
            $commande->save();

            // 3. log optionnel métier
            Log::info('Commande expédiée avec livreur', [
                'commande_id' => $commande->id,
                'livreur_id' => $livreur->id,
                'par' => $user->id
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Commande expédiée et livreur assigné avec succès.',
            'data' => $commande->fresh(['livraison', 'succursaleAssignee'])
        ]);

    } catch (\Throwable $e) {
        Log::error('Erreur expedition commande', [
            'commande_id' => $id,
            'error' => $e->getMessage()
        ]);

        return response()->json([
            'success' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}

    /**
     * Marquer comme livrée
     */
    public function marquerLivree($id)
    {
        try {
            $commande = CommandeClient::findOrFail($id);

            $this->commandeService->marquerLivree($commande);

            return response()->json([
                'success' => true,
                'message' => 'Commande marquée comme livrée',
                'data' => $commande->fresh(['livraison', 'succursaleAssignee'])
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Annuler une commande
     */
    public function annuler(Request $request, $id)
    {
        try {
            $request->validate([
                'motif' => 'required|string'
            ]);

            $commande = CommandeClient::findOrFail($id);

            $this->commandeService->annulerCommande($commande, $request->motif);

            return response()->json([
                'success' => true,
                'message' => 'Commande annulée',
                'data' => $commande->fresh(['livraison'])
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Statistiques globales
     */
    public function statistiques()
{
    $user = auth()->user();

    $baseQuery = CommandeClient::query();

    /**
     * 🔐 GESTION DES RÔLES
     */

    // SUPER_ADMIN → aucune restriction
    if ($user->isSuperAdmin()) {
        // accès global
    }

    // CHEF_AGENCE → restriction automatique
    elseif ($user->isChefAgence()) {
        if (!$user->succursale_id) {
            return response()->json([
                'success' => false,
                'message' => 'Aucune succursale associée'
            ], 403);
        }

        $baseQuery->where('succursale_assignee_id', $user->succursale_id);
    }

    return response()->json([
        'success' => true,
        'data' => [
            'total_commandes' => (clone $baseQuery)->count(),
            'en_attente_paiement' => (clone $baseQuery)->where('statut', 'EN_ATTENTE_PAIEMENT')->count(),
            'en_attente_logistique' => (clone $baseQuery)->where('statut', 'EN_ATTENTE_LOGISTIQUE')->count(),
            'assignees' => (clone $baseQuery)->where('statut', 'ASSIGNEE')->count(),
            'en_preparation' => (clone $baseQuery)->where('statut', 'EN_PREPARATION')->count(),
            'pretes' => (clone $baseQuery)->where('statut', 'PRETE')->count(),
            'en_livraison' => (clone $baseQuery)->where('statut', 'EN_LIVRAISON')->count(),
            'livrees' => (clone $baseQuery)->where('statut', 'LIVREE')->count(),
            'annulees' => (clone $baseQuery)->where('statut', 'ANNULEE')->count(),
            'montant_total' => (clone $baseQuery)->sum('total'),
        ]
    ]);
}
    /**
     * Succursales disponibles pour assignation
     */
    public function succursalesDisponibles($id)
    {
        try {
            $commande = CommandeClient::findOrFail($id);

            $succursales = Succursale::where('est_active', true)
                ->where('statut', 'ACTIVE')
                ->get()
                ->map(function ($succursale) use ($commande) {
                    $disponible = true;
                    $stockManquant = [];

                    foreach ($commande->lignes as $ligne) {
                        $stock = $succursale->getStock($ligne->produit_id);
                        if (!$stock || $stock->quantite_disponible < $ligne->quantite) {
                            $disponible = false;
                            $stockManquant[] = [
                                'produit' => $ligne->produit->nom,
                                'disponible' => $stock ? $stock->quantite_disponible : 0,
                                'demande' => $ligne->quantite
                            ];
                        }
                    }

                    return [
                        'id' => $succursale->id,
                        'nom' => $succursale->nom,
                        'code' => $succursale->code,
                        'ville' => $succursale->ville,
                        'disponible' => $disponible,
                        'stock_manquant' => $stockManquant
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $succursales
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}