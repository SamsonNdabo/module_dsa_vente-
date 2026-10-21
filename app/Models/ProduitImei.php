<?php
// app/Models/ProduitImei.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\Auditable;

class ProduitImei extends Model
{
    use HasFactory, SoftDeletes, Auditable;

    protected $table = 'produit_imei';
    protected $primaryKey = 'id';

    // ================================================================
    // CONSTANTES
    // ================================================================

    const STATUT_EN_STOCK = 'EN_STOCK';
    const STATUT_RESERVE = 'RESERVE';
    const STATUT_EN_PREPARATION = 'EN_PREPARATION';
    const STATUT_EXPEDIE = 'EXPEDIE';
    const STATUT_VENDU = 'VENDU';
    const STATUT_TRANSFERE = 'TRANSFERE';
    const STATUT_RETOURNE = 'RETOURNE';
    const STATUT_MAINTENANCE = 'MAINTENANCE';
    const STATUT_PERDU = 'PERDU';
    const STATUT_CASSE = 'CASSE';
    const STATUT_NON_CONFORME = 'NON_CONFORME';
    const STATUT_ANNULE = 'ANNULE';

    const STATUTS = [
        self::STATUT_EN_STOCK => 'En stock',
        self::STATUT_RESERVE => 'Réservé',
        self::STATUT_EN_PREPARATION => 'En préparation',
        self::STATUT_EXPEDIE => 'Expédié',
        self::STATUT_VENDU => 'Vendu',
        self::STATUT_TRANSFERE => 'Transféré',
        self::STATUT_RETOURNE => 'Retourné',
        self::STATUT_MAINTENANCE => 'En maintenance',
        self::STATUT_PERDU => 'Perdu',
        self::STATUT_CASSE => 'Casse',
        self::STATUT_NON_CONFORME => 'Non conforme',
        self::STATUT_ANNULE => 'Annulé',
    ];

    const STATUTS_DISPONIBLES = [
        self::STATUT_EN_STOCK,
        self::STATUT_RESERVE,
    ];

    // ================================================================
    // FILLABLE
    // ================================================================

    protected $fillable = [
        'produit_id',
        'numero_imei',
        'numero_serie',
        'lot_stock_id',
        'succursale_id',
        'statut',
        'mouvement_entree_id',
        'mouvement_sortie_id',
        'facture_id',
        'commande_id',
        'ligne_commande_id',
        'ligne_approvisionnement_id',
        'client_id',
        'date_entree',
        'date_sortie',
        'date_vente',
        'date_achat',
        'prix_achat',
        'prix_vente',
        'garanti_jour',
        'date_debut_garanti',
        'date_fin_garanti',
        'emplacement',
        'notes',
        'metadata',
    ];

    // ================================================================
    // CASTS
    // ================================================================

    protected $casts = [
        'date_entree' => 'date',
        'date_sortie' => 'date',
        'date_vente' => 'date',
        'date_achat' => 'date',
        'date_debut_garanti' => 'date',
        'date_fin_garanti' => 'date',
        'prix_achat' => 'decimal:2',
        'prix_vente' => 'decimal:2',
        'garanti_jour' => 'integer',
        'metadata' => 'array',
    ];

    // ================================================================
    // APPENDS
    // ================================================================

    protected $appends = [
        'libelle_statut',
        'est_sous_garantie',
        'jours_restants_garantie',
        'est_disponible',
    ];

    // ================================================================
    // RELATIONS
    // ================================================================

    public function produit()
    {
        return $this->belongsTo(Produit::class, 'produit_id');
    }

    public function lotStock()
    {
        return $this->belongsTo(LotStock::class, 'lot_stock_id');
    }

    public function succursale()
    {
        return $this->belongsTo(Succursale::class, 'succursale_id');
    }

    public function mouvementEntree()
    {
        return $this->belongsTo(MouvementStock::class, 'mouvement_entree_id', 'mouvement_id');
    }

    public function mouvementSortie()
    {
        return $this->belongsTo(MouvementStock::class, 'mouvement_sortie_id', 'mouvement_id');
    }

    public function facture()
    {
        return $this->belongsTo(Facture::class, 'facture_id');
    }

    public function commande()
    {
        return $this->belongsTo(CommandeClient::class, 'commande_id');
    }

    public function ligneCommande()
    {
        return $this->belongsTo(LigneCommandeClient::class, 'ligne_commande_id');
    }

