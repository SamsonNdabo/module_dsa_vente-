<?php

namespace App\Models;

use App\Events\NotificationComptableEvent;
use App\Models\SysLogComptable;
use App\Models\NotificationComptable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class EchecEcriture extends Model
{
    protected $table = 'echecs_ecritures';

    protected $fillable = [
        'entite_id',
        'succursale_id',
        'periode_id',
        'operation_journal_id',
        'utilisateur_id',
        'montant',
        'libelle',
        'reference',
        'date_ecriture',
        'raison_echec',
        'details',
        'statut',
    ];

    protected $casts = [
        'entite_id' => 'integer',
        'succursale_id' => 'integer',
        'periode_id' => 'integer',
        'operation_journal_id' => 'integer',
        'utilisateur_id' => 'integer',
        'montant' => 'decimal:2',
        'date_ecriture' => 'date',
        'details' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $attributes = [
        'statut' => 'echec',
        'montant' => 0.00,
    ];

    // =========================================================================
    // 🔥 BOOT - LOG + NOTIFICATION AUTOMATIQUES (SANS BOUTON)
    // =========================================================================

    protected static function booted(): void
    {
        static::created(function (EchecEcriture $echec) {
            try {
                //  LOG SYSTÈME
                SysLogComptable::create([
                    'utilisateur_id' => $echec->utilisateur_id,
                    'action' => 'echec_ecriture',
                    'description' => "Échec écriture: {$echec->raison_echec} - {$echec->libelle} - " . number_format($echec->montant, 2, ',', ' ') . " $",
                    'ip_address' => request()->ip() ?? '127.0.0.1',
                    'user_agent' => request()->userAgent() ?? 'System',
                    'created_at' => now(),
                ]);

                //  NOTIFICATION (SANS BOUTON)
                $notification = NotificationComptable::create([
                    'utilisateur_id' => $echec->utilisateur_id,
                    'type' => 'anomalie',
                    'severity' => 'danger',
                    'icon' => '❌',
                    'titre' => 'Échec d\'écriture',
                    'message' => "{$echec->raison_echec} - " . number_format($echec->montant, 2, ',', ' ') . " $",
                    'action_label' => null,
                    'action_url' => null,
                    'action_required' => false,
                    'metadata' => [
                        'echec_id' => $echec->id,
                        'montant' => $echec->montant,
                        'raison' => $echec->raison_echec,
                    ],
                    'created_at' => now(),
                ]);

                //  BROADCAST REVERB
                broadcast(new NotificationComptableEvent($notification, $echec->utilisateur_id, 'created'))->toOthers();

            } catch (\Exception $e) {
                \Log::error('❌ Erreur log/notification EchecEcriture', [
                    'error' => $e->getMessage(),
                    'echec_id' => $echec->id ?? null,
                ]);
            }
        });
    }

    // =============================================
    // RELATIONS
    // =============================================

    public function entite(): BelongsTo
    {
        return $this->belongsTo(Entite::class, 'entite_id');
    }

    public function succursale(): BelongsTo
    {
        return $this->belongsTo(Succursale::class, 'succursale_id');
    }

    public function periode(): BelongsTo
    {
        return $this->belongsTo(PeriodesComptable::class, 'periode_id');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(OperationsJournal::class, 'operation_journal_id');
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'utilisateur_id');
    }

    // =============================================
    // SCOPES
    // =============================================

    public function scopeEnAttente($query)
    {
        return $query->where('statut', 'echec');
    }

    public function scopeCorriges($query)
    {
        return $query->where('statut', 'corrige');
    }

    public function scopeIgnores($query)
    {
        return $query->where('statut', 'ignore');
    }

    public function scopeParPeriode($query, $periodeId)
    {
        return $query->where('periode_id', $periodeId);
    }

    public function scopeParEntite($query, $entiteId)
    {
        return $query->where('entite_id', $entiteId);
    }

    public function scopeDepassementBudget($query)
    {
        return $query->where('raison_echec', 'LIKE', '%Dépassement budgétaire%');
    }

    // =============================================
    // ACCESSEURS
    // =============================================

    public function getMontantFormateAttribute(): string
    {
        return number_format($this->montant, 2, ',', ' ') . ' $';
    }

    public function getDateEcritureFormateeAttribute(): string
    {
        return $this->date_ecriture ? $this->date_ecriture->format('d/m/Y') : 'N/A';
    }

    public function getStatutIconeAttribute(): string
    {
        return match ($this->statut) {
            'echec' => '❌',
            'corrige' => '',
            'ignore' => '⚠️',
            default => '❓',
        };
    }

    public function getStatutLibelleAttribute(): string
    {
        return match ($this->statut) {
            'echec' => 'En échec',
            'corrige' => 'Corrigé',
            'ignore' => 'Ignoré',
            default => 'Inconnu',
        };
    }

    public function getResumeDepassementAttribute(): string
    {
        if (empty($this->details)) return 'Aucun détail';
        $details = is_array($this->details) ? $this->details : json_decode($this->details, true);
        if (isset($details['nb_depassements'])) return "{$details['nb_depassements']} dépassement(s)";
        if (isset($details['rubrique_libelle'])) return "Rubrique : {$details['rubrique_libelle']}";
        return 'Voir détails';
    }

    public function getCodeEntiteAttribute(): string
    {
        return $this->entite ? $this->entite->code : 'N/A';
    }

    public function getCodePeriodeAttribute(): string
    {
        return $this->periode ? $this->periode->code : 'N/A';
    }

    // =============================================
    // MÉTHODES
    // =============================================

    public function marquerCorrige(): bool
    {
        return $this->update(['statut' => 'corrige']);
    }

    public function marquerIgnore(): bool
    {
        return $this->update(['statut' => 'ignore']);
    }

    public function estEnAttente(): bool
    {
        return $this->statut === 'echec';
    }
}