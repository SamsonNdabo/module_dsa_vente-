<?php
namespace App\Http\Controllers\Caisse;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Caisse\CaissesController;
use App\Models\ConfigTransfertCoffre;
use App\Models\ConfigTransfertCoffreDevise;
use App\Models\Caisses;
use Illuminate\Http\Request;

class ConfigTransfertCoffreController extends Controller
{
            public function index(Request $request)
    {
        $user = auth()->user();
    
        $query = ConfigTransfertCoffre::with([
            'coffre',
            'devises.devise'
        ])->orderBy('nom');

        if ($user->isSuperAdmin() || $user->isChefOperation()||$user->isAuditeur()) {
            // Accès à toutes les caisses
        } elseif ($user->isChefAgence()) {
            $query->whereHas('coffre', function ($q) use ($user) {
                $q->where('succursale_id', $user->succursale_id);
            });
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Accès refusé.'
            ], 403);
        }
    
        if ($request->has('actif')) {
            $query->where('actif', $request->boolean('actif'));
        }
    
        return response()->json([
            'success' => true,
            'message' => 'Liste des configurations de transfert coffre',
            'data'    => $query->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nom'         => 'required|string|max:150',
            'coffre_id'   => 'required|integer|exists:caisses,id',
            'caisses_ids' => 'required|array|min:1',
            'caisses_ids.*' => 'integer|exists:caisses,id',
            'motif'       => 'nullable|string|max:255',
            'actif'       => 'boolean',
            'devises'     => 'required|array|min:1',
            'devises.*.devise_id' => 'required|integer|exists:devise,id',
            'devises.*.montant'   => 'required|numeric|min:0.01',
        ]);

        // Vérifier coffre
        $coffre = Caisses::findOrFail($validated['coffre_id']);
        if (!$coffre->isCoffre()) {
            return response()->json(['success' => false, 'message' => 'La source doit être un coffre.'], 422);
        }

        // Vérifier principales
        $countPrincipales = Caisses::whereIn('id', $validated['caisses_ids'])
            ->where('categorie', 'PRINCIPALE')->count();
        if ($countPrincipales !== count($validated['caisses_ids'])) {
            return response()->json(['success' => false, 'message' => 'Toutes les caisses destination doivent être principales.'], 422);
        }

        // Créer la configuration mère
        $config = ConfigTransfertCoffre::create([
            'nom'         => $validated['nom'],
            'coffre_id'   => $validated['coffre_id'],
            'caisses_ids' => $validated['caisses_ids'],
            'motif'       => $validated['motif'] ?? null,
            'actif'       => $validated['actif'] ?? true,
        ]);

        // Ajouter les devises
        foreach ($validated['devises'] as $devise) {
            ConfigTransfertCoffreDevise::create([
                'config_id' => $config->id,
                'devise_id' => $devise['devise_id'],
                'montant'   => $devise['montant'],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Configuration créée.',
            'data'    => $config->load(['coffre', 'devises.devise']),
        ], 201);
    }

    public function show($id)
    {
        $config = ConfigTransfertCoffre::with(['coffre', 'devises.devise'])->findOrFail($id);
        return response()->json(['success' => true, 'data' => $config]);
    }

    public function update(Request $request, $id)
    {
        $config = ConfigTransfertCoffre::findOrFail($id);

        $validated = $request->validate([
            'nom'         => 'sometimes|string|max:150',
            'coffre_id'   => 'sometimes|integer|exists:caisses,id',
            'caisses_ids' => 'sometimes|array|min:1',
            'caisses_ids.*' => 'integer|exists:caisses,id',
            'motif'       => 'nullable|string|max:255',
            'actif'       => 'boolean',
            'devises'     => 'sometimes|array|min:1',
            'devises.*.devise_id' => 'required|integer|exists:devise,id',
            'devises.*.montant'   => 'required|numeric|min:0.01',
        ]);

        // Vérifications coffre / principales si modifiés
        if (isset($validated['coffre_id'])) {
            $coffre = Caisses::findOrFail($validated['coffre_id']);
            if (!$coffre->isCoffre()) {
                return response()->json(['success' => false, 'message' => 'La source doit être un coffre.'], 422);
            }
        }

        if (isset($validated['caisses_ids'])) {
            $countPrincipales = Caisses::whereIn('id', $validated['caisses_ids'])
                ->where('categorie', 'PRINCIPALE')->count();
            if ($countPrincipales !== count($validated['caisses_ids'])) {
                return response()->json(['success' => false, 'message' => 'Toutes les destinations doivent être principales.'], 422);
            }
        }

        // Mettre à jour les champs simples
        $config->update($request->only(['nom', 'coffre_id', 'caisses_ids', 'motif', 'actif']));

        // Si devises fournies, remplacer toutes les lignes
        if (isset($validated['devises'])) {
            // Supprimer les anciennes
            $config->devises()->delete();
            // Créer les nouvelles
            foreach ($validated['devises'] as $devise) {
                ConfigTransfertCoffreDevise::create([
                    'config_id' => $config->id,
                    'devise_id' => $devise['devise_id'],
                    'montant'   => $devise['montant'],
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Configuration mise à jour.',
            'data'    => $config->fresh()->load(['coffre', 'devises.devise']),
        ]);
    }

    public function destroy($id)
    {
        $config = ConfigTransfertCoffre::findOrFail($id);
        $config->delete();
        return response()->json(['success' => true, 'message' => 'Configuration supprimée.']);
    }

    // Permet d'exécuter la configuration : Envoi en masse
    public function executer($id)
    {
        $config = ConfigTransfertCoffre::with('devises.devise')->findOrFail($id);

        if (!$config->actif) {
            return response()->json(['success' => false, 'message' => 'Configuration inactive.'], 422);
        }

        $resultats = [];
        $caissesController = app(CaissesController::class);

        foreach ($config->devises as $configDevise) {
            // Construire la requête pour un transfert multiple (coffre → principales)
            $destinations = [];
            foreach ($config->caisses_ids as $caisseId) {
                $destinations[] = ['caisse_id' => $caisseId];
            }

            $requestData = [
                'caisse_source_id' => $config->coffre_id,
                'devise_id'        => $configDevise->devise_id,
                'montant'          => $configDevise->montant,
                'motif'            => $config->motif ?? "Transfert configuré : {$config->nom}",
                'destinations'     => $destinations,
            ];

            // Créer une requête HTTP factice
            $request = Request::create('/', 'POST', $requestData);
            $request->setUserResolver(fn() => auth()->user()); // transmettre l'utilisateur authentifié

            try {
                $response = $caissesController->transfererFonds($request);
                $responseData = $response->getData(true);

                if ($response->status() == 200 && $responseData['success']) {
                    $resultats[] = [
                        'devise' => $configDevise->devise->code,
                        'montant' => $configDevise->montant,
                        'statut' => 'SUCCES',
                        'details' => $responseData['data'] ?? null,
                    ];
                } else {
                    $resultats[] = [
                        'devise' => $configDevise->devise->code,
                        'montant' => $configDevise->montant,
                        'statut' => 'ERREUR',
                        'message' => $responseData['message'] ?? 'Erreur inconnue',
                    ];
                }
            } catch (\Exception $e) {
                $resultats[] = [
                    'devise' => $configDevise->devise->code,
                    'montant' => $configDevise->montant,
                    'statut' => 'ERREUR',
                    'message' => $e->getMessage(),
                ];
            }
        }

        $successCount = collect($resultats)->where('statut', 'SUCCES')->count();
        $errorCount = collect($resultats)->where('statut', 'ERREUR')->count();

        return response()->json([
            'success' => $errorCount === 0,
            'message' => "Exécution terminée : {$successCount} réussis, {$errorCount} erreurs.",
            'data' => $resultats,
        ]);
    }
}