<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use App\Traits\Auditable;

class Produit extends Model
{
    use HasFactory, SoftDeletes, Auditable;

    protected $table = 'produit';
    protected $primaryKey = 'id';

    protected $fillable = [
        'code_barre',
        'nom',
        'description',
        'marque',
        'modele',
        'image_principale',
        'images_gallerie',
        'type',
        'gere_imei',
        'prix_vente',
        'prix_vente_TTC',
        'prix_achat',
        'tva',
        'prix_achatTTC',
        'stock_minimum',
        'est_perissable',
        'date_fabrication',
        'date_expiration',
        'categorie_id',
        'devise_id',
        'actif',
    ];

    protected $casts = [
        'prix_vente' => 'decimal:2',
        'prix_vente_TTC' => 'decimal:2',
        'prix_achatTTC' => 'decimal:2',
        'tva' => 'integer',
        'stock_minimum' => 'integer',
        'actif' => 'boolean',
        'est_perissable' => 'boolean',
        'gere_imei' => 'boolean',
        'date_fabrication' => 'date',
        'date_expiration' => 'date',
        'images_gallerie' => 'array',
    ];

    protected $appends = [
        'image_url',
        'gallerie_urls',
        'image_thumbnail_url',
        'type_label',
        'stock_total',
        'est_en_rupture',
        'est_en_alerte',
    ];

    const TYPES_PRODUIT = [
        'ELECTRONIQUE' => ['label' => 'Électronique', 'gestion_imei' => true],
        'ACCESSOIRE' => ['label' => 'Accessoire', 'gestion_imei' => false],
        'ALIMENTAIRE' => ['label' => 'Alimentaire', 'gestion_imei' => false],
        'STANDARD' => ['label' => 'Standard', 'gestion_imei' => false],
        'PRODUIT' => ['label' => 'Produit', 'gestion_imei' => false],
        'SERVICE' => ['label' => 'Service', 'gestion_imei' => false],
        'CONSOMMABLE' => ['label' => 'Consommable', 'gestion_imei' => false],
        'MATIERE_PREMIERE' => ['label' => 'Matière première', 'gestion_imei' => false],
        'PRODUIT_FINI' => ['label' => 'Produit fini', 'gestion_imei' => false],
        'SEMI_FINI' => ['label' => 'Semi-fini', 'gestion_imei' => false],
        'SOUS_TRAITANCE' => ['label' => 'Sous-traitance', 'gestion_imei' => false],
        'STOCKABLE' => ['label' => 'Stockable', 'gestion_imei' => false],
        'NUMERIQUE' => ['label' => 'Numérique', 'gestion_imei' => false],
    ];

    const TYPES_AVEC_IMEI = ['ELECTRONIQUE'];

    /*
    |--------------------------------------------------------------------------
    | RELATIONS
    |--------------------------------------------------------------------------
    */

    public function categorie()
    {
        return $this->belongsTo(CategorieProduit::class, 'categorie_id');
    }

    public function devise()
    {
        return $this->belongsTo(Devise::class, 'devise_id');
    }

    public function stocks()
    {
        return $this->hasMany(StockSuccursale::class, 'produit_id');
    }

    public function imeis()
    {
        return $this->hasMany(ProduitImei::class, 'produit_id');
    }

    public function mouvements()
    {
        return $this->hasMany(MouvementStock::class, 'produit_id');
    }

    public function lignesFacture()
    {
        return $this->hasMany(LigneFacture::class, 'produit_id');
    }

    // ✅ AJOUT 1 : Relation avec les Partenaires (Corrige l'erreur)
    public function partenaires()
    {
        return $this->belongsToMany(
            \App\Models\Partenaire\Partenaire::class, 
            'partenaire_produits', 
            'id_produit', 
            'id_partenaire'
        )->withPivot('type_produit', 'date_ajout', 'actif');
    }

    // ✅ AJOUT 2 : Relation avec les Campagnes (Utile pour l'historique)
    public function campagneProduits()
    {
        return $this->hasMany(\App\Models\Campagne\CampagneProduit::class, 'id_produit');
    }

    /*
    |--------------------------------------------------------------------------
    | ACCESSORS
    |--------------------------------------------------------------------------
    */

    public function getTypeLabelAttribute(): string
    {
        $type = $this->type;

        if (is_array($type)) {
            $type = $type[0] ?? 'STANDARD';
        }

        if (is_string($type) && isset(self::TYPES_PRODUIT[$type])) {
            return self::TYPES_PRODUIT[$type]['label'];
        }

        if (is_string($type)) {
            return $type;
        }

        return 'Non défini';
    }

    public function getImageUrlAttribute(): ?string
    {
        if (!$this->image_principale) {
            return null;
        }

        if (filter_var($this->image_principale, FILTER_VALIDATE_URL)) {
            return $this->image_principale;
        }

        return asset('storage/' . $this->image_principale);
    }

