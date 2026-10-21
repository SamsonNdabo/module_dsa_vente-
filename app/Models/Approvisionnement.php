<?php
// app/Models/Approvisionnement.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

class Approvisionnement extends Model
{
    use SoftDeletes;

    protected $table = 'approvisionnements';

    // ============================================================
    // CONSTANTES
    // ============================================================
    
    const TYPE_DEMANDE = [
        'APPROVISIONNEMENT' => 'Approvisionnement',
        'TRANSFERT' => 'Transfert'
    ];

    const STATUT_DEMANDE = [
        'PENDING_APPROVAL' => 'Pending Approval',
        'APPROVED' => 'Approved',
        'REJECTED' => 'Rejected',
        'IN_TRANSIT' => 'In Transit',
        'RECEIVED' => 'Received',
        'PARTIALLY_RECEIVED' => 'Partially Received'
    ];

    const STATUT_OPERATION = [
        'EN_ATTENTE_OPERATION' => 'EN_ATTENTE_OPERATION',
        'EN_ATTENTE_LOGISTIQUE' => 'EN_ATTENTE_LOGISTIQUE',
        'EN_ATTENTE_APPROBATION_LOGISTIQUE' => 'EN_ATTENTE_APPROBATION_LOGISTIQUE',
        'VALIDE' => 'VALIDE',
        'REJETE' => 'REJETE',
        'PREPARE' => 'PREPARE',
        'EXPEDIE' => 'EXPEDIE',
        'ARRIVE_INTERMEDIAIRE' => 'ARRIVE_INTERMEDIAIRE',
        'DEPART_INTERMEDIAIRE' => 'DEPART_INTERMEDIAIRE',
        'RECU_PARTIEL' => 'RECU_PARTIEL',
        'RECU' => 'RECU'
    ];

    const TYPE_PARCOURS = [
        'DIRECT' => 'DIRECT',
        'AVEC_INTERMEDIAIRE' => 'AVEC_INTERMEDIAIRE'
    ];

    const MODE_TRANSPORT = [
        'AERIEN' => 'Aérien',
        'TERRESTRE' => 'Terrestre',
        'MARITIME' => 'Maritime',
        'AUTRE' => 'Autre'
    ];

protected $fillable = [
    // Informations générales
    'reference',
    'type_demande',
    'statut_demande',
    'statut_operation',

    // Workflow logistique
    'type_demande_logistique',
    'statut_approbation_logistique',
    'approbateur_superieur_id',
    'date_approbation_superieur',
    'motif_rejet_superieur',

    // Succursales
    'source_succursale_id',
    'destination_succursale_id',
    'source_decidee_id',
    'intermediaire_succursale_id',

    // Configuration du transport
    'type_parcours',
    'mode_transport',
    'devise_id',
    'avec_intermediaire',

    // Informations transport
    'moyen_transport',
    'responsable_transport',
    'numero_vehicule',
    'code_suivi',

    // Quantités
    'quantite_demandee',
    'quantite_envoyee',
    'quantite_recue',
    'quantite_casse',
    'quantite_perdue',
    'quantite_non_conforme',
    'quantite_ecart',

    // Écarts et remarques
    'motif_ecart',
    'motif',
    'notes',

    // Données logistiques
    'poids_total',
    'volume_total',
    'valeur_totale',
    'cout_transport',
    'cout_assurance',
    'cout_emballage',
    'cout_total',

    // Dates
    'date_demande',
    'date_livraison_souhaitee',
    'date_validation_ops',
    'date_validation_logistique',
    'date_approbation_superieur',
    'date_preparation',
    'date_expedition',
    'date_arrivee_intermediaire',
    'date_depart_intermediaire',
    'date_reception',

    // Utilisateurs responsables
    'demandeur_id',
    'validateur_ops_id',
    'responsable_logistique_id',
    'approbateur_superieur_id',
    'preparateur_id',
    'expediteur_id',
    'responsable_intermediaire_id',
    'recepteur_id',
];

