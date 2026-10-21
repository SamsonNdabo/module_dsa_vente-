<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;

/**
 * Class BilanLigne
 * 
 * @property int $id
 * @property int $bilan_id
 * @property string $compte_numero
 * @property string|null $compte_nom
 * @property string $type_compte
 * @property float $montant
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * 
 * @property Bilan $bilan
 * @property PlanComptable|null $plan_comptable
 *
 * @package App\Models
 */
class BilanLigne extends Model
{
    use Auditable;

    protected $table = 'bilan_lignes';
    public $timestamps = true;

    protected $casts = [
        'bilan_id' => 'integer',
        'montant' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $fillable = [
        'bilan_id',
        'compte_numero',
        'compte_nom',
        'type_compte',
        'montant',
    ];

    // =============================================
    // BOOT - AUDIT AUTOMATIQUE
    // =============================================

    protected static function boot()
    {
        parent::boot();

        static::created(function ($ligne) {
            $ligne->logAudit('CREATION_LIGNE_BILAN', null, [
                'bilan_id' => $ligne->bilan_id,
                'compte_numero' => $ligne->compte_numero,
                'type_compte' => $ligne->type_compte,
                'montant' => $ligne->montant,
            ], 'INFO');
        });

        static::updated(function ($ligne) {
            $changed = $ligne->getDirty();
            if (!empty($changed)) {
                $ligne->logAudit('MODIFICATION_LIGNE_BILAN', $ligne->getOriginal(), $changed, 'HAUTE');
            }
        });

        static::deleted(function ($ligne) {
            $ligne->logAudit('SUPPRESSION_LIGNE_BILAN', $ligne->toArray(), null, 'CRITIQUE');
        });
    }

    // =============================================
    // RELATIONS
    // =============================================
    
    public function bilan()
    {
        return $this->belongsTo(Bilan::class, 'bilan_id');
    }

    public function plan_comptable()
    {
        return $this->belongsTo(PlanComptable::class, 'compte_numero', 'numero')
            ->withDefault([
                'numero' => $this->compte_numero,
                'nom' => $this->compte_nom ?? 'Compte supprimé',
                'position_bilan' => $this->type_compte,
            ]);
    }

    // =============================================
    // SCOPES
    // =============================================
    
    public function scopeActif($query)
    {
        return $query->where('type_compte', 'actif');
    }

    public function scopePassif($query)
    {
        return $query->where('type_compte', 'passif');
    }

    public function scopeParClasse($query, $classe)
    {
        return $query->where('compte_numero', 'LIKE', $classe . '%');
    }

    // =============================================
    // ACCESSORS
    // =============================================
    
    public function getNomCompteAttribute(): string
    {
        return $this->compte_nom 
            ?? optional($this->plan_comptable)->nom 
            ?? 'Compte #' . $this->compte_numero;
    }

    public function getMontantFormateAttribute(): string
    {
        return number_format($this->montant, 2, ',', ' ') . ' FC';
    }

    public function getClasseAttribute(): string
    {
        return substr($this->compte_numero, 0, 1);
    }

    public function getPourcentageAttribute(): float
    {
        $total = $this->bilan->bilan_lignes()
            ->where('type_compte', $this->type_compte)
            ->sum('montant');
            
        return $total > 0 ? round(($this->montant / $total) * 100, 2) : 0;
    }
}