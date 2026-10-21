<?php
namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ExternalSystem;
use App\Models\Utilisateur;
use Illuminate\Http\Request;

class ExternalSystemController extends Controller
{
    public function index()
    {
        return response()->json([
            'success' => true,
            'data'    => ExternalSystem::with('utilisateurs:id,nom_complet,email')->get()
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nom'         => 'required|string|max:100',
            'slug'        => 'required|string|max:100|unique:external_systems',
            'description' => 'nullable|string',
            'permissions' => 'nullable|array',
        ]);

        $apiKeyPlain = \Str::random(32);
        $hashedApiKey = hash('sha256', $apiKeyPlain);

        // Verifier que l'utilisateur existe
        $user = Utilisateur::where('nom_complet', $validated['nom'])->first();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non trouvé pour le nom fourni.'
            ], 404);
        }

        // Créer le système externe avec la clé hachée
        $system = ExternalSystem::create([
            'nom'         => $validated['nom'],
            'slug'        => $validated['slug'],
            'api_key'     => $hashedApiKey,
            'description' => $validated['description'] ?? null,
            'permissions' => $validated['permissions'] ?? null,
            'user_id'     => $user->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Système créé.',
            'data'    => [
                'system'  => $system,
                'api_key' => $apiKeyPlain,
            ]
        ], 201);
    }

    public function show($id)
    {
        $system = ExternalSystem::with('utilisateurs:id,nom_complet,email')->findOrFail($id);
        return response()->json([
            'success' => true, 
            'data' => $system
        ], 200);
    }

    public function update(Request $request, $id)
    {
        $system = ExternalSystem::findOrFail($id);
        $validated = $request->validate([
            'nom'         => 'sometimes|string|max:100',
            'description' => 'nullable|string',
            'permissions' => 'nullable|array',
            'actif'       => 'boolean',
        ]);
        $system->update($validated);
        return response()->json([
            'success' => true, 
            'message' => 'Système mis à jour.', 
            'data' => $system->fresh()
        ], 200);
    }

    public function destroy($id)
    {
        $system = ExternalSystem::findOrFail($id);
        if ($system->utilisateurs) {
            $system->utilisateurs->tokens()->delete();
            $system->utilisateurs->delete();
        }
        $system->delete();
        return response()->json([
            'success' => true, 
            'message' => 'Système supprimé.'
        ], 200);
    }

    // Régénérer la clé API (invalide l'ancienne) En cas de compromission
    public function regenerateKey($id)
    {
        $system = ExternalSystem::findOrFail($id);

        $newApiKeyPlain = \Str::random(32);
        $newHashedKey = hash('sha256', $newApiKeyPlain);

        // Utiliser forceFill pour contourner toute restriction readonly potentielle
        $system->forceFill(['api_key' => $newHashedKey])->save();

        // Révoquer les anciens tokens
        if ($system->utilisateurs) {
            $system->utilisateurs->tokens()->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Nouvelle clé générée.',
            'data'    => ['api_key' => $newApiKeyPlain]
        ]);
    }
}