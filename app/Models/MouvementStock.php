<?php
// app/Models/MouvementStock.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\Auditable;
use App\Traits\Notifiable;
use App\Models\LotStock;
use Illuminate\Support\Facades\DB;

class MouvementStock extends Model
{
    use HasFactory, SoftDeletes, Auditable, Notifiable;

    protected $table = 'mouvements_stock';
    protected $primaryKey = 'mouvement_id';

    const TYPES_MOUVEMENT = [
        'ENTREE', 'SORTIE', 'TRANSFERT', 'RETOUR', 
        'AJUSTEMENT', 'INVENTAIRE'
    ];

    const STATUTS = [
        'BROUILLON' => 'Brouillon',
        'VALIDE' => 'Validé',
        'ANNULE' => 'Annulé',
        'REFUSE' => 'Refusé'
    ];

    protected $fillable = [
        'reference_mouvement',
        'succursale_id',
        'produit_id',
        'stock_source_id',
        'stock_destination_id',
        'demande_parent_id',
        'mouvement_parent_id',
        'mouvement_origine_id',
        'type_mouvement',
        'statut',
        'quantite',
        'quantite_avant',
        'quantite_apres',
        'quantite_casse',
        'quantite_perdue',
        'quantite_non_conforme',
        'motif_ecart',
        'lots_entree',
        'lots_sortie',
        'date_lot',
        'date_expiration_lot',
        'prix_achat_lot',
        'quantite_restante_lot',
        'est_lot_fifo',
        'cout_unitaire',
        'cout_total',
        'transporteur',
        'numero_vehicule',
        'nom_chauffeur',
        'telephone_chauffeur',
        'numero_colis',
        'reference_document',
        'raison',
        'notes',
        'metadata',
        'responsable_id',
        'valide_par_id',
        'date_validation',
        'date_mouvement'
    ];

    protected $casts = [
        'date_mouvement' => 'datetime',
        'date_validation' => 'datetime',
        'date_lot' => 'datetime',
        'date_expiration_lot' => 'date',
        'cout_unitaire' => 'decimal:2',
        'cout_total' => 'decimal:2',
        'prix_achat_lot' => 'decimal:2',
        'metadata' => 'array',
        'lots_entree' => 'array',
        'lots_sortie' => 'array',
        'quantite' => 'integer',
        'quantite_avant' => 'integer',
        'quantite_apres' => 'integer',
        'quantite_restante_lot' => 'integer',
        'est_lot_fifo' => 'boolean'
    ];

    protected $appends = [
        'est_entree',
        'est_sortie',
        'montant_total',
        'couleur_statut',
        'libelle_statut',
        'type_mouvement_libelle',
        'est_expire',
        'jours_avant_expiration'
    ];

