<?php

namespace App\Models;

use App\Events\SystemLogEvent;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;

class BudgetSection extends Model
{
    use Auditable;

    protected $table = 'budget_sections';

    protected $casts = [
        'ordre' => 'int',
        'actif' => 'bool',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $fillable = [
        'code',
        'libelle',
        'type_section',
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
        static::created(function ($section) {
            $section->logAudit('CREATION_SECTION_BUDGET', null, [
                'code' => $section->code,
                'libelle' => $section->libelle,
                'type_section' => $section->type_section,
                'ordre' => $section->ordre,
                'actif' => $section->actif,
            ], 'INFO');

            // 🆕 LOG + BROADCAST
            try {
                $log = SysLogComptable::create([
                    'utilisateur_id' => auth()->id() ?? 1,
                    'action' => 'creation_section',
                    'description' => "Section budgétaire créée: {$section->code} - {$section->libelle} ({$section->type_section})",
                    'ip_address' => request()->ip() ?? '127.0.0.1',
                    'user_agent' => request()->userAgent() ?? 'System',
                    'created_at' => now(),
                ]);
                broadcast(new SystemLogEvent($log, auth()->id() ?? 1))->toOthers();
            } catch (\Exception $e) {
                \Log::error('❌ Erreur log création section', ['error' => $e->getMessage()]);
            }
        });

        // 🟡 MODIFICATION
        static::updated(function ($section) {
            $changed = $section->getDirty();
            if (!empty($changed)) {
                $section->logAudit('MODIFICATION_SECTION_BUDGET', $section->getOriginal(), $changed, 'HAUTE');
            }

            // 🆕 LOG + BROADCAST
            try {
                $log = SysLogComptable::create([
                    'utilisateur_id' => auth()->id() ?? 1,
                    'action' => 'modification_section',
                    'description' => "Section budgétaire modifiée: {$section->code} - {$section->libelle}",
                    'ip_address' => request()->ip() ?? '127.0.0.1',
                    'user_agent' => request()->userAgent() ?? 'System',
                    'created_at' => now(),
                ]);
                broadcast(new SystemLogEvent($log, auth()->id() ?? 1))->toOthers();
            } catch (\Exception $e) {
                \Log::error('❌ Erreur log modification section', ['error' => $e->getMessage()]);
            }
        });

        // 🔴 SUPPRESSION
        static::deleted(function ($section) {
            $section->logAudit('SUPPRESSION_SECTION_BUDGET', $section->toArray(), null, 'CRITIQUE');

            // 🆕 LOG + BROADCAST
            try {
                $log = SysLogComptable::create([
                    'utilisateur_id' => auth()->id() ?? 1,
                    'action' => 'suppression_section',
                    'description' => "Section budgétaire supprimée: {$section->code} - {$section->libelle}",
                    'ip_address' => request()->ip() ?? '127.0.0.1',
                    'user_agent' => request()->userAgent() ?? 'System',
                    'created_at' => now(),
                ]);
                broadcast(new SystemLogEvent($log, auth()->id() ?? 1))->toOthers();
            } catch (\Exception $e) {
                \Log::error('❌ Erreur log suppression section', ['error' => $e->getMessage()]);
            }
        });
    }

    // =============================================
    // RELATIONS
    // =============================================

    public function rubriques()
    {
        return $this->hasMany(BudgetRubrique::class, 'section_id');
    }

    // =============================================
    // SCOPES
    // =============================================

    public function scopeActives($query)
    {
        return $query->where('actif', true);
    }

    public function scopeParType($query, $type)
    {
        return $query->where('type_section', $type);
    }

    public function scopeOrdonnees($query)
    {
        return $query->orderBy('ordre');
    }

    // =============================================
    // ACCESSEURS
    // =============================================

    public function getTypeLibelleAttribute(): string
    {
        return $this->type_section;
    }

    public function getEstRevenuAttribute(): bool
    {
        return strtolower($this->type_section) === 'revenu';
    }

    public function getEstChargeAttribute(): bool
    {
        return strtolower($this->type_section) === 'charge';
    }
}