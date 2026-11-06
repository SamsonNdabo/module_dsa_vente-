<?php
// app/Http/Middleware/PermissionMiddleware.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PermissionMiddleware
{
    public function handle(Request $request, Closure $next, $permission)
    {
        if (!auth()->check()) {
            Log::warning('Tentative d\'accès sans authentification', [
                'ip' => $request->ip(),
                'route' => $request->route()->getName(),
                'permission' => $permission
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Non authentifié'
            ], 401);
        }

        $user = auth()->user();

        //  SUPER ADMIN a toujours accès
        if ($user->role && $user->role->code === 'SUPER_ADMIN') {
            Log::info('Super Admin - accès automatique', [
                'user_id' => $user->id,
                'route' => $request->route()->getName(),
                'permission' => $permission
            ]);
            return $next($request);
        }

        //  GESTION DES PERMISSIONS MULTIPLES
        // Si la permission contient un pipe "|", c'est une condition OU
        if (str_contains($permission, '|')) {
            $permissions = explode('|', $permission);
            
            foreach ($permissions as $perm) {
                $perm = trim($perm); // Nettoyer les espaces éventuels
                
                if ($user->hasPermission($perm)) {
                    Log::info('Permission accordée (condition OU)', [
                        'user_id' => $user->id,
                        'role' => $user->role?->code,
                        'permission_requise' => $permission,
                        'permission_accordee' => $perm
                    ]);
                    return $next($request);
                }
            }
            
            // Aucune permission n'est satisfaite
            Log::warning('Aucune permission satisfaite (condition OU)', [
                'user_id' => $user->id,
                'role' => $user->role?->code,
                'permissions_requises' => $permissions,
                'user_permissions' => $user->permissions_liste->toArray()
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Accès non autorisé - Vous devez avoir l\'une des permissions: ' . implode(' ou ', $permissions)
            ], 403);
        }
        
        //  Si la permission contient une virgule ",", c'est aussi une condition OU
        if (str_contains($permission, ',')) {
            $permissions = explode(',', $permission);
            
            foreach ($permissions as $perm) {
                $perm = trim($perm);
                
                if ($user->hasPermission($perm)) {
                    Log::info('Permission accordée (condition OU avec virgule)', [
                        'user_id' => $user->id,
                        'role' => $user->role?->code,
                        'permission_requise' => $permission,
                        'permission_accordee' => $perm
                    ]);
                    return $next($request);
                }
            }
            
            Log::warning('Aucune permission satisfaite (condition OU avec virgule)', [
                'user_id' => $user->id,
                'role' => $user->role?->code,
                'permissions_requises' => $permissions,
                'user_permissions' => $user->permissions_liste->toArray()
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Accès non autorisé - Vous devez avoir l\'une des permissions: ' . implode(' ou ', $permissions)
            ], 403);
        }

        //  Permission simple (une seule)
        if (!$user->hasPermission($permission)) {
            Log::warning('Permission refusée (simple)', [
                'user_id' => $user->id,
                'role' => $user->role?->code,
                'required_permission' => $permission,
                'user_permissions' => $user->permissions_liste->toArray()
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Accès non autorisé - Permission requise: ' . $permission
            ], 403);
        }

        Log::info('Permission accordée (simple)', [
            'user_id' => $user->id,
            'role' => $user->role?->code,
            'permission' => $permission
        ]);

        return $next($request);
    }
}