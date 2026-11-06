<?php
// app/Traits/MultiTenantable.php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

trait MultiTenantable
{
    /**
     * Boot the multi-tenantable trait for a model.
     */
    protected static function bootMultiTenantable()
    {
        if (auth()->check()) {
            $user = auth()->user();
            
            //  Remplacer 'view-all-data' par la vérification du rôle
            $rolesWithFullAccess = ['SUPER_ADMIN', 'ADMIN_SYSTEME', 'AUDITEUR'];
            
            if (!in_array($user->role->code ?? '', $rolesWithFullAccess)) {
                static::addGlobalScope('succursale', function (Builder $builder) use ($user) {
                    $builder->where('succursale_id', $user->succursale_id);
                });
            }
        }
    }

    /**
     * Scope pour voir toutes les succursales (outrepasser le global scope)
     */
    public function scopeAllSuccursales(Builder $query)
    {
        return $query->withoutGlobalScope('succursale');
    }

    /**
     * Scope pour filtrer par succursale
     */
    public function scopeParSuccursale(Builder $query, $succursaleId)
    {
        return $query->where('succursale_id', $succursaleId);
    }

    /**
     * Vérifier si l'utilisateur a accès à cette ressource
     */
    public function isAccessible(): bool
    {
        if (!auth()->check()) {
            return false;
        }

        $user = auth()->user();
        $rolesWithFullAccess = ['SUPER_ADMIN', 'ADMIN', 'AUDITEUR'];
        
        if (in_array($user->role->code ?? '', $rolesWithFullAccess)) {
            return true;
        }

        return $this->succursale_id == $user->succursale_id;
    }
}