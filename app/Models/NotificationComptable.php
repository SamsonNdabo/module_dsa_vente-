<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class NotificationComptable extends Model
{
    use SoftDeletes;

    protected $table = 'notification_comptable';
    
    protected $fillable = [
        'utilisateur_id',
        'type',
        'severity',
        'icon',
        'titre',
        'message',
        'action_label',
        'action_url',
        'action_required',
        'metadata',
        'read_at',
    ];

    protected $casts = [
        'action_required' => 'boolean',
        'metadata' => 'array',
        'read_at' => 'datetime',
        'deleted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $dates = [
        'read_at',
        'deleted_at',
        'created_at',
        'updated_at',
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
     * Scope pour les notifications non lues
     */
    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    /**
     * Scope pour les notifications lues
     */
    public function scopeRead($query)
    {
        return $query->whereNotNull('read_at');
    }

    /**
     * Scope pour un type spécifique
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope pour une sévérité spécifique
     */
    public function scopeOfSeverity($query, $severity)
    {
        return $query->where('severity', $severity);
    }

    /**
     * Scope pour les notifications avec action requise
     */
    public function scopeActionRequired($query)
    {
        return $query->where('action_required', true);
    }

    /**
     * Scope pour les notifications récentes
     */
    public function scopeRecent($query, $hours = 24)
    {
        return $query->where('created_at', '>=', now()->subHours($hours));
    }

    /**
     * Scope pour les notifications à supprimer (lues depuis plus de 5 jours)
     */
    public function scopeShouldDelete($query)
    {
        return $query->whereNotNull('read_at')
            ->where('read_at', '<', now()->subDays(5));
    }

    /**
     * Scope pour un utilisateur spécifique
     */
    public function scopeForUser($query, $utilisateurId)
    {
        return $query->where('utilisateur_id', $utilisateurId);
    }

    // =========================================================================
    // MÉTHODES
    // =========================================================================
    
    /**
     * Marquer comme lue
     */
    public function markAsRead()
    {
        $this->update(['read_at' => now()]);
        return $this;
    }

    /**
     * Marquer comme non lue
     */
    public function markAsUnread()
    {
        $this->update(['read_at' => null]);
        return $this;
    }

    /**
     * Vérifier si la notification est lue
     */
    public function isRead()
    {
        return !is_null($this->read_at);
    }

    /**
     * Vérifier si la notification n'est pas lue
     */
    public function isUnread()
    {
        return is_null($this->read_at);
    }

    /**
     * Vérifier si la notification doit être supprimée
     */
    public function shouldBeDeleted()
    {
        return $this->isRead() && $this->read_at->diffInDays(now()) >= 5;
    }

    /**
     * Vérifier si la notification est récente
     */
    public function isRecent($hours = 24)
    {
        return $this->created_at->diffInHours(now()) <= $hours;
    }

    /**
     * Vérifier si une action est requise
     */
    public function hasActionRequired()
    {
        return $this->action_required === true;
    }

    /**
     * Formater la date de création
     */
    public function getHumanDateAttribute()
    {
        return $this->created_at->diffForHumans();
    }

    /**
     * Formater la date de lecture
     */
    public function getHumanReadDateAttribute()
    {
        return $this->read_at ? $this->read_at->diffForHumans() : null;
    }

    /**
     * Obtenir la couleur selon la sévérité
     */
    public function getSeverityColorAttribute()
    {
        return match($this->severity) {
            'info' => 'blue',
            'warning' => 'orange',
            'danger' => 'red',
            'critical' => 'darkred',
            'success' => 'green',
            default => 'gray'
        };
    }

    /**
     * Obtenir la classe CSS selon le type
     */
    public function getTypeClassAttribute()
    {
        return match($this->type) {
            'system_alert' => 'notification-system',
            'ecriture' => 'notification-ecriture',
            'budget_alert' => 'notification-budget',
            'suggestion' => 'notification-suggestion',
            'anomalie' => 'notification-anomalie',
            default => 'notification-default'
        };
    }
}