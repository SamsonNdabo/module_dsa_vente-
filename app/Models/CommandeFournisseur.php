<?php
// app/Models/CommandeFournisseur.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\Auditable;
use App\Traits\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Mail\CommandeFournisseurMail;
use Illuminate\Support\Facades\Mail;
use App\Mail\CommandeFournisseurAnnulationMail;

class CommandeFournisseur extends Model
{
    use HasFactory, SoftDeletes, Auditable, Notifiable;

    protected $table = 'commandes_fournisseurs';

    const STATUTS = [
        'BROUILLON' => 'Brouillon',
        'EN_ATTENTE' => 'En attente de validation',
        'VALIDEE' => 'Validée',
        'ENVOYEE' => 'Envoyée au fournisseur',
        'CONFIRMEE' => 'Confirmée par fournisseur',
        'EXPEDIEE' => 'Expédiée par fournisseur',
        'EN_TRANSIT' => 'En transit',
        'RECUE_PARTIELLE' => 'Reçue partiellement',
        'RECUE' => 'Reçue complètement',
        'ANNULEE' => 'Annulée',
        'REFUSEE' => 'Refusée'
    ];

    protected $fillable = [
        'reference',
        'devis_id',
        'fournisseur_id',
        'depot_destination_id',
        'date_commande',
        'date_livraison_prevue',
        'date_livraison_reelle',
        'statut',
        'conditions_paiement',
        'mode_livraison',
        'transporteur',
        'numero_suivi',
        'notes',
        'notes_internes',
        'devise_id',
        'documents',
        'metadata',
        'cree_par_id',
        'valide_par_id',
        'date_validation',
        'annule_par_id',
        'motif_annulation',
        'reception_par_id',
        'date_reception',
        'montant_total_ht',
        'montant_total_tva',
        'montant_total_ttc',
        'montant_paye',
        'montant_restant',
        'statut_paiement',
    ];

    protected $casts = [
        'date_commande' => 'datetime',
        'date_livraison_prevue' => 'date',
        'date_livraison_reelle' => 'datetime',
        'date_validation' => 'datetime',
        'date_reception' => 'datetime',
        'taux_change' => 'decimal:4',
        'documents' => 'array',
        'metadata' => 'array'
    ];

    protected $appends = [
        'libelle_statut',
        'couleur_statut',
        'avancement',
        'nb_lignes',
        'total_articles'
    ];

