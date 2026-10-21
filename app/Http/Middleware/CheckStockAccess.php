<?php
// app/Http/Middleware/CheckStockAccess.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckStockAccess
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json(['error' => 'Non authentifié'], 401);
        }

        // SUPER_ADMIN et CHEF_AGENCE ont accès à tout
        if ($user->hasRole(['SUPER_ADMIN', 'CHEF_AGENCE'])) {
            return $next($request);
        }

        // GUICHETIER peut voir le stock mais pas le gérer
        if ($user->hasRole('GUICHETIER') && $request->isMethod('get')) {
            return $next($request);
        }

        // Vérifier si l'utilisateur a la permission spécifique
        if ($user->hasPermission('view-stock-agence') || 
            $user->hasPermission('manage-stock-agence')) {
            return $next($request);
        }

        return response()->json([
            'error' => 'Accès non autorisé au module stock'
        ], 403);
    }
}