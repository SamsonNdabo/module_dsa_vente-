<?php
// app/Models/Devise.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;         

class Devise extends Model
{
    use SoftDeletes;

    //  1. Nom de la table
    protected $table = 'devise';

    //  2. Clé primaire
    protected $primaryKey = 'id';

    //  3. Timestamps
    public $timestamps = true;

    //  4. Colonnes modifiables en masse (selon votre table)
    protected $fillable = [
        'code',
        'nom',
        'symbole',
        'devise_defaut',
        'position_symbole',
        'statut',
    ];

    //  5. Colonnes cachées (pour API)
    protected $hidden = [
        'deleted_at',
    ];

    //  6. Casts automatiques
    protected $casts = [
        'devise_defaut' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    //  7. Valeurs par défaut
    protected $attributes = [
        'devise_defaut' => false,
        'position_symbole' => 'APRES',
        'statut' => 1,  // 1 = ACTIF, 0 = INACTIF
    ];

    //  8. Constantes pour les statuts
    const STATUT_ACTIF = 1;
    const STATUT_INACTIF = 0;
    
    const POSITION_AVANT = 'AVANT';
    const POSITION_APRES = 'APRES';

    //  9. Relations
    public function tauxChangesSource()
    {
        return $this->hasMany(TauxChange::class, 'devise_source', 'id');
    }

    public function tauxChangesCible()
    {
        return $this->hasMany(TauxChange::class, 'devise_cible', 'id');
    }
    
        public function factures()
    {
        return $this->hasMany(Facture::class, 'devise_id');
    }

    public static function getIdByCode(string $code) {
        return self::where('code', $code)->firstOrFail()->id;
    }
    
    //  10. Scopes (pour faciliter les requêtes)
    public function scopeActif($query)
    {
        return $query->where('statut', self::STATUT_ACTIF);
    }

    public function scopeInactif($query)
    {
        return $query->where('statut', self::STATUT_INACTIF);
    }

    public function scopeParDefaut($query)
    {
        return $query->where('devise_defaut', true);
    }

    //  11. Accesseurs
    public function getEstActifAttribute(): bool
    {
        return $this->statut == self::STATUT_ACTIF;
    }

    public function getNomCompletAttribute(): string
    {
        return $this->nom . ' (' . $this->code . ')';
    }

    public function getSymbolePositionAttribute(): string
    {
        return $this->position_symbole === 'AVANT' 
            ? $this->symbole . $this->code 
            : $this->code . $this->symbole;
    }
    
    public function getStatutLibelleAttribute(): string
    {
        return $this->statut == self::STATUT_ACTIF ? 'ACTIF' : 'INACTIF';
    }

    //  12. Mutateurs (pour formater automatiquement)
    public function setCodeAttribute($value)
    {
        $this->attributes['code'] = strtoupper(trim($value));
    }

    public function setNomAttribute($value)
    {
        $this->attributes['nom'] = ucfirst(trim($value));
    }

    public function setSymboleAttribute($value)
    {
        $this->attributes['symbole'] = $value ? trim($value) : null;
    }

    public function setPositionSymboleAttribute($value)
    {
        $this->attributes['position_symbole'] = $value ?? 'APRES';
    }

    public function setStatutAttribute($value)
    {
        // Accepter soit 1/0 soit 'ACTIF'/'INACTIF'
        if ($value === 'ACTIF') {
            $this->attributes['statut'] = self::STATUT_ACTIF;
        } elseif ($value === 'INACTIF') {
            $this->attributes['statut'] = self::STATUT_INACTIF;
        } else {
            $this->attributes['statut'] = $value ?? self::STATUT_ACTIF;
        }
    }

    //  13. Méthodes de validation (pour le contrôleur)
    public static function validationRules($id = null)
    {
        $rules = [
            'code' => 'required|string|max:15|unique:devise,code',
            'nom' => 'required|string|max:50|unique:devise,nom',
            'symbole' => 'nullable|string|max:5|unique:devise,symbole',
            'devise_defaut' => 'nullable|boolean',
            'position_symbole' => 'nullable|in:AVANT,APRES',
            'statut' => 'nullable|in:1,0,ACTIF,INACTIF',
        ];

        if ($id) {
            $rules['code'] = 'sometimes|string|max:15|unique:devise,code,' . $id;
            $rules['nom'] = 'sometimes|string|max:50|unique:devise,nom,' . $id;
            $rules['symbole'] = 'nullable|string|max:5|unique:devise,symbole,' . $id;
        }

        return $rules;
    }

    //  14. Méthodes utilitaires
    public function estUtiliseeDansTauxChange(): bool
    {
        return DB::table('taux_change')
            ->where('devise_source', $this->id)
            ->orWhere('devise_cible', $this->id)
            ->exists();
    }

    public function estDeviseParDefaut(): bool
    {
        return (bool) $this->devise_defaut;
    }

    public static function getDeviseParDefaut(): ?self
    {
        return self::where('devise_defaut', true)->first();
    }

    public function peutEtreSupprimee(): bool
    {
        return !$this->devise_defaut && !$this->estUtiliseeDansTauxChange();
    }

    //  15. Boot method (gestion automatique de la devise par défaut)
    protected static function boot()
    {
        parent::boot();

        // Avant création
        static::creating(function ($devise) {
            // Si c'est la première devise, la définir comme par défaut
            if (self::count() === 0) {
                $devise->devise_defaut = true;
            }
            
            // Si aucune devise par défaut n'existe, celle-ci devient par défaut
            if (!self::where('devise_defaut', true)->exists()) {
                $devise->devise_defaut = true;
            }
        });

        // Après création
        static::created(function ($devise) {
            Log::info('Nouvelle devise créée', [
                'code' => $devise->code,
                'nom' => $devise->nom,
                'statut' => $devise->statut
            ]);
        });

        // Avant mise à jour
        static::updating(function ($devise) {
            // Si on change la devise par défaut
            if ($devise->isDirty('devise_defaut') && $devise->devise_defaut) {
                // Retirer le statut par défaut des autres devises
                self::where('id', '!=', $devise->id)
                    ->where('devise_defaut', true)
                    ->update(['devise_defaut' => false]);
            }
        });

        // Avant suppression
        static::deleting(function ($devise) {
            // Empêcher la suppression de la devise par défaut
            if ($devise->devise_defaut) {
                throw new \Exception('Impossible de supprimer la devise par défaut');
            }

            // Empêcher la suppression si utilisée dans taux_change
            if ($devise->estUtiliseeDansTauxChange()) {
                throw new \Exception('Impossible de supprimer cette devise car elle est utilisée dans des taux de change');
            }
        });
    }


    public static function getCodeById($id) {
        return self::where('id', $id)->firstOrFail()->code;
    }
}