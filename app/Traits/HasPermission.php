<?php

namespace App\Traits;

trait HasPermissions
{
    public function hasPermission($permission)
    {
        // Utiliser Spatie si disponible
        if (method_exists($this, 'hasPermissionTo')) {
            return $this->hasPermissionTo($permission);
        }
        
        // Fallback sur votre logique
        return $this->role && $this->role->hasPermission($permission);
    }

    public function hasAnyPermission($permissions)
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermissionTo($permission)) {
                return true;
            }
        }
        
        return false;
    }

    public function hasAllPermissions($permissions)
    {
        foreach ($permissions as $permission) {
            if (!$this->hasPermissionTo($permission)) {
                return false;
            }
        }
        
        return true;
    }

    public function givePermissionTo($permission)
    {
        if (!$this->role) {
            return false;
        }
        
        return $this->role->addPermission($permission);
    }

    public function revokePermissionTo($permission)
    {
        if (!$this->role) {
            return false;
        }
        
        return $this->role->removePermission($permission);
    }
}