<?php

namespace App\Models;

use App\Events\SystemLogEvent;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Traits\Auditable;

class OperationsJournal extends Model
{
    use Auditable;

    protected $table = 'operations_journal';

    protected $casts = [
        'operation_type_id' => 'int',
        'has_tva' => 'bool',
        'taux_tva' => 'float',
        'is_active' => 'bool',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $fillable = [
        'code_operation',
        'operation_type_id',
        'libelle',
        'compte_debit',
        'compte_credit',
        'auto_operation_code',
        'has_tva',
        'taux_tva',
        'sens_tva',
        'is_active',
    ];

    // =========================================================================
    // 🔥 BOOT - AUDIT + LOG SYSTÈME + BROADCAST
    // =========================================================================

    protected static function boot()
    {
        parent::boot();

        // 🟢 CRÉATION
        static::created(function ($operation) {
            $operation->logAudit('CREATION_OPERATION_JOURNAL', null, [
                'code_operation' => $operation->code_operation,
                'libelle' => $operation->libelle,
                'compte_debit' => $operation->compte_debit,
                'compte_credit' => $operation->compte_credit,
                'operation_type_id' => $operation->operation_type_id,
                'has_tva' => $operation->has_tva,
                'taux_tva' => $operation->taux_tva,
            ], 'INFO');

            //  LOG SYSTÈME + BROADCAST
            try {
                // Récupérer les entités si elles sont dans la requête
                $entitesIds = request()->input('entite_ids', []);
                $entitesNoms = '';
                if (!empty($entitesIds)) {
                    $entites = Entite::whereIn('id', (array) $entitesIds)->pluck('code')->toArray();
                    $entitesNoms = $entites ? ' - Entités: ' . implode(', ', $entites) : '';
                }

                $log = SysLogComptable::create([
                    'utilisateur_id' => auth()->id() ?? 1,
                    'action' => 'creation_operation',
                    'description' => "Opération créée: {$operation->code_operation} - {$operation->libelle}{$entitesNoms}",
                    'ip_address' => request()->ip() ?? '127.0.0.1',
                    'user_agent' => request()->userAgent() ?? 'System',
                    'created_at' => now(),
                ]);
                broadcast(new SystemLogEvent($log, auth()->id() ?? 1))->toOthers();
            } catch (\Exception $e) {
                \Log::error('❌ Erreur log création opération', ['error' => $e->getMessage()]);
            }
        });

        // 🟡 MODIFICATION
        static::updated(function ($operation) {
            $changed = $operation->getDirty();
            if (!empty($changed)) {
                $operation->logAudit('MODIFICATION_OPERATION_JOURNAL', $operation->getOriginal(), $changed, 'HAUTE');
            }

            //  LOG SYSTÈME + BROADCAST
            try {
                // Vérifier si les entités ont changé
                $entitesChanged = request()->has('entite_ids');
                $entitesInfo = '';
                if ($entitesChanged) {
                    $entitesIds = request()->input('entite_ids', []);
                    $entites = Entite::whereIn('id', (array) $entitesIds)->pluck('code')->toArray();
                    $entitesInfo = $entites ? ' - Entités mises à jour: ' . implode(', ', $entites) : ' - Entités retirées';
                }

                $log = SysLogComptable::create([
                    'utilisateur_id' => auth()->id() ?? 1,
                    'action' => 'modification_operation',
                    'description' => "Opération modifiée: {$operation->code_operation} - {$operation->libelle}{$entitesInfo}",
                    'ip_address' => request()->ip() ?? '127.0.0.1',
                    'user_agent' => request()->userAgent() ?? 'System',
                    'created_at' => now(),
                ]);
                broadcast(new SystemLogEvent($log, auth()->id() ?? 1))->toOthers();
            } catch (\Exception $e) {
                \Log::error('❌ Erreur log modification opération', ['error' => $e->getMessage()]);
            }
        });

        // 🔴 SUPPRESSION
        static::deleted(function ($operation) {
            $operation->logAudit('SUPPRESSION_OPERATION_JOURNAL', $operation->toArray(), null, 'CRITIQUE');

            //  LOG SYSTÈME + BROADCAST
            try {
                $log = SysLogComptable::create([
                    'utilisateur_id' => auth()->id() ?? 1,
                    'action' => 'suppression_operation',
                    'description' => "Opération supprimée: {$operation->code_operation} - {$operation->libelle}",
                    'ip_address' => request()->ip() ?? '127.0.0.1',
                    'user_agent' => request()->userAgent() ?? 'System',
                    'created_at' => now(),
                ]);
                broadcast(new SystemLogEvent($log, auth()->id() ?? 1))->toOthers();
            } catch (\Exception $e) {
                \Log::error('❌ Erreur log suppression opération', ['error' => $e->getMessage()]);
            }
        });
    }

    // =============================================
    // 🔥 PIVOT : Quand on attache/détache une entité
    // =============================================

    /**
     * Attacher des entités avec log
     */
    public function attachEntitesWithLog(array $entiteIds)
    {
        $this->entites()->syncWithoutDetaching($entiteIds);

        try {
            $entites = Entite::whereIn('id', $entiteIds)->pluck('code')->toArray();
            $log = SysLogComptable::create([
                'utilisateur_id' => auth()->id() ?? 1,
                'action' => 'affectation_entite_operation',
                'description' => "Entité(s) affectée(s) à l'opération {$this->code_operation}: " . implode(', ', $entites),
                'ip_address' => request()->ip() ?? '127.0.0.1',
                'user_agent' => request()->userAgent() ?? 'System',
                'created_at' => now(),
            ]);
            broadcast(new SystemLogEvent($log, auth()->id() ?? 1))->toOthers();
        } catch (\Exception $e) {
            \Log::error('❌ Erreur log affectation entité', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Détacher une entité avec log
     */
    public function detachEntiteWithLog($entiteId)
    {
        $entite = Entite::find($entiteId);
        $this->entites()->detach($entiteId);

        try {
            $log = SysLogComptable::create([
                'utilisateur_id' => auth()->id() ?? 1,
                'action' => 'retrait_entite_operation',
                'description' => "Entité retirée de l'opération {$this->code_operation}: " . ($entite->code ?? 'N/A'),
                'ip_address' => request()->ip() ?? '127.0.0.1',
                'user_agent' => request()->userAgent() ?? 'System',
                'created_at' => now(),
            ]);
            broadcast(new SystemLogEvent($log, auth()->id() ?? 1))->toOthers();
        } catch (\Exception $e) {
            \Log::error('❌ Erreur log retrait entité', ['error' => $e->getMessage()]);
        }
    }

    // =============================================
    // RELATIONS
    // =============================================

    public function operations_type(): BelongsTo
    {
        return $this->belongsTo(OperationsType::class, 'operation_type_id');
    }

    public function plan_comptable(): BelongsTo
    {
        return $this->belongsTo(PlanComptable::class, 'compte_credit', 'numero');
    }

    public function ecritures_comptables(): HasMany
    {
        return $this->hasMany(EcrituresComptable::class, 'operation_journal_id');
    }

    public function entite_operation_journals(): HasMany
    {
        return $this->hasMany(EntiteOperationJournal::class, 'operation_journal_id');
    }

    public function operations_autos(): HasMany
    {
        return $this->hasMany(OperationsAuto::class, 'parent_operation_id');
    }

    public function entites(): BelongsToMany
    {
        return $this->belongsToMany(
            Entite::class,
            'entite_operation_journal',
            'operation_journal_id',
            'entite_id'
        )->withTimestamps();
    }

    public function immobilisations(): HasMany
    {
        return $this->hasMany(ImmobilisationsComptable::class, 'operation_journal_id');
    }
}