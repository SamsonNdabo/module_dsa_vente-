<?php

namespace App\Models;

use App\Events\EcritureComptableChanged;
use App\Events\NotificationComptableEvent; // 🆕 Ajout
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Traits\Auditable;

class EcrituresComptable extends Model
{
    use Auditable;

    protected $table = 'ecritures_comptables';
    public $timestamps = false;

    protected $casts = [
        'operation_journal_id' => 'integer',
        'auto_operation_id' => 'integer',
        'entite_id' => 'integer',
        'succursale_id' => 'integer',
        'periode_id' => 'integer',
        'id_utilisateur' => 'integer',
        'date_ecriture' => 'date',
        'debit' => 'decimal:4',
        'credit' => 'decimal:4',
        'parent_ecriture_id' => 'integer',
        'ordre_affichage' => 'integer',
        'is_auto' => 'boolean',
        'is_tva' => 'boolean',
        'taux_tva' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    protected $fillable = [
        'operation_journal_id',
        'auto_operation_id',
        'entite_id',
        'succursale_id',
        'periode_id',
        'id_utilisateur',
        'date_ecriture',
        'compte_numero',
        'compte_numero_backup',
        'libelle',
        'debit',
        'credit',
        'reference',
        'numero_ecriture',
        'parent_ecriture_id',
        'ordre_affichage',
        'sens_ecriture',
        'is_auto',
        'is_tva',
        'sens_tva',
        'taux_tva',
        'created_at',
    ];

    protected $appends = [
        'montant',
        'date_ecriture_formatee',
    ];

    // =========================================================================
    // 🔥 BOOT - TEMPS RÉEL INTÉGRÉ + AUDIT + NOTIFICATIONS
    // =========================================================================

    protected static function booted(): void
    {
        // --- AUDIT ---
        static::created(function (EcrituresComptable $ecriture) {
            $ecriture->logAudit('CREATION_ECRITURE', null, [
                'compte_numero' => $ecriture->compte_numero,
                'debit' => $ecriture->debit,
                'credit' => $ecriture->credit,
                'libelle' => $ecriture->libelle,
                'reference' => $ecriture->reference,
                'numero_ecriture' => $ecriture->numero_ecriture,
            ], 'HAUTE');
        });

        static::updated(function (EcrituresComptable $ecriture) {
            $changed = $ecriture->getDirty();
            if (!empty($changed)) {
                $ecriture->logAudit('MODIFICATION_ECRITURE', $ecriture->getOriginal(), $changed, 'HAUTE');
            }
        });

        static::deleted(function (EcrituresComptable $ecriture) {
            $ecriture->logAudit('SUPPRESSION_ECRITURE', $ecriture->toArray(), null, 'CRITIQUE');
        });

        // --- TEMPS RÉEL + NOTIFICATIONS ---
        static::created(function (EcrituresComptable $ecriture) {
            try {
                $ecriture->load('plan_comptable');
                
                // Broadcast écriture
                broadcast(new EcritureComptableChanged('created', $ecriture))->toOthers();
                
                Log::info('🔔 Temps réel - Écriture créée', [
                    'id' => $ecriture->id,
                    'compte' => $ecriture->compte_numero,
                    'debit' => $ecriture->debit,
                    'credit' => $ecriture->credit,
                    'est_immobilisation' => $ecriture->estCompteImmobilisation(),
                ]);

                // 🆕 CRÉER UNE NOTIFICATION POUR L'UTILISATEUR
                $ecriture->creerNotificationEcriture('created');

                // Vérifier immobilisation
                if ($ecriture->estAchatImmobilisation() && !$ecriture->aDejaAmortissement()) {
                    Log::info('🏗️ Nouvelle immobilisation détectée - Configuration amortissement requise', [
                        'ecriture_id' => $ecriture->id,
                        'compte' => $ecriture->compte_numero,
                        'montant' => $ecriture->debit,
                        'libelle' => $ecriture->libelle,
                    ]);

                    // 🆕 NOTIFICATION POUR IMMOBILISATION
                    $ecriture->creerNotificationImmobilisation();
                }
            } catch (\Exception $e) {
                Log::error('❌ Erreur broadcast création écriture', [
                    'id' => $ecriture->id ?? null,
                    'error' => $e->getMessage()
                ]);
            }
        });

        static::updated(function (EcrituresComptable $ecriture) {
            try {
                $ecriture->load('plan_comptable');
                
                // Broadcast écriture
                broadcast(new EcritureComptableChanged('updated', $ecriture))->toOthers();
                
                Log::info('🔔 Temps réel - Écriture modifiée', [
                    'id' => $ecriture->id,
                    'compte' => $ecriture->compte_numero
                ]);

                // 🆕 NOTIFICATION DE MODIFICATION
                $ecriture->creerNotificationEcriture('updated');
            } catch (\Exception $e) {
                Log::error('❌ Erreur broadcast modification écriture', [
                    'id' => $ecriture->id ?? null,
                    'error' => $e->getMessage()
                ]);
            }
        });

        static::deleted(function (EcrituresComptable $ecriture) {
            try {
                $ecriture->load('plan_comptable');
                
                // Broadcast écriture
                broadcast(new EcritureComptableChanged('deleted', $ecriture))->toOthers();
                
                Log::info('🔔 Temps réel - Écriture supprimée', [
                    'id' => $ecriture->id,
                    'compte' => $ecriture->compte_numero
                ]);

                // 🆕 NOTIFICATION DE SUPPRESSION
                $ecriture->creerNotificationEcriture('deleted');
            } catch (\Exception $e) {
                Log::error('❌ Erreur broadcast suppression écriture', [
                    'id' => $ecriture->id ?? null,
                    'error' => $e->getMessage()
                ]);
            }
        });
    }

    // =========================================================================
    // RELATIONS
    // =========================================================================

    public function periode(): BelongsTo
    {
        return $this->belongsTo(PeriodesComptable::class, 'periode_id');
    }

    public function periode_comptable(): BelongsTo
    {
        return $this->periode();
    }

    public function operations_journal(): BelongsTo
    {
        return $this->belongsTo(OperationsJournal::class, 'operation_journal_id');
    }

    public function entite(): BelongsTo
    {
        return $this->belongsTo(Entite::class, 'entite_id');
    }

    public function succursale(): BelongsTo
    {
        return $this->belongsTo(Succursale::class, 'succursale_id');
    }

    public function plan_comptable(): BelongsTo
    {
        return $this->belongsTo(PlanComptable::class, 'compte_numero', 'numero');
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'id_utilisateur');
    }

