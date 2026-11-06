<?php

namespace App\Http\Controllers\Approvisionnement;

use App\Http\Controllers\Controller;
use App\Models\SeuilLogistique;
use App\Models\Devise;
use App\Models\Role;
use App\Services\Logistique\ValidationLogistiqueService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SeuilsLogistiqueController extends Controller
{
    protected ValidationLogistiqueService $validationService;

    public function __construct(ValidationLogistiqueService $validationService)
    {
        $this->validationService = $validationService;
    }

    /**
     * Lister tous les seuils configurés - OPTIMISÉ
     */
        public function index()
{
    $seuils = SeuilLogistique::with([
        'devise' => function($query) {
            $query->select('id', 'code', 'nom', 'symbole');
        },
        'roleSuperieur' => function($query) {
            $query->select(
                'id',
                'code',
                'nom_role',
                'description',
                'niveau_acces'
            );
        }
    ])
    ->select([
        'id',
        'devise_id',
        'montant_max_approbation_superieur',
        'niveau_superieur_role',
        'actif',
        'created_at',
        'updated_at'
    ])
    ->get();


    // Liste complète des rôles pour le select frontend
    $roles = Role::select([
            'id',
            'code',
            'nom_role',
            'description',
            'niveau_acces'
        ])
        ->orderBy('niveau_acces')
        ->get();


    return response()->json([
        'success' => true,
        'data' => $seuils,   //  ne change pas
        'roles' => $roles   //  nouvelle donnée ajoutée
    ]);
}

    /**
     * Obtenir le seuil pour une devise spécifique - OPTIMISÉ
     */
    public function show($deviseId)
    {
        $seuil = SeuilLogistique::where('devise_id', $deviseId)
            ->with(['devise' => function($query) {
                $query->select('id', 'code', 'nom', 'symbole');
            }])
            ->select([
                'id', 'devise_id', 'montant_max_approbation_superieur',
                'niveau_superieur_role', 'actif', 'created_at', 'updated_at'
            ])
            ->first();

        if (!$seuil) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun seuil configuré pour cette devise'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $seuil
        ]);
    }

    /**
     * Créer un nouveau seuil
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'devise_id' => 'required|exists:devise,id', //  CORRIGÉ : devise (singulier)
            'montant_max_approbation_superieur' => 'required|numeric|min:0',
            'niveau_superieur_role' => 'required|string|max:255',
            'actif' => 'sometimes|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        //  Vérifier si la devise existe
        $devise = Devise::find($request->devise_id);
        if (!$devise) {
            return response()->json([
                'success' => false,
                'message' => 'Devise non trouvée'
            ], 404);
        }

        //  Vérifier si un seuil existe déjà pour cette devise
        $seuilExistant = SeuilLogistique::where('devise_id', $request->devise_id)->first();
        if ($seuilExistant) {
            return response()->json([
                'success' => false,
                'message' => 'Un seuil existe déjà pour cette devise. Utilisez la méthode PUT pour le modifier.',
                'data' => $seuilExistant
            ], 409);
        }

        //  Création
        $seuil = SeuilLogistique::create([
            'devise_id' => $request->devise_id,
            'montant_max_approbation_superieur' => $request->montant_max_approbation_superieur,
            'niveau_superieur_role' => $request->niveau_superieur_role,
            'actif' => $request->has('actif') ? $request->boolean('actif') : true
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Seuil créé avec succès',
            'data' => $seuil->load(['devise' => function($query) {
                $query->select('id', 'code', 'nom', 'symbole');
            }])
        ], 201);
    }

    /**
     * Mettre à jour un seuil existant
     */
    public function update(Request $request, $id)
    {
        //  Vérifier si le seuil existe
        $seuil = SeuilLogistique::find($id);
        if (!$seuil) {
            return response()->json([
                'success' => false,
                'message' => 'Seuil non trouvé'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'devise_id' => 'sometimes|exists:devise,id', //  CORRIGÉ : devise (singulier)
            'montant_max_approbation_superieur' => 'sometimes|numeric|min:0',
            'niveau_superieur_role' => 'sometimes|string|max:255',
            'actif' => 'sometimes|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        //  Si devise_id est fourni, vérifier son existence
        if ($request->has('devise_id') && $request->devise_id != $seuil->devise_id) {
            $devise = Devise::find($request->devise_id);
            if (!$devise) {
                return response()->json([
                    'success' => false,
                    'message' => 'Devise non trouvée'
                ], 404);
            }

            // Vérifier si un autre seuil existe déjà pour cette nouvelle devise
            $autreSeuil = SeuilLogistique::where('devise_id', $request->devise_id)
                ->where('id', '!=', $id)
                ->first();
            if ($autreSeuil) {
                return response()->json([
                    'success' => false,
                    'message' => 'Un seuil existe déjà pour cette devise'
                ], 409);
            }
        }

        //  Préparer les données à mettre à jour
        $data = [];
        if ($request->has('devise_id')) {
            $data['devise_id'] = $request->devise_id;
        }
        if ($request->has('montant_max_approbation_superieur')) {
            $data['montant_max_approbation_superieur'] = $request->montant_max_approbation_superieur;
        }
        if ($request->has('niveau_superieur_role')) {
            $data['niveau_superieur_role'] = $request->niveau_superieur_role;
        }
        if ($request->has('actif')) {
            $data['actif'] = $request->boolean('actif');
        }

        //  Mise à jour
        $seuil->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Seuil mis à jour avec succès',
            'data' => $seuil->fresh()->load(['devise' => function($query) {
                $query->select('id', 'code', 'nom', 'symbole');
            }])
        ]);
    }

    /**
     * Supprimer un seuil (suppression physique)
     */
    public function destroy($id)
    {
        $seuil = SeuilLogistique::find($id);
        
        if (!$seuil) {
            return response()->json([
                'success' => false,
                'message' => 'Seuil non trouvé'
            ], 404);
        }

        $seuil->delete();

        return response()->json([
            'success' => true,
            'message' => 'Seuil supprimé avec succès'
        ]);
    }

    /**
     * Désactiver un seuil
     */
    public function desactiver($id)
    {
        $seuil = SeuilLogistique::find($id);
        
        if (!$seuil) {
            return response()->json([
                'success' => false,
                'message' => 'Seuil non trouvé'
            ], 404);
        }

        $seuil->update(['actif' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Seuil désactivé avec succès',
            'data' => $seuil->fresh()->load(['devise' => function($query) {
                $query->select('id', 'code', 'nom', 'symbole');
            }])
        ]);
    }

    /**
     * Activer un seuil
     */
    public function activer($id)
    {
        $seuil = SeuilLogistique::find($id);
        
        if (!$seuil) {
            return response()->json([
                'success' => false,
                'message' => 'Seuil non trouvé'
            ], 404);
        }

        $seuil->update(['actif' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Seuil activé avec succès',
            'data' => $seuil->fresh()->load(['devise' => function($query) {
                $query->select('id', 'code', 'nom', 'symbole');
            }])
        ]);
    }

    /**
     * Récupérer la configuration par défaut pour les devises
     * (Aide au frontend pour pré-remplir les formulaires)
     */
    public function defaults()
    {
        $defaultConfig = [
            'CDF' => [
                'montant_max_approbation_superieur' => 100000,
                'role' => 'CHEF_OPERATIONS'
            ],
            'USD' => [
                'montant_max_approbation_superieur' => 200,
                'role' => 'CHEF_OPERATIONS'
            ],
            'EUR' => [
                'montant_max_approbation_superieur' => 18000,
                'role' => 'CHEF_OPERATIONS'
            ],
            'NGN' => [
                'montant_max_approbation_superieur' => 10000000,
                'role' => 'CHEF_OPERATIONS'
            ],
        ];

        //  CORRIGÉ : Utilisation de la table 'devise' (singulier)
        $devises = Devise::select('id', 'code', 'nom', 'symbole')->get();

        $result = $devises->map(function($devise) use ($defaultConfig) {
            $seuil = SeuilLogistique::where('devise_id', $devise->id)
                ->select('id', 'devise_id', 'montant_max_approbation_superieur', 'niveau_superieur_role', 'actif')
                ->first();

            $default = $defaultConfig[$devise->code] ?? [
                'montant_max_approbation_superieur' => 0,
                'role' => 'CHEF_OPERATIONS'
            ];

            return [
                'devise' => [
                    'id' => $devise->id,
                    'code' => $devise->code,
                    'nom' => $devise->nom,
                    'symbole' => $devise->symbole
                ],
                'seuil' => $seuil,
                'defaults' => $default,
                'est_configure' => !is_null($seuil)
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $result
        ]);
    }
}