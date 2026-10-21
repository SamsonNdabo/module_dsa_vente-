<?php
// app/Models/Facture.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\Auditable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Facture extends Model
{
    use HasFactory, SoftDeletes, Auditable;

    protected $table = 'factures';
    protected $primaryKey = 'id';

    const STATUTS = [
        'BROUILLON' => 'Brouillon',
        'EN_COURS' => 'En cours',
        'VALIDEE' => 'Validée',
        'ANNULEE' => 'Annulée',
        'REMBOURSEE' => 'Remboursée',
        'PARTIELLE' => 'Partiellement payée',
        'PAYEE' =>'payee'
    ];

    const TYPES_FACTURE = [
        'VENTE' => 'Vente',
        'AVOIR' => 'Avoir',
        'DEVIS' => 'Devis'
    ];

    protected $fillable = [
        'reference',
        'client_id',
        'utilisateur_id',
        'succursale_id',
        'caisse_id',
        'devise_id',
        'date_facture',
        'statut',
        'type_facture',
        'sous_total',
        'remise_globale',
        'type_remise',
        'total_remise',
        'total_ht',
        'taux_tva',
        'total_tva',
        'total_ttc',
        'avance',
        'reste_a_payer',
        'montant_regle',
        'montant_du',
        'notes',
        'conditions_paiement',
        'date_echeance',
        'metadata'
    ];

    protected $casts = [
        'date_facture' => 'datetime',
        'date_echeance' => 'date',
        'sous_total' => 'decimal:2',
        'remise_globale' => 'decimal:2',
        'total_remise' => 'decimal:2',
        'total_ht' => 'decimal:2',
        'taux_tva' => 'decimal:2',
        'total_tva' => 'decimal:2',
        'total_ttc' => 'decimal:2',
        'avance' => 'decimal:2',
        'reste_a_payer' => 'decimal:2',
        'montant_regle' => 'decimal:2',
        'montant_du' => 'decimal:2',
        'metadata' => 'array'
    ];

    protected $appends = [
        'statut_label',
        'type_label',
        'est_reglee',
        'est_remboursable',
        'montant_restant'
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($facture) {
            if (empty($facture->reference)) {
                $facture->reference = static::genererReference();
            }
            if (empty($facture->date_facture)) {
                $facture->date_facture = now();
            }
            if ($facture->statut === 'VALIDEE' && empty($facture->caisse_id)) {
                throw new \Exception('Une facture validée doit être associée à une caisse');
            }
        });

        static::created(function ($facture) {
            $facture->logAudit('CREATION_FACTURE', null, [
                'reference' => $facture->reference,
                'client_id' => $facture->client_id,
                'total_ttc' => $facture->total_ttc,
                'statut' => $facture->statut
            ], 'INFO');
        });

        static::updating(function ($facture) {
            if ($facture->isDirty('statut')) {
                $ancienStatut = $facture->getOriginal('statut');
                $nouveauStatut = $facture->statut;
                
                // Vérifications avant changement de statut
                if ($ancienStatut === 'VALIDEE' && $nouveauStatut === 'ANNULEE') {
                    if ($facture->montant_regle > 0) {
                        throw new \Exception('Impossible d\'annuler une facture déjà payée');
                    }
                }
            }
        });

        static::updated(function ($facture) {
            $changes = [];
            $fieldsToTrack = ['statut', 'total_ttc', 'montant_regle', 'avance'];
            
            foreach ($fieldsToTrack as $field) {
                if ($facture->isDirty($field)) {
                    $changes[$field] = [
                        'ancien' => $facture->getOriginal($field),
                        'nouveau' => $facture->$field
                    ];
                }
            }
            
            if (!empty($changes)) {
                $facture->logAudit('MODIFICATION_FACTURE', $facture->getOriginal(), $facture->getAttributes(), 'INFO');
            }
        });
    }

    /**
     * Générer une référence unique pour la facture
     */
    public static function genererReference(): string
    {
        $prefix = 'FAC';
        $date = now()->format('Ymd');
        $count = static::whereDate('created_at', today())->count() + 1;
        $sequence = str_pad($count, 5, '0', STR_PAD_LEFT);
        
        return "{$prefix}-{$date}-{$sequence}";
    }

    /**
     * Relations
     */
     
    public function devise()
    {
        return $this->belongsTo(Devise::class);
    }
    
    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function utilisateur()
    {
        return $this->belongsTo(Utilisateur::class, 'utilisateur_id');
    }

    public function succursale()
    {
        return $this->belongsTo(Succursale::class, 'succursale_id');
    }

    public function caisse()
    {
        return $this->belongsTo(Caisses::class, 'caisse_id');
    }

    public function lignes()
    {
        return $this->hasMany(LigneFacture::class, 'facture_id');
    }

    public function paiements()
    {
        return $this->hasMany(Paiement::class, 'facture_id');
    }

    /**
     * Accesseurs
     */
    public function getEstRegleeAttribute(): bool
    {
        return $this->montant_regle >= $this->total_ttc;
    }

    public function getEstRemboursableAttribute(): bool
{
    return in_array($this->statut, ['VALIDEE', 'PAYEE'])
        && $this->montant_regle > 0
        && $this->total_ttc > 0;
}

    public function getMontantRestantAttribute(): float
    {
        return max(0, $this->total_ttc - $this->montant_regle);
    }

    /**
     * Calculer le total de la facture
     */
    public function calculerTotaux(): array
    {
        $sousTotal = $this->lignes->sum('total_ht');
        
        // Application de la remise globale
        $totalRemise = 0;
        if ($this->remise_globale > 0) {
            if ($this->type_remise === 'POURCENTAGE') {
                $totalRemise = $sousTotal * ($this->remise_globale / 100);
            } else {
                $totalRemise = $this->remise_globale;
            }
        }
        
        $totalHT = $sousTotal - $totalRemise;
        $totalTVA = $totalHT * ($this->taux_tva / 100);
        $totalTTC = $totalHT + $totalTVA;
        
        return [
            'sous_total' => $sousTotal,
            'total_remise' => $totalRemise,
            'total_ht' => $totalHT,
            'total_tva' => $totalTVA,
            'total_ttc' => $totalTTC
        ];
    }

    /**
     * Valider la facture et mettre à jour les stocks
     */
    public function valider(): self
    {
        if ($this->statut !== 'BROUILLON') {
            throw new \Exception('Seules les factures en brouillon peuvent être validées');
        }

        if ($this->lignes->isEmpty()) {
            throw new \Exception('La facture ne contient aucune ligne');
        }

        DB::transaction(function () {
            // Vérifier et réserver les stocks
            foreach ($this->lignes as $ligne) {
                $stock = $ligne->stockSuccursale;
                
                if (!$stock) {
                    throw new \Exception("Stock non trouvé pour le produit {$ligne->produit->nom}");
                }
                
                if ($stock->quantite_disponible < $ligne->quantite) {
                    throw new \Exception("Stock insuffisant pour {$ligne->produit->nom}. Disponible: {$stock->quantite_disponible}, Demandé: {$ligne->quantite}");
                }
                
                // Si le produit utilise le FIFO
                if ($ligne->produit->est_perissable) {
                    // Utiliser la méthode FIFO pour sortir du stock
                    $mouvement = MouvementStock::sortirAvecFIFO(
                        $ligne->produit_id,
                        $stock->id,
                        $ligne->quantite,
                        [
                            'type_sortie' => 'SORTIE',
                            'raison' => "Vente - Facture {$this->reference}",
                            'quantite_avant' => $stock->quantite_disponible
                        ]
                    );
                    
                    // Stocker les lots utilisés dans la ligne
                    $ligne->lots_fifo_utilises = $mouvement->lots_entree;
                    $ligne->save();
                } else {
                    // Sortie simple
                    $stock->diminuerStock(
                        $ligne->quantite,
                        'SORTIE',
                        "Vente - Facture {$this->reference}"
                    );
                }
            }
            
            // Mettre à jour le statut de la facture
            $this->statut = 'VALIDEE';
            $this->save();
            
            // Enregistrer l'audit
            $this->logAudit('VALIDATION_FACTURE', null, [
                'reference' => $this->reference,
                'total' => $this->total_ttc
            ], 'HAUTE');
        });

        return $this;
    }

    /**
     * Enregistrer un paiement
     */
    public function enregistrerPaiement(array $data): Paiement
{
    if (!in_array($this->statut, ['VALIDEE', 'PARTIELLE'])) {
        throw new \Exception(
            'Seules les factures validées peuvent être payées'
        );
    }

    $montant = $data['montant'];
    $montantRestant = $this->montant_restant;

    if ($montant > $montantRestant) {
        throw new \Exception(
            "Le montant du paiement ({$montant}) dépasse le reste à payer ({$montantRestant})"
        );
    }

    $paiement = DB::transaction(function () use ($data, $montant) {

        $paiement = Paiement::create([
            'reference' => Paiement::genererReference(),
            'facture_id' => $this->id,
            'caisse_id' => $data['caisse_id'],
            'utilisateur_id' => $data['utilisateur_id'],
            'client_id' => $this->client_id,
            'mode_paiement' => $data['mode_paiement'],
            'montant' => $montant,
            'montant_rendu' => $data['montant_rendu'] ?? 0,
            'date_paiement' => now(),
            'statut' => 'COMPLET',
            'reference_externe' => $data['reference_externe'] ?? null,
            'details_paiement' => $data['details_paiement'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        $caisse = Caisses::find($data['caisse_id']);

        if ($caisse && $caisse->statut === 'OUVERTE') {
            $caisse->approvisionner(
                $montant,
                $data['utilisateur_id'],
                "Paiement facture {$this->reference}"
            );
        }

        $this->montant_regle += $montant;
        $this->reste_a_payer = $this->total_ttc - $this->montant_regle;

        if ($this->montant_regle >= $this->total_ttc) {
            $this->statut = 'PAYEE';
            $this->avance = 0;
            $this->reste_a_payer = 0;
        } else {
            $this->statut = 'PARTIELLE';
        }

        $this->save();

        if ($this->client) {
            $this->client->updateSolde(
                $this->total_ttc,
                $montant
            );
        }

        return $paiement;
    });

    return $paiement;
}

    /**
     * Annuler la facture
     */
    public function annuler(string $motif): self
    {
        if (
            in_array($this->statut, ['VALIDEE', 'PAYEE'])
            && $this->montant_regle > 0
        ) {
            throw new \Exception(
                'Impossible d\'annuler une facture déjà payée'
            );
        }
    
        DB::transaction(function () use ($motif) {
    
            if (in_array($this->statut, ['VALIDEE', 'PAYEE'])) {
    
                foreach ($this->lignes as $ligne) {
    
                    $stock = $ligne->stockSuccursale;
    
                    if (
                        !empty($ligne->lots_fifo_utilises)
                        && is_array($ligne->lots_fifo_utilises)
                    ) {
                        // ✅ Restaurer les lots FIFO utilisés
                        foreach ($ligne->lots_fifo_utilises as $lot) {
    
                            $mouvement = MouvementStock::find(
                                $lot['mouvement_id']
                            );
    
                            if ($mouvement) {
                                $mouvement->quantite_restante_lot += $lot['quantite'];
                                $mouvement->save();
                            }
                        }
                    }
    
                    // ✅ Utiliser la méthode correcte : augmenterStockFIFO()
                    if ($stock) {
                        // Restaurer le stock avec création d'un mouvement de retour
                        $stock->augmenterStockFIFO(
                            $ligne->quantite,
                            $ligne->prix_unitaire_ht ?? 0,
                            [
                                'raison' => "Annulation facture {$this->reference} - {$motif}",
                                'type_creation' => 'ANNULATION',
                                'reference_document' => $this->reference,
                                'metadata' => [
                                    'facture_annulee_id' => $this->id,
                                    'motif_annulation' => $motif,
                                    'date_annulation' => now()->toDateTimeString()
                                ]
                            ]
                        );
                    }
                }
            }
    
            $this->statut = 'ANNULEE';
    
            $this->metadata = array_merge(
                $this->metadata ?? [],
                [
                    'motif_annulation' => $motif,
                    'date_annulation' => now(),
                    'annule_par' => auth()->id()
                ]
            );
    
            $this->save();
    
            $this->logAudit(
                'ANNULATION_FACTURE',
                null,
                [
                    'motif' => $motif,
                    'reference' => $this->reference
                ],
                'HAUTE'
            );
        });
    
        return $this;
    }
    /**
     * Créer un avoir
     */
    public function creerAvoir(array $data): self
    {
        if (!in_array($this->statut, ['VALIDEE', 'PAYEE'])) {
            throw new \Exception(
                'Seules les factures validées ou payées peuvent générer un avoir'
            );
        }
    
        $montantAvoir = $data['montant'] ?? $this->montant_regle;
    
        if ($montantAvoir > $this->montant_regle) {
            throw new \Exception(
                'Le montant de l\'avoir ne peut pas dépasser le montant payé'
            );
        }
    
        DB::transaction(function () use ($data, $montantAvoir) {
    
            self::create([
                'reference' => 'AVOIR-' . $this->reference,
                'client_id' => $this->client_id,
                'utilisateur_id' => auth()->id(),
                'succursale_id' => $this->succursale_id,
                'caisse_id' => $data['caisse_id'] ?? null,
                'date_facture' => now(),
                'statut' => 'VALIDEE',
                'type_facture' => 'AVOIR',
                'sous_total' => -$montantAvoir,
                'total_ht' => -$montantAvoir,
                'total_ttc' => -$montantAvoir,
                'montant_regle' => 0,
                'reste_a_payer' => 0,
                'notes' => "Avoir pour facture {$this->reference} - Motif: {$data['motif']}",
                'metadata' => [
                    'facture_originale_id' => $this->id,
                    'motif_avoir' => $data['motif']
                ]
            ]);
    
            if ($data['rembourser'] ?? false) {
                $this->rembourser($montantAvoir, $data);
            }
        });
    
        return $this;
    }

    
    /**
     * Rembourser une facture
     */
    public function rembourser(float $montant, array $data): self
    {
        if ($montant <= 0) {
            throw new \Exception(
                'Le montant du remboursement doit être supérieur à zéro.'
            );
        }
    
    
        if ($montant > $this->montant_regle) {
            throw new \Exception(
                'Le montant du remboursement dépasse le montant payé.'
            );
        }
    
    
        DB::transaction(function () use ($montant, $data) {
    
    
            $this->loadMissing(
                'client',
                'succursale',
                'lignes.stockSuccursale'
            );
    
    
            /**
     * Vérifier les remboursements déjà effectués
     */
    $totalDejaRembourse = $this->paiements()
        ->where('statut', 'REMBOURSE')
        ->sum('montant');
    
    
    $paiementInitialTotal = $this->paiements()
        ->whereIn('statut', ['COMPLET', 'PAYEE'])
        ->where('montant', '>', 0)
        ->sum('montant');
    
    
    if (($totalDejaRembourse + $montant) > $paiementInitialTotal) {
    
        throw new \Exception(
            'Le montant total des remboursements dépasse le paiement initial.'
        );
    }
    
    
    
            /**
             * Paiement original
             */
            $paiementOriginal = $this->paiements()
                ->whereIn('statut', ['COMPLET', 'PAYEE'])
                ->where('montant', '>', 0)
                ->latest('date_paiement')
                ->first();
                
                
    
    
            if (!$paiementOriginal) {
    
                throw new \Exception(
                    'Aucun paiement valide trouvé à rembourser.'
                );
            }
    
    
    
            /**
             * Création paiement remboursement
             */
            $remboursement = Paiement::create([
    
                'reference' =>
                    'RMB-' . $this->reference . '-' . time(),
    
                'facture_id' =>
                    $this->id,
    
                'caisse_id' =>
                    $data['caisse_id'] ?? null,
    
                'utilisateur_id' =>
                    auth()->id(),
    
                'client_id' =>
                    $this->client_id,
    
    
                'mode_paiement' =>
                    $data['mode_paiement']
                    ?? $paiementOriginal->mode_paiement,
    
    
                'montant' =>
                    $montant,
    
    
                'statut' =>
                    'REMBOURSE',
    
    
                'date_paiement' =>
                    now(),
    
    
                'notes' =>
                    $data['motif']
                    ?? 'Remboursement facture '.$this->reference,
    
    
                'paiement_original_id' =>
                    $paiementOriginal->id,
    
    
                'metadata' => [
    
                    'remboursement_de' =>
                        $paiementOriginal->reference,
    
                    'motif' =>
                        $data['motif'] ?? null,
    
                    'date' =>
                        now()->toDateTimeString()
                ]
    
            ]);
    
    
    
    
            /**
             * Sortie caisse (même logique que la vente)
             */
            $caissePrincipale = null;
            $caisseOperationnelle = null;
            
            $caisseSelectionnee = Caisses::find($this->caisse_id);
            
            if (!$caisseSelectionnee) {
                throw new \Exception('Caisse introuvable.');
            }
            
            if ($caisseSelectionnee->isPrincipale()) {
            
                $caisseOperationnelle = $caisseSelectionnee->secondairePhysique();
            
                if (!$caisseOperationnelle) {
                    throw new \Exception(
                        "La caisse principale n'a pas de caisse secondaire physique ouverte."
                    );
                }
            
                $caissePrincipale = $caisseSelectionnee;
            
            } elseif ($caisseSelectionnee->isSecondaire()) {
            
                if ($caisseSelectionnee->type_caisse !== 'PHYSYSIQUE') {
                    throw new \Exception(
                        'Un remboursement nécessite une caisse physique.'
                    );
                }
            
                if ($caisseSelectionnee->statut !== 'OUVERTE') {
                    throw new \Exception(
                        'La caisse doit être ouverte pour effectuer un remboursement.'
                    );
                }
            
                $caisseOperationnelle = $caisseSelectionnee;
                $caissePrincipale = $caisseSelectionnee->parent ?? $caisseSelectionnee;
            
            } else {
            
                throw new \Exception(
                    'Type de caisse non autorisé pour un remboursement.'
                );
            }
            
            /**
             * Vérifier les droits sur la caisse principale
             */
            if (!$caissePrincipale->peut('VENTE')) {
                throw new \Exception(
                    'Cette caisse n\'est pas autorisée à effectuer un remboursement.'
                );
            }
            
            /**
             * Débiter la caisse physique
             */
            $debit = $caisseOperationnelle->debiter(
                (float) $montant,
                $this->devise_id,
                'Remboursement facture ' . $this->reference,
                $remboursement->reference,
                auth()->id()
            );
            
            if (!$debit) {
            
                throw new \Exception(
                    'La sortie d\'argent de la caisse a échoué.'
                );
            }
    
    
    
    
            /**
             * Mise à jour facture
             */
            $ancienMontantRegle = $this->montant_regle;
    
    
            $this->montant_regle =
                max(
                    0,
                    $this->montant_regle - $montant
                );
    
    
            $this->reste_a_payer =
                max(
                    0,
                    $this->total_ttc - $this->montant_regle
                );
    
    
    
            if ($this->montant_regle <= 0) {
    
                $this->statut =
                    'REMBOURSEE';
    
            } else {
    
                $this->statut =
                    'PARTIELLE';
            }
    
    
            $this->save();
    
    
    
    
            /**
             * Restauration stock
             */
            $ratioRemboursement =
                $montant / $this->total_ttc;
    
    
    
            foreach ($this->lignes as $ligne) {
    
    
                $quantiteRetour =
                    round(
                        $ligne->quantite * $ratioRemboursement,
                        3
                    );
    
    
                if ($quantiteRetour <= 0) {
                    continue;
                }
    
    
    
                $stock =
                    $ligne->stockSuccursale;
    
    
    
    
                /**
                 * Restaurer les lots FIFO
                 */
                if (
                    !empty($ligne->lots_fifo_utilises)
                    &&
                    is_array($ligne->lots_fifo_utilises)
                ) {
    
    
                    foreach ($ligne->lots_fifo_utilises as $lot) {
    
    
                        $mouvement =
                            MouvementStock::find(
                                $lot['mouvement_id']
                            );
    
    
    
                        if ($mouvement) {
    
    
                            $qteLot =
                                round(
                                    $lot['quantite']
                                    *
                                    $ratioRemboursement,
                                    3
                                );
    
    
                            $mouvement
                                ->quantite_restante_lot +=
                                    $qteLot;
    
    
                            $mouvement->save();
                        }
                    }
                }
    
    
    
    
                /**
                 * Réintégration stock
                 */
                if ($stock) {

            $ancienneQuantite = $stock->quantite_disponible;
        
            $stock->quantite_disponible += $quantiteRetour;
            $stock->date_dernier_entree = now();
            $stock->save();
        
            MouvementStock::create([
                'reference_mouvement' => MouvementStock::genererReference(),
                'succursale_id' => $stock->succursale_id,
                'produit_id' => $stock->produit_id,
                'stock_destination_id' => $stock->id,
        
                'type_mouvement' => 'ENTREE',
                'statut' => 'VALIDE',
        
                'quantite' => $quantiteRetour,
                'quantite_avant' => $ancienneQuantite,
                'quantite_apres' => $stock->quantite_disponible,
        
                'cout_unitaire' => $ligne->prix_unitaire_ht,
                'prix_achat_lot' => $ligne->prix_unitaire_ht,
                'cout_total' => $ligne->prix_unitaire_ht * $quantiteRetour,
        
                // Important : ce mouvement est un historique,
                // pas un nouveau lot FIFO.
                'est_lot_fifo' => false,
                'quantite_restante_lot' => 0,
        
                'reference_document' => $this->reference,
                'raison' => "Remboursement facture {$this->reference}",
        
                'responsable_id' => auth()->id(),
                'valide_par_id' => auth()->id(),
        
                'date_mouvement' => now(),
                'date_validation' => now(),
        
                'metadata' => [
                    'facture_id' => $this->id,
                    'remboursement_id' => $remboursement->id,
                    'type_creation' => 'REMBOURSEMENT',
                    'lots_restitues' => $ligne->lots_fifo_utilises,
                ],
            ]);
}
 
            }
    
    
    
    
    
            /**
             * Mise à jour client
             */
            if ($this->client) {
    
    
                $this->client->total_achats =
                    max(
                        0,
                        $this->client->total_achats - $montant
                    );
    
    
                /**
                 * Seulement remboursement total
                 */
                if ($this->montant_regle <= 0) {
    
                    $this->client->nombre_achats =
                        max(
                            0,
                            $this->client->nombre_achats - 1
                        );
                }
    
    
                $this->client->save();
            }
    
    
    
    
    
            /**
             * Audit
             */
            $this->logAudit(
    
                'REMBOURSEMENT_FACTURE',
    
                [
    
                    'montant' =>
                        $montant,
    
                    'paiement_original_id' =>
                        $paiementOriginal->id,
    
                    'remboursement_id' =>
                        $remboursement->id,
    
                    'ancien_montant_regle' =>
                        $ancienMontantRegle
                ]
    
            );
    
        });
    
    
    
        return $this->fresh();
    
    }
    /**
     * Imprimer la facture
     */
    public function imprimer(): array
    {
        return [
            'facture' => $this->load(['client', 'lignes.produit', 'succursale', 'utilisateur']),
            'total_ttc_lettres' => $this->convertirMontantEnLettres($this->total_ttc),
            'date_impression' => now()->format('d/m/Y H:i:s')
        ];
    }

    /**
     * Convertir un montant en lettres
     */
    private function convertirMontantEnLettres(float $montant): string
    {
        // À implémenter selon vos besoins
        return number_format($montant, 2) . ' Francs Congolais';
    }

    /**
     * Scopes
     */
    // ✅ CORRECTION de la méthode getTypeLabelAttribute
    public function getTypeLabelAttribute(): string
    {
        if (
            $this->type_facture &&
            isset(self::TYPES_FACTURE[$this->type_facture])
        ) {
            return self::TYPES_FACTURE[$this->type_facture];
        }
    
        return 'Vente';
    }

// ✅ CORRECTION de la méthode getStatutLabelAttribute
public function getStatutLabelAttribute(): string
{
    if (
        $this->statut &&
        isset(self::STATUTS[$this->statut])
    ) {
        return self::STATUTS[$this->statut];
    }

    return 'Brouillon';
}
    public function scopeParPeriode($query, $debut, $fin)
    {
        return $query->whereBetween('date_facture', [$debut, $fin]);
    }

    public function scopeParClient($query, $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    public function scopeParStatut($query, $statut)
    {
        return $query->where('statut', $statut);
    }

        public function scopeValidees($query)
    {
        return $query->whereIn(
            'statut',
            ['VALIDEE', 'PARTIELLE', 'PAYEE']
        );
    }

    public function scopeNonPayees($query)
    {
        return $query->whereRaw('montant_regle < total_ttc');
    }

    public function toArray()
    {
        $array = parent::toArray();
        $array['statut_label'] = $this->statut_label;
        $array['type_label'] = $this->type_label;
        $array['est_reglee'] = $this->est_reglee;
        $array['montant_restant'] = $this->montant_restant;
        $array['lignes'] = $this->lignes;
        $array['paiements'] = $this->paiements;
        
        return $array;
    }
}