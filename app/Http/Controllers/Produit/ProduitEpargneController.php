<?php

namespace App\Http\Controllers\Produit;

use App\Http\Controllers\Controller;
use App\Models\ProduitEpargne;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProduitEpargneController extends Controller
{
    /**
     * Liste des produits d'épargne
     */
    public function index(Request $request)
    {
        $query = ProduitEpargne::with('devise:id,code,symbole');

        // ============================================================
        // FILTRE PAR PARTENAIRE (NOUVEAU)
        // ============================================================
        if ($request->filled('id_partenaire')) {
            $idPartenaire = $request->id_partenaire;
            $query->whereHas('partenaires', function ($q) use ($idPartenaire) {
                $q->where('partenaires.id', $idPartenaire)
                  ->where('partenaire_produits.type_produit', 'EPARGNE')
                  ->where('partenaire_produits.actif', 1);
            });
        }

        if ($request->filled('recherche')) {
            $search = $request->recherche;
            $query->where(function($q) use ($search) {
                $q->where('nom', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('actif')) {
            $query->where('actif', $request->boolean('actif'));
        }

        $produits = $query->orderBy('created_at', 'desc')->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data'    => $produits,
        ]);
    }

    /**
     * Créer un produit d'épargne
     */
    public function store(Request $request)
    {
        $request->validate([
            'code'                 => 'required|string|max:50|unique:produits_epargne,code',
            'nom'                  => 'required|string|max:150',
            'type'                 => 'required|in:CLASSIQUE,SCOLAIRE,PROJET,ENTREPRISE,GROUPE,AUTRE',
            'devise_id'            => 'required|exists:devise,id',
            'solde_minimum'        => 'required|numeric|min:0',
            'plafond_maximum'      => 'nullable|numeric|min:0',
            'montant_min_depot'    => 'required|numeric|min:0',
            'montant_min_retrait'  => 'required|numeric|min:0',
            'frais_depot_pct'      => 'required|numeric|min:0',
            'frais_retrait_pct'    => 'required|numeric|min:0',
            'commission_dsa_pct'   => 'required|numeric|min:0',
            'periodicite'          => 'nullable|in:JOURNALIER,HEBDOMADAIRE,MENSUEL,LIBRE',
            'duree_min_mois'       => 'nullable|integer|min:0',
            'date_debut_validite'  => 'nullable|date',
            'date_fin_validite'    => 'nullable|date|after_or_equal:date_debut_validite',
            'actif'                => 'boolean',
            'regles_specifiques'   => 'nullable|array',
        ]);

        $produit = ProduitEpargne::create([
            'code'                 => $request->code,
            'nom'                  => $request->nom,
            'type'                 => $request->type,
            'devise_id'            => $request->devise_id,
            'solde_minimum'        => $request->solde_minimum,
            'plafond_maximum'      => $request->plafond_maximum,
            'montant_min_depot'    => $request->montant_min_depot,
            'montant_min_retrait'  => $request->montant_min_retrait,
            'frais_depot_pct'      => $request->frais_depot_pct,
            'frais_retrait_pct'    => $request->frais_retrait_pct,
            'commission_dsa_pct'   => $request->commission_dsa_pct,
            'periodicite'          => $request->periodicite,
            'duree_min_mois'       => $request->duree_min_mois,
            'date_debut_validite'  => $request->date_debut_validite,
            'date_fin_validite'    => $request->date_fin_validite,
            'actif'                => $request->boolean('actif', true),
            'regles_specifiques'   => $request->regles_specifiques,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Produit d\'épargne créé avec succès',
            'data'    => $produit->load('devise'),
        ], 201);
    }

    /**
     * Afficher un produit d'épargne
     */
    public function show($id)
    {
        $produit = ProduitEpargne::with(['devise', 'partenaires'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $produit,
        ]);
    }

    /**
     * Mettre à jour un produit d'épargne
     */
    public function update(Request $request, $id)
    {
        $produit = ProduitEpargne::findOrFail($id);

        $request->validate([
            'code'                 => ['sometimes', 'string', 'max:50', Rule::unique('produits_epargne', 'code')->ignore($produit->id)],
            'nom'                  => 'sometimes|string|max:150',
            'type'                 => 'sometimes|in:CLASSIQUE,SCOLAIRE,PROJET,ENTREPRISE,GROUPE,AUTRE',
            'devise_id'            => 'sometimes|exists:devise,id',
            'solde_minimum'        => 'sometimes|numeric|min:0',
            'plafond_maximum'      => 'nullable|numeric|min:0',
            'montant_min_depot'    => 'sometimes|numeric|min:0',
            'montant_min_retrait'  => 'sometimes|numeric|min:0',
            'frais_depot_pct'      => 'sometimes|numeric|min:0',
            'frais_retrait_pct'    => 'sometimes|numeric|min:0',
            'commission_dsa_pct'   => 'sometimes|numeric|min:0',
            'periodicite'          => 'nullable|in:JOURNALIER,HEBDOMADAIRE,MENSUEL,LIBRE',
            'duree_min_mois'       => 'nullable|integer|min:0',
            'date_debut_validite'  => 'nullable|date',
            'date_fin_validite'    => 'nullable|date|after_or_equal:date_debut_validite',
            'actif'                => 'boolean',
            'regles_specifiques'   => 'nullable|array',
        ]);

        $produit->update($request->only([
            'code', 'nom', 'type', 'devise_id', 'solde_minimum', 'plafond_maximum',
            'montant_min_depot', 'montant_min_retrait', 'frais_depot_pct', 'frais_retrait_pct',
            'commission_dsa_pct', 'periodicite', 'duree_min_mois', 'date_debut_validite',
            'date_fin_validite', 'actif', 'regles_specifiques'
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Produit d\'épargne mis à jour',
            'data'    => $produit->fresh('devise'),
        ]);
    }

    /**
     * Supprimer un produit d'épargne
     */
    public function destroy($id)
    {
        $produit = ProduitEpargne::findOrFail($id);

        // Empêcher la suppression si des partenaires sont liés
        if ($produit->partenaires()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer : ce produit est lié à des partenaires.'
            ], 400);
        }

        $produit->delete();

        return response()->json([
            'success' => true,
            'message' => 'Produit d\'épargne supprimé',
        ]);
    }
}