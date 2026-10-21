<?php
// app/Http/Middleware/CheckFournisseurAccess.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckFournisseurAccess
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json(['error' => 'Non authentifié'], 401);
        }

        // SUPER_ADMIN et CHEF_AGENCE peuvent gérer les fournisseurs
        if ($user->hasRole(['SUPER_ADMIN', 'CHEF_AGENCE'])) {
            return $next($request);
        }

        return response()->json([
            'error' => 'Accès non autorisé aux fournisseurs'
        ], 403);
    }
}