<?php
// app/Models/ReceptionFournisseur.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\Auditable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReceptionFournisseur extends Model
{
    use SoftDeletes, Auditable;

    protected $table = 'receptions_fournisseurs';

    protected $fillable = [
            'reference',
            'commande_id',
            'fournisseur_id',
            'succursale_id',
            'depot_destination_id',
        
            'date_reception',
        
            'num_bon_livraison',
            'num_facture_fournisseur',
            'date_facture',
        
            'transporteur',
            'nom_livreur',
            'vehicule_immatriculation',
        
            'quantite_recue',
        
            'total_valeur_ht',
            'total_valeur_tva',
            'total_valeur_ttc',
        
            'notes',
            'statut',
        
            'documents',
            'metadata',
        
            'cree_par_id',
            'valide_par_id',
            'date_validation'
        ];

    protected $casts = [

        'date_reception' => 'datetime',
        'date_facture' => 'date',
        'date_validation' => 'datetime',
    
        'total_valeur_ht' => 'decimal:2',
        'total_valeur_tva' => 'decimal:2',
        'total_valeur_ttc' => 'decimal:2',
    
        'documents' => 'array',
        'metadata' => 'array'
    ];

    protected $appends = [
        'libelle_statut',
        'couleur_statut',
        'total_quantite_recue',
        'total_ecarts'
    ];

    // ============================================================
    // RELATIONS
    // ============================================================

    public function commande()
    {
        return $this->belongsTo(CommandeFournisseur::class, 'commande_id');
    }

    public function fournisseur()
    {
        return $this->belongsTo(Fournisseur::class, 'fournisseur_id');
    }

    public function depotDestination()
    {
        return $this->belongsTo(StockSuccursale::class, 'depot_destination_id')
            ->where('is_depot', true);
    }

    public function lignes()
    {
        return $this->hasMany(LigneReceptionFournisseur::class, 'reception_id');
    }

    public function creePar()
    {
        return $this->belongsTo(Utilisateur::class, 'cree_par_id');
    }

    public function validePar()
    {
        return $this->belongsTo(Utilisateur::class, 'valide_par_id');
    }
    
        public function paiements()
    {
        return $this->hasMany(Paiement::class, 'reception_id');
    }

    // ============================================================
    // ATTRIBUTS
    // ============================================================

    public function getLibelleStatutAttribute()
    {
        $statuts = [
            'EN_ATTENTE' => 'En attente de validation',
            'VALIDE' => 'Validée',
            'ANNULE' => 'Annulée',
            'PARTIELLE' => 'Partielle'
        ];
        return $statuts[$this->statut] ?? $this->statut;
    }

    public function getCouleurStatutAttribute()
    {
        return match ($this->statut) {
            'EN_ATTENTE' => 'orange',
            'VALIDE' => 'green',
            'ANNULE' => 'red',
            'PARTIELLE' => 'yellow',
            default => 'gray'
        };
    }

    public function getTotalQuantiteRecueAttribute()
    {
        return $this->lignes()->sum('quantite_recue');
    }

    public function getTotalEcartsAttribute()
    {
        return $this->lignes()->sum('quantite_casse') +
            $this->lignes()->sum('quantite_perdue') +
            $this->lignes()->sum('quantite_non_conforme');
    }

    // ============================================================
    // MÉTHODES
    // ============================================================

    public static function genererReference()
    {
        return 'REC' . now()->format('YmdHis') . rand(100, 999);
    }

    // app/Models/ReceptionFournisseur.php

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($reception) {
            if (empty($reception->reference)) {
                $reception->reference = static::genererReference();
            }
            if (empty($reception->date_reception)) {
                $reception->date_reception = now();
            }

            Log::info('Création réception', [
                'commande_id' => $reception->commande_id,
                'depot' => $reception->depot_destination_id
            ]);
        });

        static::created(function ($reception) {
            $reception->logAudit('CREATION_RECEPTION', null, [
                'commande_id' => $reception->commande_id,
                'fournisseur_id' => $reception->fournisseur_id,
                'depot_destination' => $reception->depot_destination_id,
                'num_bon_livraison' => $reception->num_bon_livraison
            ], 'INFO');
        });

        static::updated(function ($reception) {
            if ($reception->isDirty('statut')) {
                $reception->logAudit('CHANGEMENT_STATUT_RECEPTION', [
                    'ancien_statut' => $reception->getOriginal('statut'),
                    'nouveau_statut' => $reception->statut
                ], null, 'HAUTE');
            }
        });

        static::deleting(function ($reception) {
            if ($reception->statut === 'VALIDE') {
                throw new \Exception('Impossible de supprimer une réception déjà validée');
            }

            $reception->logAudit('SUPPRESSION_RECEPTION', $reception->toArray(), null, 'HAUTE');
        });
    }

    /**
     * Ajouter une ligne de réception
     */
    public function ajouterLigne($ligneCommandeId, $quantiteRecue, $options = [])
    {
        $ligneCommande = LigneCommandeFournisseur::findOrFail($ligneCommandeId);

        $ligne = $this->lignes()->create([
            'reception_id' => $this->id,
            'commande_ligne_id' => $ligneCommandeId,
            'produit_id' => $ligneCommande->produit_id,
            'quantite_attendue' => $ligneCommande->quantite,  // ✅ AJOUTER
            'quantite_recue' => $quantiteRecue,
            'quantite_casse' => $options['quantite_casse'] ?? 0,
            'quantite_perdue' => $options['quantite_perdue'] ?? 0,
            'quantite_non_conforme' => $options['quantite_non_conforme'] ?? 0,
            'prix_unitaire_ht_commande' => $ligneCommande->prix_unitaire_ht,  // ✅ AJOUTER
            'prix_unitaire_ht_recu' => $ligneCommande->prix_unitaire_ht,
            'remise' => $ligneCommande->remise ?? 0,  // ✅ AJOUTER
            'taux_tva' => $ligneCommande->taux_tva ?? 16,  // ✅ AJOUTER
            'date_expiration_lot' => $options['date_expiration'] ?? null,
            'numero_lot' => $options['numero_lot'] ?? null,  // ✅ AJOUTER
            'notes' => $options['notes'] ?? null
        ]);

        return $ligne;
    }

    public function annuler($userId, $motif)
    {
        if ($this->statut === 'VALIDE') {
            throw new \Exception('Impossible d\'annuler une réception déjà validée');
        }

        $this->statut = 'ANNULE';
        $this->metadata = array_merge($this->metadata ?? [], [
            'annulation' => [
                'date' => now(),
                'user_id' => $userId,
                'motif' => $motif
            ]
        ]);
        $this->save();

        $this->logAudit('ANNULATION_RECEPTION', [
            'motif' => $motif,
            'annule_par' => $userId
        ]);

        return $this;
    }
}
