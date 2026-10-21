<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ApiKeyMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // ✅ Autoriser les requêtes preflight CORS
        if ($request->isMethod('OPTIONS')) {
            return response()->json([], 200);
        }

        // Récupérer la clé depuis Authorization
        $apiKey = $request->header('Authorization');

        // Enlever Bearer
        if ($apiKey && str_starts_with($apiKey, 'Bearer ')) {
            $apiKey = substr($apiKey, 7);
        }

        $expectedKey = config('services.shared_api.key');

        if (!$apiKey || $apiKey !== $expectedKey) {

            Log::warning('API Key invalide ou manquante', [
                'received' => $apiKey,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        return $next($request);
    }
}