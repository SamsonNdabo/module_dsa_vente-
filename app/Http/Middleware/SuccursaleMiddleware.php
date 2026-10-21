<?php
// app/Http/Middleware/SuccursaleMiddleware.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SuccursaleMiddleware
{
    private $rolesWithoutSuccursale = ['SUPER_ADMIN', 'AUDITEUR', 'COMPTABLE_HOLDING'];

    public function handle(Request $request, Closure $next)
    {
        if (!auth()->check()) {
            return $this->erreur('Non authentifié', 401);
        }

        $user = auth()->user();

        // ✅ Vérification du rôle utilisateur
        if (!$user->role) {
            return $this->erreur('Utilisateur sans rôle défini', 403);
        }

        // ✅ Cas 1: Utilisateur avec succursale
        if ($user->succursale_id) {
            return $this->traiterAvecSuccursale($user, $request, $next);
        }

        // ✅ Cas 2: Utilisateur sans succursale
        return $this->traiterSansSuccursale($user, $request, $next);
    }

        private function traiterAvecSuccursale($user, $request, $next)
    {
        $succursale = $user->succursale;
    
        if (!$succursale) {
            return $this->erreur('Agence non trouvée', 404);
        }
    
        // Rôles autorisés même si la succursale est inactive
        $rolesAutorises = [
            'SUPER_ADMIN',
            'CHEF_OPERATIONS',
            'CHEF_AGENCE',
            'AUDITEUR',
            'COMPTABLE_HOLDING'
        ];
    
        if (
            !$succursale->est_active &&
            !in_array($user->role->code, $rolesAutorises)
        ) {
            return $this->erreur("L'agence {$succursale->nom} est inactive", 403);
        }
    
        // Restriction spécifique aux guichetiers
        if (
            $user->role->code === 'GUICHETIER' &&
            !$succursale->accepte_guichetiers
        ) {
            return $this->erreur(
                'Vous n\'avez pas les droits pour effectuer cette action',
                403
            );
        }
    
        return $next($request);
    }

    private function traiterSansSuccursale($user, $request, $next)
    {
        // ✅ Rôles autorisés sans succursale
        if (in_array($user->role->code, $this->rolesWithoutSuccursale)) {
            return $next($request);
        }

        // ✅ Permissions spéciales
        if ($user->hasPermission('view-all-agences')) {
            return $next($request);
        }

        return $this->erreur('Accès non autorisé sans agence assignée', 403);
    }

    private function erreur($message, $code)
    {
        return response()->json([
            'success' => false,
            'error' => $message
        ], $code);
    }
}