    protected $casts = [
        'date_demande' => 'datetime',
        'date_livraison_souhaitee' => 'date',
        'date_validation_ops' => 'datetime',
        'date_validation_logistique' => 'datetime',
        'date_preparation' => 'datetime',
        'date_expedition' => 'datetime',
        'date_arrivee_intermediaire' => 'datetime',
        'date_depart_intermediaire' => 'datetime',
        'date_reception' => 'datetime',
        'avec_intermediaire' => 'boolean',
        'poids_total' => 'decimal:2',
        'volume_total' => 'decimal:2',
        'valeur_totale' => 'decimal:2',
        'cout_transport' => 'decimal:2',
        'cout_assurance' => 'decimal:2',
        'cout_emballage' => 'decimal:2',
        'cout_total' => 'decimal:2'
    ];

    protected $appends = [
        'libelle_statut_demande',
        'libelle_statut_operation'
    ];

    // ============================================================
    // RELATIONS
    // ============================================================
    
    public function devise()
    {
        return $this->belongsTo(Devise::class, 'devise_id');
    }

    public function sourceSuccursale()
    {
        return $this->belongsTo(Succursale::class, 'source_succursale_id');
    }

    public function destinationSuccursale()
    {
        return $this->belongsTo(Succursale::class, 'destination_succursale_id');
    }

    public function sourceDecidee()
    {
        return $this->belongsTo(Succursale::class, 'source_decidee_id');
    }

    public function intermediaireSuccursale()
    {
        return $this->belongsTo(Succursale::class, 'intermediaire_succursale_id');
    }

    /**
     * RELATION MANQUANTE AJOUTÉE
     * Relation vers le dépôt source (via stock_succursale si nécessaire)
     * ou vers la succursale source selon votre logique métier.
     * 
     * Basé sur votre base de données, la table 'stock_succursale' a un champ
     * 'depot_source_id' qui pourrait être utilisé.
     * 
     * Si 'depot_source_id' est une colonne dans 'approvisionnements', utilisez plutôt :
     * return $this->belongsTo(StockSuccursale::class, 'depot_source_id');
     * 
     * Si 'depotSource' fait référence à la succursale source décidée (logique métier) :
     */
    public function depotSource()
    {
        // Option 1: Si depot_source_id fait référence à une succursale
        return $this->belongsTo(Succursale::class, 'source_decidee_id');
        
        // Option 2: Si depot_source_id fait référence à un enregistrement stock_succursale
        // return $this->belongsTo(StockSuccursale::class, 'depot_source_id');
    }

    public function lignes()
    {
        return $this->hasMany(LigneApprovisionnement::class, 'approvisionnement_id');
    }

    public function demandeur()
    {
        return $this->belongsTo(Utilisateur::class, 'demandeur_id');
    }

    public function validateurOps()
    {
        return $this->belongsTo(Utilisateur::class, 'validateur_ops_id');
    }

    public function responsableLogistique()
    {
        return $this->belongsTo(Utilisateur::class, 'responsable_logistique_id');
    }

    public function preparateur()
    {
        return $this->belongsTo(Utilisateur::class, 'preparateur_id');
    }

    public function expediteur()
    {
        return $this->belongsTo(Utilisateur::class, 'expediteur_id');
    }

    public function recepteur()
    {
        return $this->belongsTo(Utilisateur::class, 'recepteur_id');
    }

    public function decisionsOps()
    {
        return $this->hasMany(DecisionOperation::class);
    }

    // ============================================================
    // ACCESSORS
    // ============================================================

    public function getLibelleStatutDemandeAttribute()
    {
        return self::STATUT_DEMANDE[$this->statut_demande] ?? $this->statut_demande;
    }

    public function getLibelleStatutOperationAttribute()
    {
        return self::STATUT_OPERATION[$this->statut_operation] ?? $this->statut_operation;
    }

    // ============================================================
    // SCOPES PRINCIPAUX
    // ============================================================

    public function scopeTransferts(Builder $query): Builder
    {
        return $query->where('type_demande', 'TRANSFERT');
    }

    public function scopeApprovisionnements(Builder $query): Builder
    {
        return $query->where('type_demande', 'APPROVISIONNEMENT');
    }

    public function scopeEnPreparation(Builder $query): Builder
    {
        return $query->where('type_demande', 'TRANSFERT')
                     ->where('statut_operation', 'PREPARE');
    }

    public function scopeEnTransit(Builder $query): Builder
    {
        return $query->where('type_demande', 'TRANSFERT')
                     ->whereIn('statut_operation', ['EXPEDIE', 'ARRIVE_INTERMEDIAIRE', 'DEPART_INTERMEDIAIRE']);
    }

