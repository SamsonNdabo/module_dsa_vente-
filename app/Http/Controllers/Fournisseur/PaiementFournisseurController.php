<?php
// app/Http/Controllers/Fournisseur/PaiementFournisseurController.php

namespace App\Http\Controllers\Fournisseur;

use App\Http\Controllers\Controller;
use App\Models\CommandeFournisseur;
use App\Models\PaiementFournisseur;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PaiementFournisseurController extends Controller
{
    /**
     * Liste des paiements
     */
    public function index(Request $request)
    {
        $query = PaiementFournisseur::with(['commande.fournisseur', 'creePar']);

        if ($request->filled('statut')) {
            $query->where('statut', $request->statut);
        }

        if ($request->filled('commande_id')) {
            $query->where('commande_id', $request->commande_id);
        }

        if ($request->filled('fournisseur_id')) {
            $query->whereHas('commande', fn($q) => $q->where('fournisseur_id', $request->fournisseur_id));
        }

        if ($request->filled('date_debut')) {
            $query->whereDate('date_paiement', '>=', $request->date_debut);
        }

        if ($request->filled('date_fin')) {
            $query->whereDate('date_paiement', '<=', $request->date_fin);
        }

        $paiements = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $paiements
        ]);
    }

    /**
     * Enregistrer un paiement pour une commande
     * ✅ Vérifie que la réception a été validée avant paiement
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'commande_id' => 'required|exists:commandes_fournisseurs,id',
            'montant' => 'required|numeric|min:0.01',
            'mode_paiement' => 'required|in:VIREMENT,CHEQUE,ESPECES,MOBILE_MONEY,COMPENSATION',
            'reference_transaction' => 'nullable|string|max:100',
            'notes' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $commande = CommandeFournisseur::findOrFail($request->commande_id);

        // ✅ VÉRIFICATION CRITIQUE DU CAHIER DES CHARGES
        // Étape 6 : Tant que la commande n'est pas validée, le paiement ne peut pas être déclenché
        if (!in_array($commande->statut, ['RECUE', 'RECUE_PARTIELLE', 'VALIDEE'])) {
            return response()->json([
                'success' => false,
                'message' => '⚠️ Cette commande ne peut pas être payée car elle n\'a pas été réceptionnée et validée.',
                'statut_actuel' => $commande->statut,
                'action_requise' => 'La réception doit être validée par le magasinier avant tout paiement',
                'etapes_manquantes' => [
                    '5. Livraison' => $commande->statut === 'ENVOYEE' ? 'En attente de livraison' : 'Effectuée',
                    '6. Vérification' => !in_array($commande->statut, ['RECUE', 'RECUE_PARTIELLE']) ? 'Non validée' : 'Validée'
                ]
            ], 422);
        }

        // Vérifier si la commande est déjà complètement payée
        $totalPaye = PaiementFournisseur::where('commande_id', $commande->id)
            ->where('statut', 'COMPLET')
            ->sum('montant');
        
        $totalCommande = $commande->total_ttc ?? 0;
        
        if ($totalPaye >= $totalCommande) {
            return response()->json([
                'success' => false,
                'message' => 'Cette commande est déjà entièrement payée'
            ], 422);
        }

        $solde = $totalCommande - $totalPaye;
        if ($request->montant > $solde) {
            return response()->json([
                'success' => false,
                'message' => "Le montant (" . number_format($request->montant, 2) . ") dépasse le solde dû (" . number_format($solde, 2) . ")"
            ], 422);
        }

        try {
            $paiement = PaiementFournisseur::enregistrerPaiementPartiel(
                $request->commande_id,
                $request->montant,
                $request->mode_paiement,
                auth()->id()
            );

            if ($request->reference_transaction) {
                $paiement->reference_transaction = $request->reference_transaction;
                $paiement->save();
            }

            if ($request->notes) {
                $paiement->notes = $request->notes;
                $paiement->save();
            }

            return response()->json([
                'success' => true,
                'message' => $paiement->statut === 'COMPLET' 
                    ? '✅ Paiement complété avec succès' 
                    : '💰 Paiement partiel enregistré',
                'data' => [
                    'paiement' => $paiement->load('commande.fournisseur'),
                    'solde_restant' => $paiement->solde,
                    'pourcentage_paye' => round(($paiement->acompte / $totalCommande) * 100, 2),
                    'historique_paiements' => PaiementFournisseur::where('commande_id', $commande->id)
                        ->select('montant', 'mode_paiement', 'date_paiement', 'statut')
                        ->get()
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Afficher un paiement spécifique
     */
    public function show($id)
    {
        $paiement = PaiementFournisseur::with(['commande.fournisseur', 'commande.lignes', 'creePar', 'validePar'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $paiement
        ]);
    }

    /**
     * Historique des paiements d'un fournisseur
     * ✅ Répond à: "Un historique complet des paiements par fournisseur est conservé"
     */
    public function historiqueFournisseur($fournisseurId)
    {
        $historique = PaiementFournisseur::historiqueFournisseur($fournisseurId);
        
        $stats = [
            'total_paye' => $historique->sum('montant'),
            'nombre_paiements' => $historique->count(),
            'dernier_paiement' => $historique->first()?->date_paiement,
            'premier_paiement' => $historique->last()?->date_paiement,
            'par_mode' => $historique->groupBy('mode_paiement')->map(fn($g) => [
                'montant' => $g->sum('montant'),
                'nombre' => $g->count()
            ]),
            'par_mois' => $historique->groupBy(fn($p) => $p->date_paiement->format('Y-m'))
                ->map(fn($g) => $g->sum('montant'))
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'fournisseur_id' => $fournisseurId,
                'historique' => $historique,
                'statistiques' => $stats
            ]
        ]);
    }

    /**
     * Commandes éligibles au paiement (réception validée)
     * ✅ Affiche les commandes qui peuvent être payées
     */
    public function commandesEligibles(Request $request)
    {
        $query = CommandeFournisseur::with(['fournisseur', 'lignes'])
            ->whereIn('statut', ['RECUE', 'RECUE_PARTIELLE', 'VALIDEE'])
            ->whereDoesntHave('paiements', function($q) {
                $q->where('statut', 'COMPLET');
            });

        if ($request->filled('fournisseur_id')) {
            $query->where('fournisseur_id', $request->fournisseur_id);
        }

        $commandes = $query->orderBy('date_commande', 'desc')
            ->paginate($request->get('per_page', 20));

        // Ajouter le solde dû pour chaque commande
        $commandes->getCollection()->transform(function($commande) {
            $totalPaye = $commande->paiements()->sum('montant');
            $commande->total_du = ($commande->total_ttc ?? 0) - $totalPaye;
            $commande->total_paye = $totalPaye;
            $commande->pourcentage_paye = $commande->total_ttc > 0 
                ? round(($totalPaye / $commande->total_ttc) * 100, 2) 
                : 0;
            return $commande;
        });

        return response()->json([
            'success' => true,
            'data' => $commandes,
            'message' => 'Commandes dont la réception a été validée et qui sont éligibles au paiement'
        ]);
    }
}