<?php

namespace App\Models;

use App\Events\SystemLogEvent;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;

class BudgetSituation extends Model
{
    use Auditable;

    protected $table = 'budget_situations';

    protected $casts = [
        'rubrique_id' => 'int',
        'periode_id' => 'int',
        'mois' => 'int',
        'situation_initiale' => 'float',
        'situation_finale' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $fillable = [
        'rubrique_id',
        'periode_id',
        'mois',
        'situation_initiale',
        'situation_finale',
    ];

    // =========================================================================
    // 🔥 BOOT - AUDIT + LOG + BROADCAST TEMPS RÉEL
    // =========================================================================

    protected static function boot()
    {
        parent::boot();

        // 🟢 CRÉATION
        static::created(function ($situation) {
            $situation->logAudit('CREATION_SITUATION_BUDGET', null, [
                'rubrique_id' => $situation->rubrique_id,
                'periode_id' => $situation->periode_id,
                'mois' => $situation->mois,
                'situation_initiale' => $situation->situation_initiale,
                'situation_finale' => $situation->situation_finale,
            ], 'INFO');

            //  LOG + BROADCAST
            try {
                $rubrique = $situation->budgetRubrique ? $situation->budgetRubrique->libelle : 'N/A';
                $periode = $situation->periodeComptable ? $situation->periodeComptable->code : 'N/A';
                $log = SysLogComptable::create([
                    'utilisateur_id' => auth()->id() ?? 1,
                    'action' => 'creation_situation_budget',
                    'description' => "Situation budgétaire créée: {$rubrique} - Période: {$periode} - Mois: {$situation->mois}",
                    'ip_address' => request()->ip() ?? '127.0.0.1',
                    'user_agent' => request()->userAgent() ?? 'System',
                    'created_at' => now(),
                ]);
                broadcast(new SystemLogEvent($log, auth()->id() ?? 1))->toOthers();
            } catch (\Exception $e) {
                \Log::error('❌ Erreur log création situation budget', ['error' => $e->getMessage()]);
            }
        });

        // 🟡 MODIFICATION
        static::updated(function ($situation) {
            $changed = $situation->getDirty();
            if (!empty($changed)) {
                $situation->logAudit('MODIFICATION_SITUATION_BUDGET', $situation->getOriginal(), $changed, 'HAUTE');
            }

            //  LOG + BROADCAST
            try {
                $rubrique = $situation->budgetRubrique ? $situation->budgetRubrique->libelle : 'N/A';
                $log = SysLogComptable::create([
                    'utilisateur_id' => auth()->id() ?? 1,
                    'action' => 'modification_situation_budget',
                    'description' => "Situation budgétaire modifiée: {$rubrique} - Mois: {$situation->mois}",
                    'ip_address' => request()->ip() ?? '127.0.0.1',
                    'user_agent' => request()->userAgent() ?? 'System',
                    'created_at' => now(),
                ]);
                broadcast(new SystemLogEvent($log, auth()->id() ?? 1))->toOthers();
            } catch (\Exception $e) {
                \Log::error('❌ Erreur log modification situation budget', ['error' => $e->getMessage()]);
            }
        });

        // 🔴 SUPPRESSION
        static::deleted(function ($situation) {
            $situation->logAudit('SUPPRESSION_SITUATION_BUDGET', $situation->toArray(), null, 'CRITIQUE');

            //  LOG + BROADCAST
            try {
                $rubrique = $situation->budgetRubrique ? $situation->budgetRubrique->libelle : 'N/A';
                $log = SysLogComptable::create([
                    'utilisateur_id' => auth()->id() ?? 1,
                    'action' => 'suppression_situation_budget',
                    'description' => "Situation budgétaire supprimée: {$rubrique} - Mois: {$situation->mois}",
                    'ip_address' => request()->ip() ?? '127.0.0.1',
                    'user_agent' => request()->userAgent() ?? 'System',
                    'created_at' => now(),
                ]);
                broadcast(new SystemLogEvent($log, auth()->id() ?? 1))->toOthers();
            } catch (\Exception $e) {
                \Log::error('❌ Erreur log suppression situation budget', ['error' => $e->getMessage()]);
            }
        });
    }

    // =============================================
    // RELATIONS
    // =============================================

    public function periodeComptable()
    {
        return $this->belongsTo(PeriodesComptable::class, 'periode_id');
    }

    public function budgetRubrique()
    {
        return $this->belongsTo(BudgetRubrique::class, 'rubrique_id');
    }

    // =============================================
    // SCOPES
    // =============================================

    public function scopeParPeriode($query, $periodeId)
    {
        return $query->where('periode_id', $periodeId);
    }

    public function scopeParRubrique($query, $rubriqueId)
    {
        return $query->where('rubrique_id', $rubriqueId);
    }

    public function scopeParMois($query, $mois)
    {
        return $query->where('mois', $mois);
    }
}