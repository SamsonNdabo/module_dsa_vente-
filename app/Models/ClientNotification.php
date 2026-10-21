<?php
// app/Models/ClientNotification.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientNotification extends Model
{
    use HasFactory;

    protected $table = 'client_notifications';

    const TYPES = [
        'WELCOME' => 'Bienvenue',
        'KYC_VALIDE' => 'KYC Validé',
        'KYC_REJETE' => 'KYC Rejeté',
        'COMMANDE_CREE' => 'Commande créée',
        'COMMANDE_PAYEE' => 'Commande payée',
        'COMMANDE_EXPEDIEE' => 'Commande expédiée',
        'COMMANDE_LIVREE' => 'Commande livrée',
        'COMMANDE_ANNULEE' => 'Commande annulée',
        'PAIEMENT_RECU' => 'Paiement reçu',
        'PAIEMENT_ECHOUE' => 'Paiement échoué',
        'LIVRAISON_EN_COURS' => 'Livraison en cours',
        'LIVRAISON_LIVREE' => 'Livraison effectuée',
        'PASSWORD_CHANGED' => 'Mot de passe changé',
        'ACCOUNT_ACTIVATED' => 'Compte activé',
        'ACCOUNT_BLOCKED' => 'Compte bloqué'
    ];

    protected $fillable = [
        'client_id',
        'type',
        'titre',
        'message',
        'data',
        'est_lu',
        'lu_at',
        'sent_at'
    ];

    protected $casts = [
        'data' => 'array',
        'est_lu' => 'boolean',
        'lu_at' => 'datetime',
        'sent_at' => 'datetime'
    ];

    protected $appends = ['type_label', 'est_lu_label'];

    // ============================================================
    // RELATIONS
    // ============================================================

    /**
     * Relation avec le client
     */
    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    // ============================================================
    // ACCESSORS
    // ============================================================

    /**
     * Libellé du type de notification
     */
    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    /**
     * Libellé du statut de lecture
     */
    public function getEstLuLabelAttribute(): string
    {
        return $this->est_lu ? 'Lu' : 'Non lu';
    }

    /**
     * Temps écoulé depuis l'envoi
     */
    public function getTempsEcouleAttribute(): string
    {
        if (!$this->sent_at) return 'Non envoyée';
        
        $diff = $this->sent_at->diffForHumans();
        return $diff;
    }

    // ============================================================
    // MÉTHODES
    // ============================================================

    /**
     * Marquer la notification comme lue
     */
    public function marquerCommeLu(): void
    {
        if (!$this->est_lu) {
            $this->est_lu = true;
            $this->lu_at = now();
            $this->save();
        }
    }

    /**
     * Marquer la notification comme non lue
     */
    public function marquerCommeNonLu(): void
    {
        if ($this->est_lu) {
            $this->est_lu = false;
            $this->lu_at = null;
            $this->save();
        }
    }

    /**
     * Envoyer la notification (marquer comme envoyée)
     */
    public function marquerCommeEnvoyee(): void
    {
        if (!$this->sent_at) {
            $this->sent_at = now();
            $this->save();
        }
    }

    // ============================================================
    // SCOPES
    // ============================================================

    /**
     * Filtrer les notifications non lues
     */
    public function scopeNonLues($query)
    {
        return $query->where('est_lu', false);
    }

    /**
     * Filtrer les notifications lues
     */
    public function scopeLues($query)
    {
        return $query->where('est_lu', true);
    }

    /**
     * Filtrer par type
     */
    public function scopeParType($query, $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Filtrer par période
     */
    public function scopeParPeriode($query, $debut, $fin)
    {
        return $query->whereBetween('created_at', [$debut, $fin]);
    }

    /**
     * Notifications récentes (derniers 7 jours)
     */
    public function scopeRecentes($query)
    {
        return $query->where('created_at', '>=', now()->subDays(7));
    }

    /**
     * Notifications non envoyées
     */
    public function scopeNonEnvoyees($query)
    {
        return $query->whereNull('sent_at');
    }

    // ============================================================
    // MÉTHODES STATIQUES
    // ============================================================

    /**
     * Créer une notification pour un client
     */
    public static function creer(Client $client, string $type, string $titre, string $message, array $data = []): self
    {
        $notification = self::create([
            'client_id' => $client->id,
            'type' => $type,
            'titre' => $titre,
            'message' => $message,
            'data' => $data,
            'sent_at' => now()
        ]);

        return $notification;
    }

    /**
     * Marquer toutes les notifications d'un client comme lues
     */
    public static function marquerToutesLues(Client $client): int
    {
        return self::where('client_id', $client->id)
            ->where('est_lu', false)
            ->update([
                'est_lu' => true,
                'lu_at' => now()
            ]);
    }

    /**
     * Supprimer les notifications anciennes (plus de 90 jours)
     */
    public static function nettoyerAnciennes(): int
    {
        return self::where('created_at', '<', now()->subDays(90))->delete();
    }
}