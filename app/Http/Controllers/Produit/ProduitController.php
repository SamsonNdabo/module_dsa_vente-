<?php
// app/Http/Controllers/Produit/ProduitController.php

namespace App\Http\Controllers\Produit;

use App\Http\Controllers\Controller;
use App\Models\Produit;
use App\Models\ProduitImei;
use App\Models\CategorieProduit;
use App\Models\StockSuccursale;
use App\Models\MouvementStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Picqer\Barcode\BarcodeGeneratorPNG;
use Picqer\Barcode\BarcodeGeneratorHTML;
use Picqer\Barcode\BarcodeGeneratorJPG;
use Picqer\Barcode\BarcodeGeneratorSVG;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class ProduitController extends Controller
{
    public function __construct()
    {
        // Les permissions sont gérées directement dans chaque méthode
    }

    private function typeGereImei($type) { return in_array($type, Produit::TYPES_AVEC_IMEI); }
    private function getTypesDisponibles() { return collect(Produit::TYPES_PRODUIT)->map(fn($t, $k) => ['code' => $k, 'label' => $t['label'], 'gestion_imei' => $t['gestion_imei']])->values(); }

    /**
     * Liste des produits - OPTIMISÉ
     * Permissions: view-stock-agence
     */
    public function index(Request $request)
    {
        $user = auth()->user();
    
        // ✅ Sélection des colonnes nécessaires
        $query = Produit::select([
            'id', 'code_barre', 'nom', 'description', 'marque', 'modele',
            'type', 'prix_vente', 'prix_vente_TTC', 'stock_minimum',
            'est_perissable', 'date_fabrication', 'date_expiration',
            'categorie_id', 'devise_id', 'image_principale',
            'images_gallerie', 'actif', 'created_at', 'updated_at'
        ])->with([
            'categorie:id,nom',
            'devise:id,code,nom,symbole'
        ]);
    
        // Filtrage par succursale
        if ($user->is_admin) {
            // ADMIN voit tout - aucune restriction
            // Ne rien faire
        } elseif ($user->is_chef_agence) {
            // Chef d'agence voit les produits qui ont du stock dans sa succursale
            $query->where(function ($q) use ($user) {
                $q->whereHas('stocks', function ($q2) use ($user) {
                    $q2->where('succursale_id', $user->succursale_id);
                })->orWhereDoesntHave('stocks', function ($q2) use ($user) {
                    $q2->where('succursale_id', $user->succursale_id);
                });
            });
        } else {
            // Autres utilisateurs (gestionnaire stock, etc.) - même restriction
            $query->where(function ($q) use ($user) {
                $q->whereHas('stocks', function ($q2) use ($user) {
                    $q2->where('succursale_id', $user->succursale_id);
                })->orWhereDoesntHave('stocks', function ($q2) use ($user) {
                    $q2->where('succursale_id', $user->succursale_id);
                });
            });
        }
    
        // FILTRES
        if ($request->filled('categorie_id')) {
            $query->where('categorie_id', $request->categorie_id);
        }
    
        if ($request->filled('categorie_nom')) {
            $query->whereHas('categorie', function($q) use ($request) {
                $q->where('nom', 'like', "%{$request->categorie_nom}%");
            });
        }
    
        if ($request->filled('categories_ids') && is_array($request->categories_ids)) {
            $query->whereIn('categorie_id', $request->categories_ids);
        }
    
        if ($request->filled('recherche')) {
            $search = $request->recherche;
            $query->where(function($q) use ($search) {
                $q->where('nom', 'like', "%{$search}%")
                  ->orWhere('code_barre', 'like', "%{$search}%")
                  ->orWhere('marque', 'like', "%{$search}%")
                  ->orWhere('modele', 'like', "%{$search}%");
            });
        }
    
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
    
        if ($request->filled('actif')) {
            $query->where('actif', $request->boolean('actif'));
        }
    
        if ($request->boolean('en_alerte')) {
            $query->whereHas('stocks', function ($q) {
                $q->whereRaw('quantite_disponible <= quantite_minimale');
            });
        }
    
        if ($request->boolean('rupture')) {
            $query->whereHas('stocks', function ($q) {
                $q->where('quantite_disponible', '<=', 0);
            });
        }
    
        if ($request->boolean('perissable')) {
            $query->where('est_perissable', true)
                  ->whereNotNull('date_expiration')
                  ->where('date_expiration', '<=', now()->addDays(30));
        }
    
        if ($request->boolean('avec_imei')) {
            $query->whereIn('type', Produit::TYPES_AVEC_IMEI);
        }
    
        // TRI
        $orderBy = $request->get('order_by', 'created_at');
        $orderDir = $request->get('order_dir', 'desc');
        $query->orderBy($orderBy, $orderDir);
    
        // PAGINATION
        $produits = $query->paginate($request->get('per_page', 15));
    
        // ENRICHISSEMENT DATA
        foreach ($produits as $produit) {
            $produit->stock_total = $produit->stockTotal;
            $produit->est_en_alerte = $produit->estEnAlerte;
            $produit->est_en_rupture = $produit->estEnRupture;
            $produit->gere_imei = $this->typeGereImei($produit->type);
    
            if ($produit->gere_imei) {
                $produit->imei_count = $produit->imeis()->count();
                $produit->imei_disponibles = $produit->imeis()->disponibles()->count();
                $produit->imei_vendus = $produit->imeis()->vendus()->count();
            }
        }
    
        // ✅ STATS - Optimisées avec sécurité (colonnes existantes)
        $stats = $this->getProduitStats();
    
        return response()->json([
            'success' => true,
            'data' => $produits,
            'stats' => $stats,
            'types_disponibles' => $this->getTypesDisponibles(),
            'categories' => CategorieProduit::select('id', 'nom')->orderBy('nom')->get()
        ]);
    }

    /**
     * Créer un produit
     * Permissions: create-stock
     */
    public function store(Request $request)
    {
        $user = auth()->user();
        
        if (!$user->hasPermission('create-stock') && !$user->hasPermission('manage-stock-agence')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour créer des produits'
            ], 403);
        }
        
        // Les produits alimentaires sont toujours périssables
        if ($request->type === 'ALIMENTAIRE') {
            $request->merge([
                'est_perissable' => true
            ]);
        }
        // $deviseUSD = Devise::find(2);
        // if (!$deviseUSD) {
        //     // ⚠️ Ne pas jeter d'exception ici, juste un log
        //     Log::warning('Devise USD non trouvée (id: 2)', [
        //         'user_id' => $user->id,
        //         'action' => 'creation_produit'
        //     ]);
        // }
                
        $request->validate([
            'code_barre' => 'required|string|max:100|unique:produit,code_barre',
            'nom' => 'required|string|max:150',
            'description' => 'nullable|string',
            'marque' => 'nullable|string|max:150',
            'modele' => 'nullable|string|max:150',
            'type' => 'required|string|in:' . implode(',', array_keys(Produit::TYPES_PRODUIT)),
            'prix_vente' => 'required|numeric|min:0',
            'stock_minimum' => 'required|integer|min:0',
            'est_perissable' => 'boolean',
            'date_fabrication' => [
                'nullable',
                'date',
                Rule::requiredIf(function () use ($request) {
                    return $request->est_perissable || $request->type === 'ALIMENTAIRE';
                }),
            ],
            
            'date_expiration' => [
                'nullable',
                'date',
                'after:date_fabrication',
                Rule::requiredIf(function () use ($request) {
                    return $request->est_perissable || $request->type === 'ALIMENTAIRE';
                }),
            ],
            'categorie_id' => 'required|exists:categorie_produit,id',
            'devise_id' => 'required|exists:devise,id',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048'
        ]);

        DB::beginTransaction();

        try {
            $tauxTVA = 16;
            $prixVenteTTC = $request->prix_vente * (1 + $tauxTVA / 100);

            $produit = Produit::create([
                'code_barre' => $request->code_barre,
                'nom' => $request->nom,
                'description' => $request->description,
                'marque' => $request->marque,
                'modele' => $request->modele,
                'type' => $request->type,
                'prix_vente' => $request->prix_vente,
                'prix_vente_TTC' => $prixVenteTTC,
                'stock_minimum' => $request->stock_minimum,
                'est_perissable' => $request->type === 'ALIMENTAIRE'
                ? true
                : ($request->est_perissable ?? false),
                'date_fabrication' => $request->date_fabrication,
                'date_expiration' => $request->date_expiration,
                'categorie_id' => $request->categorie_id,
                'devise_id' => $request->devise_id,
                'actif' => true
            ]);

            if ($request->hasFile('image')) {
                $file = $request->file('image');
                $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $path = 'produits/' . $produit->id . '/' . $filename;
                
                Storage::disk('public')->put($path, file_get_contents($file));
                
                $produit->image_principale = $path;
                $produit->save();
            }

            $produit->load(['categorie', 'devise']);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Produit créé avec succès',
                'data' => $produit,
                'type_info' => Produit::TYPES_PRODUIT[$produit->type]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur création produit', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            return response()->json(['error' => 'Erreur lors de la création: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Afficher un produit
     * Permissions: view-stock-agence
     */
    public function show($id)
    {
        $user = auth()->user();
        
        if (!$user->hasPermission('view-stock-agence') && !$user->hasPermission('view-all-stock')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour voir les produits'
            ], 403);
        }
        
        $produit = Produit::with([
            'categorie',
            'devise',
            'stocks.succursale',
            'imeis' => fn($q) => $q->with(['client', 'facture', 'succursale'])->latest()->limit(20)
        ])->findOrFail($id);

        if ($user->hasPermission('view-stock-agence') && !$user->hasPermission('manage-stock-agence')) {
            if ($produit->stocks) {
                $produit->stocks = $produit->stocks->filter(fn($stock) => $stock->succursale_id == $user->succursale_id)->values();
            }
            if ($produit->imeis) {
                $produit->imeis = $produit->imeis->filter(fn($imei) => $imei->succursale_id == $user->succursale_id)->values();
            }
        }

        $produit->type_info = Produit::TYPES_PRODUIT[$produit->type] ?? null;
        $produit->gere_imei = $this->typeGereImei($produit->type);

        $stats = [
            'stock_total' => $produit->stock_total,
            'stock_par_succursale' => $produit->stocks->map(fn($s) => [
                'succursale' => $s->succursale?->nom,
                'quantite' => $s->quantite_disponible,
                'reserve' => $s->quantite_reserve,
                'emplacement' => $s->emplacement
            ])
        ];

        if ($produit->gere_imei) {
            $stats['imei'] = [
                'total' => $produit->imeis()->count(),
                'disponibles' => $produit->imeis()->disponibles()->count(),
                'vendus' => $produit->imeis()->vendus()->count(),
                'reserves' => $produit->imeis()->where('statut', 'RESERVE')->count(),
                'sous_garantie' => $produit->imeis()->sousGarantie()->count(),
                'garantie_expiree' => $produit->imeis()->garantieExpiree()->count(),
                'par_succursale' => $produit->imeis()
                    ->select('succursale_id', DB::raw('count(*) as total'))
                    ->with('succursale:id,nom')
                    ->groupBy('succursale_id')
                    ->get()
                    ->map(fn($i) => ['succursale' => $i->succursale?->nom, 'total' => $i->total]),
                'derniers_imei' => $produit->imeis()
                    ->with('succursale')->latest()->limit(10)->get()
                    ->map(fn($i) => [
                        'id' => $i->id,
                        'numero' => $i->numero_imei,
                        'statut' => $i->libelle_statut,
                        'statut_code' => $i->statut,
                        'succursale' => $i->succursale?->nom,
                        'garantie' => $i->est_sous_garantie,
                        'jours_garantie' => $i->jours_restants_garantie,
                        'date_entree' => $i->date_entree?->format('d/m/Y'),
                        'date_vente' => $i->date_achat?->format('d/m/Y'),
                        'client' => $i->client?->nom_complet
                    ])
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $produit,
            'stats' => $stats,
            'indicateurs' => [
                'taux_marque' => $produit->taux_marque ?? 0,
                'est_en_alerte' => $produit->est_en_alerte,
                'est_en_rupture' => $produit->est_en_rupture,
                'gere_imei' => $produit->gere_imei
            ]
        ]);
    }

    /**
     * Mettre à jour un produit
     * Permissions: edit-stock
     */
    public function update(Request $request, $id)
    {
        $user = auth()->user();
        
        if (!$user->hasPermission('edit-stock') && !$user->hasPermission('manage-stock-agence')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour modifier des produits'
            ], 403);
        }
        
        $produit = Produit::findOrFail($id);
        if ($request->type === 'ALIMENTAIRE') {
            $request->merge([
                'est_perissable' => true
            ]);
        }

        $request->validate([
            'code_barre' => ['sometimes', 'string', 'max:100', Rule::unique('produit')->ignore($produit->id)],
            'nom' => 'sometimes|string|max:150',
            'description' => 'nullable|string',
            'marque' => 'nullable|string|max:150',
            'modele' => 'nullable|string|max:150',
            'type' => 'sometimes|string|in:' . implode(',', array_keys(Produit::TYPES_PRODUIT)),
            'prix_vente' => 'sometimes|numeric|min:0',
            'stock_minimum' => 'sometimes|integer|min:0',
            'est_perissable' => 'boolean',
            'date_fabrication' => [
                'nullable',
                'date',
                Rule::requiredIf(function () use ($request, $produit) {
                    $type = $request->type ?? $produit->type;
                    $perissable = $request->has('est_perissable')
                        ? $request->boolean('est_perissable')
                        : $produit->est_perissable;
            
                    return $type === 'ALIMENTAIRE' || $perissable;
                }),
            ],
            
            'date_expiration' => [
                'nullable',
                'date',
                'after:date_fabrication',
                Rule::requiredIf(function () use ($request, $produit) {
                    $type = $request->type ?? $produit->type;
                    $perissable = $request->has('est_perissable')
                        ? $request->boolean('est_perissable')
                        : $produit->est_perissable;
            
                    return $type === 'ALIMENTAIRE' || $perissable;
                }),
            ],
            'date_fabrication' => 'nullable|date',
            'date_expiration' => 'nullable|date|after:date_fabrication',
            'categorie_id' => 'sometimes|exists:categorie_produit,id',
            'devise_id' => 'sometimes|exists:devise,id',
            'actif' => 'boolean',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'supprimer_image' => 'nullable|boolean'
        ]);

        DB::beginTransaction();

        try {
            $data = $request->only([
                'code_barre', 'nom', 'description', 'marque', 'modele', 'type',
                'prix_vente', 'stock_minimum', 'est_perissable', 'date_fabrication', 
                'date_expiration', 'categorie_id', 'devise_id', 'actif'
            ]);

            if ($request->has('prix_vente')) {
                $tauxTVA = 16;
                $data['prix_vente_TTC'] = $request->prix_vente * (1 + $tauxTVA / 100);
            }
            
            if (($data['type'] ?? $produit->type) === 'ALIMENTAIRE') {
                $data['est_perissable'] = true;
            }

            $produit->update($data);

            if ($request->boolean('supprimer_image')) {
                if ($produit->image_principale) {
                    $produit->deleteImageFile($produit->image_principale);
                    $produit->image_principale = null;
                    $produit->save();
                }
            }

            if ($request->hasFile('image')) {
                if ($produit->image_principale) {
                    $produit->deleteImageFile($produit->image_principale);
                }
                $produit->uploadImage($request->file('image'), 'principale');
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Produit mis à jour',
                'data' => $produit->fresh(['categorie', 'devise'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur mise à jour produit', [
                'produit_id' => $id,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Supprimer un produit
     * Permissions: delete-stock
     */
    public function destroy($id)
    {
        $user = auth()->user();
        
        if (!$user->hasPermission('delete-stock') && !$user->hasPermission('manage-stock-agence')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour supprimer des produits'
            ], 403);
        }
        
        $produit = Produit::findOrFail($id);

        if ($produit->imeis()->exists()) {
            return response()->json(['error' => 'Impossible de supprimer un produit avec des IMEI'], 400);
        }

        if ($produit->stocks()->where('quantite_disponible', '>', 0)->exists()) {
            return response()->json(['error' => 'Impossible de supprimer un produit avec du stock'], 400);
        }

        $produit->delete();

        return response()->json(['success' => true, 'message' => 'Produit supprimé']);
    }

    /**
     * Suppression multiple
     * Permissions: delete-stock
     */
    public function bulkDestroy(Request $request)
    {
        $user = auth()->user();
        
        if (!$user->hasPermission('delete-stock') && !$user->hasPermission('manage-stock-agence')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour supprimer des produits'
            ], 403);
        }
        
        $request->validate(['ids' => 'required|array', 'ids.*' => 'exists:produit,id']);

        $count = 0;
        $errors = [];

        foreach ($request->ids as $id) {
            $produit = Produit::find($id);
            if (!$produit) continue;

            if ($produit->lignesFacture()->exists()) {
                $errors[] = "Produit {$produit->nom} a des ventes";
                continue;
            }
            if ($produit->imeis()->exists()) {
                $errors[] = "Produit {$produit->nom} a des IMEI";
                continue;
            }
            if ($produit->stocks()->where('quantite_disponible', '>', 0)->exists()) {
                $errors[] = "Produit {$produit->nom} a du stock";
                continue;
            }

            $produit->delete();
            $count++;
        }

        return response()->json([
            'success' => true,
            'message' => "{$count} produit(s) supprimé(s)",
            'errors' => $errors
        ]);
    }

    /**

     * Valider un code-barre

     * Permissions: scan-barcode

     */

    public function validerCodeBarre(Request $request)

    {

        $user = auth()->user();

        

        if (!$user->hasPermission('scan-barcode') && !$user->hasPermission('view-stock-agence')) {

            return response()->json([

                'success' => false,

                'message' => 'Vous n\'avez pas les droits pour scanner des codes-barres'

            ], 403);

        }

        

        $request->validate(['code_barre' => 'required|string']);

        $produit = Produit::where('code_barre', $request->code_barre)->first();



        if ($produit) {

            return response()->json([

                'success' => true,

                'valid' => true,

                'produit' => [

                    'id' => $produit->id,

                    'nom' => $produit->nom,

                    'prix' => $produit->prix_vente,

                    'prix_ttc' => $produit->prix_vente_TTC,

                    'stock' => $produit->stock_total,

                    'type' => $produit->type,

                    'gere_imei' => $this->typeGereImei($produit->type)

                ]

            ]);

        }



        return response()->json(['success' => true, 'valid' => false, 'message' => 'Code-barre non trouvé']);

    }



    /**

     * Générer un code-barre

     * Permissions: generate-barcode

     */

    public function genererCodeBarre($id, Request $request)

    {

        $user = auth()->user();

        

        if (!$user->hasPermission('generate-barcode') && !$user->hasPermission('manage-stock-agence')) {

            return response()->json([

                'success' => false,

                'message' => 'Vous n\'avez pas les droits pour générer des codes-barres'

            ], 403);

        }

        

        $produit = Produit::findOrFail($id);

        $type = $request->get('type', 'png');

        $width = $request->get('width', 2);

        $height = $request->get('height', 50);



        try {

            $generator = match($type) {

                'png' => new BarcodeGeneratorPNG(),

                'jpg' => new BarcodeGeneratorJPG(),

                'svg' => new BarcodeGeneratorSVG(),

                'html' => new BarcodeGeneratorHTML(),

                default => throw new \Exception('Type non supporté')

            };



            $barcode = $generator->getBarcode($produit->code_barre, $generator::TYPE_CODE_128, $width, $height);

            

            $contentType = match($type) {

                'png' => 'image/png',

                'jpg' => 'image/jpeg',

                'svg' => 'image/svg+xml',

                'html' => 'text/html',

                default => 'application/octet-stream'

            };



            return response($barcode)->header('Content-Type', $contentType)

                ->header('Content-Disposition', 'inline; filename="barcode_' . $produit->code_barre . '.' . $type . '"');



        } catch (\Exception $e) {

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);

        }

    }



    /**

     * Uploader une image (endpoint dédié)

     * Permissions: edit-stock

     */

    public function uploadImage(Request $request, $id)

    {

        $user = auth()->user();

        

        if (!$user->hasPermission('edit-stock') && !$user->hasPermission('manage-stock-agence')) {

            return response()->json([

                'success' => false,

                'message' => 'Vous n\'avez pas les droits pour uploader des images'

            ], 403);

        }

        

        $produit = Produit::findOrFail($id);



        $request->validate([

            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:2048',

            'type' => 'sometimes|in:principale,gallerie'

        ]);



        try {

            $type = $request->get('type', 'principale');

            

            if ($type === 'principale') {

                // Supprimer l'ancienne image principale si elle existe

                if ($produit->image_principale) {

                    $produit->deleteImageFile($produit->image_principale);

                }

                

                $produit->uploadImage($request->file('image'), 'principale');

                

                return response()->json([

                    'success' => true,

                    'message' => 'Image principale uploadée avec succès',

                    'data' => [

                        'image_url' => $produit->image_url,

                        'thumbnail_url' => $produit->image_thumbnail_url

                    ]

                ]);

            } else {

                // Ajouter à la galerie

                $produit->addToGallery($request->file('image'));

                

                return response()->json([

                    'success' => true,

                    'message' => 'Image ajoutée à la galerie',

                    'data' => [

                        'gallerie' => $produit->gallerie_urls,

                        'total_images' => count($produit->images_gallerie ?? [])

                    ]

                ]);

            }



        } catch (\Exception $e) {

            return response()->json([

                'success' => false,

                'message' => 'Erreur lors de l\'upload: ' . $e->getMessage()

            ], 500);

        }

    }



    /**

     * Récupérer toutes les images d'un produit

     * Permissions: view-stock-agence

     */

    public function getImages($id)

    {

        $user = auth()->user();

        

        if (!$user->hasPermission('view-stock-agence') && !$user->hasPermission('view-all-stock')) {

            return response()->json([

                'success' => false,

                'message' => 'Vous n\'avez pas les droits pour voir les images'

            ], 403);

        }

        

        $produit = Produit::findOrFail($id);



        return response()->json([

            'success' => true,

            'data' => [

                'image_principale' => [

                    'url' => $produit->image_url,

                    'thumbnail' => $produit->image_thumbnail_url

                ],

                'gallerie' => $produit->gallerie_urls,

                'total' => [

                    'principale' => $produit->image_principale ? 1 : 0,

                    'gallerie' => count($produit->images_gallerie ?? [])

                ]

            ]

        ]);

    }



    /**

     * Supprimer une image

     * Permissions: edit-stock

     */

    public function deleteImage(Request $request, $id)

    {

        $user = auth()->user();

        

        if (!$user->hasPermission('edit-stock') && !$user->hasPermission('manage-stock-agence')) {

            return response()->json([

                'success' => false,

                'message' => 'Vous n\'avez pas les droits pour supprimer des images'

            ], 403);

        }

        

        $produit = Produit::findOrFail($id);



        $request->validate([

            'image_path' => 'required|string',

            'type' => 'required|in:principale,gallerie'

        ]);



        try {

            $type = $request->type;

            $imagePath = $request->image_path;



            if ($type === 'principale') {

                if ($produit->image_principale !== $imagePath) {

                    return response()->json([

                        'success' => false,

                        'message' => 'Cette image n\'est pas l\'image principale'

                    ], 400);

                }

                

                $produit->deleteImageFile($imagePath);

                $produit->image_principale = null;

                $produit->save();

                

                return response()->json([

                    'success' => true,

                    'message' => 'Image principale supprimée avec succès'

                ]);

            }

            

            if ($type === 'gallerie') {

                $produit->removeFromGallery($imagePath);

                

                return response()->json([

                    'success' => true,

                    'message' => 'Image supprimée de la galerie',

                    'data' => [

                        'gallerie' => $produit->gallerie_urls,

                        'total_images' => count($produit->images_gallerie ?? [])

                    ]

                ]);

            }

            

            return response()->json([

                'success' => false,

                'message' => 'Type d\'image invalide'

            ], 400);



        } catch (\Exception $e) {

            return response()->json([

                'success' => false,

                'message' => 'Erreur lors de la suppression: ' . $e->getMessage()

            ], 500);

        }

    }



    /**

     * Générer une étiquette produit

     * Permissions: print-barcodes

     */

    public function genererEtiquette($id, Request $request)

    {

        $user = auth()->user();

        

        if (!$user->hasPermission('print-barcodes') && !$user->hasPermission('manage-stock-agence')) {

            return response()->json([

                'success' => false,

                'message' => 'Vous n\'avez pas les droits pour générer des étiquettes'

            ], 403);

        }

        

        $produit = Produit::with('devise')->findOrFail($id); 



        $width = $request->get('width', 400);

        $height = $request->get('height', 200);



        try {

            $image = imagecreate($width, $height);

            

            $blanc = imagecolorallocate($image, 255, 255, 255);

            $noir = imagecolorallocate($image, 0, 0, 0);

            $gris = imagecolorallocate($image, 200, 200, 200);

            $bleu = imagecolorallocate($image, 0, 102, 204);

            

            imagefill($image, 0, 0, $blanc);

            

            $generator = new BarcodeGeneratorPNG();

            $barcode = $generator->getBarcode($produit->code_barre, $generator::TYPE_CODE_128, 2, 50);

            $barcodeImage = imagecreatefromstring($barcode);

            imagecopy($image, $barcodeImage, 10, 10, 0, 0, imagesx($barcodeImage), imagesy($barcodeImage));

            

            $font = 5;

            $nom = $produit->nom;

            if (strlen($nom) > 30) {

                $nom = substr($nom, 0, 27) . '...';

            }

            

            $deviseSymbole = $produit->devise ? $produit->devise->symbole : 'FC';

            

            imagestring($image, $font, 10, 70, $nom, $noir);

            imagestring($image, $font, 10, 90, 'Prix: ' . number_format($produit->prix_vente, 2) . ' ' . $deviseSymbole, $noir);

            imagestring($image, $font, 10, 110, 'Code: ' . $produit->code_barre, $gris);

            imagestring($image, $font, 10, 130, 'TTC: ' . number_format($produit->prix_vente_TTC, 2) . ' ' . $deviseSymbole, $bleu);

            

            if ($produit->est_perissable && $produit->date_expiration) {

                $expiration = 'Expire: ' . $produit->date_expiration->format('d/m/Y');

                imagestring($image, $font, 10, 150, $expiration, $noir);

            }

            

            imagedestroy($barcodeImage);

            

            ob_start();

            imagepng($image);

            $imageData = ob_get_clean();

            imagedestroy($image);

            

            return response($imageData)

                ->header('Content-Type', 'image/png')

                ->header('Content-Disposition', 'inline; filename="etiquette_' . $produit->code_barre . '.png"');



        } catch (\Exception $e) {

            return response()->json([

                'success' => false,

                'error' => 'Erreur lors de la génération de l\'étiquette: ' . $e->getMessage()

            ], 500);

        }

    }



    /**

     * Générer un code-barre unique

     * Permissions: generate-barcode

     */

    public function genererCodeBarreUnique()

    {

        $user = auth()->user();

        

        if (!$user->hasPermission('generate-barcode') && !$user->hasPermission('manage-stock-agence')) {

            return response()->json([

                'success' => false,

                'message' => 'Vous n\'avez pas les droits pour générer des codes-barres'

            ], 403);

        }

        

        $prefix = 'PROD';

        $timestamp = now()->format('ymd');

        $random = str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        

        $code = $prefix . $timestamp . $random;

        

        $counter = 1;

        while (Produit::where('code_barre', $code)->exists()) {

            $code = $prefix . $timestamp . $random . $counter;

            $counter++;

        }

        

        return response()->json([

            'success' => true,

            'data' => [

                'code_barre' => $code,

                'format' => 'PROD[YYMMDD][XXXX]'

            ]

        ]);

    }



    /**

     * Imprimer plusieurs codes-barres

     * Permissions: print-barcodes

     */

    public function imprimerCodesBarres(Request $request)

    {

        $user = auth()->user();

        

        if (!$user->hasPermission('print-barcodes') && !$user->hasPermission('manage-stock-agence')) {

            return response()->json([

                'success' => false,

                'message' => 'Vous n\'avez pas les droits pour imprimer des codes-barres'

            ], 403);

        }

        

        $request->validate([

            'produits' => 'required|array',

            'produits.*.id' => 'required|exists:produit,id',

            'produits.*.quantite' => 'integer|min:1|max:10'

        ]);



        try {

            $html = '<html><head><style>';

            $html .= 'body { font-family: Arial; padding: 20px; }';

            $html .= '.label { border: 1px dashed #ccc; padding: 15px; margin: 10px; display: inline-block; text-align: center; width: 250px; }';

            $html .= '.barcode { margin: 10px 0; }';

            $html .= '.product-name { font-size: 14px; font-weight: bold; margin: 5px 0; }';

            $html .= '.product-price { font-size: 16px; color: #000; font-weight: bold; }';

            $html .= '.product-code { font-size: 10px; color: #666; margin-top: 5px; }';

            $html .= '@media print { .no-print { display: none; } }';

            $html .= '</style></head><body>';

            

            $html .= '<div class="no-print" style="margin-bottom: 20px;">';

            $html .= '<button onclick="window.print()" style="padding: 10px 20px; margin-right: 10px;">Imprimer</button> ';

            $html .= '<button onclick="window.close()" style="padding: 10px 20px;">Fermer</button>';

            $html .= '</div>';



            $generator = new BarcodeGeneratorHTML();



            foreach ($request->produits as $item) {

                $produit = Produit::find($item['id']);

                $quantite = $item['quantite'] ?? 1;



                for ($i = 0; $i < $quantite; $i++) {

                    $barcode = $generator->getBarcode($produit->code_barre, $generator::TYPE_CODE_128, 2, 50);

                    

                    $html .= '<div class="label">';

                    $html .= '<div class="product-name">' . e($produit->nom) . '</div>';

                    $html .= '<div class="barcode">' . $barcode . '</div>';

                    $html .= '<div class="product-price">' . number_format($produit->prix_vente, 2) . ' FCFA</div>';

                    $html .= '<div class="product-code">' . e($produit->code_barre) . '</div>';

                    

                    if ($produit->est_perissable && $produit->date_expiration) {

                        $html .= '<div class="expiration">Exp: ' . $produit->date_expiration->format('d/m/Y') . '</div>';

                    }

                    

                    $html .= '</div>';

                }

            }



            $html .= '</body></html>';



            return response($html)->header('Content-Type', 'text/html');



        } catch (\Exception $e) {

            return response()->json([

                'success' => false,

                'error' => 'Erreur: ' . $e->getMessage()

            ], 500);

        }

    }





    /**

     * Liste des produits (PUBLIC)

     */

    public function indexPublic(Request $request)

