<?php

namespace App\Models;

use App\Events\SystemLogEvent;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class OperationsType extends Model
{
    protected $table = 'operations_types';

    protected $fillable = [
        'code',
        'nom'
    ];

    // =========================================================================
    // 🔥 BOOT - LOG + BROADCAST TEMPS RÉEL
    // =========================================================================

    protected static function booted(): void
    {
        // 🟢 CRÉATION
        static::created(function (OperationsType $type) {
            try {
                $log = SysLogComptable::create([
                    'utilisateur_id' => auth()->id() ?? 1,
                    'action' => 'creation_type_operation',
                    'description' => "Type d'opération créé: {$type->code} - {$type->nom}",
                    'ip_address' => request()->ip() ?? '127.0.0.1',
                    'user_agent' => request()->userAgent() ?? 'System',
                    'created_at' => now(),
                ]);
                broadcast(new SystemLogEvent($log, auth()->id() ?? 1))->toOthers();
            } catch (\Exception $e) {
                \Log::error('❌ Erreur log création type opération', ['error' => $e->getMessage()]);
            }
        });

        // 🟡 MODIFICATION
        static::updated(function (OperationsType $type) {
            try {
                $log = SysLogComptable::create([
                    'utilisateur_id' => auth()->id() ?? 1,
                    'action' => 'modification_type_operation',
                    'description' => "Type d'opération modifié: {$type->code} - {$type->nom}",
                    'ip_address' => request()->ip() ?? '127.0.0.1',
                    'user_agent' => request()->userAgent() ?? 'System',
                    'created_at' => now(),
                ]);
                broadcast(new SystemLogEvent($log, auth()->id() ?? 1))->toOthers();
            } catch (\Exception $e) {
                \Log::error('❌ Erreur log modification type opération', ['error' => $e->getMessage()]);
            }
        });

        // 🔴 SUPPRESSION
        static::deleted(function (OperationsType $type) {
            try {
                $log = SysLogComptable::create([
                    'utilisateur_id' => auth()->id() ?? 1,
                    'action' => 'suppression_type_operation',
                    'description' => "Type d'opération supprimé: {$type->code} - {$type->nom}",
                    'ip_address' => request()->ip() ?? '127.0.0.1',
                    'user_agent' => request()->userAgent() ?? 'System',
                    'created_at' => now(),
                ]);
                broadcast(new SystemLogEvent($log, auth()->id() ?? 1))->toOthers();
            } catch (\Exception $e) {
                \Log::error('❌ Erreur log suppression type opération', ['error' => $e->getMessage()]);
            }
        });
    }

    // =========================================================================
    // RELATIONS
    // =========================================================================

    public function operations_journals()
    {
        return $this->hasMany(OperationsJournal::class, 'operation_type_id');
    }
}