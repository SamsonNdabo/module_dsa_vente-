<?php
// app/Models/NotificationStock.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationStock extends Model
{
    use HasFactory;

    protected $table = 'notifications_stock';

    protected $fillable = [
        'utilisateur_id',
        'utilisateur_ids', 
        'succursale_id',
        'produit_id',
        'type',
        'titre',
        'message',
        'donnees',
        'est_lue',
        'date_lecture',
        'est_envoyee_email',
        'date_envoi_email',
        'destinataire_type',
        'role_cible',
        'priorite'
    ];

    protected $casts = [
        'donnees' => 'array',
        'utilisateur_ids' => 'array',
        'est_lue' => 'boolean',
        'est_envoyee_email' => 'boolean',
        'date_lecture' => 'datetime',
        'date_envoi_email' => 'datetime'
    ];

    protected $appends = [
        'libelle_type',
        'couleur_type',
        'temps_ecoule',
        'icone',
        'destinataire_libelle'
    ];

    // ============================================================
    // CONSTANTES
    // ============================================================

    const TYPES = [
        'ALERTE_STOCK' => 'Alerte stock',
        'RUPTURE' => 'Rupture de stock',
        'SEUIL_CRITIQUE' => 'Seuil critique atteint',
        'RETOUR_NORMAL' => 'Retour à la normale',
        
        'APPROVISIONNEMENT_CREE' => 'Demande créée',
        'APPROVISIONNEMENT_VALIDE' => 'Demande validée',
        'APPROVISIONNEMENT_EXPEDIE' => 'Demande expédiée',
        'APPROVISIONNEMENT_RECU' => 'Demande reçue',
        'APPROVISIONNEMENT_ANNULE' => 'Demande annulée',
        
        'DEMANDE_APPROVISIONNEMENT' => 'Demande d\'approvisionnement',
        'DEMANDE_APPRO_A_L_AVEUGLE' => 'Demande à l\'aveugle',
        'DEMANDE_APPRO_PRISE' => 'Demande prise en charge',
        'DEMANDE_TRANSFERT' => 'Demande de transfert',
        'DEMANDE_TRANSFERT_A_L_AVEUGLE' => 'Transfert à l\'aveugle',
        'DEMANDE_TRANSFERT_PRISE' => 'Transfert pris en charge',
        'DEMANDE_EXPEDIEE' => 'Demande expédiée',

        
        'MOUVEMENT_CREE' => 'Mouvement créé',
        'MOUVEMENT_VALIDE' => 'Mouvement validé',
        'MOUVEMENT_ANNULE' => 'Mouvement annulé',
        
        'ACHAT_CREE' => 'Achat créé',
        'ACHAT_VALIDE' => 'Achat validé',
        'ACHAT_RECU' => 'Achat reçu',
        'ACHAT_ANNULE' => 'Achat annulé',
        
        'PAIEMENT_EFFECTUE' => 'Paiement effectué',
        'PAIEMENT_VALIDE' => 'Paiement validé',
        'PAIEMENT_ECHOUE' => 'Paiement échoué',
        
        'INVENTAIRE_REALISE' => 'Inventaire réalisé',
        'ECART_INVENTAIRE' => 'Écart d\'inventaire détecté',
        
        'VENTE_EFFECTUEE' => 'Vente effectuée',
        'VENTE_ANNULEE' => 'Vente annulée',
        'FACTURE_EMISE' => 'Facture émise',
        'FACTURE_PAYEE' => 'Facture payée',
        
        'TRANSFERT_PREPARE' => 'Transfert préparé',
        'TRANSFERT_EXPEDIE' => 'Transfert expédié',
        'TRANSFERT_EN_TRANSIT' => 'Transfert en transit',
        'TRANSFERT_INTERMEDIAIRE' => 'Transfert arrivé au point intermédiaire',
        'TRANSFERT_EN_ROUTE' => 'Transfert en route vers destination',
        'TRANSFERT_RECU' => 'Transfert reçu',
        'TRANSFERT_ANNULE' => 'Transfert annulé',
        'ECART_TRANSFERT' => 'Écart détecté sur transfert',
        'RAPPEL_TRANSFERT' => 'Rappel: Transfert en attente'
    ];

    const COULEURS = [
        'ALERTE_STOCK' => 'warning',
        'RUPTURE' => 'danger',
        'SEUIL_CRITIQUE' => 'danger',
        'RETOUR_NORMAL' => 'success',
        
        'APPROVISIONNEMENT_CREE' => 'info',
        'APPROVISIONNEMENT_VALIDE' => 'success',
        'APPROVISIONNEMENT_EXPEDIE' => 'primary',
        'APPROVISIONNEMENT_RECU' => 'success',
        'APPROVISIONNEMENT_ANNULE' => 'danger',
        
        'DEMANDE_APPROVISIONNEMENT' => 'info',
        'DEMANDE_APPRO_A_L_AVEUGLE' => 'warning',
        'DEMANDE_APPRO_PRISE' => 'success',
        'DEMANDE_TRANSFERT' => 'info',
        'DEMANDE_TRANSFERT_A_L_AVEUGLE' => 'warning',
        'DEMANDE_TRANSFERT_PRISE' => 'success',
        'DEMANDE_EXPEDIEE' => 'primary',
        
        'MOUVEMENT_CREE' => 'info',
        'MOUVEMENT_VALIDE' => 'success',
        'MOUVEMENT_ANNULE' => 'danger',
        
        'ACHAT_CREE' => 'info',
        'ACHAT_VALIDE' => 'success',
        'ACHAT_RECU' => 'success',
        'ACHAT_ANNULE' => 'danger',
        
        'PAIEMENT_EFFECTUE' => 'success',
        'PAIEMENT_VALIDE' => 'success',
        'PAIEMENT_ECHOUE' => 'danger',
        
        'INVENTAIRE_REALISE' => 'primary',
        'ECART_INVENTAIRE' => 'warning',
        
        'VENTE_EFFECTUEE' => 'success',
        'VENTE_ANNULEE' => 'danger',
        'FACTURE_EMISE' => 'primary',
        'FACTURE_PAYEE' => 'success',
        
        'TRANSFERT_PREPARE' => 'primary',
        'TRANSFERT_EXPEDIE' => 'warning',
        'TRANSFERT_EN_TRANSIT' => 'info',
        'TRANSFERT_INTERMEDIAIRE' => 'info',
        'TRANSFERT_EN_ROUTE' => 'primary',
        'TRANSFERT_RECU' => 'success',
        'TRANSFERT_ANNULE' => 'danger',
        'ECART_TRANSFERT' => 'danger',
        'RAPPEL_TRANSFERT' => 'warning'
    ];

    const DESTINATAIRE_TYPES = [
        'utilisateur' => 'Utilisateur spécifique',
        'multiple' => 'Plusieurs utilisateurs',
        'succursale' => 'Toute une succursale',
        'role' => 'Tous les utilisateurs d\'un rôle',
        'public' => 'Public (tous)'
    ];

    const PRIORITES = [
        'BASSE' => 'Basse',
        'NORMALE' => 'Normale',
        'HAUTE' => 'Haute',
        'URGENTE' => 'Urgente'
    ];

    // ============================================================
    // RELATIONS
    // ============================================================

    public function utilisateur()
    {
        return $this->belongsTo(Utilisateur::class, 'utilisateur_id');
    }

    public function succursale()
    {
        return $this->belongsTo(Succursale::class, 'succursale_id');
    }

    public function produit()
    {
        return $this->belongsTo(Produit::class, 'produit_id');
    }

    // ============================================================
    // ATTRIBUTS
    // ============================================================

    public function getLibelleTypeAttribute()
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function getCouleurTypeAttribute()
    {
        return self::COULEURS[$this->type] ?? 'secondary';
    }

    public function getTempsEcouleAttribute()
    {
        return $this->created_at ? $this->created_at->diffForHumans() : null;
    }

    public function getIconeAttribute()
    {
        $icones = [
            'ALERTE_STOCK' => 'fa-exclamation-triangle',
            'RUPTURE' => 'fa-times-circle',
            'SEUIL_CRITIQUE' => 'fa-chart-line',
            'APPROVISIONNEMENT' => 'fa-truck',
            'TRANSFERT' => 'fa-exchange-alt',
            'MOUVEMENT' => 'fa-arrows-alt',
            'ACHAT' => 'fa-shopping-cart',
            'VENTE' => 'fa-cash-register',
            'default' => 'fa-bell'
        ];
        
        foreach ($icones as $key => $icone) {
            if (str_contains($this->type, $key)) {
                return $icone;
            }
        }
        
        return $icones['default'];
    }

    public function getDestinataireLibelleAttribute()
    {
        return match($this->destinataire_type) {
            'utilisateur' => $this->utilisateur?->nom_complet ?? 'Utilisateur inconnu',
            'multiple' => count($this->utilisateur_ids ?? []) . ' utilisateurs',
            'succursale' => 'Succursale: ' . ($this->succursale?->nom ?? 'Inconnue'),
            'role' => 'Rôle: ' . ($this->role_cible ?? 'Inconnu'),
            'public' => 'Tous les utilisateurs',
            default => 'Destinataire inconnu'
        };
    }

    public function getDestinatairesAttribute()
    {
        return match($this->destinataire_type) {
            'utilisateur' => $this->utilisateur_id ? [$this->utilisateur_id] : [],
            'multiple' => $this->utilisateur_ids ?? [],
            'succursale' => Utilisateur::where('succursale_id', $this->succursale_id)
                ->where('statut', 'ACTIF')
                ->pluck('id')
                ->toArray(),
            'role' => Utilisateur::whereHas('role', function($q) {
                    $q->where('code', $this->role_cible);
                })
                ->where('statut', 'ACTIF')
                ->pluck('id')
                ->toArray(),
            'public' => Utilisateur::where('statut', 'ACTIF')->pluck('id')->toArray(),
            default => []
        };
    }

    // ============================================================
    // MÉTHODES STATIQUES PRINCIPALES
    // ============================================================

    /**
     * Notifier un utilisateur spécifique
     */
    public static function notifierUtilisateur($userId, $type, $titre, $message, $donnees = null, $succursaleId = null, $produitId = null, $priorite = 'NORMALE')
    {
        try {
            return self::create([
                'utilisateur_id' => $userId,
                'destinataire_type' => 'utilisateur',
                'succursale_id' => $succursaleId,
                'produit_id' => $produitId,
                'type' => $type,
                'titre' => $titre,
                'message' => $message,
                'donnees' => $donnees,
                'priorite' => $priorite,
                'est_lue' => false
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Erreur création notification utilisateur', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'type' => $type
            ]);
            return null;
        }
    }

    /**
     * Notifier plusieurs utilisateurs spécifiques
     */
    public static function notifierUtilisateurs($userIds, $type, $titre, $message, $donnees = null, $succursaleId = null, $produitId = null, $priorite = 'NORMALE')
    {
        try {
            if (empty($userIds)) {
                \Illuminate\Support\Facades\Log::warning('Tentative de notification sans destinataires', ['type' => $type]);
                return null;
            }
            
            return self::create([
                'utilisateur_ids' => $userIds,
                'destinataire_type' => 'multiple',
                'succursale_id' => $succursaleId,
                'produit_id' => $produitId,
                'type' => $type,
                'titre' => $titre,
                'message' => $message,
                'donnees' => $donnees,
                'priorite' => $priorite,
                'est_lue' => false
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Erreur création notification multiples', [
                'error' => $e->getMessage(),
                'count' => count($userIds),
                'type' => $type
            ]);
            return null;
        }
    }

    /**
     * Notifier par rôle
     */
    public static function notifierRole($roleCode, $type, $titre, $message, $donnees = null, $succursaleId = null, $priorite = 'NORMALE')
    {
        try {
            return self::create([
                'role_cible' => $roleCode,
                'destinataire_type' => 'role',
                'succursale_id' => $succursaleId,
                'type' => $type,
                'titre' => $titre,
                'message' => $message,
                'donnees' => $donnees,
                'priorite' => $priorite,
                'est_lue' => false
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Erreur création notification rôle', [
                'error' => $e->getMessage(),
                'role' => $roleCode,
                'type' => $type
            ]);
            return null;
        }
    }

    /**
     * Notifier une succursale entière
     */
    public static function notifierSuccursale($succursaleId, $type, $titre, $message, $donnees = null, $priorite = 'NORMALE')
    {
        try {
            return self::create([
                'succursale_id' => $succursaleId,
                'destinataire_type' => 'succursale',
                'type' => $type,
                'titre' => $titre,
                'message' => $message,
                'donnees' => $donnees,
                'priorite' => $priorite,
                'est_lue' => false
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Erreur création notification succursale', [
                'error' => $e->getMessage(),
                'succursale_id' => $succursaleId,
                'type' => $type
            ]);
            return null;
        }
    }

    /**
     * Notifier tous les utilisateurs actifs
     */
    public static function notifierPublic($type, $titre, $message, $donnees = null, $priorite = 'NORMALE')
    {
        try {
            return self::create([
                'destinataire_type' => 'public',
                'type' => $type,
                'titre' => $titre,
                'message' => $message,
                'donnees' => $donnees,
                'priorite' => $priorite,
                'est_lue' => false
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Erreur création notification publique', [
                'error' => $e->getMessage(),
                'type' => $type
            ]);
            return null;
        }
    }

    // ============================================================
    // SCOPES
    // ============================================================

    /**
     * Scope pour les notifications d'un utilisateur spécifique
     */
    public function scopePourUtilisateur($query, $userId)
    {
        return $query->where(function($q) use ($userId) {
            $q->where('destinataire_type', 'utilisateur')
              ->where('utilisateur_id', $userId)
              ->orWhere('destinataire_type', 'multiple')
              ->whereJsonContains('utilisateur_ids', $userId)
              ->orWhere('destinataire_type', 'role')
              ->whereIn('role_cible', function($sub) use ($userId) {
                  $sub->select('code')
                      ->from('roles')
                      ->whereIn('id', function($roleSub) use ($userId) {
                          $roleSub->select('role_id')
                                  ->from('utilisateurs')
                                  ->where('id', $userId);
                      });
              })
              ->orWhere('destinataire_type', 'succursale')
              ->whereIn('succursale_id', function($sub) use ($userId) {
                  $sub->select('succursale_id')
                      ->from('utilisateurs')
                      ->where('id', $userId);
              })
              ->orWhere('destinataire_type', 'public');
        });
    }

    /**
     * Scope pour les notifications non lues
     */
    public function scopeNonLues($query)
    {
        return $query->where('est_lue', false);
    }

    /**
     * Scope pour les notifications par priorité
     */
    public function scopePriorite($query, $priorite)
    {
        return $query->where('priorite', $priorite);
    }

    /**
     * Scope pour les notifications par type
     */
    public function scopeType($query, $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope pour les notifications récentes
     */
    public function scopeRecentes($query, $limit = 30)
    {
        return $query->orderBy('created_at', 'desc')->limit($limit);
    }

    // ============================================================
    // MÉTHODES UTILITAIRES
    // ============================================================

    /**
     * Marquer la notification comme lue
     */
    public function marquerCommeLue()
    {
        $this->est_lue = true;
        $this->date_lecture = now();
        return $this->save();
    }

    /**
     * Marquer comme envoyée par email
     */
    public function marquerEmailEnvoye()
    {
        $this->est_envoyee_email = true;
        $this->date_envoi_email = now();
        return $this->save();
    }

    /**
     * Vérifier si la notification est urgente
     */
    public function isUrgent()
    {
        return in_array($this->priorite, ['HAUTE', 'URGENTE']);
    }
}