    public function scopeTermines(Builder $query): Builder
    {
        return $query->where('type_demande', 'TRANSFERT')
                     ->where('statut_operation', 'RECU');
    }

    public function scopeAvecEcart(Builder $query): Builder
    {
        return $query->where('type_demande', 'TRANSFERT')
                     ->where(function($q) {
                         $q->where('quantite_casse', '>', 0)
                           ->orWhere('quantite_perdue', '>', 0)
                           ->orWhere('quantite_non_conforme', '>', 0);
                     });
    }

    public function scopeTransfertsValides(Builder $query): Builder
    {
        return $query->where('type_demande', 'TRANSFERT')
                     ->where('statut_operation', 'VALIDE');
    }

    public function scopeTransfertsRejetes(Builder $query): Builder
    {
        return $query->where('type_demande', 'TRANSFERT')
                     ->where('statut_operation', 'REJETE');
    }

    public function scopeEnAttenteOps($query)
    {
        return $query->where('statut_operation', 'EN_ATTENTE_OPERATION');
    }

    public function scopeEnAttenteLogistique($query)
    {
        return $query->where('statut_operation', 'EN_ATTENTE_LOGISTIQUE');
    }

    public function scopeValides($query)
    {
        return $query->where('statut_operation', 'VALIDE');
    }

    // ============================================================
    // MÉTHODES MÉTIER
    // ============================================================

public function verifierDisponibiliteStock(): array
{
    $erreurs = [];
    
    foreach ($this->lignes as $ligne) {
        $stock = StockSuccursale::where('succursale_id', $this->source_decidee_id)
            ->where('produit_id', $ligne->produit_id)
            ->first();
        
        // ✅ Utilisation de stockDisponible() pour plus de clarté
        $disponible = $stock ? $stock->stockDisponible() : 0;
        
        if ($disponible < $ligne->quantite_demandee) {
            $erreurs[] = [
                'produit' => $ligne->produit->nom ?? 'Produit #' . $ligne->produit_id,
                'produit_id' => $ligne->produit_id,
                'demande' => $ligne->quantite_demandee,
                'disponible' => $disponible,
                'manquant' => $ligne->quantite_demandee - $disponible
            ];
        }
    }
    
    return $erreurs;
}

    public function reserverStock(): self
    {
        foreach ($this->lignes as $ligne) {
            $stock = StockSuccursale::where('succursale_id', $this->source_decidee_id)
                ->where('produit_id', $ligne->produit_id)
                ->first();
            
            if ($stock) {
                $ligne->quantite_envoyee = $ligne->quantite_demandee;
                $ligne->save();
                
                $stock->quantite_reserve += $ligne->quantite_demandee;
                $stock->save();
            }
        }
        
        return $this;
    }

    public function libererReservation(): self
    {
        foreach ($this->lignes as $ligne) {
            if ($ligne->quantite_envoyee > 0) {
                $stock = StockSuccursale::where('succursale_id', $this->source_decidee_id)
                    ->where('produit_id', $ligne->produit_id)
                    ->first();
                
                if ($stock) {
                    $stock->quantite_reserve = max(0, $stock->quantite_reserve - $ligne->quantite_envoyee);
                    $stock->save();
                }
                
                $ligne->quantite_envoyee = 0;
                $ligne->save();
            }
        }
        
        return $this;
    }

    public static function genererReference($type)
    {
        $prefix = $type === 'APPROVISIONNEMENT' ? 'APP' : 'TRF';
        $date = now()->format('Ymd');
        $count = static::whereDate('created_at', today())->count() + 1;
        return "{$prefix}-{$date}-" . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    public function getStockSource()
    {
        if ($this->source_decidee_id) {
            return StockSuccursale::where('succursale_id', $this->source_decidee_id)->first();
        }
        return null;
    }

    public function getStockDestination()
    {
        return StockSuccursale::where('succursale_id', $this->destination_succursale_id)->first();
    }

    // ============================================================
    // BOOT
    // ============================================================

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($appro) {
            if (empty($appro->reference)) {
                $appro->reference = static::genererReference($appro->type_demande);
            }
            if (empty($appro->date_demande)) {
                $appro->date_demande = now();
            }
            if (empty($appro->statut_demande)) {
                $appro->statut_demande = 'Pending Approval';
            }
            if (empty($appro->statut_operation)) {
                $appro->statut_operation = 'EN_ATTENTE_OPERATION';
            }
        });
    }
}