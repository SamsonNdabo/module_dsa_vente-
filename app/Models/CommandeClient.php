<?php
// app/Models/CommandeClient.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\Auditable;

class CommandeClient extends Model
{
    use HasFactory, SoftDeletes, Auditable;

    protected $table = 'commandes';

    // =============================================
    // CONSTANTES
    // =============================================
    
    const STATUTS = [
        'EN_ATTENTE_PAIEMENT' => 'En attente de paiement',
        'PAYEE' => 'Payée',
        'EN_ATTENTE_LOGISTIQUE' => 'En attente logistique',
        'ASSIGNEE' => 'Assignée',
        'EN_PREPARATION' => 'En préparation',
        'PRETE' => 'Prête',
        'EN_LIVRAISON' => 'En livraison',
        'LIVREE' => 'Livrée',
        'ANNULEE' => 'Annulée'
    ];

    const STATUTS_LOGISTIQUE = [
        'EN_ATTENTE' => 'En attente',
        'ASSIGNEE' => 'Assignée',
        'PREPARATION' => 'En préparation',
        'PRETE' => 'Prête',
        'EXPEDIEE' => 'Expédiée',
        'LIVREE' => 'Livrée'
    ];

    const MODES_LIVRAISON = [
        'DOMICILE' => 'Livraison à domicile',
        'POINT_RELAIS' => 'Point relais',
        'RETRAIT_MAGASIN' => 'Retrait en magasin'
    ];

    // =============================================
    // FILLABLE
    // =============================================
    
    protected $fillable = [
        'reference',
        'client_id',
        'succursale_assignee_id',
        'assignee_par_id',
        'date_assignation',
        'statut',
        'statut_logistique',
        'sous_total',
        'remise',
        'frais_livraison',
        'total',
        'notes',
        'adresse_livraison',
        'adresse_facturation',
        'mode_livraison',
        'point_relais_id',
        'telephone_livraison',
        'date_commande',
        'date_paiement',
        'date_expedition',
        'date_livraison',
        'metadata'
    ];

    // =============================================
    // CASTS
    // =============================================
    
    protected $casts = [
        'sous_total' => 'decimal:2',
        'remise' => 'decimal:2',
        'frais_livraison' => 'decimal:2',
        'total' => 'decimal:2',
        'adresse_livraison' => 'string',
        'adresse_facturation' => 'string',
        'date_commande' => 'datetime',
        'date_paiement' => 'datetime',
        'date_expedition' => 'datetime',
        'date_livraison' => 'datetime',
        'date_assignation' => 'datetime',
        'metadata' => 'array'
    ];

    // =============================================
    // APPENDS
    // =============================================
    
    protected $appends = [
        'statut_label',
        'statut_logistique_label',
        'mode_livraison_label',
        'est_payee',
        'peut_annuler',
        'peut_assigner'
    ];

