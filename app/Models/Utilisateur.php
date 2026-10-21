<?php
// app/Models/Utilisateur.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use App\Traits\Auditable;
use App\Traits\HasRole;
use App\Mail\TwoFactorCodeMail;
use PragmaRX\Google2FA\Google2FA;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Utilisateur extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes, Auditable, HasRole;

    protected $table = 'utilisateurs';
    protected $guard_name = 'api';
    protected $primaryKey = 'id';

    protected $fillable = [
        'matricule',
        'nom_complet',
        'email',
        'telephone',
        'role_id',
        'succursale_id',
        'nom_utilisateur',
        'password_hash',
        'salt',
        'two_factor_secret',
        'two_factor_authentification',
        'two_factor_confirmed_at',
        'two_factor_recovery_codes',
        'two_factor_email_enabled',
        'date_derniere_connexion',
        'date_expiration_mdp',
        'compte_verrouille',
        'tentatives_echec',
        'statut',
        'photo_profil',
        'preferences',
        'email_verified_at',
        'remember_token'
    ];

    protected $hidden = [
        'password_hash',
        'salt',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    protected $casts = [
        // 'matricule' => 'encrypted',
        'two_factor_authentification' => 'boolean',
        'two_factor_email_enabled' => 'boolean',
        'compte_verrouille' => 'boolean',
        'date_derniere_connexion' => 'datetime',
        'date_expiration_mdp' => 'date',
        'email_verified_at' => 'datetime',
        'two_factor_confirmed_at' => 'datetime',
        'preferences' => 'array',
        'tentatives_echec' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $attributes = [
        'statut' => 'ACTIF',
        'tentatives_echec' => 0,
        'compte_verrouille' => false,
        'two_factor_authentification' => false,
        'two_factor_email_enabled' => false,
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($utilisateur) {
            if (empty($utilisateur->matricule)) {
                $utilisateur->matricule = static::genererMatricule();
            }

            if (empty($utilisateur->date_expiration_mdp)) {
                $utilisateur->date_expiration_mdp = now()->addDays(90);
            }

            if (empty($utilisateur->salt)) {
                $utilisateur->salt = bin2hex(random_bytes(16));
            }
        });

        static::created(function ($utilisateur) {
            $utilisateur->logAudit('CREATION', null, $utilisateur->toArray());
            
            if ($utilisateur->succursale_id) {
                Succursale::where('id', $utilisateur->succursale_id)
                    ->increment('nombre_employes');
                
                Log::info('Nombre d\'employés incrémenté', [
                    'succursale_id' => $utilisateur->succursale_id,
                    'utilisateur_id' => $utilisateur->id,
                    'action' => 'CREATION'
                ]);
            }
        });

        static::updated(function ($utilisateur) {
            $changes = $utilisateur->getChanges();
            $original = [];
            foreach (array_keys($changes) as $key) {
                $original[$key] = $utilisateur->getOriginal($key);
            }
            $utilisateur->logAudit('MODIFICATION', $original, $changes);

            if ($utilisateur->isDirty('succursale_id')) {
                $oldSuccursaleId = $utilisateur->getOriginal('succursale_id');
                $newSuccursaleId = $utilisateur->succursale_id;
                
                if ($oldSuccursaleId) {
                    Succursale::where('id', $oldSuccursaleId)->decrement('nombre_employes');
                    Log::info('Nombre d\'employés décrémenté (ancienne succursale)', [
                        'succursale_id' => $oldSuccursaleId,
                        'utilisateur_id' => $utilisateur->id
                    ]);
                }
                
                if ($newSuccursaleId) {
                    Succursale::where('id', $newSuccursaleId)->increment('nombre_employes');
                    Log::info('Nombre d\'employés incrémenté (nouvelle succursale)', [
                        'succursale_id' => $newSuccursaleId,
                        'utilisateur_id' => $utilisateur->id
                    ]);
                }
            }
        });

        static::deleted(function ($utilisateur) {
            $utilisateur->logAudit('SUPPRESSION', $utilisateur->toArray(), null);
            
            if ($utilisateur->succursale_id) {
                Succursale::where('id', $utilisateur->succursale_id)
                    ->decrement('nombre_employes');
                
                Log::info('Nombre d\'employés décrémenté', [
                    'succursale_id' => $utilisateur->succursale_id,
                    'utilisateur_id' => $utilisateur->id,
                    'action' => 'SUPPRESSION'
                ]);
            }
        });
    }

    // ============================================================
    // MÉTHODES DE BASE
    // ============================================================

    public static function genererMatricule(): string
    {
        $prefix = 'EMP';
        $timestamp = now()->format('ymdHis'); 
        $random = str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        
        return "{$prefix}{$timestamp}{$random}";
    }

    public function getAuthPassword()
    {
        return $this->password_hash;
    }

    // ============================================================
    // RELATIONS
    // ============================================================

    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function succursale()
    {
        return $this->belongsTo(Succursale::class, 'succursale_id');
    }

    public function audits()
    {
        return $this->hasMany(AuditSysteme::class, 'id_utilisateur');
    }

    public function caisses()
    {
        return $this->hasMany(Caisses::class, 'caissier_attribue', 'id');
    }
    
    public function ecrituresComptables()
    {
        return $this->hasMany(
            EcrituresComptable::class,
            'id_utilisateur',
            'id'
        );
    }

    // 🆕 NOUVELLES RELATIONS AJOUTÉES
    // ============================================================

    /**
     * Notifications comptables de l'utilisateur
     */
    public function notificationsComptables()
    {
        return $this->hasMany(NotificationComptable::class, 'utilisateur_id');
    }

    /**
     * Notifications comptables non lues
     */
    public function notificationsNonLues()
    {
        return $this->notificationsComptables()->unread();
    }

    /**
     * Logs système de l'utilisateur
     */
    public function systemLogs()
    {
        return $this->hasMany(SysLogComptable::class, 'utilisateur_id');
    }

    /**
     * Budgets créés par l'utilisateur
     */
    public function budgets()
    {
        return $this->hasMany(Budget::class, 'utilisateur_id');
    }

    /**
     * Périodes comptables gérées par l'utilisateur
     */
    public function periodesComptables()
    {
        return $this->hasMany(PeriodesComptable::class, 'utilisateur_id');
    }

    /**
     * Prévisions budgétaires créées par l'utilisateur
     */
    public function previsionsBudgetaires()
    {
        return $this->hasMany(PrevisionBudgetaire::class, 'utilisateur_id');
    }

    /**
     * Validations effectuées par l'utilisateur
     */
    public function validations()
    {
        return $this->hasMany(ValidationPrevision::class, 'validateur_id');
    }

    /**
     * Écritures validées par l'utilisateur
     */
    public function ecrituresValidees()
    {
        return $this->hasMany(EcrituresComptable::class, 'valide_par');
    }

    /**
     * Rapprochements bancaires effectués par l'utilisateur
     */
    public function rapprochementsBancaires()
    {
        return $this->hasMany(RapprochementBancaire::class, 'utilisateur_id');
    }

    /**
     * Immobilisations gérées par l'utilisateur
     */
    public function immobilisations()
    {
        return $this->hasMany(Immobilisation::class, 'utilisateur_id');
    }

    /**
     * Déclarations TVA effectuées par l'utilisateur
     */
    public function declarationsTva()
    {
        return $this->hasMany(DeclarationTva::class, 'utilisateur_id');
    }

    /**
     * Bilans créés par l'utilisateur
     */
    public function bilans()
    {
        return $this->hasMany(Bilan::class, 'utilisateur_id');
    }

    /**
     * Sessions actives de l'utilisateur
     */
    public function sessions()
    {
        return $this->hasMany(SessionUtilisateur::class, 'utilisateur_id');
    }

        public function agentDsa()
    {
        return $this->hasOne(\App\Models\AgentDsa::class, 'utilisateur_id');
    }

    // ============================================================
    // ATTRIBUTS
    // ============================================================

    public function getInitialesAttribute(): string
    {
        $words = explode(' ', $this->nom_complet);
        $initiales = '';
        foreach ($words as $word) {
            if (!empty($word)) {
                $initiales .= strtoupper(substr($word, 0, 1));
            }
        }
        return substr($initiales, 0, 2);
    }
    
    public static function getUtilisateursByIds(array $ids){
        // 1. Aplatit les tableaux imbriqués (ex: [[1], [2]] devient)
        // // 2. Supprime les valeurs nulles, vides ou non numériques
        // // 3. Force la conversion en entiers (integer)
        $cleanIds = collect($ids)
        ->flatten()
        ->filter(function ($value) {
            return is_numeric($value);
        })
        ->map(function ($value) 
        {return (int) $value;
        })
        ->unique()
        ->toArray();
        // Si le tableau est vide après nettoyage, on retourne une collection vide
        // Cela évite d'exécuter une requête SQL inutile
        if (empty($cleanIds)) {
            return collect();}
            
        // Récupère uniquement les colonnes nécessaires pour les IDs nettoyés
            return self::whereIn('id', $cleanIds)
                ->select('id', 'nom_complet', 'nom_utilisateur', 'matricule')
                ->get()
                ->keyBy('id'); // Facilite la recherche par ID plus tard
    }

    public function getEstActifAttribute(): bool
    {
        return $this->statut === 'ACTIF' && !$this->compte_verrouille;
    }

    public function getEstEnLigneAttribute(): bool
    {
        return Cache::has('user-is-online-' . $this->id);
    }

    public function getDerniereActiviteAttribute()
    {
        return $this->date_derniere_connexion;
    }

    public function getPermissionsListeAttribute()
    {
        return $this->role ? $this->role->permissions->pluck('permission_code') : collect();
    }

    public function getQrCode2faAttribute()
    {
        if (!$this->two_factor_secret) {
            return null;
        }

        $google2fa = new Google2FA();

        return $google2fa->getQRCodeUrl(
            config('app.name'),
            $this->email,
            $this->two_factor_secret
        );
    }

    // 🆕 NOUVEAUX ATTRIBUTS AJOUTÉS
    // ============================================================

    /**
     * Nombre de notifications non lues
     */
    public function getNombreNotificationsNonLuesAttribute()
    {
        return $this->notificationsNonLues()->count();
    }

    /**
     * Vérifier si l'utilisateur a des notifications non lues
     */
    public function getHasNotificationsNonLuesAttribute()
    {
        return $this->notificationsNonLues()->exists();
    }

    /**
     * Dernières notifications (5 plus récentes)
     */
    public function getDernieresNotificationsAttribute()
    {
        return $this->notificationsComptables()
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();
    }

    /**
     * Nombre total d'écritures créées
     */
    public function getNombreEcrituresAttribute()
    {
        return $this->ecrituresComptables()->count();
    }

    /**
     * Nombre d'écritures en attente de validation
     */
          public function getNombreEcrituresEnAttenteAttribute()
    {
        return $this->ecrituresComptables()->count();
    }
    // ============================================================
    // SCOPES
    // ============================================================

    public function scopeActifs($query)
    {
        return $query->where('statut', 'ACTIF')
            ->where('compte_verrouille', false);
    }

    public function scopeParSuccursale($query, $succursaleId)
    {
        return $query->where('succursale_id', $succursaleId);
    }

    public function scopeParRole($query, $roleId)
    {
        return $query->where('role_id', $roleId);
    }

    // 🆕 NOUVEAUX SCOPES AJOUTÉS
    // ============================================================

    /**
     * Utilisateurs avec notifications non lues
     */
    public function scopeAvecNotificationsNonLues($query)
    {
        return $query->whereHas('notificationsComptables', function ($q) {
            $q->whereNull('read_at');
        });
    }

    /**
     * Utilisateurs en ligne
     */
    public function scopeEnLigne($query)
    {
        return $query->where('date_derniere_connexion', '>=', now()->subMinutes(5));
    }

    /**
     * Utilisateurs avec compte expiré
     */
    public function scopeCompteExpire($query)
    {
        return $query->where('date_expiration_mdp', '<', now());
    }

    // ============================================================
    // MÉTHODES D'AUTHENTIFICATION
    // ============================================================

    public function verifierMotDePasse($password): bool
    {
        return Hash::check($password, $this->password_hash);
    }

    public function setMotDePasse($password): void
    {
        $this->password_hash = Hash::make($password);
        $this->date_expiration_mdp = now()->addDays(90);
        $this->tentatives_echec = 0;
        $this->save();

        $this->logAudit('CHANGEMENT_MDP', null, ['date_expiration' => $this->date_expiration_mdp]);
    }

    public function incrementerTentatives(): self
    {
        $this->increment('tentatives_echec');

        if ($this->tentatives_echec >= config('auth.max_login_attempts', 5)) {
            $this->compte_verrouille = true;
            $this->save();

            $this->logAudit('COMPTE_VERROUILLE', null, [
                'raison' => 'Trop de tentatives échouées',
                'tentatives' => $this->tentatives_echec
            ]);
        }

        return $this;
    }

    public function resetTentatives(): self
    {
        $this->tentatives_echec = 0;
        $this->compte_verrouille = false;
        $this->save();

        return $this;
    }

    public function enregistrerConnexion($request): void
    {
        $this->date_derniere_connexion = now();
        $this->tentatives_echec = 0;
        $this->save();

        $this->logAudit('CONNEXION', null, [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent()
        ]);

        Cache::put('user-is-online-' . $this->id, true, now()->addMinutes(5));
    }

    public function deconnecter($request): void
    {
        $this->logAudit('DECONNEXION', null, [
            'ip' => $request->ip()
        ]);

        Cache::forget('user-is-online-' . $this->id);
    }

    public function deconnecterToutesSessions($exceptCurrent = true): void
    {
        $this->logAudit('DECONNEXION_TOTALE', null, [
            'except_current' => $exceptCurrent
        ]);

        Cache::forget('user-is-online-' . $this->id);
    }

    public function revokeCurrentToken()
    {
        $token = $this->currentAccessToken();
        if ($token) {
            return $this->tokens()->where('id', $token->id)->delete();
        }
        return false;
    }

    public function revokeAllTokens()
    {
        return $this->tokens()->delete();
    }

    // ============================================================
    // 2FA - GOOGLE AUTHENTICATOR
    // ============================================================

    public function activer2FA($secret): void
    {
        $this->two_factor_secret = $secret;
        $this->two_factor_authentification = false;
        $this->save();

        $this->logAudit('2FA_ACTIVEE', null, null);
    }

    public function confirmer2FA(): void
    {
        $this->two_factor_authentification = true;
        $this->two_factor_confirmed_at = now();
        $this->save();

        $this->logAudit('2FA_CONFIRMEE', null, null);
    }

    public function desactiver2FA(): void
    {
        $this->two_factor_secret = null;
        $this->two_factor_authentification = false;
        $this->two_factor_confirmed_at = null;
        $this->two_factor_recovery_codes = null;
        $this->save();

        $this->logAudit('2FA_DESACTIVEE', null, null);
    }

    public function verifier2FA($code): bool
    {
        if (!$this->two_factor_secret) {
            return false;
        }

        $google2fa = new Google2FA();

        return $google2fa->verifyKey($this->two_factor_secret, $code);
    }

    public function verifier2FAActive($code): bool
    {
        if (!$this->two_factor_authentification || !$this->two_factor_secret) {
            return true;
        }

        $google2fa = new Google2FA();

        return $google2fa->verifyKey($this->two_factor_secret, $code);
    }

    public function genererCodesRecuperation(): array
    {
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = strtoupper(\Illuminate\Support\Str::random(10));
        }

        $this->two_factor_recovery_codes = json_encode($codes);
        $this->save();

        return $codes;
    }

    public function verifierCodeRecuperation($code): bool
    {
        if (!$this->two_factor_recovery_codes) {
            return false;
        }

        $codes = json_decode($this->two_factor_recovery_codes, true);
        $key = array_search($code, $codes);

        if ($key !== false) {
            unset($codes[$key]);
            $this->two_factor_recovery_codes = json_encode(array_values($codes));
            $this->save();

            return true;
        }

        return false;
    }

    // ============================================================
    // 2FA - EMAIL (VERSION CACHE)
    // ============================================================

    /**
     * Activer le 2FA par email
     */
    public function activer2FAEmail(): void
    {
        $this->two_factor_email_enabled = true;
        $this->save();

        $this->logAudit('2FA_EMAIL_ACTIVEE', null, null);
    }

    /**
     * Désactiver le 2FA par email
     */
    public function desactiver2FAEmail(): void
    {
        $this->two_factor_email_enabled = false;
        // Nettoyer le cache
        Cache::forget('2fa_email_' . $this->id);
        $this->save();

        $this->logAudit('2FA_EMAIL_DESACTIVEE', null, null);
    }

    /**
     * Générer et envoyer un code 2FA par email
     */
    public function genererEtEnvoyerCode2FAEmail()
    {
        // Générer un code à 6 chiffres
        $code = sprintf("%06d", random_int(0, 999999));
        
        // Stocker dans le cache avec expiration de 10 minutes
        Cache::put('2fa_email_' . $this->id, [
            'code' => Hash::make($code),
            'expires_at' => now()->addMinutes(10)
        ], now()->addMinutes(10));
        
        // Envoi en arrière-plan (ne bloque pas)
        dispatch(function () use ($code) {
            try {
                Mail::to($this->email)->send(new TwoFactorCodeMail($code, $this));
                
                Log::info('Email 2FA envoyé avec succès', [
                    'user_id' => $this->id,
                    'email' => $this->email
                ]);
            } catch (\Exception $e) {
                Log::error('Erreur envoi email 2FA', [
                    'user_id' => $this->id,
                    'error' => $e->getMessage()
                ]);
            }
        })->afterResponse();
        
        return true;
    }

    /**
     * Vérifier le code 2FA email
     */
    public function verifierCode2FAEmail($code): bool
    {
        $cached = Cache::get('2fa_email_' . $this->id);
        
        if (!$cached) {
            Log::warning('Code 2FA non trouvé dans le cache', [
                'user_id' => $this->id
            ]);
            return false;
        }
        
        // Vérifier si le code a expiré
        if (now()->gt($cached['expires_at'])) {
            Cache::forget('2fa_email_' . $this->id);
            Log::warning('Code 2FA expiré', [
                'user_id' => $this->id,
                'expires_at' => $cached['expires_at']
            ]);
            return false;
        }
        
        // Vérifier le code
        if (Hash::check($code, $cached['code'])) {
            // Effacer le cache après utilisation
            Cache::forget('2fa_email_' . $this->id);
            
            Log::info('Code 2FA vérifié avec succès', [
                'user_id' => $this->id
            ]);
            
            return true;
        }
        
        Log::warning('Code 2FA invalide', [
            'user_id' => $this->id,
            'code_soumis' => $code
        ]);
        
        return false;
    }

    // ============================================================
    // MÉTHODES HELPER POUR LES RÔLES
    // ============================================================

    public function isSuperAdmin(): bool
    {
        return $this->role && $this->role->code === 'SUPER_ADMIN';
    }

    public function isAuditeur(): bool
    {
        return $this->role && $this->role->code === 'AUDITEUR';
    }

    public function isComptableHolding(): bool
    {
        return $this->role && $this->role->code === 'COMPTABLE_HOLDING';
    }

    public function isChefAgence(): bool
    {
        return $this->role && $this->role->code === 'CHEF_AGENCE';
    }

    public function isCaissier(): bool
    {
        return $this->role && $this->role->code === 'CAISSIER';
    }

    public function isChefOperation(): bool
    {
        return $this->role && $this->role->code === 'CHEF_OPERATIONS';
    }

    public function hasRole($roleCode): bool
    {
        if (is_array($roleCode)) {
            return $this->role && in_array($this->role->code, $roleCode);
        }
        
        return $this->role && $this->role->code === $roleCode;
    }

    public function getNiveauAccesAttribute(): int
    {
        return $this->role?->niveau_acces ?? 0;
    }

    public function hasMinimumLevel(int $requiredLevel): bool
    {
        return $this->niveau_acces >= $requiredLevel;
    }

    // ============================================================
    // MÉTHODES DE PERMISSIONS
    // ============================================================

    public function hasPermission($permission): bool
    {
        return $this->role && $this->role->hasPermission($permission);
    }

    public function hasAnyPermission($permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    public function hasAllPermissions($permissions): bool
    {
        foreach ($permissions as $permission) {
            if (!$this->hasPermission($permission)) {
                return false;
            }
        }

        return true;
    }

    public function peutAccederSuccursale($succursaleId): bool
    {
        return $this->hasPermission('view-all-succursales') ||
            $this->succursale_id == $succursaleId;
    }

    // 🆕 NOUVELLES MÉTHODES DE NOTIFICATIONS
    // ============================================================

    /**
     * Marquer toutes les notifications comme lues
     */
    public function marquerToutesNotificationsLues()
    {
        return $this->notificationsComptables()
            ->unread()
            ->update(['read_at' => now()]);
    }

    /**
     * Supprimer les notifications lues depuis plus de 5 jours
     */
    public function nettoyerVieillesNotifications()
    {
        return $this->notificationsComptables()
            ->shouldDelete()
            ->delete();
    }

    /**
     * Créer une notification pour cet utilisateur
     */
    public function creerNotification($data)
    {
        return $this->notificationsComptables()->create($data);
    }

    /**
     * Envoyer une notification temps réel
     */
    public function envoyerNotificationTempsReel($notification)
    {
        broadcast(new \App\Events\NotificationComptableEvent($notification, $this->id));
    }

    // ============================================================
    // TO ARRAY
    // ============================================================

    public function toArray()
    {
        $array = parent::toArray();
        $array['initiales'] = $this->initiales;
        $array['est_actif'] = $this->est_actif;
        $array['est_en_ligne'] = $this->est_en_ligne;
        $array['derniere_activite'] = $this->derniere_activite;
        $array['permissions'] = $this->permissions_liste;
        $array['qr_code_2fa'] = $this->qr_code_2fa;
        $array['two_factor_email_enabled'] = $this->two_factor_email_enabled;
        $array['two_factor_authentification'] = $this->two_factor_authentification;
        
        // 🆕 Nouvelles propriétés ajoutées
        $array['nombre_notifications_non_lues'] = $this->nombre_notifications_non_lues;
        $array['nombre_ecritures_en_attente'] = $this->nombre_ecritures_en_attente;

        return $array;
    }
    
   // RAPHAEL MODIFICATION
   
    public static function extrairePrenom(string $nomComplet): string
    {
        // Retourne tout ce qui précède le premier espace
        return Str::before($nomComplet, ' ');
    }
    
    public static function extraireServiceCoffre(string $nomCoffre): string
    {
        // Recupère tout ce qui suit le premier espace
        $nom = Str::after($nomCoffre, ' ');

        // Appelle la fonction extrairePrenom pour obtenir le prénom
        $service = self::extrairePrenom($nom);

        return $service;
    }
}