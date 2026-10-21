<?php

namespace App\Models;

use App\Events\SystemLogEvent;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OperationsAuto extends Model
{
    protected $table = 'operations_auto';

    protected $casts = [
        'parent_operation_id' => 'int',
        'montant_fixe' => 'float',
        'is_percentage' => 'bool',
        'pourcentage' => 'float',
        'is_active' => 'bool',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $fillable = [
        'code_operation',
        'parent_operation_id',
        'libelle',
        'compte_debit',
        'compte_credit',
        'montant_fixe',
        'is_percentage',
        'pourcentage',
        'type_calcul',
        'sens_operation',
        'is_active',
    ];

    // =========================================================================
    // 🔥 BOOT - LOG + BROADCAST TEMPS RÉEL
    // =========================================================================

    protected static function booted(): void
    {
        // 🟢 CRÉATION
        static::created(function (OperationsAuto $auto) {
            try {
                $log = SysLogComptable::create([
                    'utilisateur_id' => auth()->id() ?? 1,
                    'action' => 'creation_operation_auto',
                    'description' => "Opération auto créée: {$auto->code_operation} - {$auto->libelle}",
                    'ip_address' => request()->ip() ?? '127.0.0.1',
                    'user_agent' => request()->userAgent() ?? 'System',
                    'created_at' => now(),
                ]);
                broadcast(new SystemLogEvent($log, auth()->id() ?? 1))->toOthers();
            } catch (\Exception $e) {
                \Log::error('❌ Erreur log création opération auto', ['error' => $e->getMessage()]);
            }
        });

        // 🟡 MODIFICATION
        static::updated(function (OperationsAuto $auto) {
            try {
                $log = SysLogComptable::create([
                    'utilisateur_id' => auth()->id() ?? 1,
                    'action' => 'modification_operation_auto',
                    'description' => "Opération auto modifiée: {$auto->code_operation} - {$auto->libelle}",
                    'ip_address' => request()->ip() ?? '127.0.0.1',
                    'user_agent' => request()->userAgent() ?? 'System',
                    'created_at' => now(),
                ]);
                broadcast(new SystemLogEvent($log, auth()->id() ?? 1))->toOthers();
            } catch (\Exception $e) {
                \Log::error('❌ Erreur log modification opération auto', ['error' => $e->getMessage()]);
            }
        });

        // 🔴 SUPPRESSION
        static::deleted(function (OperationsAuto $auto) {
            try {
                $log = SysLogComptable::create([
                    'utilisateur_id' => auth()->id() ?? 1,
                    'action' => 'suppression_operation_auto',
                    'description' => "Opération auto supprimée: {$auto->code_operation} - {$auto->libelle}",
                    'ip_address' => request()->ip() ?? '127.0.0.1',
                    'user_agent' => request()->userAgent() ?? 'System',
                    'created_at' => now(),
                ]);
                broadcast(new SystemLogEvent($log, auth()->id() ?? 1))->toOthers();
            } catch (\Exception $e) {
                \Log::error('❌ Erreur log suppression opération auto', ['error' => $e->getMessage()]);
            }
        });
    }

    // =========================================================================
    // RELATIONS
    // =========================================================================

    public function parentOperation(): BelongsTo
    {
        return $this->belongsTo(OperationsJournal::class, 'parent_operation_id');
    }

    public function operations_journal(): BelongsTo
    {
        return $this->parentOperation();
    }

    public function ecritures_comptables(): HasMany
    {
        return $this->hasMany(EcrituresComptable::class, 'auto_operation_id');
    }
}