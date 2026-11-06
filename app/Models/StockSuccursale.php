<?php
// app/Models/StockSuccursale.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use App\Traits\Auditable;
use App\Traits\Notifiable;
use App\Traits\GestionStockFIFO;
use App\Traits\NotificationsStock;
use App\Services\LotStockService;
use Illuminate\Support\Facades\Log;

class StockSuccursale extends Model
{
    use HasFactory, 
        SoftDeletes, 
        Auditable, 
        Notifiable,
        GestionStockFIFO,
        NotificationsStock;

    protected $table = 'stock_succursale';
    
    protected $temporaryProperties = ['oldValues', 'newValues'];
    
    public function setAttribute($key, $value)
    {
        if (in_array($key, $this->temporaryProperties)) {
            $this->{$key} = $value;
            return $this;
        }
        return parent::setAttribute($key, $value);
    }
    
    protected $primaryKey = 'id';

    protected $fillable = [
        'succursale_id',
        'produit_id',
        'is_depot',
        'statut_alerte',
        'depot_source_id',
        'quantite_disponible',
        'quantite_reserve',
        'quantite_commande',
        'quantite_approvisionnement',
        'quantite_maximale',
        'quantite_minimale',
        'quantite_securite',
        'emplacement',
        'zone_stockage',
        'rayon',
        'etagere',
        'date_dernier_entree',
        'date_dernier_sortie',
        'date_dernier_inventaire',
        'date_alerte_stock',
        'date_prochaine_appro',
        'rotation_stock',
        'stock_moyen'
    ];

    protected $casts = [
        'is_depot' => 'boolean',
        'date_dernier_entree' => 'date',
        'statut_alerte'=>'string',
        'date_dernier_sortie' => 'date',
        'date_dernier_inventaire' => 'date',
        'date_alerte_stock' => 'date',
        'date_prochaine_appro' => 'date'
    ];

    protected $appends = [
        'est_en_alerte',
        'est_en_rupture',
        'est_critique',
        'est_faible',
        'est_depot',
        'taux_occupation',
        'jours_avant_rupture',
        'valeur_stock',
        'utilise_fifo',
        'pmp'
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($stock) {
            Log::info('CrÃ©ation d\'un stock', [
                'succursale_id' => $stock->succursale_id,
                'produit_id' => $stock->produit_id,
                'quantite' => $stock->quantite_disponible,
                'is_depot' => $stock->is_depot
            ]);
        });

        static::created(function ($stock) {
            $stock->logAudit('CREATION_STOCK', null, [
                'succursale_id' => $stock->succursale_id,
                'produit_id' => $stock->produit_id,
                'quantite_disponible' => $stock->quantite_disponible,
                'quantite_minimale' => $stock->quantite_minimale,
                'is_depot' => $stock->is_depot,
                'emplacement' => $stock->emplacement
            ], 'INFO');
        });

        static::updating(function ($stock) {
            $oldValues = $stock->getOriginal();
            $newValues = $stock->getAttributes();
            $stock->oldValues = $oldValues;
            $stock->newValues = $newValues;
        });

        static::updated(function ($stock) {
            $significantChanges = [];
            $fieldsToTrack = [
                'quantite_disponible', 'quantite_reserve', 'quantite_minimale',
                'quantite_maximale', 'is_depot', 'depot_source_id', 'emplacement'
            ];
            
            foreach ($fieldsToTrack as $field) {
                if (isset($stock->oldValues[$field]) && $stock->oldValues[$field] != $stock->newValues[$field]) {
                    $significantChanges[$field] = [
                        'ancien' => $stock->oldValues[$field],
                        'nouveau' => $stock->newValues[$field]
                    ];
                }
            }
            
            if (!empty($significantChanges)) {
                $stock->logAudit('MODIFICATION_STOCK', 
                    $stock->oldValues, 
                    $stock->newValues,
                    $stock->determineImportance($significantChanges)
                );
            }
        });

        static::deleting(function ($stock) {
            if ($stock->isForceDeleting()) {
                $stock->logAudit('SUPPRESSION_DEFINITIVE_STOCK', $stock->toArray(), null, 'CRITIQUE');
            } else {
                $stock->logAudit('SUPPRESSION_STOCK', $stock->toArray(), null, 'HAUTE');
            }
        });

        static::restored(function ($stock) {
            $stock->logAudit('RESTAURATION_STOCK', null, $stock->toArray(), 'INFO');
        });
        