    // ============================================================
    // BOOT
    // ============================================================

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($mouvement) {
            if (empty($mouvement->reference_mouvement)) {
                $mouvement->reference_mouvement = static::genererReference();
            }
            if (empty($mouvement->statut)) {
                $mouvement->statut = 'BROUILLON';
            }
        });
    }

    // ============================================================
    // RELATIONS
    // ============================================================

    public function succursale()
    {
        return $this->belongsTo(Succursale::class, 'succursale_id');
    }

    public function produit()
    {
        return $this->belongsTo(Produit::class, 'produit_id');
    }

    public function stockSource()
    {
        return $this->belongsTo(StockSuccursale::class, 'stock_source_id');
    }

    public function stockDestination()
    {
        return $this->belongsTo(StockSuccursale::class, 'stock_destination_id');
    }

    // ✅ Lien vers la demande parente (Approvisionnement ou Transfert)
    public function demandeParente()
    {
        return $this->belongsTo(Approvisionnement::class, 'demande_parent_id');
    }

    public function parent()
    {
        return $this->belongsTo(MouvementStock::class, 'mouvement_parent_id', 'mouvement_id');
    }

    public function enfants()
    {
        return $this->hasMany(MouvementStock::class, 'mouvement_parent_id', 'mouvement_id');
    }

    public function mouvementOrigine()
    {
        return $this->belongsTo(MouvementStock::class, 'mouvement_origine_id', 'mouvement_id');
    }

    public function responsable()
    {
        return $this->belongsTo(Utilisateur::class, 'responsable_id');
    }

    public function validePar()
    {
        return $this->belongsTo(Utilisateur::class, 'valide_par_id');
    }

    // ============================================================
    // ATTRIBUTS
    // ============================================================

    public function getEstEntreeAttribute()
    {
        return in_array($this->type_mouvement, ['ENTREE', 'RETOUR']);
    }

    public function getEstSortieAttribute()
    {
        return in_array($this->type_mouvement, ['SORTIE', 'TRANSFERT']);
    }

    public function getMontantTotalAttribute()
    {
        return $this->cout_total ?? ($this->cout_unitaire * $this->quantite);
    }

    public function getCouleurStatutAttribute()
    {
        return match($this->statut) {
            'BROUILLON' => 'gray',
            'VALIDE' => 'green',
            'ANNULE' => 'red',
            'REFUSE' => 'darkred',
            default => 'gray'
        };
    }

    public function getLibelleStatutAttribute()
    {
        return self::STATUTS[$this->statut] ?? $this->statut;
    }

    public function getTypeMouvementLibelleAttribute()
    {
        $labels = [
            'ENTREE' => 'Entrée',
            'SORTIE' => 'Sortie',
            'TRANSFERT' => 'Transfert',
            'RETOUR' => 'Retour',
            'AJUSTEMENT' => 'Ajustement',
            'INVENTAIRE' => 'Inventaire'
        ];
        return $labels[$this->type_mouvement] ?? $this->type_mouvement;
    }

    public function getEstExpireAttribute()
    {
        if (!$this->date_expiration_lot) return false;
        return $this->date_expiration_lot <= now()->startOfDay();
    }

    public function getJoursAvantExpirationAttribute()
    {
        if (!$this->date_expiration_lot) return null;
        return now()->startOfDay()->diffInDays($this->date_expiration_lot, false);
    }

    // ============================================================
    // MÉTHODES STATIQUES
    // ============================================================

    public static function genererReference()
    {
        $prefix = 'MVT';
        $date = now()->format('Ymd');
        $microtime = substr(microtime(true) * 10000, -6);
        $random = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
        $count = static::whereDate('created_at', today())->count();
        $sequence = str_pad($count + 1, 4, '0', STR_PAD_LEFT);
        
        $reference = "{$prefix}{$date}-{$microtime}-{$sequence}-{$random}";
        
        $tentatives = 0;
        while (static::where('reference_mouvement', $reference)->exists() && $tentatives < 10) {
            $microtime = substr(microtime(true) * 10000, -6);
            $random = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
            $reference = "{$prefix}{$date}-{$microtime}-{$sequence}-{$random}";
            $tentatives++;
        }
        
        return $reference;
    }

    /**
     * Créer un mouvement d'entrée ET le lot associé.
     * Délègue la création du lot à LotStockService.
     */
    public static function creerLotEntree($produitId, $stockId, $quantite, $prixAchat, $options = [])
    {
        $stock = StockSuccursale::find($stockId);
        if (!$stock) {
            throw new \Exception('Stock non trouvé');
        }
    
        $quantiteAvant = $stock->quantite_disponible;
    
        // ✅ Création du mouvement (sans les champs de lot)
        $mouvement = new self();
        $mouvement->reference_mouvement = self::genererReference();
        $mouvement->succursale_id = $options['succursale_id'] ?? $stock->succursale_id;
        $mouvement->produit_id = $produitId;
        $mouvement->stock_destination_id = $stockId;
        $mouvement->demande_parent_id = $options['demande_parent_id'] ?? null;
        $mouvement->type_mouvement = 'ENTREE';
        $mouvement->statut = 'VALIDE';
    
        $mouvement->quantite = $quantite;
        $mouvement->quantite_avant = $quantiteAvant;
        $mouvement->quantite_apres = $quantiteAvant + $quantite;
    
        $mouvement->cout_unitaire = $prixAchat;
        $mouvement->cout_total = $quantite * $prixAchat;
    
        // ❌ Supprimer les champs obsolètes (ou les mettre à NULL)
        $mouvement->quantite_restante_lot = null;
        $mouvement->est_lot_fifo = null;
        $mouvement->prix_achat_lot = null;
        $mouvement->date_expiration_lot = $options['date_expiration'] ?? null; // Gardé pour info
    
        $mouvement->date_lot = $options['date_lot'] ?? now();
        $mouvement->date_mouvement = now();
        $mouvement->date_validation = now();
    
        $mouvement->responsable_id = $options['responsable_id'] ?? auth()->id();
        $mouvement->valide_par_id = auth()->id();
        $mouvement->raison = $options['raison'] ?? 'Création de lot FIFO';
        $mouvement->reference_document = $options['reference_document'] ?? null;
    
        // ✅ Conserver le numéro de lot dans metadata
        $metadata = $options['metadata'] ?? [];
        if (isset($options['numero_lot_fournisseur'])) {
            $metadata['numero_lot_fournisseur'] = $options['numero_lot_fournisseur'];
        }
        $mouvement->metadata = $metadata;
    
        $mouvement->save();
        $mouvement->mouvement_origine_id = $mouvement->mouvement_id;
        $mouvement->save();
    
        // ✅ Mettre à jour le stock (quantité disponible)
        $stock->quantite_disponible += $quantite;
        $stock->save();
    
        // ✅ Créer le lot via le service
        $lotService = app(\App\Services\Stock\LotStockService::class);
        $lotService->creerLotDepuisMouvement($mouvement);
    
        return $mouvement;
    }

    /**
     * Créer un mouvement de sortie et consommer les lots FIFO/FEFO.
     * Délègue la consommation des lots à LotStockService.
     */
    public static function sortirAvecFIFO($produitId, $stockId, $quantite, $options = [])
    {
        $stock = StockSuccursale::find($stockId);
        if (!$stock) {
            throw new \Exception('Stock non trouvé');
        }
    
        // Vérifier la disponibilité
        if ($stock->quantite_disponible < $quantite) {
            throw new \Exception("Stock insuffisant. Disponible: {$stock->quantite_disponible}, Demandé: {$quantite}");
        }
    
        $quantiteAvant = $stock->quantite_disponible;
    
        // ✅ Création du mouvement de sortie (sans logique FIFO)
        $mouvementSortie = new self();
        $mouvementSortie->reference_mouvement = self::genererReference();
        $mouvementSortie->succursale_id = $options['succursale_id'] ?? auth()->user()->succursale_id ?? 1;
        $mouvementSortie->produit_id = $produitId;
        $mouvementSortie->stock_source_id = $stockId;
        $mouvementSortie->demande_parent_id = $options['demande_parent_id'] ?? null;
        $mouvementSortie->type_mouvement = $options['type_sortie'] ?? 'SORTIE';
        $mouvementSortie->statut = 'VALIDE';
    
        $mouvementSortie->quantite = $quantite;
        $mouvementSortie->quantite_avant = $quantiteAvant;
        $mouvementSortie->quantite_apres = $quantiteAvant - $quantite;
    
        // Ces champs ne seront plus utilisés (mis à NULL)
        $mouvementSortie->quantite_restante_lot = null;
        $mouvementSortie->est_lot_fifo = null;
        $mouvementSortie->prix_achat_lot = null;
    
        $mouvementSortie->date_mouvement = now();
        $mouvementSortie->date_validation = now();
        $mouvementSortie->responsable_id = auth()->id();
        $mouvementSortie->valide_par_id = auth()->id();
        $mouvementSortie->raison = $options['raison'] ?? 'Sortie FIFO';
        $mouvementSortie->reference_document = $options['reference_document'] ?? null;
    
        $mouvementSortie->metadata = $options['metadata'] ?? [];
    
        $mouvementSortie->save();
        $mouvementSortie->mouvement_origine_id = null; // Plus de lien vers un lot unique
    
        // ✅ Consommer les lots via le service
        $lotService = app(\App\Services\Stock\LotStockService::class);
        $result = $lotService->consommerLots($stock, $quantite, $mouvementSortie, [
            'autoriser_expires' => $options['autoriser_expires'] ?? false,
        ]);
    
        // Le mouvement a déjà été mis à jour par le service (cout_total, cout_unitaire, metadata)
        // On ne fait que le sauvegarder à nouveau
        $mouvementSortie->save();
    
        return $mouvementSortie;
    }

   /**
     * État FIFO/FEFO d'un produit (lecture depuis LotStock)
     */
    public static function etatFIFO($produitId, $stockId)
    {
        $lots = \App\Models\LotStock::where('produit_id', $produitId)
            ->where('stock_succursale_id', $stockId)
            ->where('statut', 'ACTIF')
            ->where('quantite_restante', '>', 0)
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($lot) {
                $aujourdhui = now()->startOfDay();
                $expiration = $lot->date_expiration ? \Carbon\Carbon::parse($lot->date_expiration)->startOfDay() : null;
                $joursAvantExpiration = $expiration ? $aujourdhui->diffInDays($expiration, false) : null;
    
                $statutExpiration = 'valide';
                if ($expiration) {
                    if ($joursAvantExpiration <= 0) {
                        $statutExpiration = 'expire';
                    } elseif ($joursAvantExpiration <= 7) {
                        $statutExpiration = 'critique';
                    } elseif ($joursAvantExpiration <= 30) {
                        $statutExpiration = 'proche';
                    }
                }
    
                return [
                    'lot_id' => $lot->id,
                    'numero_lot' => $lot->numero_lot,
                    'date_entree' => $lot->created_at->format('d/m/Y'),
                    'date_expiration' => $lot->date_expiration?->format('d/m/Y'),
                    'statut_expiration' => $statutExpiration,
                    'jours_avant_expiration' => $joursAvantExpiration,
                    'quantite_initiale' => $lot->quantite_initiale,
                    'quantite_restante' => $lot->quantite_restante,
                    'prix_achat' => $lot->cout_unitaire,
                    'valeur' => $lot->cout_unitaire * $lot->quantite_restante,
                ];
            });
    
        $totalQuantite = $lots->sum('quantite_restante');
        $totalValeur = $lots->sum('valeur');
    
        return [
            'produit_id' => $produitId,
            'stock_id' => $stockId,
            'lots' => $lots,
            'total_quantite' => $totalQuantite,
            'total_valeur' => $totalValeur,
            'prix_moyen' => $totalQuantite > 0 ? round($totalValeur / $totalQuantite, 2) : 0,
        ];
    }
    
        public function lotEntree()
    {
        return $this->hasOne(LotStock::class, 'mouvement_entree_id', 'mouvement_id');
    }
    
    public function lotsConsommes()
    {
        return $this->belongsToMany(LotStock::class, 'mouvement_stock_lots', 'mouvement_stock_id', 'lot_stock_id')
                    ->withPivot('quantite', 'cout_unitaire')
                    ->withTimestamps();
    }
    public function lotsSortie()
{
    return $this->belongsToMany(
        LotStock::class,
        'mouvement_stock_lots',
        'mouvement_stock_id',
        'lot_stock_id'
    )->withPivot('quantite', 'cout_unitaire')
     ->withTimestamps();
}

    // ============================================================
    // SCOPES
    // ============================================================

    public function scopeEntrees($query)
    {
        return $query->whereIn('type_mouvement', ['ENTREE', 'RETOUR']);
    }

    public function scopeSorties($query)
    {
        return $query->whereIn('type_mouvement', ['SORTIE', 'TRANSFERT']);
    }

    public function scopeParStatut($query, $statut)
    {
        return $query->where('statut', $statut);
    }

    public function scopeValides($query)
    {
        return $query->where('statut', 'VALIDE');
    }

    public function scopeAujourdHui($query)
    {
        return $query->whereDate('date_mouvement', today());
    }

    public function scopeParPeriode($query, $debut, $fin)
    {
        return $query->whereBetween('date_mouvement', [$debut, $fin]);
    }

    public function scopeFIFO($query)
    {
        return $query->where('est_lot_fifo', true);
    }

    public function scopeLotsNonExpires($query)
    {
        return $query->where('est_lot_fifo', true)
            ->where('quantite_restante_lot', '>', 0)
            ->where(function($q) {
                $q->whereNull('date_expiration_lot')
                  ->orWhere('date_expiration_lot', '>', now());
            });
    }
}