    // =============================================
    // BOOT
    // =============================================
    
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($commande) {
            if (empty($commande->reference)) {
                $commande->reference = static::genererReference();
            }
            if (empty($commande->statut)) {
                $commande->statut = 'EN_ATTENTE_PAIEMENT';
            }
            if (empty($commande->statut_logistique)) {
                $commande->statut_logistique = 'EN_ATTENTE';
            }
            if (empty($commande->date_commande)) {
                $commande->date_commande = now();
            }
        });
    }

    // =============================================
    // MÉTHODES STATIQUES
    // =============================================
    
    public static function genererReference(): string
    {
        return 'CMD' . now()->format('Ymd') . strtoupper(substr(uniqid(), -6));
    }

    // =============================================
    // RELATIONS
    // =============================================
    
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function succursaleAssignee()
    {
        return $this->belongsTo(Succursale::class, 'succursale_assignee_id');
    }

    public function assignePar()
    {
        return $this->belongsTo(Utilisateur::class, 'assignee_par_id');
    }

    public function lignes()
    {
        return $this->hasMany(LigneCommande::class, 'commande_id');
    }

    public function livraison()
    {
        return $this->hasOne(Livraison::class, 'commande_id');
    }

    public function paiements()
    {
        return $this->hasMany(Paiement::class, 'commande_id');
    }

    // =============================================
    // ACCESSORS
    // =============================================
    
    public function getStatutLabelAttribute(): string
    {
        return self::STATUTS[$this->statut] ?? $this->statut ?? 'Non défini';
    }

    public function getStatutLogistiqueLabelAttribute(): string
    {
        return self::STATUTS_LOGISTIQUE[$this->statut_logistique] ?? $this->statut_logistique ?? 'En attente';
    }

    public function getModeLivraisonLabelAttribute(): string
    {
        return self::MODES_LIVRAISON[$this->mode_livraison] ?? $this->mode_livraison ?? 'Non spécifié';
    }

    public function getEstPayeeAttribute(): bool
    {
        return in_array($this->statut, ['PAYEE', 'EN_ATTENTE_LOGISTIQUE', 'ASSIGNEE', 'EN_PREPARATION', 'PRETE', 'EN_LIVRAISON', 'LIVREE']);
    }

    public function getPeutAnnulerAttribute(): bool
    {
        return in_array($this->statut, ['EN_ATTENTE_PAIEMENT', 'PAYEE', 'EN_ATTENTE_LOGISTIQUE']);
    }

    public function getPeutAssignerAttribute(): bool
    {
        return $this->statut === 'PAYEE' 
            && $this->statut_logistique === 'EN_ATTENTE';
    }

    // =============================================
    // MÉTHODES MÉTIER
    // =============================================

    /**
     * Valider le paiement
     */
    public function validerPaiement(string $referencePaiement): void
    {
        $this->statut = 'EN_ATTENTE_LOGISTIQUE';
        $this->date_paiement = now();
        $this->metadata = array_merge($this->metadata ?? [], [
            'reference_paiement' => $referencePaiement,
            'date_paiement' => now()->toDateTimeString()
        ]);
        $this->save();

        if ($this->livraison) {
            $this->livraison->ajouterHistorique('PAYEE', 'Paiement confirmé');
        }

        $this->logAudit('COMMANDE_PAYEE', null, [
            'reference' => $this->reference,
            'montant' => $this->total
        ]);
    }

    /**
     * Assigner la commande à une succursale
     * ✅ Vérifie le stock disponible en tenant compte des réservations
     */
    public function assignerSuccursale(Succursale $succursale, Utilisateur $utilisateur): void
    {
        if (!$this->peutAssigner) {
            throw new \Exception("Cette commande ne peut pas être assignée. Statut: {$this->statut}");
        }

        // Vérifier le stock dans la succursale (en utilisant stockDisponible)
        foreach ($this->lignes as $ligne) {
            $stock = StockSuccursale::where('succursale_id', $succursale->id)
                ->where('produit_id', $ligne->produit_id)
                ->first();

            if (!$stock || $stock->stockDisponible() < $ligne->quantite) {
                $disponible = $stock ? $stock->stockDisponible() : 0;
                throw new \Exception(
                    "Stock insuffisant dans {$succursale->nom} pour {$ligne->produit->nom}. "
                    . "Disponible: {$disponible}, Demandé: {$ligne->quantite}"
                );
            }
        }

        $this->succursale_assignee_id = $succursale->id;
        $this->assignee_par_id = $utilisateur->id;
        $this->date_assignation = now();
        $this->statut = 'ASSIGNEE';
        $this->statut_logistique = 'ASSIGNEE';
        $this->save();

        // Mettre à jour les lignes
        foreach ($this->lignes as $ligne) {
            $ligne->logisticien_id = $utilisateur->id;
            $ligne->save();
        }

        // Mettre à jour la livraison
        if ($this->livraison) {
            $this->livraison->update([
                'succursale_id' => $succursale->id,
                'statut' => 'PRISE_EN_CHARGE'
            ]);
        }

        $this->logAudit('COMMANDE_ASSIGNEE', null, [
            'succursale_id' => $succursale->id,
            'assignee_par' => $utilisateur->id
        ]);
    }

    /**
     * Démarrer la préparation
     */
    public function demarrerPreparation(): void
    {
        if (!in_array($this->statut, ['ASSIGNEE', 'EN_ATTENTE_LOGISTIQUE'])) {
            throw new \Exception("Seules les commandes assignées peuvent être préparées. Statut: {$this->statut}");
        }

        if ($this->statut_logistique !== 'ASSIGNEE') {
            throw new \Exception("La commande doit être assignée à une succursale");
        }

        $this->statut = 'EN_PREPARATION';
        $this->statut_logistique = 'PREPARATION';
        $this->save();

        if ($this->livraison) {
            $this->livraison->ajouterHistorique('EN_PREPARATION', 'Commande en préparation');
        }

        $this->logAudit('COMMANDE_PREPARATION', null, ['reference' => $this->reference]);
    }

    /**
     * Marquer comme prête
     */
    public function marquerPrete(): void
    {
        if ($this->statut !== 'EN_PREPARATION') {
            throw new \Exception("Seules les commandes en préparation peuvent être marquées comme prêtes");
        }

        $this->statut = 'PRETE';
        $this->statut_logistique = 'PRETE';
        $this->save();

        if ($this->livraison) {
            $this->livraison->ajouterHistorique('PRETE', 'Commande prête pour livraison');
        }

        $this->logAudit('COMMANDE_PRETE', null, ['reference' => $this->reference]);
    }

    /**
     * Expédier la commande
     */
    public function expedier(): void
    {
        if (!in_array($this->statut, ['PRETE', 'EN_PREPARATION'])) {
            throw new \Exception("Seules les commandes prêtes peuvent être expédiées");
        }

        $this->statut = 'EN_LIVRAISON';
        $this->statut_logistique = 'EXPEDIEE';
        $this->date_expedition = now();
        $this->save();

        if ($this->livraison) {
            $this->livraison->ajouterHistorique('EXPEDIEE', 'Commande remise au transporteur');
        }

        $this->logAudit('COMMANDE_EXPEDIEE', null, ['reference' => $this->reference]);
    }

    /**
     * Marquer comme livrée
     */
    public function marquerLivree(): void
    {
        if ($this->statut !== 'EN_LIVRAISON') {
            throw new \Exception("Seules les commandes en livraison peuvent être marquées comme livrées");
        }

        $this->statut = 'LIVREE';
        $this->statut_logistique = 'LIVREE';
        $this->date_livraison = now();
        $this->save();

        if ($this->livraison) {
            $this->livraison->ajouterHistorique('LIVREE', 'Commande livrée avec succès');
            $this->livraison->update([
                'statut' => 'LIVREE',
                'date_livraison_reelle' => now()
            ]);
        }

        $this->logAudit('COMMANDE_LIVREE', null, ['reference' => $this->reference]);
    }

    /**
     * Annuler la commande (mise à jour du statut uniquement)
     * ⚠️ La gestion du stock (libération des réservations) est effectuée dans CommandeService
     */
    public function annuler(string $motif): void
    {
        if (!$this->peutAnnuler) {
            throw new \Exception("Cette commande ne peut pas être annulée. Statut: {$this->statut}");
        }

        $this->statut = 'ANNULEE';
        $this->statut_logistique = 'EN_ATTENTE';
        $this->metadata = array_merge($this->metadata ?? [], [
            'motif_annulation' => $motif,
            'date_annulation' => now()->toDateTimeString()
        ]);
        $this->save();

        if ($this->livraison) {
            $this->livraison->annuler($motif);
        }

        $this->logAudit('COMMANDE_ANNULEE', null, [
            'reference' => $this->reference,
            'motif' => $motif
        ]);
    }

    /**
     * Vérifier si la commande peut être traitée par une succursale
     */
    public function peutEtreTraiteePar(Succursale $succursale): bool
    {
        return $this->statut_logistique === 'ASSIGNEE' 
            && $this->succursale_assignee_id === $succursale->id;
    }

    // =============================================
    // SCOPES
    // =============================================
    
    public function scopeParClient($query, $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    public function scopeParStatut($query, $statut)
    {
        return $query->where('statut', $statut);
    }

    public function scopeParStatutLogistique($query, $statut)
    {
        return $query->where('statut_logistique', $statut);
    }

    public function scopeParSuccursale($query, $succursaleId)
    {
        return $query->where('succursale_assignee_id', $succursaleId);
    }

    public function scopeEnAttenteAssignation($query)
    {
        return $query->where('statut', 'PAYEE')
            ->where('statut_logistique', 'EN_ATTENTE');
    }

    public function scopeParPeriode($query, $debut, $fin)
    {
        return $query->whereBetween('date_commande', [$debut, $fin]);
    }
}