<?php
// app/Http/Middleware/CheckInventaireAccess.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckInventaireAccess
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json(['error' => 'Non authentifié'], 401);
        }

        // SUPER_ADMIN et CHEF_AGENCE peuvent gérer les inventaires
        if ($user->hasRole(['SUPER_ADMIN', 'CHEF_AGENCE'])) {
            return $next($request);
        }

        // AUDITEUR peut voir les inventaires
        if ($user->hasRole('AUDITEUR') && $request->isMethod('get')) {
            return $next($request);
        }

        return response()->json([
            'error' => 'Accès non autorisé aux inventaires'
        ], 403);
    }
}