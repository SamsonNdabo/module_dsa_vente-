<?php
// app/Models/Role.php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use App\Traits\Auditable;

class Role extends SpatieRole
{
    use HasFactory,Auditable;

    protected $table = 'roles';

    protected $fillable = [
        'name',
        'code',
        'nom_role',
        'description',
        'niveau_acces',
        'est_defaut',
        'est_systeme',
        'metadata',
        'guard_name'
    ];

    protected $casts = [
        'est_defaut' => 'boolean',
        'est_systeme' => 'boolean',
        'niveau_acces' => 'integer',
        'metadata' => 'array'
    ];

    protected $attributes = [
        'guard_name' => 'api'
    ];


protected static function boot()
{
    parent::boot();

    // static::created(function ($role) {
    //     $role->logAudit('CREATION_ROLE', null, [
    //         'code' => $role->code,
    //         'nom_role' => $role->nom_role,
    //         'niveau_acces' => $role->niveau_acces,
    //         'est_systeme' => $role->est_systeme
    //     ], 'INFO');
    // });

    static::updated(function ($role) {
        $changes = [];
        $fieldsToTrack = ['code', 'nom_role', 'description', 'niveau_acces', 'est_defaut', 'est_systeme'];
        
        foreach ($fieldsToTrack as $field) {
            if ($role->isDirty($field)) {
                $changes[$field] = [
                    'ancien' => $role->getOriginal($field),
                    'nouveau' => $role->$field
                ];
            }
        }
        
        if (!empty($changes)) {
            $role->logAudit('MODIFICATION_ROLE', $role->getOriginal(), $role->getAttributes(), 'INFO');
        }
    });

    static::deleting(function ($role) {
        $canDelete = $role->canDelete();
        if (!$canDelete['can_delete']) {
            throw new \Exception("Suppression impossible: " . $canDelete['reason']);
        }
        
        $role->logAudit('SUPPRESSION_ROLE', $role->toArray(), null, 'HAUTE');
    });
}

    /**
     * Relation Spatie (table model_has_roles)
     */
    public function users(): BelongsToMany
    {
        return $this->morphedByMany(
            Utilisateur::class,
            'model',
            'model_has_roles',
            'role_id',
            'model_id'
        );
    }

    /**
     * Alias pour la compatibilité
     */
    
    public function utilisateurs(): BelongsToMany
    {
        return $this->users();
    }

    /**
     * Relation directe (clé étrangère role_id dans table utilisateurs)
     */
    public function utilisateursDirects()
    {
        return $this->hasMany(Utilisateur::class, 'role_id');
    }

    /**
     * ✅ CORRIGÉ : Vérifie si le rôle a des utilisateurs
     */
    public function hasUtilisateurs(): bool
    {
        return $this->countUtilisateurs() > 0;
    }
    
    

    /**
     * ✅ CORRIGÉ : Compte TOUS les utilisateurs liés à ce rôle
     * (Combine les deux tables) - SANS CACHE STATIQUE
     */
    public function countUtilisateurs(): int
    {
        // Compter via Spatie (table model_has_roles)
        $spatieCount = DB::table('model_has_roles')
            ->where('role_id', $this->id)
            ->where('model_type', Utilisateur::class)
            ->count();

        // Compter via relation directe (table utilisateurs)
        $directCount = DB::table('utilisateurs')
            ->where('role_id', $this->id)
            ->count();

        return $spatieCount + $directCount;
    }

    /**
     * ✅ CORRIGÉ : Accesseur principal - utilise la méthode sans cache
     */
    public function getNombreUtilisateursAttribute()
    {
        return $this->countUtilisateurs();
    }

    /**
     * ✅ CORRIGÉ : Vérifie la cohérence avant suppression
     */
    public function canDelete(): array
    {
        $userCount = $this->countUtilisateurs();
        
        return [
            'can_delete' => $userCount === 0 && !$this->est_systeme,
            'reason' => $this->est_systeme ? 'Rôle système' : 
                       ($userCount > 0 ? "{$userCount} utilisateur(s) lié(s)" : 'OK'),
            'user_count' => $userCount,
            'is_system' => $this->est_systeme
        ];
    }

