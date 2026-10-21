<?php
// app/Http/Middleware/CheckDeviseAccess.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Devise;
use Illuminate\Support\Facades\Log;

class CheckDeviseAccess
{
    public function handle(Request $request, Closure $next)
    {
        if (!auth()->check()) {
            return response()->json(['success' => false, 'error' => 'Non authentifié'], 401);
        }

        $user = auth()->user();
        
        if (!$user->role) {
            return response()->json(['success' => false, 'error' => 'Utilisateur sans rôle'], 403);
        }

        $deviseId = $request->route('devise') ?? $request->input('devise_id');

        if (!$deviseId) {
            return $next($request);
        }

        $devise = Devise::withTrashed()->find($deviseId);
        
        if (!$devise) {
            return response()->json(['success' => false, 'error' => 'Devise non trouvée'], 404);
        }

        $currentRoute = $request->route()->getActionMethod();
        
        // Selon la structure STAREHE
        $managementRoles = ['SUPER_ADMIN', 'COMPTABLE_HOLDING'];
        $consultationRoles = ['SUPER_ADMIN', 'AUDITEUR', 'COMPTABLE_HOLDING', 'CHEF_AGENCE', 'GUICHETIER'];

        // Opérations de gestion (nécessitent manage-produits-financiers)
        $managementOperations = ['store', 'update', 'destroy', 'setDefault', 'toggleStatus'];

        if (in_array($currentRoute, $managementOperations)) {
            // Vérifier par rôle OU par permission
            if (!in_array($user->role->code, $managementRoles) && 
                !$user->hasPermission('manage-produits-financiers')) {
                return response()->json([
                    'success' => false,
                    'error' => 'Non autorisé à gérer les devises'
                ], 403);
            }

            // Protection devise par défaut
            if ($devise->devise_defaut && in_array($currentRoute, ['destroy', 'toggleStatus'])) {
                return response()->json([
                    'success' => false,
                    'error' => 'Impossible de modifier la devise par défaut'
                ], 403);
            }
        }

        // Opérations de consultation
        if (in_array($currentRoute, ['index', 'show', 'liste', 'convertir', 'tauxActuels'])) {
            if (!in_array($user->role->code, $consultationRoles) && 
                !$user->hasPermission('view-devises')) {
                return response()->json([
                    'success' => false,
                    'error' => 'Non autorisé à consulter les devises'
                ], 403);
            }

            // Cacher les devises inactives aux guichetiers
            if ($user->role->code === 'GUICHETIER' && $devise->statut !== 'ACTIF') {
                return response()->json([
                    'success' => false,
                    'error' => 'Devise inactive'
                ], 403);
            }
        }

        $request->merge(['devise' => $devise]);
        return $next($request);
    }
}