    public function ecriture_parente(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_ecriture_id');
    }

    public function ecritures_comptable(): BelongsTo
    {
        return $this->ecriture_parente();
    }

    public function operations_auto(): BelongsTo
    {
        return $this->belongsTo(OperationsAuto::class, 'auto_operation_id');
    }

    public function ecritures_enfants(): HasMany
    {
        return $this->hasMany(self::class, 'parent_ecriture_id');
    }

    public function ecritures_comptables(): HasMany
    {
        return $this->ecritures_enfants();
    }
    

    // =========================================================================
    // ACCESSORS & MUTATORS
    // =========================================================================

    public function setDateEcritureAttribute($value): void
    {
        if (empty($value)) {
            $this->attributes['date_ecriture'] = null;
            return;
        }

        if ($value instanceof Carbon) {
            $this->attributes['date_ecriture'] = $value->format('Y-m-d');
            return;
        }

        if (is_string($value)) {
            try {
                $date = Carbon::createFromFormat('Y-m-d', $value);
                $this->attributes['date_ecriture'] = $date->format('Y-m-d');
                return;
            } catch (\Exception $e) {
            }

            try {
                $date = Carbon::createFromFormat('d/m/Y', $value);
                $this->attributes['date_ecriture'] = $date->format('Y-m-d');
                return;
            } catch (\Exception $e) {
            }

            try {
                $date = Carbon::parse($value);
                $this->attributes['date_ecriture'] = $date->format('Y-m-d');
                return;
            } catch (\Exception $e) {
                $this->attributes['date_ecriture'] = $value;
                return;
            }
        }

        $this->attributes['date_ecriture'] = $value;
    }