{

    $query = Produit::query()

        ->with([

            'categorie:id,nom',

            'devise:id,code,symbole'

        ])

        ->where('actif', true);





    if ($request->filled('categorie_id')) {

        $query->where('categorie_id', $request->categorie_id);

    }





    if ($request->filled('type')) {

        $query->where('type', $request->type);

    }



    if ($request->filled('marque')) {

        $query->where('marque', $request->marque);

    }





    if ($request->filled('prix_min')) {

        $query->where('prix_vente', '>=', $request->prix_min);

    }





    if ($request->filled('prix_max')) {

        $query->where('prix_vente', '<=', $request->prix_max);

    }





    if ($request->filled('recherche')) {



        $search = trim($request->recherche);



        $query->where(function ($q) use ($search) {



            $q->where('nom', 'LIKE', "%{$search}%")

                ->orWhere('code_barre', 'LIKE', "%{$search}%")

                ->orWhere('marque', 'LIKE', "%{$search}%")

                ->orWhere('modele', 'LIKE', "%{$search}%")

                ->orWhere('description', 'LIKE', "%{$search}%");

        });

    }



    if ($request->has('en_stock')) {



        if ($request->boolean('en_stock')) {



            $query->whereHas('stocks', function ($q) {

                $q->where('quantite_disponible', '>', 0);

            });



        } else {



            $query->whereDoesntHave('stocks', function ($q) {

                $q->where('quantite_disponible', '>', 0);

            });

        }

    }



    /**

     * ==========================================

     * TRI

     * ==========================================

     */



    $sortBy = $request->get('sort_by', 'created_at');

    $sortOrder = strtolower($request->get('sort_order', 'desc'));



    $allowedSorts = [

        'created_at',

        'nom',

        'prix_vente'

    ];



    if (!in_array($sortBy, $allowedSorts)) {

        $sortBy = 'created_at';

    }



    if (!in_array($sortOrder, ['asc', 'desc'])) {

        $sortOrder = 'desc';

    }



    $query->orderBy($sortBy, $sortOrder);



    /**

     * ==========================================

     * PAGINATION

     * ==========================================

     */



    $perPage = min(

        max((int) $request->get('per_page', 20), 1),

        100

    );



    $produits = $query->paginate($perPage);



    /**

     * ==========================================

     * TRANSFORMATION

     * ==========================================

     */



    $produits->getCollection()->transform(function ($p) {



        return [

            'id' => $p->id,



            'nom' => $p->nom,



            'description' => $p->description,



            'code_barre' => $p->code_barre,



            'marque' => $p->marque,



            'modele' => $p->modele,



            'prix_vente' => $p->prix_vente,



            'prix_vente_TTC' => $p->prix_vente_TTC,



            'type' => $p->type,



            'type_label' => $p->type_label,



            'image' => [

                'url' => $p->image_url,

                'thumbnail' => $p->image_thumbnail_url,

                'path' => $p->image_principale

            ],



            'devise' => $p->devise ? [

                'id' => $p->devise->id,

                'code' => $p->devise->code,

                'symbole' => $p->devise->symbole

            ] : null,



            'categorie' => $p->categorie ? [

                'id' => $p->categorie->id,

                'nom' => $p->categorie->nom

            ] : null,

        ];

    });



    return response()->json([

        'success' => true,



        'count' => $produits->total(),



        'pagination' => [

            'current_page' => $produits->currentPage(),

            'last_page' => $produits->lastPage(),

            'per_page' => $produits->perPage(),

            'total' => $produits->total(),

        ],



        'filters' => [

            'categorie_id' => $request->categorie_id,

            'type' => $request->type,

            'marque' => $request->marque,

            'prix_min' => $request->prix_min,

            'prix_max' => $request->prix_max,

            'en_stock' => $request->has('en_stock')

                ? $request->boolean('en_stock')

                : null,

            'recherche' => $request->recherche,

            'sort_by' => $sortBy,

            'sort_order' => $sortOrder,

        ],



        'data' => $produits->items()

    ]);

}

    

    /**

     * Détails produit (PUBLIC)

     */

    public function showPublic($id)

    {

        $produit = Produit::query()

            ->with([

                'categorie:id,nom',

                'devise:id,code,symbole'

            ])

            ->where('actif', true)

            ->find($id);

    

        if (!$produit) {

    

            return response()->json([

                'success' => false,

                'message' => 'Produit non trouvé'

            ], 404);

        }

    

        return response()->json([

    

            'success' => true,

    

            'data' => [

    

                'id' => $produit->id,

    

                'nom' => $produit->nom,

    

                'description' => $produit->description,

    

                'code_barre' => $produit->code_barre,

    

                'marque' => $produit->marque,

    

                'modele' => $produit->modele,

    

                'prix_vente' => $produit->prix_vente,

    

                'prix_vente_TTC' => $produit->prix_vente_TTC,

    

                'type' => $produit->type,

    

                'type_label' => $produit->type_label,

    

                'image' => [

                    'url' => $produit->image_url,

                    'thumbnail' => $produit->image_thumbnail_url,

                    'path' => $produit->image_principale

                ],

    

                'devise' => $produit->devise ? [

                    'id' => $produit->devise->id,

                    'code' => $produit->devise->code,

                    'symbole' => $produit->devise->symbole

                ] : null,

    

                'categorie' => $produit->categorie ? [

                    'id' => $produit->categorie->id,

                    'nom' => $produit->categorie->nom

                ] : null,

            ]

        ]);

    }



    /**

     * Obtenir les options pour les filtres

     * Permissions: view-stock-agence

     */

    public function options()

    {

        $user = auth()->user();

        

        if (!$user->hasPermission('view-stock-agence') && !$user->hasPermission('view-all-stock')) {

            return response()->json([

                'success' => false,

                'message' => 'Vous n\'avez pas les droits pour accéder à cette ressource'

            ], 403);

        }

        

        return response()->json([

            'success' => true,

            'data' => [

                'categories' => CategorieProduit::select('id', 'nom')->orderBy('nom')->get(),

                'types' => $this->getTypesDisponibles(),

                'statuts_imei' => [

                    ['code' => 'EN_STOCK', 'libelle' => 'En stock'],

                    ['code' => 'RESERVE', 'libelle' => 'Réservé'],

                    ['code' => 'VENDU', 'libelle' => 'Vendu'],

                    ['code' => 'TRANSFERE', 'libelle' => 'Transféré'],

                    ['code' => 'RETOURNE', 'libelle' => 'Retourné'],

                    ['code' => 'MAINTENANCE', 'libelle' => 'En maintenance']

                ]

            ]

        ]);

    }

    // ============================================================
    // MÉTHODES PRIVÉES
    // ============================================================

    /**
     * Statistiques des produits - OPTIMISÉ avec UNE SEULE requête
     */
    private function getProduitStats(): array
    {
        // ✅ Statistiques principales en UNE SEULE requête
        $stats = Produit::selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN actif = 1 THEN 1 ELSE 0 END) as actifs,
            SUM(CASE WHEN est_perissable = 1 AND date_expiration <= DATE_ADD(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as perissables,
            SUM(CASE WHEN type IN ('ELECTRONIQUE') THEN 1 ELSE 0 END) as avec_imei
        ")->first();

        // ✅ Produits en alerte et en rupture (nécessitent sous-requêtes)
        $enAlerte = Produit::whereHas('stocks', function ($q) {
            $q->whereRaw('quantite_disponible <= quantite_minimale');
        })->count();

        $enRupture = Produit::whereHas('stocks', function ($q) {
            $q->where('quantite_disponible', '<=', 0);
        })->count();

        // ✅ Par type - UNE SEULE requête groupée
        $parType = Produit::select('type', DB::raw('count(*) as total'))
            ->groupBy('type')
            ->get()
            ->map(fn($item) => [
                'code' => $item->type,
                'label' => Produit::TYPES_PRODUIT[$item->type]['label'] ?? $item->type,
                'total' => $item->total,
                'gestion_imei' => Produit::TYPES_PRODUIT[$item->type]['gestion_imei'] ?? false
            ]);

        // ✅ Par catégorie - UNE SEULE requête
        $parCategorie = CategorieProduit::withCount('produits')
            ->orderBy('produits_count', 'desc')
            ->get()
            ->map(fn($cat) => [
                'id' => $cat->id,
                'nom' => $cat->nom,
                'total_produits' => $cat->produits_count
            ]);

        // ✅ Statistiques IMEI - UNE SEULE requête (colonnes existantes)
        $imeiStats = ProduitImei::selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN statut = 'EN_STOCK' THEN 1 ELSE 0 END) as disponibles,
            SUM(CASE WHEN statut = 'VENDU' THEN 1 ELSE 0 END) as vendus
        ")->first();

        // ✅ IMEI sous garantie - calcul séparé si la colonne existe
        $sousGarantie = 0;
        if (\Schema::hasColumn('produit_imei', 'garantie_expiration')) {
            $sousGarantie = ProduitImei::where('statut', 'VENDU')
                ->where('garantie_expiration', '>=', now())
                ->count();
        } else {
            // Alternative: utiliser la méthode scope sousGarantie si disponible
            try {
                $sousGarantie = ProduitImei::sousGarantie()->count();
            } catch (\Exception $e) {
                $sousGarantie = 0;
            }
        }

        return [
            'total' => (int) $stats->total,
            'actifs' => (int) $stats->actifs,
            'en_alerte' => $enAlerte,
            'en_rupture' => $enRupture,
            'perissables' => (int) $stats->perissables,
            'avec_imei' => (int) $stats->avec_imei,
            'par_type' => $parType,
            'par_categorie' => $parCategorie,
            'total_imei' => (int) $imeiStats->total,
            'imei_disponibles' => (int) $imeiStats->disponibles,
            'imei_vendus' => (int) $imeiStats->vendus,
            'imei_sous_garantie' => $sousGarantie
        ];
    }

}