    /**
     * Nettoie toutes les relations avant suppression
     */
    protected function cleanupRelations(): void
    {
        // Nettoyer les relations Spatie
        DB::table('model_has_roles')
            ->where('role_id', $this->id)
            ->where('model_type', Utilisateur::class)
            ->delete();

        // Nettoyer les relations directes (mettre à null)
        DB::table('utilisateurs')
            ->where('role_id', $this->id)
            ->update(['role_id' => null]);

        // Nettoyer les permissions
        DB::table('role_has_permissions')
            ->where('role_id', $this->id)
            ->delete();
    }

    /**
     * Surcharge de delete() pour ajouter nos vérifications
     */
    public function delete()
    {
        $canDelete = $this->canDelete();
        
        if (!$canDelete['can_delete']) {
            throw new \Exception("Suppression impossible: " . $canDelete['reason']);
        }

        DB::transaction(function() {
            $this->cleanupRelations();
            parent::delete();
        });

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | RELATIONS PERMISSIONS
    |--------------------------------------------------------------------------
    */

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            'role_has_permissions',
            'role_id',
            'permission_id'
        )->withPivot('date_attribution', 'attribue_par')
         ->withTimestamps();
    }

    /*
    |--------------------------------------------------------------------------
    | MAPPING NIVEAUX (Votre code existant)
    |--------------------------------------------------------------------------
    */

    public const NIVEAU_MAPPING = [
        'GUICHETIER' => 1,
        'CAISSIER' => 2,
        'AGENT_COMMERCIAL' => 3,
        'SUPERVISEUR_JUNIOR' => 4,
        'SUPERVISEUR' => 5,
        'CHEF_EQUIPE' => 6,
        'CHEF_AGENCE' => 7,
        'DIRECTEUR_REGIONAL' => 8,
        'ADMIN_SYSTEME' => 9,
        'SUPER_ADMIN' => 10,
    ];

    public static function convertNiveauNomToValue(string $niveauNom): ?int
    {
        $normalized = strtoupper(str_replace(' ', '_', trim($niveauNom)));
        return self::NIVEAU_MAPPING[$normalized] ?? null;
    }

    public function setNiveauAccesAttribute($value)
    {
        if (is_numeric($value)) {
            $this->attributes['niveau_acces'] = (int) $value;
            return;
        }

        $converted = self::convertNiveauNomToValue($value);
        if ($converted === null) {
            throw new \InvalidArgumentException("Niveau d'accès invalide : {$value}");
        }
        $this->attributes['niveau_acces'] = $converted;
    }

    public function getNiveauNomAttribute(): ?string
    {
        return array_search($this->niveau_acces, self::NIVEAU_MAPPING) ?: null;
    }

    public function getNiveauInfoAttribute(): array
    {
        return [
            'valeur' => $this->niveau_acces,
            'nom' => $this->niveau_nom
        ];
    }

    public function getNameAttribute()
    {
        return $this->code;
    }

    public function setNameAttribute($value)
    {
        $this->attributes['name'] = $value;
        $this->code = $value;
    }

    /*
    |--------------------------------------------------------------------------
    | SCOPES
    |--------------------------------------------------------------------------
    */

    public function scopeNonSysteme($query)
    {
        return $query->where('est_systeme', false);
    }

    public function scopeDefaut($query)
    {
        return $query->where('est_defaut', true);
    }

    /*
    |--------------------------------------------------------------------------
    | MÉTHODES
    |--------------------------------------------------------------------------
    */

    public function hasPermission($permission): bool
    {
        if (is_string($permission)) {
            return $this->permissions()->where('permission_code', $permission)->exists();
        }
        
        if ($permission instanceof Permission) {
            return $this->permissions()->where('permission_id', $permission->id)->exists();
        }
        
        return false;
    }

    /**
     * toArray utilise nos nouvelles méthodes
     */
    public function toArray()
    {
        $array = parent::toArray();
        $array['code'] = $this->code;
        $array['nom_role'] = $this->nom_role;
        $array['niveau_acces'] = $this->niveau_acces;
        $array['est_defaut'] = $this->est_defaut;
        $array['est_systeme'] = $this->est_systeme;
        
        // Utilise la méthode centralisée
        $array['nombre_utilisateurs'] = $this->countUtilisateurs();
        $array['peut_etre_supprime'] = $this->canDelete()['can_delete'];
        
        if (isset($array['name']) && isset($array['code'])) {
            unset($array['name']);
        }
        
        return $array;
    }
    
    public static function getIdByCode(string $code) {
        return self::where('code', $code)->firstOrFail()->id;
    }
}