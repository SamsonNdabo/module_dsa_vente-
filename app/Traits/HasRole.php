<?php
// app/Traits/HasRole.php

namespace App\Traits;

use App\Models\Role;
use App\Models\Permission;

trait HasRole
{
    /**
     * Relation avec le rôle
     */
    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /**
     * Vérifier si l'utilisateur a un rôle spécifique
     */
    public function hasRole($roleCode): bool
    {
        if (!$this->role) {
            return false;
        }

        if (is_string($roleCode)) {
            return $this->role->code === $roleCode;
        }

        if ($roleCode instanceof Role) {
            return $this->role->id === $roleCode->id;
        }

        return false;
    }

    /**
     * Vérifier si l'utilisateur a au moins un des rôles
     */
    public function hasAnyRole(array $roleCodes): bool
    {
        if (!$this->role) {
            return false;
        }

        return in_array($this->role->code, $roleCodes);
    }

    /**
     * Vérifier si l'utilisateur a tous les rôles (utile si jamais plusieurs rôles)
     */
    public function hasAllRoles(array $roleCodes): bool
    {
        if (!$this->role) {
            return false;
        }

        // Si l'utilisateur n'a qu'un seul rôle, il ne peut pas avoir tous les rôles
        return false;
    }

    /**
     * Assigner un rôle à l'utilisateur
     */
    public function assignRole($role): self
    {
        if (is_string($role)) {
            $role = Role::where('code', $role)->first();
        }

        if (!$role) {
            throw new \Exception('Rôle non trouvé');
        }

        $this->role_id = $role->id;
        $this->save();

        return $this;
    }

    /**
     * Retirer le rôle de l'utilisateur
     */
    public function removeRole(): self
    {
        $this->role_id = null;
        $this->save();

        return $this;
    }

    /**
     * Changer le rôle de l'utilisateur
     */
    public function syncRoles($role): self
    {
        return $this->assignRole($role);
    }

    /**
     * Obtenir le nom du rôle
     */
    public function getRoleNameAttribute(): ?string
    {
        return $this->role?->nom_role;
    }

    /**
     * Obtenir le code du rôle
     */
    public function getRoleCodeAttribute(): ?string
    {
        return $this->role?->code;
    }

    /**
     * Obtenir le niveau d'accès du rôle
     */
    public function getRoleLevelAttribute(): int
    {
        return $this->role?->niveau_acces ?? 0;
    }

    /**
     * Vérifier si l'utilisateur est un administrateur
     */
    public function isAdmin(): bool
    {
        return $this->hasAnyRole(['ADMIN_SYSTEME','CHEF_AGENCE', 'SUPER_ADMIN']);
    }

    /**
     * Vérifier si l'utilisateur est un superviseur
     */
    public function isSupervisor(): bool
    {
        return $this->hasRole('SUPERVISEUR');
    }

    /**
     * Vérifier si l'utilisateur est un caissier
     */
    public function isCashier(): bool
    {
        return $this->hasRole('CAISSIER');
    }

    /**
     * Vérifier si l'utilisateur a une permission spécifique
     */
    public function hasPermission($permissionCode): bool
    {
        if (!$this->role) {
            return false;
        }

        return $this->role->permissions()
            ->where('permission_code', $permissionCode)
            ->exists();
    }

    /**
     * Vérifier si l'utilisateur a au moins une des permissions
     */
    public function hasAnyPermission(array $permissionCodes): bool
    {
        if (!$this->role) {
            return false;
        }

        return $this->role->permissions()
            ->whereIn('permission_code', $permissionCodes)
            ->exists();
    }

    /**
     * Vérifier si l'utilisateur a toutes les permissions
     */
    public function hasAllPermissions(array $permissionCodes): bool
    {
        if (!$this->role) {
            return false;
        }

        $userPermissionCodes = $this->role->permissions()
            ->pluck('permission_code')
            ->toArray();

        return count(array_intersect($permissionCodes, $userPermissionCodes)) === count($permissionCodes);
    }

    /**
     * Obtenir toutes les permissions de l'utilisateur
     */
    public function getPermissionsAttribute()
    {
        return $this->role?->permissions ?? collect();
    }

    /**
     * Obtenir les codes des permissions
     */
    public function getPermissionCodesAttribute()
    {
        return $this->permissions->pluck('permission_code');
    }

    /**
     * Vérifier si l'utilisateur a un niveau d'accès suffisant
     */
    public function hasMinimumLevel(int $requiredLevel): bool
    {
        return $this->role_level >= $requiredLevel;
    }

    /**
     * Vérifier si l'utilisateur peut accéder à une succursale
     */
    public function canAccessSuccursale($succursaleId): bool
    {
        // Admin peut tout voir
        if ($this->isAdmin()) {
            return true;
        }

        // Sinon, vérifier que c'est sa succursale
        return $this->succursale_id == $succursaleId;
    }

    /**
     * Vérifier si l'utilisateur peut gérer les utilisateurs
     */
    public function canManageUsers(): bool
    {
        return $this->hasAnyPermission(['create-users', 'edit-users', 'delete-users']);
    }

    /**
     * Vérifier si l'utilisateur peut gérer les caisses
     */
    public function canManageCaisses(): bool
    {
        return $this->hasAnyPermission(['create-caisses', 'edit-caisses', 'delete-caisses']);
    }

    /**
     * Vérifier si l'utilisateur peut effectuer des opérations de caisse
     */
    public function canOperateCaisse(): bool
    {
        return $this->hasPermission('operate-caisses') || $this->isCashier();
    }
}