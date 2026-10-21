<?php
// app/Models/Permission.php

namespace App\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends SpatiePermission
{
    use HasFactory;

    protected $table = 'permissions';
    
    protected $fillable = [
        'name',
        'permission_code',
        'description',
        'module',
        'action',
        'categorie',
        'est_systeme',
        'guard_name'
    ];

    protected $casts = [
        'est_systeme' => 'boolean'
    ];

    protected $attributes = [
        'guard_name' => 'api'
    ];

    /**
     * Boot method pour synchroniser name et permission_code
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($permission) {
            // Synchroniser name et permission_code
            if (empty($permission->name) && !empty($permission->permission_code)) {
                $permission->name = $permission->permission_code;
            }
            if (empty($permission->permission_code) && !empty($permission->name)) {
                $permission->permission_code = $permission->name;
            }
        });
    }

    /**
     * Relation avec les rôles
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'role_has_permissions',
            'permission_id',
            'role_id'
        )->withPivot('date_attribution', 'attribue_par')
         ->withTimestamps();
    }

    /**
     * Scope pour filtrer par module
     */
    public function scopeParModule($query, $module)
    {
        return $query->where('module', $module);
    }

    /**
     * Scope pour filtrer par catégorie
     */
    public function scopeParCategorie($query, $categorie)
    {
        return $query->where('categorie', $categorie);
    }

    /**
     * Scope pour les permissions non système
     */
    public function scopeNonSysteme($query)
    {
        return $query->where('est_systeme', false);
    }

    /**
     * Vérifier si la permission est assignée à un rôle
     */
    public function isAssigned(): bool
    {
        return $this->roles()->exists();
    }
    
    /**
     * Scope pour les permissions système
     */
    public function scopeSysteme($query)
    {
        return $query->where('est_systeme', true);
    }

    /**
     * Surcharge de toArray
     */
    public function toArray()
    {
        $array = parent::toArray();
        $array['permission_code'] = $this->permission_code;
        $array['module'] = $this->module;
        $array['action'] = $this->action;
        $array['categorie'] = $this->categorie;
        $array['est_systeme'] = $this->est_systeme;
        
        return $array;
    }
}