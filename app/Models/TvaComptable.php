<?php

namespace App\Models;

use App\Events\SystemLogEvent;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class TvaComptable extends Model
{
    protected $table = 'tva_comptable';

    protected $casts = [
        'periode_id' => 'int',
        'entite_id' => 'int',
        'succursale_id' => 'int',
        'tva_collectee_443' => 'decimal:2',
        'tva_deductible_445' => 'decimal:2',
        'credit_tva_anterieur_4449' => 'decimal:2',
        'tva_due_4441' => 'decimal:2',
        'credit_tva_final_4449' => 'decimal:2',
        'ecriture_regularisation_id' => 'int',
        'ecriture_contrepassation_id' => 'int',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $fillable = [
        'periode_id',
        'entite_id',
        'succursale_id',
        'mois',
        'tva_collectee_443',
        'tva_deductible_445',
        'credit_tva_anterieur_4449',
        'tva_due_4441',
        'credit_tva_final_4449',
        'ecriture_regularisation_id',
        'ecriture_contrepassation_id',
        'statut',
        'date_regularisation',
        'commentaire',
    ];

    // =========================================================================
    // 🔥 BOOT - LOG + BROADCAST TEMPS RÉEL
    // =========================================================================

    protected static function booted(): void
    {
        // 🟢 CRÉATION TVA
        static::created(function (TvaComptable $tva) {
            try {
                $entite = $tva->entite ? $tva->entite->code : 'N/A';
                $periode = $tva->periode ? $tva->periode->code : 'N/A';
                
                $log = SysLogComptable::create([
                    'utilisateur_id' => auth()->id() ?? 1,
                    'action' => 'creation_tva',
                    'description' => "Déclaration TVA créée - Entité: {$entite} - Période: {$periode} - Mois: {$tva->mois}",
                    'ip_address' => request()->ip() ?? '127.0.0.1',
                    'user_agent' => request()->userAgent() ?? 'System',
                    'created_at' => now(),
                ]);
                broadcast(new SystemLogEvent($log, auth()->id() ?? 1))->toOthers();
            } catch (\Exception $e) {
                \Log::error('❌ Erreur log création TVA', ['error' => $e->getMessage()]);
            }
        });

        // 🟡 MISE À JOUR TVA (paiement/régularisation)
        static::updated(function (TvaComptable $tva) {
            try {
                if ($tva->isDirty('statut')) {
                    $action = match($tva->statut) {
                        'paye' => 'paiement_tva',
                        'regularise' => 'regularisation_tva',
                        default => 'mise_a_jour_tva',
                    };
                    
                    $entite = $tva->entite ? $tva->entite->code : 'N/A';
                    
                    $log = SysLogComptable::create([
                        'utilisateur_id' => auth()->id() ?? 1,
                        'action' => $action,
                        'description' => "TVA {$tva->statut} - Entité: {$entite} - Mois: {$tva->mois} - Montant: " . number_format($tva->tva_due_4441, 2, ',', ' ') . " $",
                        'ip_address' => request()->ip() ?? '127.0.0.1',
                        'user_agent' => request()->userAgent() ?? 'System',
                        'created_at' => now(),
                    ]);
                    broadcast(new SystemLogEvent($log, auth()->id() ?? 1))->toOthers();
                }
            } catch (\Exception $e) {
                \Log::error('❌ Erreur log mise à jour TVA', ['error' => $e->getMessage()]);
            }
        });
    }

    // =========================================================================
    // RELATIONS
    // =========================================================================

    public function periode()
    {
        return $this->belongsTo(PeriodesComptable::class, 'periode_id');
    }

    public function entite()
    {
        return $this->belongsTo(Entite::class, 'entite_id');
    }

    public function succursale()
    {
        return $this->belongsTo(Succursale::class, 'succursale_id');
    }

    public function ecritureRegularisation()
    {
        return $this->belongsTo(EcrituresComptable::class, 'ecriture_regularisation_id');
    }

    public function ecritureContrepassation()
    {
        return $this->belongsTo(EcrituresComptable::class, 'ecriture_contrepassation_id');
    }
}