    public function getDateEcritureFormateeAttribute(): ?string
    {
        if (!$this->date_ecriture) return null;
        if ($this->date_ecriture instanceof Carbon) return $this->date_ecriture->format('d/m/Y');
        try { return Carbon::parse($this->date_ecriture)->format('d/m/Y'); } 
        catch (\Exception $e) { return $this->date_ecriture; }
    }

    public function getMontantAttribute(): float
    {
        return $this->debit > 0 ? (float) $this->debit : (float) $this->credit;
    }

    public function getStatutPeriodeAttribute(): string
    {
        return $this->periode ? $this->periode->statut : '⚠️ Aucune période';
    }

    public function getNomUtilisateurAttribute(): string
    {
        if ($this->utilisateur) {
            return $this->utilisateur->nom_complet ?? $this->utilisateur->nom_utilisateur ?? 'Utilisateur #' . $this->id_utilisateur;
        }
        return '';
    }

    // =========================================================================
    // 🏗️ MÉTHODES IMMOBILISATION
    // =========================================================================

    /**
     * Vérifie si le compte est un compte d'immobilisation (classe 2)
     */
    public function estCompteImmobilisation(): bool
    {
        $numero = $this->compte_numero ?? '';
        return preg_match('/^(2[1-5])/', $numero) === 1;
    }

    /**
     * Vérifie si une configuration d'amortissement existe déjà pour ce compte
     */
    public function aDejaAmortissement(): bool
    {
        $numero = $this->compte_numero ?? '';
        return ImmobilisationsComptable::where('compte_acquisition', $numero)->exists();
    }

    /**
     * Vérifie si c'est un débit (achat d'immobilisation)
     */
    public function estAchatImmobilisation(): bool
    {
        return $this->estCompteImmobilisation() && (float) $this->debit > 0;
    }

    public function scopeEnAttente($query)
    {
        return $query->where('statut', 'en_attente'); 
        // Remplacez 'statut' par le nom réel de la colonne dans votre table
    }

    // =========================================================================
    // 🆕 MÉTHODES DE NOTIFICATIONS
    // =========================================================================

    /**
     * Créer une notification pour une écriture comptable
     */
    public function creerNotificationEcriture(string $action): void
    {
        if (!$this->id_utilisateur) return;

        $notificationData = match($action) {
            'created' => [
                'type' => 'ecriture',
                'severity' => 'info',
                'icon' => '📝',
                'titre' => 'Nouvelle écriture comptable',
                'message' => "Écriture créée : {$this->libelle} - " . number_format($this->montant, 2, ',', ' ') . " FC",
                'action_label' => 'Voir l\'écriture',
                'action_url' => "/ecritures-comptables/{$this->id}",
                'action_required' => false,
            ],
            'updated' => [
                'type' => 'ecriture',
                'severity' => 'warning',
                'icon' => '✏️',
                'titre' => 'Écriture modifiée',
                'message' => "L'écriture {$this->reference} a été modifiée",
                'action_label' => 'Voir les modifications',
                'action_url' => "/ecritures-comptables/{$this->id}",
                'action_required' => false,
            ],
            'deleted' => [
                'type' => 'ecriture',
                'severity' => 'danger',
                'icon' => '🗑️',
                'titre' => 'Écriture supprimée',
                'message' => "L'écriture {$this->reference} - {$this->libelle} a été supprimée",
                'action_label' => null,
                'action_url' => null,
                'action_required' => false,
            ],
            default => null
        };

        if ($notificationData) {
            $this->envoyerNotification($notificationData);
        }
    }

    /**
     * Créer une notification pour une nouvelle immobilisation
     */
    public function creerNotificationImmobilisation(): void
    {
        if (!$this->id_utilisateur) return;

        $notificationData = [
            'type' => 'system_alert',
            'severity' => 'warning',
            'icon' => '🏗️',
            'titre' => 'Nouvelle immobilisation détectée',
            'message' => "Un achat d'immobilisation a été enregistré sur le compte {$this->compte_numero} pour un montant de " . number_format($this->debit, 2, ',', ' ') . " FC. Pensez à configurer l'amortissement.",
            'action_label' => 'Configurer l\'amortissement',
            'action_url' => "/amortissement/create?compte={$this->compte_numero}&ecriture_id={$this->id}",
            'action_required' => true,
            'metadata' => [
                'ecriture_id' => $this->id,
                'compte_numero' => $this->compte_numero,
                'montant' => (float) $this->debit,
                'type_immobilisation' => 'acquisition',
            ],
        ];

        $this->envoyerNotification($notificationData);
    }

