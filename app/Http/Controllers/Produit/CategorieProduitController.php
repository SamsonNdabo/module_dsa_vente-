<?php

namespace App\Http\Controllers\Produit;

use App\Http\Controllers\Controller;
use App\Models\CategorieProduit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;

class CategorieProduitController extends Controller
{
    public function __construct()
    {
        // Les permissions sont gérées dans chaque méthode
    }

    /**
     * Liste des catégories - OPTIMISÉ
     * Permissions: view-categories-produits
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        
        if (!$user->hasPermission('view-categories-produits')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour voir les catégories de produits'
            ], 403);
        }
        
        //  Sélection des colonnes nécessaires
        $query = CategorieProduit::select([
            'id', 'code', 'nom', 'description', 'statut',
            'created_at', 'updated_at'
        ])->withCount('produits');

        if ($request->filled('recherche')) {
            $search = $request->recherche;
            $query->where(function($q) use ($search) {
                $q->where('nom', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->has('statut')) {
            $query->where('statut', $request->boolean('statut'));
        }

        $categories = $query->orderBy('nom')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $categories
        ]);
    }

    /**
     * Créer une catégorie
     * Permissions: create-categorie-produit
     */
    public function store(Request $request)
    {
        $user = auth()->user();
        
        if (!$user->hasPermission('create-categorie-produit') && !$user->hasPermission('manage-stock-agence')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour créer des catégories de produits'
            ], 403);
        }
        
        $request->validate([
            'nom' => 'required|string|max:150|unique:categorie_produit,nom',
            'code' => 'nullable|string|max:150|unique:categorie_produit,code',
            'description' => 'nullable|string',
            'statut' => 'boolean'
        ]);

        try {
            $categorie = CategorieProduit::create([
                'code' => $request->code ?? null,
                'nom' => $request->nom,
                'description' => $request->description,
                'statut' => $request->statut ?? true
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Catégorie créée avec succès',
                'data' => $categorie
            ], 201);

        } catch (\Exception $e) {
            Log::error('Erreur création catégorie', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Afficher une catégorie - OPTIMISÉ
     * Permissions: view-categories-produits
     */
    public function show($id)
    {
        $user = auth()->user();
        
        if (!$user->hasPermission('view-categories-produits')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour voir les catégories de produits'
            ], 403);
        }
        
        //  Sélection des colonnes nécessaires
        $categorie = CategorieProduit::select([
            'id', 'code', 'nom', 'description', 'statut',
            'created_at', 'updated_at'
        ])->withCount('produits')->findOrFail($id);

        //  Nombre de produits actifs - une seule requête
        $produitsActifs = $categorie->produits()->where('actif', true)->count();

        return response()->json([
            'success' => true,
            'data' => $categorie,
            'stats' => [
                'nombre_produits' => $categorie->produits_count,
                'produits_actifs' => $produitsActifs
            ]
        ]);
    }

    /**
     * Mettre à jour une catégorie
     * Permissions: edit-categorie-produit
     */
    public function update(Request $request, $id)
    {
        $user = auth()->user();
        
        if (!$user->hasPermission('edit-categorie-produit') && !$user->hasPermission('manage-stock-agence')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour modifier des catégories de produits'
            ], 403);
        }
        
        $categorie = CategorieProduit::findOrFail($id);

        $request->validate([
            'code' => ['nullable', 'string', 'max:150', Rule::unique('categorie_produit', 'code')->ignore($categorie->id)],
            'nom' => ['sometimes', 'string', 'max:150', Rule::unique('categorie_produit', 'nom')->ignore($categorie->id)],
            'description' => 'nullable|string',
            'statut' => 'boolean'
        ]);

        $categorie->update($request->only([
            'code',
            'nom',
            'description',
            'statut'
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Catégorie mise à jour',
            'data' => $categorie->fresh()
        ]);
    }

    /**
     * Supprimer une catégorie
     * Permissions: delete-categorie-produit
     */
    public function destroy($id)
    {
        $user = auth()->user();
        
        if (!$user->hasPermission('delete-categorie-produit') && !$user->hasPermission('manage-stock-agence')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour supprimer des catégories de produits'
            ], 403);
        }
        
        $categorie = CategorieProduit::findOrFail($id);

        if ($categorie->produits()->exists()) {
            return response()->json([
                'success' => false,
                'error' => 'Impossible de supprimer une catégorie avec des produits'
            ], 400);
        }

        $categorie->delete();

        return response()->json([
            'success' => true,
            'message' => 'Catégorie supprimée'
        ]);
    }

    /**
     * Arborescence des catégories - OPTIMISÉ
     * Permissions: view-categories-produits
     */
    public function tree()
    {
        $user = auth()->user();
        
        if (!$user->hasPermission('view-categories-produits')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour voir les catégories de produits'
            ], 403);
        }
        
        //  Sélection des colonnes nécessaires
        $categories = CategorieProduit::select([
            'id', 'code', 'nom', 'statut'
        ])->withCount('produits')
            ->orderBy('nom')
            ->get()
            ->map(fn($cat) => [
                'id' => $cat->id,
                'code' => $cat->code,
                'nom' => $cat->nom,
                'nombre_produits' => $cat->produits_count,
                'statut' => $cat->statut
            ]);

        return response()->json([
            'success' => true,
            'data' => $categories
        ]);
    }

    /**
     * Liste des produits d'une catégorie - OPTIMISÉ
     * Permissions: view-categories-produits
     */
    public function produits($id, Request $request)
    {
        $user = auth()->user();
        
        if (!$user->hasPermission('view-categories-produits') && !$user->hasPermission('view-stock-agence')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour voir les produits de cette catégorie'
            ], 403);
        }
        
        $categorie = CategorieProduit::select(['id', 'nom'])->findOrFail($id);
        
        //  Sélection des colonnes nécessaires
        $query = $categorie->produits()->select([
            'id', 'code_barre', 'nom', 'description', 'marque', 'modele',
            'type', 'prix_vente', 'prix_vente_TTC', 'stock_minimum',
            'est_perissable', 'date_expiration', 'categorie_id', 'devise_id',
            'image_principale', 'actif', 'created_at'
        ])->with([
            'categorie:id,nom',
            'devise:id,code,symbole'
        ]);
        
        // Filtrage par succursale pour view-stock-agence
        if ($user->hasPermission('view-stock-agence') && !$user->hasPermission('manage-stock-agence')) {
            $query->whereHas('stocks', function($q) use ($user) {
                $q->where('succursale_id', $user->succursale_id);
            });
        }
        
        if ($request->filled('recherche')) {
            $search = $request->recherche;
            $query->where(function($q) use ($search) {
                $q->where('nom', 'like', "%{$search}%")
                  ->orWhere('code_barre', 'like', "%{$search}%");
            });
        }
        
        if ($request->boolean('actif')) {
            $query->where('actif', true);
        }
        
        $produits = $query->orderBy('nom')
            ->paginate($request->get('per_page', 15));
        
        return response()->json([
            'success' => true,
            'data' => $produits,
            'categorie' => [
                'id' => $categorie->id,
                'nom' => $categorie->nom
            ]
        ]);
    }

    /**
     * Activer/Désactiver une catégorie
     * Permissions: edit-categorie-produit
     */
    public function toggleStatut($id)
    {
        $user = auth()->user();
        
        if (!$user->hasPermission('edit-categorie-produit') && !$user->hasPermission('manage-stock-agence')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour modifier le statut des catégories'
            ], 403);
        }
        
        $categorie = CategorieProduit::findOrFail($id);
        $categorie->statut = !$categorie->statut;
        $categorie->save();
        
        return response()->json([
            'success' => true,
            'message' => $categorie->statut ? 'Catégorie activée' : 'Catégorie désactivée',
            'data' => [
                'id' => $categorie->id,
                'statut' => $categorie->statut
            ]
        ]);
    }

    /**
     * Statistiques des catégories - OPTIMISÉ
     * Permissions: view-categories-produits
     */
    public function statistiques()
    {
        $user = auth()->user();
        
        if (!$user->hasPermission('view-categories-produits')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour voir les statistiques des catégories'
            ], 403);
        }
        
        //  UNE SEULE REQUÊTE pour les statistiques principales
        $stats = CategorieProduit::selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN statut = 1 THEN 1 ELSE 0 END) as actives,
            SUM(CASE WHEN statut = 0 THEN 1 ELSE 0 END) as inactives
        ")->first();

        //  Catégories avec/sans produits
        $avecProduits = CategorieProduit::has('produits')->count();
        $sansProduits = CategorieProduit::doesntHave('produits')->count();

        //  Top catégories - UNE SEULE requête
        $topCategories = CategorieProduit::select([
            'id', 'nom'
        ])->withCount('produits')
            ->orderBy('produits_count', 'desc')
            ->limit(5)
            ->get()
            ->map(fn($cat) => [
                'id' => $cat->id,
                'nom' => $cat->nom,
                'nombre_produits' => $cat->produits_count
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'total' => (int) $stats->total,
                'actives' => (int) $stats->actives,
                'inactives' => (int) $stats->inactives,
                'avec_produits' => $avecProduits,
                'sans_produits' => $sansProduits,
                'top_categories' => $topCategories
            ]
        ]);
    }
}