<?php
// app/Models/CategorieProduit.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\Auditable;
use Illuminate\Support\Facades\Log;


class CategorieProduit extends Model
{
    use HasFactory, SoftDeletes, Auditable;

    protected $table = 'categorie_produit';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'code',
        'nom',
        'description',
        'statut',
    ];

    protected $casts = [
        'statut' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $attributes = [
        'statut' => true,
    ];

    /**
     * Relations
     */
    public function produits()
    {
        return $this->hasMany(Produit::class, 'categorie_id');
    }

    /**
     * Scopes
     */
    public function scopeActif($query)
    {
        return $query->where('statut', true);
    }

    public function scopeInactif($query)
    {
        return $query->where('statut', false);
    }

    /**
     * Accesseurs
     */
    public function getNombreProduitsAttribute(): int
    {
        return $this->produits()->count();
    }

    public function getProduitsActifsAttribute(): int
    {
        return $this->produits()->where('actif', true)->count();
    }

    public function getEstActifAttribute(): bool
    {
        return $this->statut;
    }

    public function getLibelleAttribute(): string
    {
        return "{$this->code} - {$this->nom} ({$this->nombre_produits} produits)";
    }

    /**
     * Méthodes métier
     */
    public function peutEtreSupprime(): bool
    {
        return $this->produits()->count() === 0;
    }

    public static function genererCode(): string
    {
        $prefix = 'CAT';
        $timestamp = now()->format('ymdHis'); 
        $random = str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        
        return "{$prefix}{$timestamp}{$random}";
    }


protected static function boot()
{
    parent::boot();

    static::creating(function ($categorie) {
        if (empty($categorie->code)) {
            $categorie->code = static::genererCode();
        }
        
        Log::info('Création catégorie', [
            'code' => $categorie->code,
            'nom' => $categorie->nom
        ]);
    });
    
    static::created(function ($categorie) {
        $categorie->logAudit('CREATION_CATEGORIE', null, [
            'code' => $categorie->code,
            'nom' => $categorie->nom,
            'description' => $categorie->description
        ], 'INFO');
    });
    
    static::updated(function ($categorie) {
        $changes = [];
        $fieldsToTrack = ['nom', 'description', 'statut'];
        
        foreach ($fieldsToTrack as $field) {
            if ($categorie->isDirty($field)) {
                $changes[$field] = [
                    'ancien' => $categorie->getOriginal($field),
                    'nouveau' => $categorie->$field
                ];
            }
        }
        
        if (!empty($changes)) {
            $categorie->logAudit('MODIFICATION_CATEGORIE', $categorie->getOriginal(), $categorie->getAttributes(), 'INFO');
        }
    });
    
    static::deleting(function ($categorie) {
        if ($categorie->produits()->exists()) {
            throw new \Exception('Impossible de supprimer une catégorie qui contient des produits');
        }
        
        $categorie->logAudit('SUPPRESSION_CATEGORIE', [
            'code' => $categorie->code,
            'nom' => $categorie->nom
        ], null, 'HAUTE');
    });
    
    static::restored(function ($categorie) {
        $categorie->logAudit('RESTAURATION_CATEGORIE', null, [
            'code' => $categorie->code,
            'nom' => $categorie->nom
        ], 'INFO');
    });
}
}