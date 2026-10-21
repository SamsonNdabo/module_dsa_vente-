<?php

namespace App\Http\Controllers\Fournisseur;

use App\Http\Controllers\Controller;
use App\Models\ReceptionFournisseur;
use App\Models\LigneReceptionFournisseur;
use App\Models\LigneCommandeFournisseur;
use App\Models\Produit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class LigneReceptionFournisseurController extends Controller
{
    /**
     * Liste des lignes d'une réception
     */
    public function index(Request $request, $receptionId)
    {
        $reception = ReceptionFournisseur::findOrFail($receptionId);
        
        $query = $reception->lignes()->with(['produit', 'commandeLigne']);

        if ($request->filled('recherche')) {
            $search = $request->recherche;
            $query->whereHas('produit', fn($q) => $q->where('nom', 'like', "%{$search}%"));
        }

        $lignes = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $lignes,
            'reception' => [
                'id' => $reception->id,
                'reference' => $reception->reference,
                'statut' => $reception->statut
            ]
        ]);
    }

    /**
     * Ajouter une ligne de réception
     */
    public function store(Request $request, $receptionId)
    {
        $reception = ReceptionFournisseur::findOrFail($receptionId);

        if ($reception->statut !== 'EN_ATTENTE') {
            return response()->json([
                'success' => false,
                'message' => 'Impossible d\'ajouter des lignes à une réception déjà validée ou annulée'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'ligne_commande_id' => 'required|exists:lignes_commandes_fournisseurs,id',
            'quantite_recue' => 'required|integer|min:1',
            'quantite_casse' => 'nullable|integer|min:0',
            'quantite_perdue' => 'nullable|integer|min:0',
            'quantite_non_conforme' => 'nullable|integer|min:0',
            'date_expiration' => 'nullable|date',
            'numero_lot' => 'nullable|string',
            'prix_unitaire_recu' => 'nullable|numeric|min:0',
            'remise' => 'nullable|numeric|min:0|max:100',
            'notes' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $ligneCommande = LigneCommandeFournisseur::findOrFail($request->ligne_commande_id);

        // Vérifier que la ligne de commande appartient à la commande de la réception
        if ($ligneCommande->commande_id != $reception->commande_id) {
            return response()->json([
                'success' => false,
                'message' => 'Cette ligne de commande n\'appartient pas à la commande associée'
            ], 422);
        }

        DB::beginTransaction();

        try {
            $ligne = $reception->ajouterLigne(
                $request->ligne_commande_id,
                $request->quantite_recue,
                [
                    'quantite_casse' => $request->quantite_casse ?? 0,
                    'quantite_perdue' => $request->quantite_perdue ?? 0,
                    'quantite_non_conforme' => $request->quantite_non_conforme ?? 0,
                    'date_expiration' => $request->date_expiration,
                    'numero_lot' => $request->numero_lot,
                    'prix_unitaire_recu' => $request->prix_unitaire_recu ?? $ligneCommande->prix_unitaire_ht,
                    'remise' => $request->remise ?? $ligneCommande->remise ?? 0,
                    'notes' => $request->notes
                ]
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Ligne de réception ajoutée avec succès',
                'data' => $ligne->load(['produit', 'commandeLigne'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Afficher une ligne de réception
     */
    public function show($receptionId, $ligneId)
    {
        $reception = ReceptionFournisseur::findOrFail($receptionId);
        $ligne = $reception->lignes()->with(['produit', 'commandeLigne', 'lot'])->findOrFail($ligneId);

        return response()->json([
            'success' => true,
            'data' => $ligne
        ]);
    }

    /**
     * Mettre à jour une ligne de réception
     */
    public function update(Request $request, $receptionId, $ligneId)
    {
        $reception = ReceptionFournisseur::findOrFail($receptionId);

        if ($reception->statut !== 'EN_ATTENTE') {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de modifier une ligne d\'une réception déjà validée ou annulée'
            ], 422);
        }

        $ligne = $reception->lignes()->findOrFail($ligneId);

        $validator = Validator::make($request->all(), [
            'quantite_recue' => 'sometimes|integer|min:1',
            'quantite_casse' => 'nullable|integer|min:0',
            'quantite_perdue' => 'nullable|integer|min:0',
            'quantite_non_conforme' => 'nullable|integer|min:0',
            'date_expiration' => 'nullable|date',
            'numero_lot' => 'nullable|string',
            'prix_unitaire_recu' => 'nullable|numeric|min:0',
            'remise' => 'nullable|numeric|min:0|max:100',
            'notes' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            $quantiteRecue = $request->quantite_recue ?? $ligne->quantite_recue;
            $quantiteCasse = $request->quantite_casse ?? $ligne->quantite_casse;
            $quantitePerdue = $request->quantite_perdue ?? $ligne->quantite_perdue;
            $quantiteNonConforme = $request->quantite_non_conforme ?? $ligne->quantite_non_conforme;
            $prixUnitaire = $request->prix_unitaire_recu ?? $ligne->prix_unitaire_ht_recu;
            $remise = $request->remise ?? $ligne->remise ?? 0;
            
            // Calcul des valeurs
            $quantiteConforme = $quantiteRecue - ($quantiteCasse + $quantitePerdue + $quantiteNonConforme);
            $prixApresRemise = $prixUnitaire * (1 - $remise / 100);
            $valeurHT = $quantiteConforme * $prixApresRemise;
            $tauxTva = $request->taux_tva ?? $ligne->taux_tva ?? 16;
            $valeurTVA = $valeurHT * ($tauxTva / 100);
            $valeurTTC = $valeurHT + $valeurTVA;
            
            $ligne->update([
                'quantite_recue' => $quantiteRecue,
                'quantite_casse' => $quantiteCasse,
                'quantite_perdue' => $quantitePerdue,
                'quantite_non_conforme' => $quantiteNonConforme,
                'prix_unitaire_ht_recu' => $prixUnitaire,
                'remise' => $remise,
                'date_expiration_lot' => $request->date_expiration ?? $ligne->date_expiration_lot,
                'numero_lot' => $request->numero_lot ?? $ligne->numero_lot,
                'notes' => $request->notes ?? $ligne->notes
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Ligne de réception mise à jour avec succès',
                'data' => $ligne->fresh(['produit', 'commandeLigne'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Supprimer une ligne de réception
     */
    public function destroy($receptionId, $ligneId)
    {
        $reception = ReceptionFournisseur::findOrFail($receptionId);

        if ($reception->statut !== 'EN_ATTENTE') {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer une ligne d\'une réception déjà validée ou annulée'
            ], 422);
        }

        $ligne = $reception->lignes()->findOrFail($ligneId);

        DB::beginTransaction();

        try {
            $ligne->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Ligne de réception supprimée avec succès'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Lignes disponibles pour réception (non encore reçues)
     */
    public function disponibles($receptionId)
    {
        $reception = ReceptionFournisseur::findOrFail($receptionId);
        
        $lignesCommandes = $reception->commande->lignes()
            ->where('quantite', '>', DB::raw('quantite_recue'))
            ->with('produit')
            ->get()
            ->map(function($ligne) {
                return [
                    'id' => $ligne->id,
                    'produit' => [
                        'id' => $ligne->produit->id,
                        'nom' => $ligne->produit->nom,
                        'code_barre' => $ligne->produit->code_barre
                    ],
                    'quantite_commandee' => $ligne->quantite,
                    'quantite_recue' => $ligne->quantite_recue,
                    'quantite_restante' => $ligne->quantite - $ligne->quantite_recue,
                    'prix_unitaire_ht' => $ligne->prix_unitaire_ht,
                    'remise' => $ligne->remise ?? 0,
                    'taux_tva' => $ligne->taux_tva ?? 16
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $lignesCommandes
        ]);
    }
}