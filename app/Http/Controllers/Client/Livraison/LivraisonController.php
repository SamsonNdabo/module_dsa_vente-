<?php
// app/Http/Controllers/Client/Livraison/LivraisonController.php

namespace App\Http\Controllers\Client\Livraison;

use App\Http\Controllers\Controller;
use App\Models\Livraison;
use App\Models\CommandeClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LivraisonController extends Controller
{
    /**
     * Vérifier si l'utilisateur peut accéder à une livraison
     */
    private function canAccessLivraison($user, $livraison): bool
    {
        if ($user->isSuperAdmin() || $user->isChefOperation()||$user->isAuditeur()) {

        }
        
        if (!$user->isChefAgence() || !$user->succursale_id) {
            return true;
        }

        if (!$livraison->commande) {
            return false;
        }

        return $livraison->commande->succursale_assignee_id == $user->succursale_id;
    }

    /**
     * Liste des livraisons - CORRIGÉ
     */
    /**
 * Liste des livraisons - CORRIGÉ
 */
public function index(Request $request)
{
    try {
        $user = auth()->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non authentifié.'
            ], 401);
        }

        // ✅ Suppression de 'succursale_id' qui n'existe pas dans la table livraisons
        $query = Livraison::select([
            'id', 'reference', 'commande_id', 'client_id', 'statut',
            'transporteur', 'numero_suivi', 'livreur_id',
            'date_pris_en_charge', 'date_livraison_estimee', 'date_livraison_reelle',
            'created_at', 'updated_at'
        ])->with([
            'commande' => function($q) {
                $q->select('id', 'reference', 'client_id', 'succursale_assignee_id', 'statut', 'total')
                  ->with(['client' => function($cq) {
                      $cq->select('id', 'nom_complet', 'email', 'telephone');
                  }]);
            },
            'commande.succursaleAssignee' => function($q) {
                $q->select('id', 'nom', 'code');
            },
            'livreur' => function($q) {
                $q->select('id', 'nom_complet', 'email', 'telephone');
            }
        ]);

        /**
         * 🔐 GESTION DES RÔLES
         */

        // SUPER_ADMIN : aucun filtre
        if ($user->isSuperAdmin()) {
            // accès total
        }

        // CHEF_AGENCE : restriction sur succursale via commande
        elseif ($user->isChefAgence()) {
            if (!$user->succursale_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune succursale associée'
                ], 403);
            }

            $query->whereHas('commande', function ($q) use ($user) {
                $q->where('succursale_assignee_id', $user->succursale_id);
            });
        }

        /**
         * 🔎 FILTRES
         */

        if ($request->filled('statut')) {
            $query->where('statut', $request->statut);
        }

        if ($request->filled('transporteur')) {
            $query->where('transporteur', $request->transporteur);
        }

        if ($request->filled('commande_id')) {
            $query->where('commande_id', $request->commande_id);
        }

        // 🔐 sécurisation succursale_id
        if ($request->filled('succursale_id')) {
            if ($user->isSuperAdmin()) {
                $query->whereHas('commande', function ($q) use ($request) {
                    $q->where('succursale_assignee_id', $request->succursale_id);
                });
            } elseif ($user->isChefAgence()) {
                $query->whereHas('commande', function ($q) use ($user) {
                    $q->where('succursale_assignee_id', $user->succursale_id);
                });
            }
        }

        if ($request->filled('reference')) {
            $query->where('reference', 'LIKE', '%' . $request->reference . '%');
        }

        if ($request->filled('date_debut')) {
            $query->whereDate('created_at', '>=', $request->date_debut);
        }

        if ($request->filled('date_fin')) {
            $query->whereDate('created_at', '<=', $request->date_fin);
        }

        $livraisons = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $livraisons
        ]);

    } catch (\Throwable $e) {
        Log::error('Erreur liste livraisons', [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Erreur: ' . $e->getMessage()
        ], 500);
    }
}

    /**
     * Détails d'une livraison - CORRIGÉ
     */
    public function show($id)
    {
        try {
            $user = auth()->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié.'
                ], 401);
            }

            // ✅ Suppression de 'succursale_id' qui n'existe pas
            $livraison = Livraison::select([
                'id', 'reference', 'commande_id', 'client_id', 'statut',
                'transporteur', 'numero_suivi', 'adresse_livraison',
                'nom_destinataire', 'telephone_destinataire',
                'instructions_livraison', 'livreur_id',
                'date_pris_en_charge', 'date_livraison_estimee', 'date_livraison_reelle',
                'historique_statuts', 'preuve_livraison', 'motif_echec',
                'created_at', 'updated_at'
            ])->with([
                'commande' => function($q) {
                    $q->select('id', 'reference', 'client_id', 'succursale_assignee_id', 
                              'statut', 'statut_logistique', 'total', 'mode_livraison')
                      ->with(['client' => function($cq) {
                          $cq->select('id', 'nom_complet', 'email', 'telephone', 'adresse');
                      }]);
                },
                'commande.succursaleAssignee' => function($q) {
                    $q->select('id', 'nom', 'code', 'adresse', 'telephone');
                },
                'livreur' => function($q) {
                    $q->select('id', 'nom_complet', 'email', 'telephone');
                }
            ])->findOrFail($id);

            if (!$this->canAccessLivraison($user, $livraison)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vous n\'avez pas accès à cette livraison.'
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => $livraison
            ]);

        } catch (\Throwable $e) {
            Log::error('Erreur affichage livraison', [
                'livraison_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 404);
        }
    }

    /**
     * Marquer une livraison comme livrée
     */
    public function marquerLivree(Request $request, $id)
    {
        try {
            $user = auth()->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié.'
                ], 401);
            }

            $request->validate([
                'photo' => 'nullable|string',
                'signature' => 'nullable|string',
                'commentaire' => 'nullable|string'
            ]);

            $livraison = Livraison::with(['commande' => function($q) {
                $q->select('id', 'succursale_assignee_id', 'statut', 'statut_logistique');
            }])->findOrFail($id);

            if (!$this->canAccessLivraison($user, $livraison)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vous ne pouvez pas marquer cette livraison comme livrée.'
                ], 403);
            }

            if (!in_array($livraison->statut, ['PRISE_EN_CHARGE', 'EN_COURS'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Seules les livraisons en cours peuvent être marquées comme livrées. Statut actuel: ' . $livraison->statut
                ], 422);
            }

            $preuve = [];
            if ($request->photo) $preuve['photo'] = $request->photo;
            if ($request->signature) $preuve['signature'] = $request->signature;
            if ($request->commentaire) $preuve['commentaire'] = $request->commentaire;

            $livraison->marquerLivree($preuve);

            $commande = $livraison->commande;
            if ($commande) {
                $commande->statut = 'LIVREE';
                $commande->statut_logistique = 'LIVREE';
                $commande->date_livraison = now();
                $commande->save();
            }

            return response()->json([
                'success' => true,
                'message' => 'Livraison marquée comme livrée',
                'data' => $livraison->fresh()
            ]);

        } catch (\Throwable $e) {
            Log::error('Erreur marquage livrée', [
                'livraison_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Marquer une livraison comme échouée
     */
    public function marquerEchec(Request $request, $id)
    {
        try {
            $user = auth()->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié.'
                ], 401);
            }

            $request->validate([
                'motif' => 'required|string'
            ]);

            $livraison = Livraison::with(['commande' => function($q) {
                $q->select('id', 'succursale_assignee_id');
            }])->findOrFail($id);

            if (!$this->canAccessLivraison($user, $livraison)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vous ne pouvez pas marquer cette livraison comme échouée.'
                ], 403);
            }

            if (in_array($livraison->statut, ['LIVREE', 'ECHOUEE', 'ANNULEE'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cette livraison ne peut pas être marquée comme échouée. Statut actuel: ' . $livraison->statut
                ], 422);
            }

            $livraison->marquerEchec($request->motif);

            return response()->json([
                'success' => true,
                'message' => 'Livraison marquée comme échouée',
                'data' => $livraison->fresh()
            ]);

        } catch (\Throwable $e) {
            Log::error('Erreur marquage échec', [
                'livraison_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }


    /**
     * Historique d'une livraison
     */
    public function historique($id)
    {
        try {
            $user = auth()->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié.'
                ], 401);
            }

            $livraison = Livraison::select([
                'id', 'reference', 'statut', 'date_livraison_estimee', 'historique_statuts'
            ])->with(['commande' => function($q) {
                $q->select('id', 'succursale_assignee_id');
            }])->findOrFail($id);

            if (!$this->canAccessLivraison($user, $livraison)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vous n\'avez pas accès à cette livraison.'
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'livraison_id' => $livraison->id,
                    'reference' => $livraison->reference,
                    'statut' => $livraison->statut,
                    'date_livraison_estimee' => optional(
                        $livraison->date_livraison_estimee
                    )->format('d/m/Y'),
                    'historique' => collect(
                        $livraison->historique_statuts ?? []
                    )->sortBy('date')->values()
                ]
            ]);

        } catch (\Throwable $e) {
            Log::error('Erreur historique livraison', [
                'livraison_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mettre à jour le numéro de suivi
     */
    public function updateNumeroSuivi(Request $request, $id)
    {
        try {
            $user = auth()->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié.'
                ], 401);
            }

            $request->validate([
                'numero_suivi' => 'required|string|max:191',
                'transporteur' => 'nullable|string|max:191'
            ]);

            $livraison = Livraison::with(['commande' => function($q) {
                $q->select('id', 'succursale_assignee_id');
            }])->findOrFail($id);

            if (!$this->canAccessLivraison($user, $livraison)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vous ne pouvez pas modifier cette livraison.'
                ], 403);
            }

            $livraison->numero_suivi = $request->numero_suivi;
            
            if ($request->filled('transporteur')) {
                $livraison->transporteur = $request->transporteur;
            }
            
            $livraison->save();

            $livraison->ajouterHistorique(
                'SUIVI_MIS_A_JOUR',
                'Numéro de suivi: ' . $request->numero_suivi
            );

            return response()->json([
                'success' => true,
                'message' => 'Numéro de suivi mis à jour',
                'data' => $livraison->fresh()
            ]);

        } catch (\Throwable $e) {
            Log::error('Erreur mise à jour numéro suivi', [
                'livraison_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Statistiques des livraisons - OPTIMISÉ
     */
    public function statistiques(Request $request)
    {
        try {
            $user = auth()->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié.'
                ], 401);
            }

            $query = Livraison::query();

            if ($user->isChefAgence() && $user->succursale_id) {
                $query->whereHas('commande', function ($q) use ($user) {
                    $q->where('succursale_assignee_id', $user->succursale_id);
                });
            }

            if ($request->filled('succursale_id') && !$user->isChefAgence()) {
                $query->whereHas('commande', function ($q) use ($request) {
                    $q->where('succursale_assignee_id', $request->succursale_id);
                });
            }

            if ($request->filled('date_debut')) {
                $query->whereDate('created_at', '>=', $request->date_debut);
            }

            if ($request->filled('date_fin')) {
                $query->whereDate('created_at', '<=', $request->date_fin);
            }

            $stats = $query->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN statut = 'EN_ATTENTE' THEN 1 ELSE 0 END) as en_attente,
                SUM(CASE WHEN statut = 'PRISE_EN_CHARGE' THEN 1 ELSE 0 END) as prise_en_charge,
                SUM(CASE WHEN statut = 'EN_COURS' THEN 1 ELSE 0 END) as en_cours,
                SUM(CASE WHEN statut = 'LIVREE' THEN 1 ELSE 0 END) as livrees,
                SUM(CASE WHEN statut = 'ECHOUEE' THEN 1 ELSE 0 END) as echouees,
                SUM(CASE WHEN statut = 'ANNULEE' THEN 1 ELSE 0 END) as annulees
            ")->first();

            $total = (int) $stats->total;
            $livrees = (int) $stats->livrees;

            return response()->json([
                'success' => true,
                'data' => [
                    'total' => $total,
                    'en_attente' => (int) $stats->en_attente,
                    'prise_en_charge' => (int) $stats->prise_en_charge,
                    'en_cours' => (int) $stats->en_cours,
                    'livrees' => $livrees,
                    'echouees' => (int) $stats->echouees,
                    'annulees' => (int) $stats->annulees,
                    'taux_succes' => $total > 0 ? round(($livrees / $total) * 100, 2) : 0,
                ]
            ]);

        } catch (\Throwable $e) {
            Log::error('Erreur statistiques livraisons', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }
}