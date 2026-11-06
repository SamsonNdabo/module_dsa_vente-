<?php

namespace App\Models;

use App\Events\SystemLogEvent;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;

class BudgetRubrique extends Model
{
    use Auditable;

    protected $table = 'budget_rubriques';

    protected $casts = [
        'section_id' => 'int',
        'parent_id' => 'int',
        'ordre' => 'int',
        'actif' => 'bool',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $fillable = [
        'section_id',
        'parent_id',
        'code',
        'libelle',
        'comptes_racines',
        'ordre',
        'actif',
    ];

    // =========================================================================
    // 🔥 BOOT - AUDIT + LOG + BROADCAST TEMPS RÉEL
    // =========================================================================

    protected static function boot()
    {
        parent::boot();

        // 🟢 CRÉATION
        static::created(function ($rubrique) {
            $rubrique->logAudit('CREATION_RUBRIQUE_BUDGET', null, [
                'section_id' => $rubrique->section_id,
                'parent_id' => $rubrique->parent_id,
                'code' => $rubrique->code,
                'libelle' => $rubrique->libelle,
                'comptes_racines' => $rubrique->comptes_racines,
                'ordre' => $rubrique->ordre,
                'actif' => $rubrique->actif,
            ], 'INFO');

            //  LOG + BROADCAST
            try {
                $section = $rubrique->section ? $rubrique->section->libelle : 'N/A';
                $log = SysLogComptable::create([
                    'utilisateur_id' => auth()->id() ?? 1,
                    'action' => 'creation_rubrique',
                    'description' => "Rubrique budgétaire créée: {$rubrique->code} - {$rubrique->libelle} (Section: {$section})",
                    'ip_address' => request()->ip() ?? '127.0.0.1',
                    'user_agent' => request()->userAgent() ?? 'System',
                    'created_at' => now(),
                ]);
                broadcast(new SystemLogEvent($log, auth()->id() ?? 1))->toOthers();
            } catch (\Exception $e) {
                \Log::error('❌ Erreur log création rubrique', ['error' => $e->getMessage()]);
            }
        });

        // 🟡 MODIFICATION
        static::updated(function ($rubrique) {
            $changed = $rubrique->getDirty();
            if (!empty($changed)) {
                $rubrique->logAudit('MODIFICATION_RUBRIQUE_BUDGET', $rubrique->getOriginal(), $changed, 'HAUTE');
            }

            //  LOG + BROADCAST
            try {
                $log = SysLogComptable::create([
                    'utilisateur_id' => auth()->id() ?? 1,
                    'action' => 'modification_rubrique',
                    'description' => "Rubrique budgétaire modifiée: {$rubrique->code} - {$rubrique->libelle}",
                    'ip_address' => request()->ip() ?? '127.0.0.1',
                    'user_agent' => request()->userAgent() ?? 'System',
                    'created_at' => now(),
                ]);
                broadcast(new SystemLogEvent($log, auth()->id() ?? 1))->toOthers();
            } catch (\Exception $e) {
                \Log::error('❌ Erreur log modification rubrique', ['error' => $e->getMessage()]);
            }
        });

        // 🔴 SUPPRESSION
        static::deleted(function ($rubrique) {
            $rubrique->logAudit('SUPPRESSION_RUBRIQUE_BUDGET', $rubrique->toArray(), null, 'CRITIQUE');

            //  LOG + BROADCAST
            try {
                $log = SysLogComptable::create([
                    'utilisateur_id' => auth()->id() ?? 1,
                    'action' => 'suppression_rubrique',
                    'description' => "Rubrique budgétaire supprimée: {$rubrique->code} - {$rubrique->libelle}",
                    'ip_address' => request()->ip() ?? '127.0.0.1',
                    'user_agent' => request()->userAgent() ?? 'System',
                    'created_at' => now(),
                ]);
                broadcast(new SystemLogEvent($log, auth()->id() ?? 1))->toOthers();
            } catch (\Exception $e) {
                \Log::error('❌ Erreur log suppression rubrique', ['error' => $e->getMessage()]);
            }
        });
    }

    // =============================================
    // RELATIONS
    // =============================================

    public function section()
    {
        return $this->belongsTo(BudgetSection::class, 'section_id');
    }

    public function parent()
    {
        return $this->belongsTo(BudgetRubrique::class, 'parent_id');
    }

    public function previsions()
    {
        return $this->hasMany(BudgetPrevision::class, 'rubrique_id');
    }

    public function enfants()
    {
        return $this->hasMany(BudgetRubrique::class, 'parent_id');
    }

    // =============================================
    // SCOPES
    // =============================================

    public function scopeActives($query)
    {
        return $query->where('actif', true);
    }

    public function scopeParSection($query, $sectionId)
    {
        return $query->where('section_id', $sectionId);
    }

    public function scopeRacines($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopeOrdonnees($query)
    {
        return $query->orderBy('ordre');
    }

    // =============================================
    // ACCESSEURS
    // =============================================

    public function getComptesRacinesArrayAttribute(): array
    {
        if (empty($this->comptes_racines)) return [];
        return array_map('trim', explode(',', $this->comptes_racines));
    }

    public function getEstParentAttribute(): bool
    {
        return is_null($this->parent_id);
    }

    public function getEstEnfantAttribute(): bool
    {
        return !is_null($this->parent_id);
    }

    public function getLibelleCompletAttribute(): string
    {
        return $this->code . ' - ' . $this->libelle;
    }
}