<?php

namespace App\Http\Controllers\Dsa;

use App\Http\Controllers\Controller;
use App\Models\ProduitEpargne;
use Illuminate\Http\Request;

class ProduitEpargneController extends Controller
{
    // ============================================================
    // LISTE
    // ============================================================
    public function index(Request $r)
    {
        return ProduitEpargne::with('devise')
            ->when($r->actif !== null, fn($q) => $q->where('actif', (bool) $r->actif))
            ->when($r->type,   fn($q) => $q->where('type', $r->type))
            ->when($r->devise_id, fn($q) => $q->where('devise_id', $r->devise_id))
            ->orderBy('nom')
            ->paginate($r->per_page ?? 50);
    }

    // ============================================================
    // CRÉATION
    // ============================================================
    public function store(Request $r)
    {
        $data = $r->validate([
            'code'                 => 'required|string|max:50|unique:produits_epargne,code',
            'nom'                  => 'required|string|max:150',
            'type'                 => 'required|in:CLASSIQUE,SCOLAIRE,PROJET,ENTREPRISE,GROUPE,AUTRE',
            'devise_id'            => 'required|exists:devise,id',
            'solde_minimum'        => 'nullable|numeric|min:0',
            'plafond_maximum'      => 'nullable|numeric|min:0|gt:solde_minimum',
            'montant_min_depot'    => 'nullable|numeric|min:0',
            'montant_min_retrait'  => 'nullable|numeric|min:0',
            'frais_depot_pct'      => 'nullable|numeric|min:0|max:100',
            'frais_retrait_pct'    => 'nullable|numeric|min:0|max:100',
            'commission_dsa_pct'   => 'nullable|numeric|min:0|max:100',
            'periodicite'          => 'nullable|in:JOURNALIER,HEBDOMADAIRE,MENSUEL,LIBRE',
            'duree_min_mois'       => 'nullable|integer|min:0',
            'date_debut_validite'  => 'nullable|date',
            'date_fin_validite'    => 'nullable|date|after_or_equal:date_debut_validite',
            'actif'                => 'boolean',
            'regles_specifiques'   => 'nullable|array',
        ]);

        $produit = ProduitEpargne::create($data);
        $produit->logAudit('CREATE', null, $produit->toArray(), 'Création produit épargne');

        return response()->json($produit->load('devise'), 201);
    }

    // ============================================================
    // AFFICHER
    // ============================================================
    public function show(ProduitEpargne $produit)
    {
        return $produit->load(['devise', 'parametres']);
    }

    // ============================================================
    // MODIFIER
    // ============================================================
    public function update(Request $r, ProduitEpargne $produit)
    {
        $data = $r->validate([
            'nom'                  => 'sometimes|string|max:150',
            'type'                 => 'sometimes|in:CLASSIQUE,SCOLAIRE,PROJET,ENTREPRISE,GROUPE,AUTRE',
            'devise_id'            => 'sometimes|exists:devise,id',
            'solde_minimum'        => 'sometimes|numeric|min:0',
            'plafond_maximum'      => 'nullable|numeric|min:0',
            'montant_min_depot'    => 'sometimes|numeric|min:0',
            'montant_min_retrait'  => 'sometimes|numeric|min:0',
            'frais_depot_pct'      => 'sometimes|numeric|min:0|max:100',
            'frais_retrait_pct'    => 'sometimes|numeric|min:0|max:100',
            'commission_dsa_pct'   => 'sometimes|numeric|min:0|max:100',
            'periodicite'          => 'nullable|in:JOURNALIER,HEBDOMADAIRE,MENSUEL,LIBRE',
            'duree_min_mois'       => 'nullable|integer|min:0',
            'date_debut_validite'  => 'nullable|date',
            'date_fin_validite'    => 'nullable|date',
            'actif'                => 'sometimes|boolean',
            'regles_specifiques'   => 'nullable|array',
        ]);

        $avant = $produit->toArray();
        $produit->update($data);
        $produit->logAudit('UPDATE', $avant, $produit->fresh()->toArray(), 'Modification produit épargne');

        return response()->json($produit->fresh('devise'));
    }

    // ============================================================
    // DÉSACTIVER (soft delete logique)
    // ============================================================
    public function destroy(ProduitEpargne $produit)
    {
        // Refus si des comptes actifs utilisent ce produit
        $comptesActifs = $produit->comptes()->where('statut', 'OUVERT')->count();
        if ($comptesActifs > 0) {
            return response()->json([
                'success' => false,
                'error'   => "Impossible de désactiver : {$comptesActifs} compte(s) actif(s) utilisent ce produit.",
            ], 422);
        }

        $produit->update(['actif' => false]);
        $produit->logAudit('DEACTIVATE', null, ['actif' => false], 'Désactivation produit épargne');

        return response()->json(['message' => 'Produit désactivé avec succès.']);
    }

    // ============================================================
    // RÉACTIVER
    // ============================================================
    public function reactiver(ProduitEpargne $produit)
    {
        $produit->update(['actif' => true]);
        $produit->logAudit('ACTIVATE', null, ['actif' => true], 'Réactivation produit épargne');

        return response()->json($produit->fresh('devise'));
    }
}