    // ============================================================
    // BOOT
    // ============================================================

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($commande) {
            if (empty($commande->reference)) {
                $commande->reference = static::genererReference();
            }
            if (empty($commande->date_commande)) {
                $commande->date_commande = now();
            }
            if (empty($commande->statut)) {
                $commande->statut = 'BROUILLON';
            }
        });

        static::created(function ($commande) {
            $commande->logAudit('CREATION_COMMANDE', null, [
                'reference' => $commande->reference,
                'fournisseur_id' => $commande->fournisseur_id,
                'depot_destination' => $commande->depot_destination_id,
            ], 'INFO');
        });

        static::updated(function ($commande) {
            if ($commande->isDirty('statut')) {
                $commande->logAudit('CHANGEMENT_STATUT_COMMANDE', [
                    'ancien_statut' => $commande->getOriginal('statut'),
                    'nouveau_statut' => $commande->statut
                ], null, 'HAUTE');
            }
        });

        static::deleting(function ($commande) {
            if (!in_array($commande->statut, ['BROUILLON', 'EN_ATTENTE', 'ANNULEE'])) {
                throw new \Exception('Impossible de supprimer une commande en cours de traitement');
            }
            
            $commande->logAudit('SUPPRESSION_COMMANDE', [
                'reference' => $commande->reference,
                'statut' => $commande->statut
            ], null, 'HAUTE');
        });
    }

    // ============================================================
    // RELATIONS
    // ============================================================

    public function fournisseur()
    {
        return $this->belongsTo(Fournisseur::class, 'fournisseur_id');
    }

    public function depotDestination()
    {
        return $this->belongsTo(StockSuccursale::class, 'depot_destination_id')
            ->where('is_depot', true);
    }

    public function succursaleDestination()
    {
        return $this->belongsTo(StockSuccursale::class, 'depot_destination_id')
            ->select('stock_succursale.*')
            ->with('succursale');
    }

    public function lignes()
    {
        return $this->hasMany(LigneCommandeFournisseur::class, 'commande_id');
    }

    public function receptions()
    {
        return $this->hasMany(ReceptionFournisseur::class, 'commande_id');
    }

    public function creePar()
    {
        return $this->belongsTo(Utilisateur::class, 'cree_par_id');
    }

    public function validePar()
    {
        return $this->belongsTo(Utilisateur::class, 'valide_par_id');
    }

    public function receptionPar()
    {
        return $this->belongsTo(Utilisateur::class, 'reception_par_id');
    }

    public function annulePar()
    {
        return $this->belongsTo(Utilisateur::class, 'annule_par_id');
    }

    public function devise()
    {
        return $this->belongsTo(Devise::class, 'devise_id');
    }

    public function devis()
    {
        return $this->belongsTo(DevisFournisseur::class, 'devis_id');
    }
    
    public function paiements()
    {
        return $this->hasMany(Paiement::class, 'commande_fournisseur_id');
    }

    // ============================================================
    // ATTRIBUTS
    // ============================================================

    public function getLibelleStatutAttribute()
    {
        return self::STATUTS[$this->statut] ?? $this->statut;
    }

    public function getCouleurStatutAttribute()
    {
        return match($this->statut) {
            'BROUILLON' => 'gray',
            'EN_ATTENTE' => 'orange',
            'VALIDEE' => 'blue',
            'ENVOYEE' => 'purple',
            'CONFIRMEE' => 'indigo',
            'EXPEDIEE' => 'cyan',
            'EN_TRANSIT' => 'teal',
            'RECUE_PARTIELLE' => 'yellow',
            'RECUE' => 'green',
            'ANNULEE' => 'red',
            'REFUSEE' => 'darkred',
            default => 'gray'
        };
    }

    public function getAvancementAttribute()
    {
        if ($this->statut === 'RECUE') return 100;
        if ($this->statut === 'RECUE_PARTIELLE') {
            $totalRecu = $this->receptions()->sum('quantite_recue');
            $totalCommande = $this->lignes()->sum('quantite');
            return $totalCommande > 0 ? round(($totalRecu / $totalCommande) * 100) : 0;
        }
        if ($this->statut === 'EXPEDIEE') return 75;
        if ($this->statut === 'CONFIRMEE') return 50;
        if ($this->statut === 'VALIDEE') return 25;
        if ($this->statut === 'EN_ATTENTE') return 10;
        return 0;
    }

    public function getNbLignesAttribute()
    {
        return $this->lignes()->count();
    }

    public function getTotalArticlesAttribute()
    {
        return $this->lignes()->sum('quantite');
    }

    public function getTotalRecuAttribute()
    {
        return $this->receptions()->sum('quantite_recue');
    }

    public function getEstCompletementRecuAttribute()
    {
        return $this->total_recu >= $this->total_articles;
    }

    public function depotEstValide()
    {
        if (!$this->depot_destination_id) return false;
        
        $depot = StockSuccursale::find($this->depot_destination_id);
        return $depot && $depot->is_depot;
    }

    // ============================================================
    // MÉTHODES DE GESTION DES MONTANTS
    // ============================================================

    /**
     * Calcule les montants de la commande à partir des lignes
     * et met à jour les champs correspondants
     */
    public function calculerMontantsDepuisLignes(): void
    {
        $totalHT = 0;
        $totalTVA = 0;
        $totalTTC = 0;

        foreach ($this->lignes as $ligne) {
            $totalHT += $ligne->montant_ht;
            $totalTVA += $ligne->montant_tva;
            $totalTTC += $ligne->montant_ttc;
        }

        $this->montant_total_ht = $totalHT;
        $this->montant_total_tva = $totalTVA;
        $this->montant_total_ttc = $totalTTC;
        $this->montant_restant = $totalTTC - ($this->montant_paye ?? 0);
        
        $this->updateStatutPaiement();
        $this->save();
    }

    /**
     * Calcule les montants à partir du devis lié
     */
    public function calculerMontantsDepuisDevis(): void
    {
        if (!$this->devis) {
            return;
        }
        
        $totalHT = 0;
        $totalTVA = 0;
        $totalTTC = 0;
        
        // Parcourir les lignes du devis
        foreach ($this->devis->lignes as $ligne) {
            $quantite = $ligne->quantite;
            $prixUnitaireHT = $ligne->prix_unitaire_ht;
            $remiseLigne = $ligne->remise_ligne ?? 0;
            $tauxTVA = $ligne->taux_tva ?? 16;
            
            // Calcul du montant HT
            $montantBrutHT = $quantite * $prixUnitaireHT;
            $montantRemise = $montantBrutHT * ($remiseLigne / 100);
            $montantHT = $montantBrutHT - $montantRemise;
            
            // Calcul de la TVA
            $montantTVA = $montantHT * ($tauxTVA / 100);
            $montantTTC = $montantHT + $montantTVA;
            
            $totalHT += $montantHT;
            $totalTVA += $montantTVA;
            $totalTTC += $montantTTC;
        }
        
        // Appliquer remise globale
        if ($this->devis->remise_globale > 0) {
            $totalHT = $totalHT * (1 - $this->devis->remise_globale / 100);
            $totalTTC = $totalTTC * (1 - $this->devis->remise_globale / 100);
        }
        
        // Ajouter frais de livraison
        if ($this->devis->frais_livraison > 0) {
            $totalTTC += $this->devis->frais_livraison;
        }
        
        $this->montant_total_ht = $totalHT;
        $this->montant_total_tva = $totalTVA;
        $this->montant_total_ttc = $totalTTC;
        $this->montant_paye = 0;
        $this->montant_restant = $totalTTC;
        $this->statut_paiement = 'NON_PAYE';
        
        $this->save();
    }

    /**
     * Recalculer le statut de paiement en fonction des montants
     */
    public function updateStatutPaiement(): void
    {
        // S'assurer que les montants sont à jour
        $this->montant_paye = $this->paiements()
            ->where('statut', 'VALIDE')
            ->sum('montant');
        
        $this->montant_restant = max(0, $this->montant_total_ttc - $this->montant_paye);

        if ($this->montant_paye <= 0) {
            $this->statut_paiement = 'NON_PAYE';
        } elseif ($this->montant_restant <= 0) {
            $this->statut_paiement = 'PAYE';
        } else {
            $this->statut_paiement = 'PARTIEL';
        }
        
        $this->save();
    }

    /**
     * Envoyer la commande par email au fournisseur
     */
    public function envoyerEmailFournisseur()
    {
        if (!$this->fournisseur || !$this->fournisseur->email) {
            return false;
        }

        try {
            Mail::to($this->fournisseur->email)
                ->send(new CommandeFournisseurMail($this));
            
            Log::info('Email commande envoyé au fournisseur', [
                'commande_id' => $this->id,
                'reference' => $this->reference,
                'email' => $this->fournisseur->email
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('Erreur envoi email commande', [
                'commande_id' => $this->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Valider la commande
     */
    public function valider($userId)
    {
        if ($this->statut !== 'EN_ATTENTE') {
            throw new \Exception('Seules les commandes en attente peuvent être validées');
        }

        if ($this->lignes()->count() === 0) {
            throw new \Exception('Impossible de valider une commande sans articles');
        }

        if (!$this->depotEstValide()) {
            throw new \Exception('Le dépôt de destination n\'existe pas ou n\'est pas valide');
        }

        $this->statut = 'VALIDEE';
        $this->valide_par_id = $userId;
        $this->date_validation = now();
        $this->save();

        $this->logAudit('VALIDATION_COMMANDE', [
            'valide_par' => $userId,
            'depot_destination' => $this->depot_destination_id
        ]);

        return $this;
    }

    /**
     * Annuler la commande
     */
    public function annuler($userId, $motif)
    {
        if (in_array($this->statut, ['RECUE', 'RECUE_PARTIELLE'])) {
            throw new \Exception('Impossible d\'annuler une commande déjà reçue');
        }

        DB::beginTransaction();
        
        try {
            $ancienStatut = $this->statut;
            
            $this->statut = 'ANNULEE';
            $this->annule_par_id = $userId;
            $this->motif_annulation = $motif;
            $this->save();

            $this->logAudit('ANNULATION_COMMANDE', [
                'motif' => $motif,
                'annule_par' => $userId,
                'ancien_statut' => $ancienStatut
            ]);

            // Envoyer l'email d'annulation au fournisseur
            $this->envoyerEmailAnnulationFournisseur();

            DB::commit();

            return $this;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Envoyer l'email d'annulation au fournisseur
     */
    public function envoyerEmailAnnulationFournisseur()
    {
        if (!$this->fournisseur || !$this->fournisseur->email) {
            Log::warning('Impossible d\'envoyer l\'email d\'annulation - fournisseur sans email', [
                'commande_id' => $this->id,
                'reference' => $this->reference
            ]);
            return false;
        }

        try {
            Mail::to($this->fournisseur->email)
                ->send(new CommandeFournisseurAnnulationMail($this));
            
            Log::info('Email d\'annulation envoyé au fournisseur', [
                'commande_id' => $this->id,
                'reference' => $this->reference,
                'email' => $this->fournisseur->email,
                'motif' => $this->motif_annulation
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('Erreur envoi email d\'annulation', [
                'commande_id' => $this->id,
                'reference' => $this->reference,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    // ============================================================
    // SCOPES
    // ============================================================

    public function scopeParStatut($query, $statut)
    {
        return $query->where('statut', $statut);
    }

    public function scopeParFournisseur($query, $fournisseurId)
    {
        return $query->where('fournisseur_id', $fournisseurId);
    }

    public function scopeParDepot($query, $depotId)
    {
        return $query->where('depot_destination_id', $depotId);
    }

    public function scopeEnAttente($query)
    {
        return $query->whereIn('statut', ['EN_ATTENTE', 'VALIDEE']);
    }

    public function scopeRecues($query)
    {
        return $query->whereIn('statut', ['RECUE', 'RECUE_PARTIELLE']);
    }

    public function scopeParPeriode($query, $debut, $fin)
    {
        return $query->whereBetween('date_commande', [$debut, $fin]);
    }

    public static function genererReference()
    {
        $prefix = 'CMD';
        $date = now()->format('Ymd');
        $count = static::whereDate('created_at', today())->count() + 1;
        $sequence = str_pad($count, 4, '0', STR_PAD_LEFT);
        
        return "{$prefix}{$date}-{$sequence}";
    }
}