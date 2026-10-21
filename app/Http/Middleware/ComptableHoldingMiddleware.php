<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Utilisateur;

class ComptableHoldingMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        /*
        ============================================================
        1. UTILISATEUR POS UNIQUEMENT
        ============================================================
        */
        $user = Auth::user();

        /*
        ============================================================
        2. VALIDATION AUTH
        ============================================================
        */
        if (!$user || !($user instanceof Utilisateur)) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur POS non authentifié'
            ], 401);
        }

        /*
        ============================================================
        3. VALIDATION DES RÔLES AUTORISÉS
        ============================================================
        */
        if (
            !$user->isSuperAdmin() &&
            !$user->isComptableHolding() &&
            !$user->isChefOperation()
        ) {
            return response()->json([
                'success' => false
            ], 403);
        }

        /*
        ============================================================
        4. INJECTION CONTEXT
        ============================================================
        */
        $request->merge([
            'current_user' => $user
        ]);

        return $next($request);
    }
}