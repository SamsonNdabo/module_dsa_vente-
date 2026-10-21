<?php
// app/Http/Controllers/Comptabilite/UserRoleController.php

namespace App\Http\Controllers\Comptabilite;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserRoleController extends Controller
{
    /**
     * Retourne le rôle et les droits de l'utilisateur connecté.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'authenticated' => false,
                'message'       => 'Utilisateur non authentifié.',
            ], 401);
        }

        // Charge le rôle + permissions (évite N+1)
        $user->loadMissing('role.permissions');

        // 🎯 Récupère le code du rôle (normalisé en MAJUSCULES)
        $roleCode = strtoupper((string) $user->role?->code);

        // 🎯 Helpers booléens basés sur le CODE du rôle
        $isSuperAdmin        = $roleCode === 'SUPER-ADMIN' || $roleCode === 'SUPER_ADMIN' || $user->isSuperAdmin();
        $isComptableHolding  = $roleCode === 'COMPTABLE-HOLDING' || $roleCode === 'COMPTABLE_HOLDING' || $user->isComptableHolding();
        $isChefComptable     = $roleCode === 'CHEF-COMPTABLE' || $roleCode === 'CHEF_COMPTABLE';
        $isComptableFinancier = $roleCode === 'COMPTABLE-FINANCIER' || $roleCode === 'COMPTABLE_FINANCIER';

        // 🎯 Admin = Super Admin OU Comptable Holding
        $isAdmin = $isSuperAdmin || $isComptableHolding;

        return response()->json([
            'authenticated' => true,

            // Identité
            'user_id'       => $user->id,
            'nom_complet'   => $user->nom_complet,
            'matricule'     => $user->matricule,
            'email'         => $user->email,

            // Rôle
            'role_code'     => $user->role?->code,
            'role_nom'      => $user->role?->nom,
            'niveau_acces'  => $user->niveau_acces,

            // Helpers booléens (calculés côté serveur)
            'is_super_admin'       => $isSuperAdmin,
            'is_auditeur'          => $user->isAuditeur(),
            'is_comptable_holding' => $isComptableHolding,
            'is_chef_agence'       => $user->isChefAgence(),
            'is_caissier'          => $user->isCaissier(),
            'is_chef_operation'    => $user->isChefOperation(),

            // 🎯 Rôles "métier" utilisés par React
            'is_admin'               => $isAdmin,
            'is_chef_comptable'      => $isChefComptable,
            'is_comptable_financier' => $isComptableFinancier,

            // 🔐 Permissions
            'permissions' => $user->permissions_liste ?? [],

            // 🎯 Droit spécifique
            'can_delete_operations' => $isSuperAdmin || $isComptableHolding,
        ]);
    }
}