<?php

namespace App\Http\Controllers\Dsa\Concerns;

use Illuminate\Http\Request;

/**
 * Trait utilitaire pour vérifier une permission DSA dans les controllers.
 *
 * Usage :
 *   use ExigePermission;
 *   $this->exiger($request, 'dsa.partenaires.gerer');
 */
trait ExigePermission
{
    /**
     * Abandonne avec une 403 si l'utilisateur n'a pas la permission demandée.
     */
    protected function exiger(Request $request, string $permission, bool $allowSuperAdmin = true): void
    {
        $user = $request->user();

        abort_unless($user, 401, 'Non authentifié');

        // SUPER_ADMIN bypass (facultatif, cohérent avec ton Controller::exiger)
        if ($allowSuperAdmin && method_exists($user, 'hasRole') && $user->hasRole('SUPER_ADMIN')) {
            return;
        }

        abort_unless(
            method_exists($user, 'hasPermission') && $user->hasPermission($permission),
            403,
            "Action non autorisée (permission requise : {$permission})"
        );
    }
}