    public function getGallerieUrlsAttribute(): array
    {
        $images = $this->images_gallerie ?? [];

        return array_map(function ($image) {
            if (filter_var($image, FILTER_VALIDATE_URL)) {
                return $image;
            }
            return Storage::url($image);
        }, $images);
    }

    public function getImageThumbnailUrlAttribute(): ?string
    {
        if (!$this->image_principale) {
            return null;
        }

        $pathInfo = pathinfo($this->image_principale);

        $thumbnail = (
            $pathInfo['dirname'] !== '.'
                ? $pathInfo['dirname'] . '/'
                : ''
        ) . 'thumbnails/' . $pathInfo['basename'];

        if (Storage::disk('public')->exists($thumbnail)) {
            return Storage::url($thumbnail);
        }

        return $this->image_url;
    }

    public function getStockTotalAttribute()
    {
        return $this->stocks_sum_quantite_disponible
            ?? $this->stocks()->sum('quantite_disponible');
    }

    public function getEstEnRuptureAttribute(): bool
    {
        return $this->stock_total <= 0;
    }

    public function getEstEnAlerteAttribute(): bool
    {
        return $this->stock_total <= $this->stock_minimum;
    }

    public function getTauxMarqueAttribute()
    {
        if ($this->prix_vente <= 0) {
            return 0;
        }
        return 0;
    }

    public function synchroniserPrix(float $prixHT, float $tauxTVA = 16): void
    {
        $this->prix_achat = $prixHT;
        $this->tva = $tauxTVA;
        $this->prix_achatTTC = round($prixHT * (1 + $tauxTVA / 100), 2);

        $this->save();
    }

    /*
    |--------------------------------------------------------------------------
    | GESTION DES IMAGES
    |--------------------------------------------------------------------------
    */

    public function uploadImage($file, string $type = 'principale'): string
    {
        $folder = 'produits/' . $this->id;

        $filename = time()
            . '_'
            . uniqid()
            . '.'
            . $file->getClientOriginalExtension();

        if (
            $type === 'principale'
            && $this->image_principale
        ) {
            $this->deleteImageFile($this->image_principale);
        }

        $path = $file->storeAs(
            $folder,
            $filename,
            'public'
        );

        if ($type === 'principale') {
            $this->image_principale = $path;
            $this->save();
        }

        return $path;
    }

    public function addToGallery($file): array
    {
        $path = $file->store(
            'produits/' . $this->id . '/gallery',
            'public'
        );

        $gallery = $this->images_gallerie ?? [];
        $gallery[] = $path;
        $this->images_gallerie = $gallery;
        $this->save();

        return $gallery;
    }

    public function removeFromGallery(string $imagePath): array
    {
        $gallery = $this->images_gallerie ?? [];

        $this->deleteImageFile($imagePath);

        $gallery = array_values(
            array_diff($gallery, [$imagePath])
        );

        $this->images_gallerie = $gallery;
        $this->save();

        return $gallery;
    }

    public function deleteAllImages(): void
    {
        if ($this->image_principale) {
            $this->deleteImageFile($this->image_principale);
        }

        if ($this->images_gallerie) {
            foreach ($this->images_gallerie as $image) {
                $this->deleteImageFile($image);
            }
        }

        Storage::disk('public')->deleteDirectory(
            'produits/' . $this->id
        );
    }

    public function deleteImageFile(string $path): void
    {
        if (
            $path &&
            Storage::disk('public')->exists($path)
        ) {
            Storage::disk('public')->delete($path);
        }
    }

    protected function generateThumbnail(string $path): void
    {
        // Optionnel
    }

    /*
    |--------------------------------------------------------------------------
    | SCOPES
    |--------------------------------------------------------------------------
    */

    public function scopeActifs($query)
    {
        return $query->where('actif', true);
    }

    public function scopeParCategorie($query, $categorieId)
    {
        return $query->where('categorie_id', $categorieId);
    }

    public function scopeGereImei($query)
    {
        return $query->where('gere_imei', true);
    }

    /*
    |--------------------------------------------------------------------------
    | EVENTS
    |--------------------------------------------------------------------------
    */

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($produit) {
            if ($produit->prix_vente && !$produit->prix_vente_TTC) {
                $tauxTVA = 16;
                $produit->prix_vente_TTC = $produit->prix_vente * (1 + $tauxTVA / 100);
            }

            if ($produit->type === 'ELECTRONIQUE' && !isset($produit->gere_imei)) {
                $produit->gere_imei = true;
            }
        });

        static::deleting(function ($produit) {
            if ($produit->isForceDeleting()) {
                $produit->deleteAllImages();
            }
        });
    }
}