<?php

namespace App\Models;

use App\Events\PlanComptableChanged;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Traits\Auditable;

class PlanComptable extends Model
{
    use Auditable;

    protected $table = 'plan_comptable';

    protected $casts = [
        'classe_id' => 'integer',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $fillable = [
        'numero',
        'nom',
        'classe_id',
        'position_bilan',
        'position_tfr',
        'fonctionnement_debit',
        'fonctionnement_credit',
        'is_active',
    ];

    // =========================================================================
    // 🔥 BOOT - TEMPS RÉEL + LOGS
    // =========================================================================

    protected static function booted(): void
    {
        // 🟢 CRÉATION
        static::created(function (PlanComptable $compte) {
            try {
                $compte->load('compte_class');
                broadcast(new PlanComptableChanged('created', $compte))->toOthers();
                
                Log::info('🔔 Temps réel - Compte créé', [
                    'id' => $compte->id,
                    'numero' => $compte->numero,
                    'nom' => $compte->nom
                ]);

                //  LOG
                SysLogComptable::create([
                    'utilisateur_id' => Auth::id() ?? 1,
                    'action' => 'creation_compte',
                    'description' => "Compte créé: {$compte->numero} - {$compte->nom}",
                    'ip_address' => request()->ip() ?? '127.0.0.1',
                    'user_agent' => request()->userAgent() ?? 'System',
                    'created_at' => now(),
                ]);
            } catch (\Exception $e) {
                Log::error('❌ Erreur broadcast création compte', [
                    'id' => $compte->id ?? null,
                    'error' => $e->getMessage()
                ]);
            }
        });

        // 🟡 MODIFICATION
        static::updated(function (PlanComptable $compte) {
            try {
                $compte->load('compte_class');
                broadcast(new PlanComptableChanged('updated', $compte))->toOthers();
                
                Log::info('🔔 Temps réel - Compte modifié', [
                    'id' => $compte->id,
                    'numero' => $compte->numero
                ]);

                //  LOG
                SysLogComptable::create([
                    'utilisateur_id' => Auth::id() ?? 1,
                    'action' => 'modification_compte',
                    'description' => "Compte modifié: {$compte->numero} - {$compte->nom}",
                    'ip_address' => request()->ip() ?? '127.0.0.1',
                    'user_agent' => request()->userAgent() ?? 'System',
                    'created_at' => now(),
                ]);
            } catch (\Exception $e) {
                Log::error('❌ Erreur broadcast modification compte', [
                    'id' => $compte->id ?? null,
                    'error' => $e->getMessage()
                ]);
            }
        });

        // 🔴 SUPPRESSION
        static::deleted(function (PlanComptable $compte) {
            try {
                $compte->load('compte_class');
                broadcast(new PlanComptableChanged('deleted', $compte))->toOthers();
                
                Log::info('🔔 Temps réel - Compte supprimé', [
                    'id' => $compte->id,
                    'numero' => $compte->numero
                ]);

                //  LOG
                SysLogComptable::create([
                    'utilisateur_id' => Auth::id() ?? 1,
                    'action' => 'suppression_compte',
                    'description' => "Compte supprimé: {$compte->numero} - {$compte->nom}",
                    'ip_address' => request()->ip() ?? '127.0.0.1',
                    'user_agent' => request()->userAgent() ?? 'System',
                    'created_at' => now(),
                ]);
            } catch (\Exception $e) {
                Log::error('❌ Erreur broadcast suppression compte', [
                    'id' => $compte->id ?? null,
                    'error' => $e->getMessage()
                ]);
            }
        });
    }

    // =========================================================================
    // RELATIONS
    // =========================================================================
    
    public function compte_class()
    {
        return $this->belongsTo(CompteClass::class, 'classe_id');
    }

    public function bilan_lignes()
    {
        return $this->hasMany(BilanLigne::class, 'compte_numero', 'numero');
    }

    public function ecritures_comptables()
    {
        return $this->hasMany(EcrituresComptable::class, 'compte_numero', 'numero');
    }

    // =========================================================================
    // SCOPES
    // =========================================================================
    
    public function scopeActif($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInactif($query)
    {
        return $query->where('is_active', false);
    }

    public function scopeParClasse($query, $classeId)
    {
        return $query->where('classe_id', $classeId);
    }

    public function scopeBilan($query)
    {
        return $query->whereNotNull('position_bilan');
    }

    public function scopeActifBilan($query)
    {
        return $query->where('position_bilan', 'actif');
    }

    public function scopePassifBilan($query)
    {
        return $query->where('position_bilan', 'passif');
    }

    public function scopeGestion($query)
    {
        return $query->whereNotNull('position_tfr');
    }

    // =========================================================================
    // MÉTHODES
    // =========================================================================
    
    public function hasEcritures(): bool
    {
        return $this->ecritures_comptables()->exists();
    }

    public function getEcrituresCount(): int
    {
        return $this->ecritures_comptables()->count();
    }

    public function hasBilanLignes(): bool
    {
        return $this->bilan_lignes()->exists();
    }

    public function getBilanLignesCount(): int
    {
        return $this->bilan_lignes()->count();
    }

    public function activer(): bool
    {
        return $this->update(['is_active' => true]);
    }

    public function desactiver(): bool
    {
        return $this->update(['is_active' => false]);
    }

    public function canBeDeleted(): bool
    {
        return true;
    }

    public function deleteWithArchive(): bool
    {
        return DB::transaction(function () {
            
            Log::warning("Suppression du compte {$this->numero} - {$this->nom}", [
                'compte_id' => $this->id,
                'deleted_by' => Auth::id()
            ]);

            DB::table('ecritures_comptables')
                ->where('compte_numero', $this->numero)
                ->update(['compte_numero_backup' => $this->numero]);

            DB::table('ecritures_comptables')
                ->where('compte_numero', $this->numero)
                ->update(['compte_numero' => null]);

            DB::table('operations_journal')
                ->where('compte_debit', $this->numero)
                ->update(['compte_debit' => null]);

            DB::table('operations_journal')
                ->where('compte_credit', $this->numero)
                ->update(['compte_credit' => null]);

            DB::table('operations_auto')
                ->where('compte_debit', $this->numero)
                ->update(['compte_debit' => null]);

            DB::table('operations_auto')
                ->where('compte_credit', $this->numero)
                ->update(['compte_credit' => null]);

            DB::table('plan_comptable_archives')->insert([
                'original_id' => $this->id,
                'numero' => $this->numero,
                'nom' => $this->nom,
                'classe_id' => $this->classe_id,
                'position_bilan' => $this->position_bilan,
                'position_tfr' => $this->position_tfr,
                'fonctionnement_debit' => $this->fonctionnement_debit,
                'fonctionnement_credit' => $this->fonctionnement_credit,
                'is_active' => $this->is_active,
                'deleted_by' => Auth::id(),
                'deleted_at' => now(),
                'created_at' => $this->created_at,
                'updated_at' => $this->updated_at,
            ]);

            $deleted = $this->delete();
            
            Log::info("Compte {$this->numero} supprimé et archivé", [
                'compte_id' => $this->id,
                'deleted_by' => Auth::id()
            ]);
            
            return $deleted;
        });
    }

    public function getInformationsCompletes(): array
    {
        return [
            'id' => $this->id,
            'numero' => $this->numero,
            'nom' => $this->nom,
            'classe' => $this->compte_class ? $this->compte_class->nom : 'N/A',
            'type_compte' => $this->position_bilan ?: $this->position_tfr,
            'position_bilan' => $this->position_bilan,
            'position_tfr' => $this->position_tfr,
            'fonctionnement' => [
                'debit' => $this->fonctionnement_debit === '+' ? 'Augmente' : 'Diminue',
                'credit' => $this->fonctionnement_credit === '+' ? 'Augmente' : 'Diminue',
            ],
            'statut' => $this->is_active ? 'Actif' : 'Inactif',
            'nombre_ecritures' => $this->getEcrituresCount(),
            'nombre_bilans' => $this->getBilanLignesCount(),
            'created_at' => $this->created_at ? $this->created_at->format('d/m/Y H:i') : null,
            'updated_at' => $this->updated_at ? $this->updated_at->format('d/m/Y H:i') : null,
        ];
    }

    public function isCompteBilan(): bool
    {
        return !is_null($this->position_bilan);
    }

    public function isCompteGestion(): bool
    {
        return !is_null($this->position_tfr);
    }

    public function getClasseNumeroAttribute(): string
    {
        return substr($this->numero, 0, 1);
    }

    public function toBroadcastArray(): array
    {
        return [
            'id' => $this->id,
            'numero' => $this->numero,
            'nom' => $this->nom,
            'classe_id' => $this->classe_id,
            'classe_nom' => $this->compte_class->nom ?? '',
            'classe_numero' => $this->compte_class->numero ?? '',
            'position_bilan' => $this->position_bilan,
            'position_tfr' => $this->position_tfr,
            'fonctionnement_debit' => $this->fonctionnement_debit,
            'fonctionnement_credit' => $this->fonctionnement_credit,
            'is_active' => $this->is_active,
            'type_compte' => $this->position_bilan ?: $this->position_tfr,
            'is_bilan' => $this->isCompteBilan(),
            'is_gestion' => $this->isCompteGestion(),
        ];
    }
}