        static::saving(function ($stock) {
        $stock->mettreAJourStatutAlerte();
    });
    }

    protected function determineImportance($changes)
    {
        $criticalFields = ['quantite_disponible', 'is_depot'];
        foreach ($criticalFields as $field) {
            if (isset($changes[$field])) {
                return 'HAUTE';
            }
        }
        return 'NORMALE';
    }

    // ============================================================
    // RELATIONS
    // ============================================================
    public function lots()
    {
        return $this->hasMany(LotStock::class, 'stock_succursale_id');
    }

    public function succursale()
    {
        return $this->belongsTo(Succursale::class, 'succursale_id');
    }

    public function produit()
    {
        return $this->belongsTo(Produit::class, 'produit_id');
    }

    public function depotSource()
    {
        return $this->belongsTo(StockSuccursale::class, 'depot_source_id');
    }

    public function pointsVente()
    {
        return $this->hasMany(StockSuccursale::class, 'depot_source_id');
    }

    public function mouvements()
    {
        return $this->hasMany(MouvementStock::class, 'stock_source_id', 'id');
    }

    public function mouvementsDestination()
    {
        return $this->hasMany(MouvementStock::class, 'stock_destination_id', 'id');
    }

    public function lotsFIFO()
    {
        return $this->hasMany(MouvementStock::class, 'stock_destination_id', 'id')
            ->where('type_mouvement', 'ENTREE')
            ->where('est_lot_fifo', true)
            ->where('quantite_restante_lot', '>', 0);
    }
    
        /**
     * Relation avec les IMEI en stock
     */
    public function imeis()
    {
        return $this->hasMany(ProduitImei::class, 'succursale_id', 'succursale_id')
            ->where('produit_id', $this->produit_id)
            ->whereIn('statut', [ProduitImei::STATUT_EN_STOCK, ProduitImei::STATUT_RESERVE]);
    }
    
    /**
     * Relation avec les IMEI disponibles (non réservés)
     */
    public function imeisDisponibles()
    {
        return $this->hasMany(ProduitImei::class, 'succursale_id', 'succursale_id')
            ->where('produit_id', $this->produit_id)
            ->where('statut', ProduitImei::STATUT_EN_STOCK);
    }
    
    /**
     * Relation avec les IMEI réservés
     */
    public function imeisReserves()
    {
        return $this->hasMany(ProduitImei::class, 'succursale_id', 'succursale_id')
            ->where('produit_id', $this->produit_id)
            ->where('statut', ProduitImei::STATUT_RESERVE);
    }
    
    /**
     * Relation avec les IMEI transférés (en transit)
     */
    public function imeisTransferes()
    {
        return $this->hasMany(ProduitImei::class, 'succursale_id', 'succursale_id')
            ->where('produit_id', $this->produit_id)
            ->where('statut', ProduitImei::STATUT_TRANSFERE);
    }

    // ============================================================
    // ATTRIBUTS CALCULÃ‰S
    // ============================================================
    
    
        public function mettreAJourStatutAlerte(): self
    {
        $ancienStatut = $this->statut_alerte ?? 'OK';
        
        if ($this->quantite_disponible <= 0) {
            $this->statut_alerte = 'RUPTURE';
        } elseif ($this->quantite_minimale > 0 && $this->quantite_disponible <= $this->quantite_minimale) {
            $this->statut_alerte = 'CRITIQUE';
        } elseif ($this->quantite_minimale > 0 && $this->quantite_disponible <= $this->quantite_minimale * 2) {
            $this->statut_alerte = 'FAIBLE';
        } else {
            $this->statut_alerte = 'OK';
        }
        
        // Si le statut change, on notifie
        if ($ancienStatut !== $this->statut_alerte) {
            $this->notifierChangementStatut($ancienStatut, $this->statut_alerte);
            $this->logAudit('CHANGEMENT_STATUT_ALERTE', [
                'ancien_statut' => $ancienStatut,
                'nouveau_statut' => $this->statut_alerte,
                'quantite' => $this->quantite_disponible,
                'seuil_min' => $this->quantite_minimale
            ]);
        }
        
        return $this;
    }

    protected function notifierChangementStatut($ancienStatut, $nouveauStatut)
    {
        if (!method_exists($this, 'notifierUtilisateurs')) {
            return;
        }
        
        $niveauxUrgence = [
            'RUPTURE' => 'URGENTE',
            'CRITIQUE' => 'HAUTE',
            'FAIBLE' => 'NORMALE',
            'OK' => 'BASSE'
        ];
        
        $this->notifierUtilisateurs([
            'type' => 'CHANGEMENT_STATUT_STOCK',
            'titre' => "ðŸ“Š Stock : {$this->produit?->nom} â†’ {$nouveauStatut}",
            'message' => "Le stock du produit {$this->produit?->nom} est passÃ© de {$ancienStatut} Ã  {$nouveauStatut}. QuantitÃ©: {$this->quantite_disponible}",
            'donnees' => [
                'stock_id' => $this->id,
                'produit_id' => $this->produit_id,
                'ancien_statut' => $ancienStatut,
                'nouveau_statut' => $nouveauStatut,
                'quantite' => $this->quantite_disponible,
                'succursale_id' => $this->succursale_id
            ],
            'priorite' => $niveauxUrgence[$nouveauStatut] ?? 'NORMALE'
        ]);
    }

    public function getEstEnAlerteAttribute()
    {
        return $this->quantite_disponible <= $this->quantite_minimale;
    }

    public function getEstEnRuptureAttribute()
    {
        return $this->quantite_disponible <= 0;
    }

    public function getEstDepotAttribute()
    {
        return $this->is_depot;
    }
    
        public function getEstCritiqueAttribute()
    {
        return $this->statut_alerte === 'CRITIQUE';
    }
    
    public function getEstFaibleAttribute()
    {
        return $this->statut_alerte === 'FAIBLE';
    }

    public function getQuantiteReelleAttribute()
    {
        return $this->quantite_disponible + $this->quantite_reserve;
    }

    public function getTauxOccupationAttribute()
    {
        if ($this->quantite_maximale <= 0) return 0;
        return round(($this->quantite_reelle / $this->quantite_maximale) * 100, 2);
    }

    public function getJoursAvantRuptureAttribute()
    {
        if ($this->rotation_stock <= 0 || $this->quantite_disponible <= 0) return null;
        return round($this->quantite_disponible / $this->rotation_stock, 1);
    }


    
        public function getValeurStockAttribute()
    {
        if (!$this->produit) return 0;
        try {
            $queryService = app(\App\Services\LotStockQueryService::class);
            $valorisation = $queryService->valorisationFIFO($this);
            return $valorisation['valeur_totale'] ?? 0;
        } catch (\Exception $e) {
            Log::error('Erreur calcul valeur stock', ['stock_id' => $this->id, 'error' => $e->getMessage()]);
            return 0;
        }
    }
    
    public function getPmpAttribute()
    {
        if (!$this->produit) return 0;
        try {
            $queryService = app(\App\Services\LotStockQueryService::class);
            $valorisation = $queryService->valorisationFIFO($this);
            return $valorisation['prix_moyen'] ?? 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    public function getUtiliseFifoAttribute()
    {
        return $this->lots()->where('statut', 'ACTIF')->count() > 0;
    }

    // ============================================================
    // SCOPES PRINCIPAUX
    // ============================================================

    public function scopeDepots($query)
    {
        return $query->where('is_depot', true);
    }

    public function scopePointsVente($query)
    {
        return $query->where('is_depot', false);
    }

    public function scopeEnAlerte($query)
    {
        return $query->whereIn('statut_alerte', ['CRITIQUE', 'RUPTURE']);
    }
    
    public function scopeEnRupture($query)
    {
        return $query->where('statut_alerte', 'RUPTURE');
    }
    
    public function scopeCritique($query)
    {
        return $query->where('statut_alerte', 'CRITIQUE');
    }
    
    public function scopeFaible($query)
    {
        return $query->where('statut_alerte', 'FAIBLE');
    }


    public function scopeParSuccursale($query, $succursaleId)
    {
        return $query->where('succursale_id', $succursaleId);
    }

    public function scopeParProduit($query, $produitId)
    {
        return $query->where('produit_id', $produitId);
    }

    public function scopeAvecStock($query)
    {
        return $query->where('quantite_disponible', '>', 0);
    }

    public function scopeSansStock($query)
    {
        return $query->where('quantite_disponible', 0);
    }

    // ============================================================
    // MÃ‰THODES DE VÃ‰RIFICATION
    // ============================================================

    public function estEnRupture(): bool
    {
        return $this->quantite_disponible <= 0;
    }

        public function estCritique(): bool
    {
        $stockDisponibleReel = $this->quantite_disponible - $this->quantite_reserve;
        
        return $stockDisponibleReel > 0 
            && $stockDisponibleReel <= $this->quantite_minimale;
    }
    
    public function estFaible(): bool
    {
        $stockDisponibleReel = $this->quantite_disponible - $this->quantite_reserve;
        $seuilDouble = $this->quantite_minimale * 2;
        
        return $stockDisponibleReel > $this->quantite_minimale 
            && $stockDisponibleReel <= $seuilDouble;
    }

    public function quantiteManquante(): int
    {
        if ($this->quantite_disponible >= $this->quantite_minimale) {
            return 0;
        }
        return $this->quantite_minimale - $this->quantite_disponible;
    }

    public function pourcentageCouverture(): float
    {
        if ($this->quantite_minimale <= 0) {
            return 100;
        }
        return ($this->quantite_disponible / $this->quantite_minimale) * 100;
    }

    public function peutEtreTransferer($quantite)
    {
        return $this->quantite_disponible >= $quantite && $this->is_depot;
    }

    // ============================================================
    // MÃ‰THODES DE GESTION DE STOCK
    // ============================================================


        /**
     * Expédier du stock (sortie)
     * 
     * @param int $quantite
     * @param array $options
     *   - est_ajustement: bool (si true, ignore la réservation)
     *   - type_mouvement: string (forcer le type du mouvement, ex: AJUSTEMENT)
     *   - autoriser_expires: bool
     *   - demande_parent_id, reference_document, raison, metadata, responsable_id, cout_unitaire
     * @return MouvementStock
     */
    public function expedier(
    int $quantite,
    array $options = []
): MouvementStock {

    $estAjustement =
        $options['est_ajustement'] ?? false;

    // ================================================================
    // 1. VÉRIFIER LA RÉSERVATION
    // ================================================================

    if (
        !$estAjustement &&
        $this->quantite_reserve < $quantite
    ) {
        throw new \Exception(
            "Réservation insuffisante pour le produit ID " .
            "{$this->produit_id}. " .
            "Réservé : {$this->quantite_reserve}, " .
            "Demandé : {$quantite}"
        );
    }

    // ================================================================
    // 2. VÉRIFIER LES DOUBLONS
    // ================================================================

    if (
        isset($options['demande_parent_id']) &&
        isset($options['type_expedition'])
    ) {

        $sortieExistante =
            MouvementStock::where(
                'produit_id',
                $this->produit_id
            )
            ->where(
                'demande_parent_id',
                $options['demande_parent_id']
            )
            ->where(
                'type_mouvement',
                'SORTIE'
            )
            ->whereJsonContains(
                'metadata->source',
                $options['type_expedition']
            )
            ->exists();

        if ($sortieExistante) {
            throw new \Exception(
                "Double sortie détectée pour le produit ID " .
                "{$this->produit_id}. " .
                "La sortie a déjà été effectuée."
            );
        }
    }

    // ================================================================
    // 3. MISE À JOUR DU STOCK PHYSIQUE
    // ================================================================

    $quantiteAvant =
        $this->quantite_disponible;

    if (!$estAjustement) {
        $this->quantite_reserve -= $quantite;
    }

    $this->quantite_disponible -=
        $quantite;

    if ($this->quantite_disponible < 0) {
        throw new \Exception(
            "Le stock disponible ne peut pas devenir négatif."
        );
    }

    $this->date_dernier_sortie =
        now();

    $this->save();

    // ================================================================
    // 4. CRÉER LE MOUVEMENT
    // ================================================================

    $typeMouvement =
        $options['type_mouvement']
        ?? 'SORTIE';

    $mouvement =
        $this->enregistrerMouvementSortie(
            $quantite,
            $quantiteAvant,
            [
                'type_mouvement' =>
                    $typeMouvement,

                'reference_document' =>
                    $options['reference_document']
                    ?? null,

                'raison' =>
                    $options['raison']
                    ?? 'Sortie de stock',

                'metadata' =>
                    array_merge(
                        $options['metadata'] ?? [],
                        [
                            'type_expedition' =>
                                $options['type_expedition']
                                ?? 'APPROVISIONNEMENT_EXPEDITION',
                        ]
                    ),

                'responsable_id' =>
                    $options['responsable_id']
                    ?? auth()->id(),

                'cout_unitaire' =>
                    $options['cout_unitaire']
                    ?? $this->produit?->prix_achat
                    ?? 0,

                'est_ajustement' =>
                    $estAjustement,
            ]
        );

    // ================================================================
    // 5. CONSOMMER LES LOTS
    // ================================================================

    $doitConsommerLots =
        in_array(
            $typeMouvement,
            [
                'SORTIE',
                'TRANSFERT',
                'AJUSTEMENT',
            ]
        );

    if ($doitConsommerLots) {

        $lotService =
            app(LotStockService::class);

        /*
         * IMPORTANT :
         *
         * Si l'expédition a été préparée,
         * on transmet la répartition exacte.
         *
         * Exemple :
         *
         * [
         *   ['lot_stock_id' => 10, 'quantite' => 2],
         *   ['lot_stock_id' => 15, 'quantite' => 3],
         *   ['lot_stock_id' => 21, 'quantite' => 2],
         * ]
         */

        $consommation =
            $lotService->consommerLots(
                $this,
                $quantite,
                $mouvement,
                [
                    'autoriser_expires' =>
                        $options['autoriser_expires']
                        ?? false,

                    'lots_fifo' =>
                        $options['lots_fifo']
                        ?? null,
                ]
            );

        /*
         * Les coûts provenant des lots sont désormais
         * ceux utilisés par le mouvement.
         */

        if (is_array($consommation)) {

            if (
                isset(
                    $consommation['cout_total_ht']
                )
            ) {
                $mouvement->cout_total =
                    $consommation[
                        'cout_total_ht'
                    ];
            }

            if (
                isset(
                    $consommation['cout_unitaire']
                )
            ) {
                $mouvement->cout_unitaire =
                    $consommation[
                        'cout_unitaire'
                    ];
            }

            $mouvement->save();
        }
    }

    // ================================================================
    // 6. LOG
    // ================================================================

    Log::info(
        'Expédition stock effectuée',
        [
            'stock_id' =>
                $this->id,

            'quantite' =>
                $quantite,

            'mouvement_id' =>
                $mouvement->mouvement_id,

            'type_mouvement' =>
                $typeMouvement,

            'est_ajustement' =>
                $estAjustement,

            'multi_lots' =>
                isset($options['lots_fifo']) &&
                is_array($options['lots_fifo']) &&
                count($options['lots_fifo']) > 1,
        ]
    );

    return $mouvement;
}
    /**
     * Réceptionner du stock (entrée)
     * 
     * @param int $quantite
     * @param array $options
     *   - type_mouvement: string (forcer le type du mouvement, ex: AJUSTEMENT)
     *   - prix_achat: float
     *   - date_expiration: date
     *   - numero_lot: string
     *   - ligne_reception_id: int (pour lier à la ligne de réception)
     *   - metadata, reference_document, raison, responsable_id
     * @return MouvementStock
     */
    public function receptionner(int $quantite, array $options = []): MouvementStock
    {
        $prixAchat = $options['prix_achat'] ?? $this->produit?->prix_achat ?? 0;
        $quantiteAvant = $this->quantite_disponible;
    
        // 1. Mise à jour du stock physique
        $this->quantite_disponible += $quantite;
        $this->date_dernier_entree = now();
        $this->save();
    
        // 2. Création du mouvement d'entrée (type peut être forcé)
        $typeMouvement = $options['type_mouvement'] ?? 'ENTREE';
        $mouvement = $this->enregistrerMouvementEntree($quantite, $quantiteAvant, [
            'type_mouvement' => $typeMouvement,
            'cout_unitaire' => $prixAchat,
            'reference_document' => $options['reference_document'] ?? null,
            'raison' => $options['raison'] ?? 'Réception stock',
            'metadata' => array_merge(
                $options['metadata'] ?? [],
                ['numero_lot_fournisseur' => $options['numero_lot'] ?? null]
            ),
            'date_lot' => $options['date_lot'] ?? now(),
            'date_expiration' => $options['date_expiration'] ?? null,
            'responsable_id' => $options['responsable_id'] ?? auth()->id(),
        ]);
    
        // 3. Création du lot via le service (sauf pour ANNULATION)
        $doitCreerLot = in_array($typeMouvement, ['ENTREE', 'RETOUR', 'AJUSTEMENT']);
        if ($doitCreerLot) {
            $lotService = app(LotStockService::class);
            $lotService->creerLotDepuisMouvement($mouvement);
        }
    
        // 4. Mise à jour de la ligne de réception si fournie
        if (isset($options['ligne_reception_id']) && $options['ligne_reception_id']) {
            $ligneReception = LigneReceptionFournisseur::find($options['ligne_reception_id']);
            if ($ligneReception && $mouvement->lotEntree) {
                $ligneReception->lot_stock_id = $mouvement->lotEntree->id;
                $ligneReception->save();
            }
        }
    
        Log::info('Réception stock effectuée', [
            'stock_id' => $this->id,
            'quantite' => $quantite,
            'mouvement_id' => $mouvement->mouvement_id,
            'type_mouvement' => $typeMouvement,
        ]);
    
        return $mouvement;
    }

    /**
     * RÃ‰CEPTIONNER AU POINT INTERMÃ‰DIAIRE
     * 
     * MÃªme logique que receptionner() mais avec un contexte spÃ©cifique
     * 
     * @param int $quantite
     * @param array $options
     * @return MouvementStock
     */
    public function receptionnerIntermediaire(int $quantite, array $options = []): MouvementStock
    {
        $options['raison'] = $options['raison'] ?? 'Arrivee Intermediaire';
        $options['metadata'] = array_merge(
            $options['metadata'] ?? [],
            ['source' => 'APPROVISIONNEMENT_INTERMEDIAIRE', 'type' => 'ARRIVEE_INTERMEDIAIRE']
        );
        
        return $this->receptionner($quantite, $options);
    }

    /**
     * EXPÃ‰DIER DEPUIS LE POINT INTERMÃ‰DIAIRE
     * 
     * MÃªme logique que expedier() mais avec un contexte spÃ©cifique
     * 
     * @param int $quantite
     * @param array $options
     * @return MouvementStock
     */
    public function expedierIntermediaire(
    int $quantite,
    array $options = []
): MouvementStock {

    $options['type_expedition'] =
        'DEPART_INTERMEDIAIRE';

    $options['metadata'] = array_merge(
        $options['metadata'] ?? [],
        [
            'source' =>
                'APPROVISIONNEMENT_INTERMEDIAIRE',

            'type' =>
                'DEPART_INTERMEDIAIRE',
        ]
    );

    if ($this->quantite_disponible < $quantite) {
        throw new \Exception(
            "Stock disponible insuffisant pour le produit ID {$this->produit_id} " .
            "au point intermédiaire. " .
            "Disponible : {$this->quantite_disponible}, " .
            "Demandé : {$quantite}"
        );
    }

    return $this->expedier(
        $quantite,
        $options
    );
}
            /**
     * Réserver des IMEI pour un transfert
     */
    public function reserverImeis(array $imeiIds, array $options = []): array
    {
        $imeis = ProduitImei::whereIn('id', $imeiIds)
            ->where('succursale_id', $this->succursale_id)
            ->where('produit_id', $this->produit_id)
            ->where('statut', ProduitImei::STATUT_EN_STOCK)
            ->lockForUpdate()
            ->get();
    
        if ($imeis->count() !== count($imeiIds)) {
            $trouves = $imeis->pluck('id')->toArray();
            $manquants = array_diff($imeiIds, $trouves);
            throw new \Exception("Certains IMEI ne sont pas disponibles: " . implode(', ', $manquants));
        }
    
        $reserved = [];
        foreach ($imeis as $imei) {
            $imei->statut = ProduitImei::STATUT_RESERVE;
            $imei->save();
            
            // Enregistrer la réservation dans les métadonnées
            $imei->logAudit('RESERVATION_IMEI', [
                'demande_parent_id' => $options['demande_parent_id'] ?? null,
                'responsable_id' => $options['responsable_id'] ?? auth()->id(),
                'date_reservation' => now()
            ]);
            
            $reserved[] = $imei->id;
        }
    
        // Mettre à jour la quantité réservée dans le stock
        $this->quantite_reserve += count($imeis);
        $this->save();
    
        $this->logAudit('RESERVATION_IMEI', [
            'imei_ids' => $reserved,
            'quantite' => count($imeis),
            'demande_parent_id' => $options['demande_parent_id'] ?? null
        ]);
    
        return $reserved;
    }

    /**
     * Expédier des IMEI (sortie définitive)
     */
    public function expedierImeis(array $imeiIds, array $options = []): array
    {
        $imeis = ProduitImei::whereIn('id', $imeiIds)
            ->where('succursale_id', $this->succursale_id)
            ->where('produit_id', $this->produit_id)
            ->where('statut', ProduitImei::STATUT_RESERVE)
            ->lockForUpdate()
            ->get();
    
        if ($imeis->count() !== count($imeiIds)) {
            $trouves = $imeis->pluck('id')->toArray();
            $manquants = array_diff($imeiIds, $trouves);
            throw new \Exception("Certains IMEI ne sont pas réservés: " . implode(', ', $manquants));
        }
    
        $expedies = [];
        foreach ($imeis as $imei) {
            $imei->statut = ProduitImei::STATUT_TRANSFERE;
            $imei->save();
            
            // Enregistrer l'expédition
            $imei->logAudit('EXPEDITION_IMEI', [
                'destination_succursale_id' => $options['destination_succursale_id'] ?? null,
                'demande_parent_id' => $options['demande_parent_id'] ?? null,
                'responsable_id' => $options['responsable_id'] ?? auth()->id(),
                'date_expedition' => now()
            ]);
            
            $expedies[] = $imei->id;
        }
    
        // Mettre à jour les quantités du stock
        $this->quantite_reserve -= count($imeis);
        $this->quantite_disponible -= count($imeis);
        $this->save();
    
        $this->logAudit('EXPEDITION_IMEI', [
            'imei_ids' => $expedies,
            'quantite' => count($imeis),
            'destination_succursale_id' => $options['destination_succursale_id'] ?? null,
            'demande_parent_id' => $options['demande_parent_id'] ?? null
        ]);
    
        return $expedies;
    }
    
    /**
     * Réceptionner des IMEI (arrivée à destination)
     */
    public function receptionnerImeis(array $imeiIds, array $options = []): array
    {
        $imeis = ProduitImei::whereIn('id', $imeiIds)
            ->where('statut', ProduitImei::STATUT_TRANSFERE)
            ->lockForUpdate()
            ->get();
    
        if ($imeis->count() !== count($imeiIds)) {
            $trouves = $imeis->pluck('id')->toArray();
            $manquants = array_diff($imeiIds, $trouves);
            throw new \Exception("Certains IMEI ne sont pas en transit: " . implode(', ', $manquants));
        }
    
        // Vérifier que tous les IMEI sont destinés à cette succursale
        foreach ($imeis as $imei) {
            if ($imei->succursale_id != $this->succursale_id) {
                throw new \Exception("L'IMEI {$imei->numero_imei} n'est pas destiné à cette succursale");
            }
        }
    
        $recus = [];
        foreach ($imeis as $imei) {
            $imei->statut = ProduitImei::STATUT_EN_STOCK;
            $imei->save();
            
            // Enregistrer la réception
            $imei->logAudit('RECEPTION_IMEI', [
                'demande_parent_id' => $options['demande_parent_id'] ?? null,
                'responsable_id' => $options['responsable_id'] ?? auth()->id(),
                'date_reception' => now()
            ]);
            
            $recus[] = $imei->id;
        }
    
        // Mettre à jour la quantité disponible du stock
        $this->quantite_disponible += count($imeis);
        $this->save();
    
        $this->logAudit('RECEPTION_IMEI', [
            'imei_ids' => $recus,
            'quantite' => count($imeis),
            'demande_parent_id' => $options['demande_parent_id'] ?? null
        ]);
    
        return $recus;
    }
    
    /**
     * Libérer la réservation des IMEI
     */
    public function libererReservationImeis(array $imeiIds = null): array
    {
        $query = ProduitImei::where('succursale_id', $this->succursale_id)
            ->where('produit_id', $this->produit_id)
            ->where('statut', ProduitImei::STATUT_RESERVE);
    
        if ($imeiIds !== null) {
            $query->whereIn('id', $imeiIds);
        }
    
        $imeis = $query->lockForUpdate()->get();
    
        if ($imeis->isEmpty()) {
            return [];
        }
    
        $liberes = [];
        foreach ($imeis as $imei) {
            $imei->statut = ProduitImei::STATUT_EN_STOCK;
            $imei->save();
            
            // Enregistrer la libération
            $imei->logAudit('LIBERATION_RESERVATION_IMEI', [
                'date_liberation' => now()
            ]);
            
            $liberes[] = $imei->id;
        }
    
        // Mettre à jour la quantité réservée
        $this->quantite_reserve -= count($imeis);
        $this->save();
    
        $this->logAudit('LIBERATION_RESERVATION_IMEI', [
            'imei_ids' => $liberes,
            'quantite' => count($imeis)
        ]);
    
        return $liberes;
    }
    
    /**
     * Récupérer les IMEI disponibles pour un produit
     */
    public function getImeisDisponibles(): array
    {
        return ProduitImei::where('succursale_id', $this->succursale_id)
            ->where('produit_id', $this->produit_id)
            ->where('statut', ProduitImei::STATUT_EN_STOCK)
            ->select('id', 'numero_imei', 'numero_serie', 'statut', 'date_fin_garanti')
            ->get()
            ->toArray();
    }
    
    /**
     * Récupérer les IMEI réservés
     */
    public function getImeisReserves(): array
    {
        return ProduitImei::where('succursale_id', $this->succursale_id)
            ->where('produit_id', $this->produit_id)
            ->where('statut', ProduitImei::STATUT_RESERVE)
            ->select('id', 'numero_imei', 'numero_serie', 'statut')
            ->get()
            ->toArray();
    }
    
    /**
     * Récupérer les IMEI en transit (transférés)
     */
    public function getImeisTransferes(): array
    {
        return ProduitImei::where('succursale_id', $this->succursale_id)
            ->where('produit_id', $this->produit_id)
            ->where('statut', ProduitImei::STATUT_TRANSFERE)
            ->select('id', 'numero_imei', 'numero_serie', 'statut')
            ->get()
            ->toArray();
    }
    
    /**
     * Vérifier si un produit utilise des IMEI
     */
    public function utiliseImeis(): bool
    {
        if (!$this->produit) return false;
        return in_array($this->produit->type, ['TELEPHONE', 'ELECTRONIQUE']);
    }

    protected function enregistrerMouvementSortie(int $quantite, int $quantiteAvant, array $options = []): MouvementStock
    {
        $produit = $this->produit;
        $coutUnitaire = $options['cout_unitaire'] ?? $produit?->prix_achat ?? 0;
        $typeMouvement = $options['type_mouvement'] ?? 'SORTIE';
        $estAjustement = $options['est_ajustement'] ?? false;
    
        $mouvement = MouvementStock::create([
            'reference_mouvement' => MouvementStock::genererReference(),
            'succursale_id' => $this->succursale_id,
            'produit_id' => $this->produit_id,
            'stock_source_id' => $this->id,
            'stock_destination_id' => null,
            'demande_parent_id' => $options['demande_parent_id'] ?? null,
            'type_mouvement' => $typeMouvement,
            'statut' => 'VALIDE',
            'quantite' => $quantite,
            'quantite_avant' => $quantiteAvant,
            'quantite_apres' => $this->quantite_disponible,
            'cout_unitaire' => $coutUnitaire,
            'cout_total' => $coutUnitaire * $quantite,
            'responsable_id' => $options['responsable_id'] ?? auth()->id(),
            'valide_par_id' => $options['responsable_id'] ?? auth()->id(),
            'date_mouvement' => now(),
            'date_validation' => now(),
            'reference_document' => $options['reference_document'] ?? null,
            'raison' => $options['raison'] ?? 'Sortie de stock',
            'metadata' => $options['metadata'] ?? [],
            // Champs FIFO obsolètes (conservés pour compatibilité)
            'quantite_restante_lot' => null,
            'est_lot_fifo' => 0, // 0 car ce n'est pas un lot FIFO (un lot est une entrée)
            'prix_achat_lot' => null,
            'lots_sortie' => null,
            'lots_entree' => null,
        ]);
    
        return $mouvement;
    }
    
    protected function enregistrerMouvementEntree(int $quantite, int $quantiteAvant, array $options = []): MouvementStock
    {
        $coutUnitaire = $options['cout_unitaire'] ?? $this->produit?->prix_achat ?? 0;
        $typeMouvement = $options['type_mouvement'] ?? 'ENTREE';
    
        $mouvement = MouvementStock::create([
            'reference_mouvement' => MouvementStock::genererReference(),
            'succursale_id' => $this->succursale_id,
            'produit_id' => $this->produit_id,
            'stock_destination_id' => $this->id,
            'demande_parent_id' => $options['demande_parent_id'] ?? null,
            'type_mouvement' => $typeMouvement,
            'statut' => 'VALIDE',
            'quantite' => $quantite,
            'quantite_avant' => $quantiteAvant,
            'quantite_apres' => $this->quantite_disponible,
            'cout_unitaire' => $coutUnitaire,
            'cout_total' => $coutUnitaire * $quantite,
            'date_lot' => $options['date_lot'] ?? now(),
            'date_expiration_lot' => $options['date_expiration'] ?? null,
            'responsable_id' => $options['responsable_id'] ?? auth()->id(),
            'valide_par_id' => $options['responsable_id'] ?? auth()->id(),
            'date_mouvement' => now(),
            'date_validation' => now(),
            'reference_document' => $options['reference_document'] ?? null,
            'raison' => $options['raison'] ?? 'Entree de stock',
            'metadata' => $options['metadata'] ?? [],
            // Champs FIFO obsolètes
            'quantite_restante_lot' => null,
            'est_lot_fifo' => 1, // 1 car un lot est créé à partir de ce mouvement (mais géré par LotStockService)
            'prix_achat_lot' => null,
            'lots_sortie' => null,
            'lots_entree' => null,
        ]);
    
        return $mouvement;
    }
    
    /**
     * Ajuster le stock (augmentation ou diminution)
     */
    public function ajuster(int $nouvelleQuantite, array $options = []): MouvementStock
    {
        $ancienneQuantite = $this->quantite_disponible;
        $difference = $nouvelleQuantite - $ancienneQuantite;
    
        if ($difference == 0) {
            throw new \Exception('La quantité est déjà égale à la valeur actuelle.');
        }
    
        if ($difference > 0) {
            return $this->receptionner($difference, [
                'prix_achat' => $options['prix_achat'] ?? $this->produit?->prix_achat ?? 0,
                'date_expiration' => $options['date_expiration'] ?? null,
                'type_mouvement' => 'AJUSTEMENT',
                'est_ajustement' => true,
                'raison' => $options['raison'] ?? 'Ajustement (augmentation)',
                'reference_document' => $options['reference_document'] ?? 'AJUST+'.now()->format('YmdHis'),
                'metadata' => array_merge($options['metadata'] ?? [], [
                    'type_ajustement' => 'AUGMENTATION',
                    'ancienne_quantite' => $ancienneQuantite,
                    'nouvelle_quantite' => $nouvelleQuantite,
                ]),
                'responsable_id' => $options['responsable_id'] ?? auth()->id(),
            ]);
        } else {
            return $this->expedier(abs($difference), [
                'type_mouvement' => 'AJUSTEMENT',
                'est_ajustement' => true,
                'raison' => $options['raison'] ?? 'Ajustement (diminution)',
                'reference_document' => $options['reference_document'] ?? 'AJUST-'.now()->format('YmdHis'),
                'metadata' => array_merge($options['metadata'] ?? [], [
                    'type_ajustement' => 'DIMINUTION',
                    'ancienne_quantite' => $ancienneQuantite,
                    'nouvelle_quantite' => $nouvelleQuantite,
                ]),
                'autoriser_expires' => $options['autoriser_expires'] ?? false,
                'responsable_id' => $options['responsable_id'] ?? auth()->id(),
            ]);
        }
    }


    public function reserver(int $quantite): self
{
    if ($quantite <= 0) {
        throw new \InvalidArgumentException('La quantité à réserver doit être supérieure à zéro.');
    }

    $this->refresh();

    $stockPhysique = (int) $this->quantite_disponible;
    $stockReserve = (int) $this->quantite_reserve;

    // Stock réellement libre
    $stockLibre = $stockPhysique - $stockReserve;

    if ($stockLibre < $quantite) {
        throw new \Exception(
            "Stock insuffisant. Disponible: {$stockLibre}, Demandé: {$quantite}"
        );
    }

    // Maximum 80 % du stock physique
    $limiteReservation = floor($stockPhysique * 0.8);

    if (($stockReserve + $quantite) > $limiteReservation) {
        throw new \Exception(
            "Réservation refusée. Maximum autorisé : {$limiteReservation}. "
            ."Réservé : {$stockReserve}. "
            ."Demandé : {$quantite}."
        );
    }

    $this->increment('quantite_reserve', $quantite);

    $this->refresh();

    $this->logAudit('RESERVATION_STOCK', [
        'quantite_reservee'      => $quantite,
        'stock_physique'         => $this->quantite_disponible,
        'stock_libre'            => $this->quantite_disponible - $this->quantite_reserve,
        'stock_reserve'          => $this->quantite_reserve,
        'limite_reservation'     => $limiteReservation,
    ]);

    return $this;
}

    public function confirmerSortie($quantite)
    {
        if ($this->quantite_reserve < $quantite) {
            throw new \Exception("RÃ©servation insuffisante. RÃ©servÃ©: {$this->quantite_reserve}, Ã€ confirmer: {$quantite}");
        }

        $this->quantite_reserve -= $quantite;
        $this->save();
        


        $this->logAudit('CONFIRMATION_SORTIE', [
            'quantite_sortie' => $quantite,
            'stock_reserve_restant' => $this->quantite_reserve
        ]);

        return $this;
    }

    public function libererReservation($quantite = null)
    {
        $quantite = $quantite ?? $this->quantite_reserve;
        
        if ($this->quantite_reserve < $quantite) {
            throw new \Exception("Réservation insuffisante à libérer");
        }
    
        //  Ne toucher qu'à quantite_reserve
        $this->quantite_reserve -= $quantite;
        $this->save();
    
        $this->logAudit('LIBERATION_RESERVATION', [
            'quantite_libere' => $quantite
        ]);
    
        return $this;
    }

    public function ajouterStock($quantite, $type = 'ENTREE', $raison = null)
    {
        $ancienneQuantite = $this->quantite_disponible;
        $this->quantite_disponible += $quantite;
        $this->date_dernier_entree = now();
        $this->save();


        $this->verifierSeuils();

        $this->logAudit('AUGMENTATION_STOCK', [
            'ancienne_quantite' => $ancienneQuantite,
            'nouvelle_quantite' => $this->quantite_disponible,
            'quantite_ajoutee' => $quantite,
            'type' => $type,
            'raison' => $raison
        ]);

        if ($this->estEnAlerte && method_exists($this, 'notifierAlerte')) {
            $this->notifierAlerte();
        }
        
        return $this;
    }

    public function diminuerStock($quantite, $type = 'SORTIE', $raison = null)
    {
        if ($this->quantite_disponible < $quantite) {
            throw new \Exception("Stock insuffisant. Disponible: {$this->quantite_disponible}, DemandÃ©: {$quantite}");
        }

        $ancienneQuantite = $this->quantite_disponible;
        $this->quantite_disponible -= $quantite;
        $this->date_dernier_sortie = now();
        $this->save();


        $this->verifierSeuils();

        $this->logAudit('DIMINUTION_STOCK', [
            'ancienne_quantite' => $ancienneQuantite,
            'nouvelle_quantite' => $this->quantite_disponible,
            'quantite_retiree' => $quantite,
            'type' => $type,
            'raison' => $raison
        ]);

        return $this;
    }

    public function getStockPourProduit($produitId)
    {
        if ($this->produit_id == $produitId) return $this->quantite_disponible;
        $stock = self::where('succursale_id', $this->succursale_id)
            ->where('produit_id', $produitId)->first();
        return $stock ? $stock->quantite_disponible : 0;
    }

    public function stockDisponible(): int
    {
        return $this->quantite_disponible - $this->quantite_reserve;
    }

    // ============================================================
    // MÃ‰THODES PROTECTED
    // ============================================================

    protected function verifierSeuils()
    {
        $etaitEnAlerte = !is_null($this->date_alerte_stock);
        $estEnAlerte = $this->est_en_alerte;

        if ($estEnAlerte && !$etaitEnAlerte) {
            $this->date_alerte_stock = now();
            $this->save();
            if (method_exists($this, 'notifierSeuilCritique')) {
                $this->notifierSeuilCritique();
            }
        } elseif (!$estEnAlerte && $etaitEnAlerte) {
            $this->date_alerte_stock = null;
            $this->save();
            if (method_exists($this, 'notifierRetourNormal')) {
                $this->notifierRetourNormal();
            }
        }
    }

    public function mettreAJourRotation($quantite_vendue, $periode_jours = 30)
    {
        if ($periode_jours <= 0) return;
        $rotation = ($quantite_vendue / $periode_jours) * 30;
        $this->rotation_stock = round($rotation, 2);
        $this->stock_moyen = round(($this->stock_moyen + $this->quantite_disponible) / 2, 2);
        $this->save();
    }

    public function getStatistiquesAttribute()
    {
        return [
            'entrees_30j' => $this->mouvements()
                ->whereIn('type_mouvement', ['ENTREE', 'RETOUR'])
                ->where('date_mouvement', '>=', now()->subDays(30))->sum('quantite'),
            'sorties_30j' => $this->mouvements()
                ->whereIn('type_mouvement', ['SORTIE', 'TRANSFERT'])
                ->where('date_mouvement', '>=', now()->subDays(30))->sum('quantite'),
            'valeur_totale' => $this->valeur_stock,
            'pmp' => $this->pmp,
            'lots_fifo' => $this->lots()->where('statut', 'ACTIF')->count(),
            'lots_expires' => $this->lots()
                ->whereNotNull('date_expiration')
                ->where('date_expiration', '<=', now())
                ->count()
        ];
    }
}