    /**
     * Envoyer une notification (sauvegarde BDD + broadcast Reverb)
     */
    private function envoyerNotification(array $data): void
    {
        try {
            // Vérifier si une notification similaire existe déjà
            $exists = NotificationComptable::where('utilisateur_id', $this->id_utilisateur)
                ->where('type', $data['type'])
                ->where('titre', $data['titre'])
                ->whereNull('read_at')
                ->where('created_at', '>', Carbon::now()->subHours(1))
                ->exists();

            if ($exists) return;

            // Sauvegarder dans la BDD
            $notification = NotificationComptable::create([
                'utilisateur_id' => $this->id_utilisateur,
                'type' => $data['type'],
                'severity' => $data['severity'] ?? 'info',
                'icon' => $data['icon'] ?? '📌',
                'titre' => $data['titre'],
                'message' => $data['message'],
                'action_label' => $data['action_label'] ?? null,
                'action_url' => $data['action_url'] ?? null,
                'action_required' => $data['action_required'] ?? false,
                'metadata' => $data['metadata'] ?? null,
            ]);

            // 🆕 Diffuser en temps réel via Reverb
            broadcast(new NotificationComptableEvent($notification, $this->id_utilisateur, 'created'))->toOthers();

            // Logger
            SysLogComptable::create([
                'utilisateur_id' => $this->id_utilisateur,
                'action' => 'notification_auto',
                'description' => "Notification auto: {$data['titre']}",
                'ip_address' => request()->ip() ?? '127.0.0.1',
                'user_agent' => request()->userAgent() ?? 'System',
                'created_at' => Carbon::now(),
            ]);

            Log::info('📬 Notification créée automatiquement', [
                'utilisateur_id' => $this->id_utilisateur,
                'type' => $data['type'],
                'titre' => $data['titre'],
            ]);
        } catch (\Exception $e) {
            Log::error('❌ Erreur création notification automatique', [
                'error' => $e->getMessage(),
                'ecriture_id' => $this->id,
            ]);
        }
    }

    // =========================================================================
    // MÉTHODES UTILITAIRES
    // =========================================================================

    public function isCompteSupprime(): bool
    {
        return is_null($this->compte_numero) && !is_null($this->compte_numero_backup);
    }

    public function getNumeroCompteDisplay(): string
    {
        if ($this->isCompteSupprime()) return $this->compte_numero_backup;
        return $this->compte_numero ?? $this->compte_numero_backup ?? '???';
    }

    public function getNomCompte(): string
    {
        if ($this->isCompteSupprime()) {
            $archive = DB::table('plan_comptable_archives')
                ->where('numero', $this->compte_numero_backup)
                ->orderBy('deleted_at', 'desc')->first();
            if ($archive) return $archive->nom . ' (supprimé)';
            return 'Compte supprimé';
        }
        if ($this->plan_comptable && $this->plan_comptable->exists) return $this->plan_comptable->nom;
        return $this->compte_numero_backup ?? 'Compte inconnu';
    }

    public function getArchiveTooltip(): ?string
    {
        if (!$this->isCompteSupprime()) return null;
        $archive = DB::table('plan_comptable_archives')
            ->where('numero', $this->compte_numero_backup)
            ->orderBy('deleted_at', 'desc')->first();
        if ($archive) {
            $deletedBy = 'Système';
            if ($archive->deleted_by) {
                $user = Utilisateur::find($archive->deleted_by);
                $deletedBy = $user ? ($user->nom_complet ?? $user->nom_utilisateur) : 'Utilisateur inconnu';
            }
            return sprintf("Compte supprimé le %s par %s", date('d/m/Y à H:i', strtotime($archive->deleted_at)), $deletedBy);
        }
        return "Ce compte a été supprimé du plan comptable";
    }

