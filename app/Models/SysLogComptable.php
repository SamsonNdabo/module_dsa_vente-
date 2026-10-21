<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SysLogComptable extends Model
{
    protected $table = 'sys_log_comptable';
    
    public $timestamps = false;
    
    protected $fillable = [
        'utilisateur_id',
        'action',
        'description',
        'ip_address',
        'user_agent',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    // =========================================================================
    // RELATIONS
    // =========================================================================
    
    /**
     * Relation avec l'utilisateur
     */
    public function utilisateur()
    {
        return $this->belongsTo(Utilisateur::class, 'utilisateur_id');
    }

    // =========================================================================
    // SCOPES
    // =========================================================================
    
    /**
     * Scope pour un utilisateur spécifique
     */
    public function scopeForUser($query, $utilisateurId)
    {
        return $query->where('utilisateur_id', $utilisateurId);
    }

    /**
     * Scope pour une action spécifique
     */
    public function scopeForAction($query, $action)
    {
        return $query->where('action', $action);
    }

    /**
     * Scope pour les logs récents
     */
    public function scopeRecent($query, $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * Scope pour les logs d'aujourd'hui
     */
    public function scopeToday($query)
    {
        return $query->whereDate('created_at', today());
    }

    /**
     * Scope pour les logs de ce mois
     */
    public function scopeThisMonth($query)
    {
        return $query->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year);
    }

    /**
     * Scope pour les logs par IP
     */
    public function scopeFromIp($query, $ip)
    {
        return $query->where('ip_address', $ip);
    }

    /**
     * Scope pour les logs critiques
     */
    public function scopeCritical($query)
    {
        $criticalActions = ['erreur_systeme', 'tentative_intrusion', 'echec_connexion', 'violation_acces'];
        return $query->whereIn('action', $criticalActions);
    }

    // =========================================================================
    // MÉTHODES
    // =========================================================================
    
    /**
     * Formater la date
     */
    public function getFormattedDateAttribute()
    {
        return $this->created_at->format('d/m/Y H:i:s');
    }

    /**
     * Date relative
     */
    public function getHumanDateAttribute()
    {
        return $this->created_at->diffForHumans();
    }

    /**
     * Vérifier si le log est récent
     */
    public function isRecent($hours = 24)
    {
        return $this->created_at->diffInHours(now()) <= $hours;
    }

    /**
     * Obtenir l'icône selon l'action
     */
    public function getActionIconAttribute()
    {
        return match($this->action) {
            'connexion' => '🔑',
            'deconnexion' => '🚪',
            'creation_ecriture' => '📝',
            'validation_ecriture' => '✅',
            'rejet_ecriture' => '❌',
            'cloture_periode' => '🔒',
            'nettoyage_notifications' => '🧹',
            'export_donnees' => '📤',
            'import_donnees' => '📥',
            'modification_compte' => '✏️',
            'suppression' => '🗑️',
            'erreur_systeme' => '💥',
            default => '📋'
        };
    }

    /**
     * Obtenir la couleur selon l'action
     */
    public function getActionColorAttribute()
    {
        return match($this->action) {
            'connexion', 'deconnexion' => 'blue',
            'creation_ecriture', 'import_donnees' => 'green',
            'validation_ecriture' => 'success',
            'rejet_ecriture', 'suppression' => 'red',
            'cloture_periode' => 'purple',
            'nettoyage_notifications' => 'gray',
            'export_donnees' => 'orange',
            'erreur_systeme', 'tentative_intrusion' => 'darkred',
            default => 'default'
        };
    }

    /**
     * Créer un log rapidement
     */
    public static function quickLog($utilisateurId, $action, $description, $metadata = null)
    {
        return self::create([
            'utilisateur_id' => $utilisateurId,
            'action' => $action,
            'description' => $description,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}