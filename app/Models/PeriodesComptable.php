<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;

/**
 * Class PeriodesComptable
 * 
 * @property int $id
 * @property string $code
 * @property string $libelle
 * @property Carbon $date_debut
 * @property Carbon $date_fin
 * @property bool|null $is_cloture
 * @property string $statut
 * @property Carbon|null $date_cloture
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * 
 * @property Collection|Bilan[] $bilans
 *
 * @package App\Models
 */
class PeriodesComptable extends Model
{
    use Auditable;

    protected $table = 'periodes_comptables';
    public $timestamps = true; 

    protected $casts = [
        'date_debut' => 'date',       
        'date_fin' => 'date',         
        'is_cloture' => 'boolean',    
        'date_cloture' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $fillable = [
        'code',
        'libelle',
        'date_debut',
        'date_fin',
        'is_cloture',
        'statut',
        'date_cloture',
    ];

    // =============================================
    // BOOT - AUDIT AUTOMATIQUE
    // =============================================

    protected static function boot()
    {
        parent::boot();

        static::created(function ($periode) {
            $periode->logAudit('CREATION_PERIODE', null, [
                'code' => $periode->code,
                'libelle' => $periode->libelle,
                'date_debut' => $periode->date_debut,
                'date_fin' => $periode->date_fin,
                'statut' => $periode->statut,
            ], 'INFO');
        });

        static::updated(function ($periode) {
            $changed = $periode->getDirty();
            if (!empty($changed)) {
                $periode->logAudit('MODIFICATION_PERIODE', $periode->getOriginal(), $changed, 'HAUTE');
            }
        });

        static::deleted(function ($periode) {
            $periode->logAudit('SUPPRESSION_PERIODE', $periode->toArray(), null, 'CRITIQUE');
        });
    }

    // =============================================
    // RELATIONS
    // =============================================
    
    public function bilans()
    {
        return $this->hasMany(Bilan::class, 'periode_id');
    }

    public function bilan_initial()
    {
        return $this->hasOne(Bilan::class, 'periode_id')
            ->where('type_bilan', 'initial');
    }

    public function bilan_fermeture()
    {
        return $this->hasOne(Bilan::class, 'periode_id')
            ->where('type_bilan', 'fermeture');
    }

    public function resultats()
    {
        return $this->hasMany(Resultat::class, 'periode_id');
    }

    // =============================================
    // SCOPES
    // =============================================
    
    public function scopeOuverte($query)
    {
        return $query->where('statut', 'ouverte');
    }

    public function scopeCloturee($query)
    {
        return $query->where('statut', 'cloture_definitive');
    }

    public function scopeEnCours($query)
    {
        return $query->where('date_debut', '<=', now())
            ->where('date_fin', '>=', now())
            ->where('statut', '!=', 'cloture_definitive');
    }

    public function scopeParAnnee($query, $annee)
    {
        return $query->whereYear('date_debut', $annee);
    }

    // =============================================
    // MÉTHODES
    // =============================================
    
    public function isEnCours(): bool
    {
        return now()->between($this->date_debut, $this->date_fin) 
            && $this->statut !== 'cloture_definitive';
    }

    public function isPassee(): bool
    {
        return now()->gt($this->date_fin);
    }

    public function isFuture(): bool
    {
        return now()->lt($this->date_debut);
    }

    public function cloturer(): bool
    {
        if ($this->statut === 'cloture_definitive') {
            return false;
        }
        $result = $this->update([
            'statut' => 'cloture_definitive',
            'is_cloture' => true,
            'date_cloture' => now(),
        ]);

        if ($result) {
            $this->logAudit('CLOTURE_PERIODE', null, [
                'date_cloture' => now(),
                'statut' => 'cloture_definitive',
            ], 'CRITIQUE');
        }

        return $result;
    }

    public function rouvrir(): bool
    {
        if ($this->statut !== 'cloture_definitive') {
            return false;
        }
        $result = $this->update([
            'statut' => 'ouverte',
            'is_cloture' => false,
            'date_cloture' => null,
        ]);

        if ($result) {
            $this->logAudit('REOUVERTURE_PERIODE', null, [
                'statut' => 'ouverte',
                'date_cloture' => null,
            ], 'CRITIQUE');
        }

        return $result;
    }

    // =============================================
    // ACCESSEURS
    // =============================================
    
    public function getDureeAttribute(): int
    {
        return $this->date_debut->diffInDays($this->date_fin) + 1;
    }

    public function getJoursRestantsAttribute(): int
    {
        if ($this->statut === 'cloture_definitive' || $this->isPassee()) {
            return 0;
        }
        return now()->diffInDays($this->date_fin) + 1;
    }

    public function getStatutAttribute($value): string
    {
        if (!empty($value)) {
            return $value;
        }
        
        if ($this->is_cloture) {
            return 'cloture_definitive';
        }
        return 'ouverte';
    }

    public function getStatutLibelleAttribute(): string
    {
        return match($this->statut) {
            'ouverte'             => '🟢 En cours',
            'cloture_provisoire'  => '🟡 Provisoire',
            'cloture_definitive'  => '🔒 Définitive',
            default               => '🟢 ' . ucfirst($this->statut),
        };
    }

    public function getLibelleCompletAttribute(): string
    {
        return $this->code . ' - ' . $this->libelle . 
               ' (' . $this->date_debut->format('d/m/Y') . 
               ' au ' . $this->date_fin->format('d/m/Y') . ')';
    }

    public function getPeriodePrecedenteAttribute(): ?self
    {
        return self::where('date_fin', '<', $this->date_debut)
            ->orderBy('date_fin', 'desc')
            ->first();
    }

    public function getPeriodeSuivanteAttribute(): ?self
    {
        return self::where('date_debut', '>', $this->date_fin)
            ->orderBy('date_debut', 'asc')
            ->first();
    }

    // =============================================
    // VÉRIFICATIONS
    // =============================================
    
    public function hasBilanInitial(): bool
    {
        return $this->bilan_initial()->exists();
    }

    public function hasBilanFermeture(): bool
    {
        return $this->bilan_fermeture()->exists();
    }
}