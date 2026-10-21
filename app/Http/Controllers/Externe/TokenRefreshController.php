<?php
namespace App\Http\Controllers\Externe;

use App\Http\Controllers\Controller;
use App\Models\ExternalSystem;
use Illuminate\Http\Request;

class TokenRefreshController extends Controller
{
    public function refresh(Request $request)
    {
        $validated = $request->validate([
            'api_key' => 'required|string|max:64',
            'slug'    => 'nullable|string|exists:external_systems,slug',
        ]);

        // La clé reçue est en clair → on la hache pour comparer
        $hashed = hash('sha256', $validated['api_key']);

        $system = ExternalSystem::where('api_key', $hashed)
            ->where('actif', true)
            ->first();

        if (!$system) {
            return response()->json([
                'success' => false, 
                'message' => 'Clé invalide.'
            ], 401);
        }

        if (isset($validated['slug']) && $system->slug !== $validated['slug']) {
            return response()->json([
                'success' => false, 
                'message' => 'Slug incorrect.'
            ], 401);
        }

        $user = $system->utilisateurs;

        if (!$user) {
            return response()->json([
                'success' => false, 
                'message' => 'Aucun utilisateur lié.'
            ], 500);
        }

        // Révoquer les anciens tokens
        $user->tokens()->delete();

        // Créer un nouveau token
        $token = $user->createToken('api-externe-' . $system->slug, ['public-convert'])->plainTextToken;
        $system->last_used_at = now();
        $system->save();

        return response()->json([
            'success' => true,
            'message' => 'Token généré.',
            'data'    => [
                'token'      => $token,
                'expires_at' => now()->addMinutes(config('sanctum.expiration', 1440)),
            ]
        ]);
    }
}