    public function isDebit(): bool { return (float) $this->debit > 0; }
    public function isCredit(): bool { return (float) $this->credit > 0; }
    public function isPeriodeCloturee(): bool { return $this->periode ? $this->periode->is_cloture : false; }
    public function isModifiable(): bool { return !$this->isPeriodeCloturee(); }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeCompteSupprime($query) { return $query->whereNull('compte_numero')->whereNotNull('compte_numero_backup'); }
    public function scopeCompteActif($query) { return $query->whereNotNull('compte_numero'); }
    public function scopeParPeriode($query, $periodeId) { return $query->where('periode_id', $periodeId); }
    public function scopePeriodeEnCours($query) {
        return $query->whereHas('periode', function ($q) {
            $q->where('is_cloture', false)->where('date_debut', '<=', now())->where('date_fin', '>=', now());
        });
    }
    public function scopeNonModifiable($query) { return $query->whereHas('periode', fn($q) => $q->where('is_cloture', true)); }
    public function scopeModifiable($query) { return $query->whereHas('periode', fn($q) => $q->where('is_cloture', false)); }
    public function scopeEstAuto($query, bool $isAuto = true) { return $query->where('is_auto', $isAuto); }
    public function scopeAvecTVA($query) { return $query->where('is_tva', true); }
    public function scopeParDate($query, $date) { return $query->whereDate('date_ecriture', $date); }
    public function scopeEntreDates($query, $debut, $fin) { return $query->whereBetween('date_ecriture', [$debut, $fin]); }
    public function scopeParEntite($query, $entiteId) { return $query->where('entite_id', $entiteId); }
    public function scopeParSuccursale($query, $succursaleId) { return $query->where('succursale_id', $succursaleId); }
    public function scopeParCompte($query, $compteNumero) { return $query->where('compte_numero', $compteNumero); }
    public function scopeParUtilisateur($query, $utilisateurId) { return $query->where('id_utilisateur', $utilisateurId); }

    // =========================================================================
    // MÉTHODES STATIQUES
    // =========================================================================

    public static function getSoldeCompte(string $compteNumero, int $periodeId): array
    {
        $ecritures = self::where('compte_numero', $compteNumero)->where('periode_id', $periodeId)->get();
        return [
            'total_debit' => $ecritures->sum('debit'),
            'total_credit' => $ecritures->sum('credit'),
            'solde' => $ecritures->sum('debit') - $ecritures->sum('credit'),
            'nombre_ecritures' => $ecritures->count(),
        ];
    }

    public static function getGrandLivre(string $compteNumero, int $periodeId): array
    {
        return self::where('compte_numero', $compteNumero)
            ->where('periode_id', $periodeId)
            ->orderBy('date_ecriture')->orderBy('ordre_affichage')
            ->get()->toArray();
    }

    public static function hasEcritures(int $periodeId): bool
    {
        return self::where('periode_id', $periodeId)->exists();
    }

    // =========================================================================
    // 🔥 MÉTHODE HELPER POUR LE TEMPS RÉEL
    // =========================================================================

    public function toBroadcastArray(): array
    {
        return [
            'id' => $this->id,
            'compte_numero' => $this->compte_numero,
            'compte_nom' => $this->plan_comptable?->nom ?? '',
            'debit' => (float) $this->debit,
            'credit' => (float) $this->credit,
            'libelle' => $this->libelle,
            'reference' => $this->reference,
            'date_ecriture' => $this->date_ecriture_formatee,
            'numero_ecriture' => $this->numero_ecriture,
            'entite_id' => $this->entite_id,
            'succursale_id' => $this->succursale_id,
            'periode_id' => $this->periode_id,
            'sens_ecriture' => $this->sens_ecriture,
            'is_auto' => $this->is_auto,
            'is_tva' => $this->is_tva,
            'est_immobilisation' => $this->estCompteImmobilisation(),
            'a_deja_amortissement' => $this->estCompteImmobilisation() ? $this->aDejaAmortissement() : null,
        ];
    }
}