    public function ligneApprovisionnement()
    {
        return $this->belongsTo(LigneApprovisionnement::class, 'ligne_approvisionnement_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    // ================================================================
    // ACCESSORS
    // ================================================================

    public function getLibelleStatutAttribute(): string
    {
        return self::STATUTS[$this->statut] ?? $this->statut ?? 'Inconnu';
    }

    public function getEstSousGarantieAttribute(): bool
    {
        if (!$this->date_fin_garanti) {
            return false;
        }
        return $this->date_fin_garanti->isFuture();
    }

    public function getJoursRestantsGarantieAttribute(): ?int
    {
        if (!$this->date_fin_garanti) {
            return null;
        }
        if ($this->date_fin_garanti->isPast()) {
            return 0;
        }
        return (int) now()->diffInDays($this->date_fin_garanti);
    }

    public function getEstDisponibleAttribute(): bool
    {
        return in_array($this->statut, self::STATUTS_DISPONIBLES);
    }

    // ================================================================
    // SCOPES
    // ================================================================

    public function scopeDisponibles($query)
    {
        return $query->whereIn('statut', self::STATUTS_DISPONIBLES);
    }

    public function scopeEnStock($query)
    {
        return $query->where('statut', self::STATUT_EN_STOCK);
    }

    public function scopeReserves($query)
    {
        return $query->where('statut', self::STATUT_RESERVE);
    }

    public function scopeVendus($query)
    {
        return $query->where('statut', self::STATUT_VENDU);
    }

    public function scopeParProduit($query, $produitId)
    {
        return $query->where('produit_id', $produitId);
    }

    public function scopeParSuccursale($query, $succursaleId)
    {
        return $query->where('succursale_id', $succursaleId);
    }

    public function scopeParLot($query, $lotStockId)
    {
        return $query->where('lot_stock_id', $lotStockId);
    }

    public function scopeParFacture($query, $factureId)
    {
        return $query->where('facture_id', $factureId);
    }

    public function scopeParCommande($query, $commandeId)
    {
        return $query->where('commande_id', $commandeId);
    }

    // ================================================================
    // MÉTHODES DE VÉRIFICATION
    // ================================================================

    public function estCohérentAvecLot(): bool
    {
        if (!$this->lot_stock_id) {
            return false;
        }
        
        $lot = $this->lotStock;
        if (!$lot) {
            return false;
        }
        
        if ($lot->produit_id !== $this->produit_id) {
            return false;
        }
        
        if ($lot->stockSuccursale && $lot->stockSuccursale->succursale_id !== $this->succursale_id) {
            return false;
        }
        
        return true;
    }

    public function estVendable(): bool
    {
        return $this->statut === self::STATUT_EN_STOCK;
    }

    public function estReservePour(int $factureId): bool
    {
        return $this->statut === self::STATUT_RESERVE && $this->facture_id === $factureId;
    }

    // ================================================================
    // MÉTHODES MÉTIER
    // ================================================================

    public function marquerReserve(int $factureId): self
    {
        if ($this->statut !== self::STATUT_EN_STOCK) {
            throw new \Exception("L'IMEI {$this->numero_imei} n'est pas disponible.");
        }
        
        $this->statut = self::STATUT_RESERVE;
        $this->facture_id = $factureId;
        $this->save();
        
        return $this;
    }
    
        public function marquerVendu(MouvementStock $mouvementSortie, int $factureId, float $prixVente = null): self
    {
        // Vérifier que l'IMEI est réservé
        if ($this->statut !== self::STATUT_RESERVE) {
            throw new \Exception("L'IMEI {$this->numero_imei} n'est pas réservé. Statut actuel : {$this->statut}");
        }
        
        $this->statut = self::STATUT_VENDU;
        $this->mouvement_sortie_id = $mouvementSortie->mouvement_id;
        $this->facture_id = $factureId;
        $this->date_vente = now();
        $this->date_sortie = now();
        
        // ✅ Enregistrer le prix de vente si fourni
        if ($prixVente !== null) {
            $this->prix_vente = $prixVente;
        }
        
        $this->save();
        
        return $this;
    }

    public function libererReservation(): self
    {
        if ($this->statut !== self::STATUT_RESERVE) {
            throw new \Exception("L'IMEI {$this->numero_imei} n'est pas réservé.");
        }
        
        $this->statut = self::STATUT_EN_STOCK;
        $this->facture_id = null;
        $this->save();
        
        return $this;
    }

     public function marquerImeisVendus(
        array $imeiIds,
        MouvementStock $mouvementSortie,
        int $factureId
    ): array {
        $imeis = ProduitImei::whereIn('id', $imeiIds)
            ->where('statut', ProduitImei::STATUT_RESERVE)
            ->where('facture_id', $factureId)
            ->lockForUpdate()
            ->get();
    
        if ($imeis->count() !== count($imeiIds)) {
            throw new \Exception(
                "Certains IMEI ne sont pas réservés pour cette facture."
            );
        }
    
        foreach ($imeis as $imei) {
            $imei->marquerVendu(
                $mouvementSortie,
                $factureId
            );
        }
    
        return $imeis->toArray();
    }

   public function marquerTransfere(
    MouvementStock $mouvementSortie
): self {
    /*
     * ================================================================
     * 1. VALIDATION DU MOUVEMENT
     * ================================================================
     */

    if (!$mouvementSortie->mouvement_id) {
        throw new \Exception(
            "Le mouvement de sortie est invalide."
        );
    }

    /*
     * ================================================================
     * 2. VALIDATION DU STATUT
     * ================================================================
     *
     * Un IMEI peut arriver ici dans deux situations :
     *
     *   EN_STOCK
     *       → transfert direct
     *
     *   RESERVE
     *       → transfert préparé/réservé auparavant
     *
     * Dans ton flux actuel, on est normalement dans RESERVE.
     */

    $statutsAutorises = [
        self::STATUT_EN_STOCK,
        self::STATUT_RESERVE,
    ];

    if (!in_array(
        $this->statut,
        $statutsAutorises,
        true
    )) {
        throw new \Exception(
            "L'IMEI {$this->numero_imei} ne peut pas être transféré. " .
            "Statut actuel : {$this->statut}."
        );
    }

    /*
     * ================================================================
     * 3. TRANSFERT
     * ================================================================
     */

    $this->statut =
        self::STATUT_TRANSFERE;

    $this->mouvement_sortie_id =
        $mouvementSortie->mouvement_id;

    $this->date_sortie =
        now();

    /*
     * ================================================================
     * 4. METADATA
     * ================================================================
     *
     * On conserve les informations de réservation mais on indique
     * que la réservation a maintenant été consommée.
     */

    $metadata = $this->metadata;

    if (is_string($metadata)) {
        $metadata = json_decode(
            $metadata,
            true
        ) ?? [];
    }

    if (!is_array($metadata)) {
        $metadata = [];
    }

    $metadata['transfert_effectue'] = true;

    $metadata['transfert_mouvement_id'] =
        $mouvementSortie->mouvement_id;

    $metadata['transfert_date'] =
        now()->toDateTimeString();

    /*
     * La réservation n'est plus active.
     */
    $metadata['transfert_reserve'] = false;

    $this->metadata = $metadata;

    /*
     * ================================================================
     * 5. SAUVEGARDE
     * ================================================================
     */

    $this->save();

    return $this;
}

    public function recevoirTransfert(int $succursaleDestinationId, int $lotDestinationId, MouvementStock $mouvementEntree): self
    {
        if ($this->statut !== self::STATUT_TRANSFERE) {
            throw new \Exception("L'IMEI {$this->numero_imei} n'est pas en transit.");
        }
        
        $this->statut = self::STATUT_EN_STOCK;
        $this->succursale_id = $succursaleDestinationId;
        $this->lot_stock_id = $lotDestinationId;
        $this->mouvement_entree_id = $mouvementEntree->mouvement_id;
        $this->date_entree = now();
        $this->save();
        
        return $this;
    }

    public function marquerRetourne(string $motif = null): self
    {
        if ($this->statut !== self::STATUT_VENDU) {
            throw new \Exception("L'IMEI {$this->numero_imei} n'est pas vendu.");
        }
        
        $this->statut = self::STATUT_RETOURNE;
        $this->save();
        
        return $this;
    }

    public function remettreEnStock(int $lotStockId, MouvementStock $mouvementEntree): self
    {
        if ($this->statut !== self::STATUT_RETOURNE) {
            throw new \Exception("L'IMEI {$this->numero_imei} doit être retourné avant d'être remis en stock.");
        }
        
        $this->statut = self::STATUT_EN_STOCK;
        $this->lot_stock_id = $lotStockId;
        $this->mouvement_entree_id = $mouvementEntree->mouvement_id;
        $this->date_entree = now();
        $this->save();
        
        return $this;
    }

    public function activerGarantie(int $dureeJours): self
    {
        $this->date_debut_garanti = now();
        $this->date_fin_garanti = now()->addDays($dureeJours);
        $this->garanti_jour = $dureeJours;
        $this->save();
        
        return $this;
    }

    // ================================================================
    // BOOT
    // ================================================================

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($imei) {
            // Normaliser
            $imei->numero_imei = trim($imei->numero_imei);
            
            // Valider le format
            if (!preg_match('/^\d{15,17}$/', $imei->numero_imei)) {
                throw new \Exception("L'IMEI '{$imei->numero_imei}' est invalide.");
            }
            
            // Vérifier l'unicité
            if (static::where('numero_imei', $imei->numero_imei)->exists()) {
                throw new \Exception("L'IMEI '{$imei->numero_imei}' existe déjà.");
            }
        });
    }
}