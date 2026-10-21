<?php
// app/Http/Controllers/Client/Livraison/FraisLivraisonController.php

namespace App\Http\Controllers\Client\Livraison;

use App\Http\Controllers\Controller;
use App\Models\FraisLivraison;
use App\Models\Succursale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FraisLivraisonController extends Controller
{
    /**
     * Frais de livraison par défaut
     */
    private const FRAIS_DEFAUT = [
        'DOMICILE' => ['type_calcul' => 'FIXE', 'valeur' => 5.00],
        'POINT_RELAIS' => ['type_calcul' => 'FIXE', 'valeur' => 3.00],
        'RETRAIT_MAGASIN' => ['type_calcul' => 'FIXE', 'valeur' => 0.00],
    ];

    private const MODES_LIVRAISON = ['DOMICILE', 'POINT_RELAIS', 'RETRAIT_MAGASIN'];
    private const TYPES_CALCUL = ['FIXE', 'POURCENTAGE'];

    /**
     * Configurer les frais de livraison pour une succursale
     */
    public function configurerFraisLivraison(Request $request, $id)
    {
        try {
            $request->validate([
                'mode_livraison' => 'required|in:' . implode(',', self::MODES_LIVRAISON),
                'type_calcul' => 'required|in:' . implode(',', self::TYPES_CALCUL),
                'valeur' => 'required|numeric|min:0',
                'devise' => 'nullable|string|max:10',
                'actif' => 'nullable|boolean',
            ]);

            $succursale = Succursale::select(['id', 'nom'])->findOrFail($id);

            $frais = FraisLivraison::updateOrCreate(
                [
                    'succursale_id' => $succursale->id,
                    'mode_livraison' => $request->mode_livraison,
                ],
                [
                    'type_calcul' => $request->type_calcul,
                    'valeur' => $request->valeur,
                    'devise' => $request->devise ?? 'USD',
                    'actif' => $request->actif ?? true,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Frais de livraison configurés avec succès',
                'data' => $frais->load(['succursale' => function($q) {
                    $q->select('id', 'nom', 'code');
                }])
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);

        } catch (\Throwable $e) {
            Log::error('Erreur configuration frais livraison', [
                'succursale_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Liste des frais de livraison - OPTIMISÉ
     */
    public function index(Request $request)
    {
        try {
            $query = FraisLivraison::select([
                'id', 'succursale_id', 'mode_livraison', 'type_calcul',
                'valeur', 'devise', 'actif', 'created_at', 'updated_at'
            ])->with(['succursale' => function($q) {
                $q->select('id', 'nom', 'code');
            }]);

            if ($request->filled('succursale_id')) {
                $query->where('succursale_id', $request->succursale_id);
            }

            if ($request->filled('mode_livraison')) {
                $query->where('mode_livraison', $request->mode_livraison);
            }

            if ($request->has('actif')) {
                $query->where('actif', $request->boolean('actif'));
            }

            $frais = $query->get();

            return response()->json([
                'success' => true,
                'data' => $frais
            ]);

        } catch (\Throwable $e) {
            Log::error('Erreur liste frais livraison', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Détails d'un frais - OPTIMISÉ
     */
    public function show($id)
    {
        try {
            $frais = FraisLivraison::select([
                'id', 'succursale_id', 'mode_livraison', 'type_calcul',
                'valeur', 'devise', 'actif', 'created_at', 'updated_at'
            ])->with(['succursale' => function($q) {
                $q->select('id', 'nom', 'code');
            }])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $frais
            ]);

        } catch (\Throwable $e) {
            Log::error('Erreur affichage frais livraison', [
                'frais_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Frais non trouvé'
            ], 404);
        }
    }

    /**
     * Mettre à jour un frais
     */
    public function update(Request $request, $id)
    {
        try {
            $request->validate([
                'type_calcul' => 'sometimes|in:' . implode(',', self::TYPES_CALCUL),
                'valeur' => 'sometimes|numeric|min:0',
                'devise' => 'nullable|string|max:10',
                'actif' => 'nullable|boolean',
            ]);

            $frais = FraisLivraison::findOrFail($id);
            $frais->update($request->only(['type_calcul', 'valeur', 'devise', 'actif']));

            return response()->json([
                'success' => true,
                'message' => 'Frais mis à jour avec succès',
                'data' => $frais->fresh()->load(['succursale' => function($q) {
                    $q->select('id', 'nom', 'code');
                }])
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);

        } catch (\Throwable $e) {
            Log::error('Erreur mise à jour frais livraison', [
                'frais_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Supprimer un frais
     */
    public function destroy($id)
    {
        try {
            $frais = FraisLivraison::findOrFail($id);
            $frais->delete();

            return response()->json([
                'success' => true,
                'message' => 'Frais supprimé avec succès'
            ]);

        } catch (\Throwable $e) {
            Log::error('Erreur suppression frais livraison', [
                'frais_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Activer/Désactiver un frais
     */
    public function toggle($id)
    {
        try {
            $frais = FraisLivraison::findOrFail($id);
            $frais->actif = !$frais->actif;
            $frais->save();

            return response()->json([
                'success' => true,
                'message' => $frais->actif ? 'Frais activé' : 'Frais désactivé',
                'data' => $frais
            ]);

        } catch (\Throwable $e) {
            Log::error('Erreur toggle frais livraison', [
                'frais_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtenir les frais pour une succursale et un mode
     */
    public function getFrais($succursaleId, $modeLivraison)
    {
        try {
            $succursale = Succursale::select(['id', 'nom'])->find($succursaleId);

            if (!$succursale) {
                return response()->json([
                    'success' => false,
                    'message' => 'Succursale non trouvée'
                ], 404);
            }

            if (!in_array($modeLivraison, self::MODES_LIVRAISON)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Mode de livraison invalide'
                ], 422);
            }

            $frais = FraisLivraison::where('succursale_id', $succursaleId)
                ->where('mode_livraison', $modeLivraison)
                ->where('actif', true)
                ->select([
                    'id', 'succursale_id', 'mode_livraison', 'type_calcul',
                    'valeur', 'devise', 'actif'
                ])
                ->first();

            if (!$frais) {
                $default = self::FRAIS_DEFAUT[$modeLivraison] ?? ['type_calcul' => 'FIXE', 'valeur' => 0.00];

                return response()->json([
                    'success' => true,
                    'data' => [
                        'succursale_id' => $succursaleId,
                        'mode_livraison' => $modeLivraison,
                        'type_calcul' => $default['type_calcul'],
                        'valeur' => $default['valeur'],
                        'devise' => 'USD',
                        'actif' => true,
                        'is_default' => true,
                    ]
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => $frais
            ]);

        } catch (\Throwable $e) {
            Log::error('Erreur getFrais', [
                'succursale_id' => $succursaleId,
                'mode_livraison' => $modeLivraison,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtenir tous les frais d'une succursale - OPTIMISÉ
     */
    public function getFraisBySuccursale($succursaleId)
    {
        try {
            $succursale = Succursale::select(['id', 'nom'])->find($succursaleId);

            if (!$succursale) {
                return response()->json([
                    'success' => false,
                    'message' => 'Succursale non trouvée'
                ], 404);
            }

            $frais = FraisLivraison::where('succursale_id', $succursaleId)
                ->where('actif', true)
                ->select([
                    'id', 'succursale_id', 'mode_livraison', 'type_calcul',
                    'valeur', 'devise', 'actif'
                ])
                ->get();

            // Si aucun frais configuré, retourner les valeurs par défaut
            if ($frais->isEmpty()) {
                $defaults = [];
                foreach (self::FRAIS_DEFAUT as $mode => $config) {
                    $defaults[] = [
                        'mode_livraison' => $mode,
                        'type_calcul' => $config['type_calcul'],
                        'valeur' => $config['valeur'],
                        'devise' => 'USD',
                        'actif' => true,
                        'is_default' => true,
                    ];
                }

                return response()->json([
                    'success' => true,
                    'data' => $defaults
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => $frais
            ]);

        } catch (\Throwable $e) {
            Log::error('Erreur getFraisBySuccursale', [
                'succursale_id' => $succursaleId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtenir les frais par défaut
     */
    public function fraisDefaut()
    {
        return response()->json([
            'success' => true,
            'data' => self::FRAIS_DEFAUT
        ]);
    }
}