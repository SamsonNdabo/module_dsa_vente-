<?php
// app/Models/Fournisseur.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
// use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;

class Fournisseur extends Model
{
    // use SoftDeletes;

    protected $table = 'fournisseur';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'nom',
        'email',
        'telephone',
        'adresse',
        'ville',
        'produits',
        'statut',
        'code_fournisseur',
    ];

    protected $casts = [
         'adresse' => 'encrypted',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'ville' => 'array',
        'produits' => 'array',
    ];

    protected $attributes = [
        'statut' => 'ACTIF',
    ];

    protected $appends = [
        'statut_label',
        'adresse_complete',
        'produits_liste',
        'villes_liste',
        'nombre_produits',
        'libelle'
    ];

    // ============================================================
    // ATTRIBUTS
    // ============================================================

    /**
     * Retourne le libellé du statut
     */
    public function getStatutLabelAttribute(): string
    {
        return match ($this->statut) {
            'ACTIF' => 'Actif',
            'INACTIF' => 'Inactif',
            'SUSPENDU' => 'Suspendu',
            default => $this->statut ?? 'Inconnu'
        };
    }

    /**
     * Adresse complète formatée
     */
    public function getAdresseCompleteAttribute(): string
    {
        $parts = [];
        if ($this->adresse) $parts[] = $this->adresse;

        $villes = $this->villes_liste;
        if ($villes) $parts[] = $villes;

        return implode(', ', $parts);
    }

    /**
     * Liste des produits fournis formatée
     */
    public function getProduitsListeAttribute(): string
    {
        if (!$this->produits) return '';
        $produits = is_array($this->produits) ? $this->produits : json_decode($this->produits, true);
        return implode(', ', $produits);
    }

    /**
     * Liste des villes formatée
     */
    public function getVillesListeAttribute(): string
    {
        if (!$this->ville) return '';
        $villes = is_array($this->ville) ? $this->ville : json_decode($this->ville, true);
        return implode(', ', $villes);
    }

    /**
     * Nombre de produits fournis
     */
    public function getNombreProduitsAttribute(): int
    {
        if (!$this->produits) return 0;
        $produits = is_array($this->produits) ? $this->produits : json_decode($this->produits, true);
        return count($produits);
    }

    /**
     * Libellé complet du fournisseur
     */
    public function getLibelleAttribute(): string
    {
        return $this->nom . ($this->code_fournisseur ? ' [' . $this->code_fournisseur . ']' : '');
    }

    // ============================================================
    // RELATIONS
    // ============================================================

    /**
     * Commandes associées à ce fournisseur
     */
    public function commandes()
    {
        return $this->hasMany(CommandeFournisseur::class, 'fournisseur_id');
    }

    /**
     * Réceptions associées à ce fournisseur
     */
    public function receptions()
    {
        return $this->hasMany(ReceptionFournisseur::class, 'fournisseur_id');
    }

    // ============================================================
    // SCOPES
    // ============================================================

    /**
     * Filtrer les fournisseurs actifs
     */
    public function scopeActif($query)
    {
        return $query->where('statut', 'ACTIF');
    }

    /**
     * Filtrer les fournisseurs inactifs
     */
    public function scopeInactif($query)
    {
        return $query->where('statut', 'INACTIF');
    }

    /**
     * Filtrer les fournisseurs suspendus
     */
    public function scopeSuspendu($query)
    {
        return $query->where('statut', 'SUSPENDU');
    }

    /**
     * Filtrer par ville
     */
    public function scopeParVille($query, $ville)
    {
        return $query->whereJsonContains('ville', $ville);
    }

    /**
     * Filtrer par produit fourni
     */
    public function scopeParProduit($query, $produit)
    {
        return $query->whereJsonContains('produits', $produit);
    }

    // ============================================================
    // MÉTHODES MÉTIER
    // ============================================================

    /**
     * Vérifier si le fournisseur peut être supprimé
     */
    public function peutEtreSupprime(): bool
    {
        return $this->commandes()->count() === 0 && $this->receptions()->count() === 0;
    }

    /**
     * Vérifier si le fournisseur fournit un type de produit
     */
    public function fournitProduit($produit): bool
    {
        if (empty($this->produits)) return false;
        $produits = is_array($this->produits) ? $this->produits : json_decode($this->produits, true);
        return in_array($produit, $produits);
    }

    /**
     * Vérifier si le fournisseur est présent dans une ville
     */
    public function estDansVille($ville): bool
    {
        if (empty($this->ville)) return false;
        $villes = is_array($this->ville) ? $this->ville : json_decode($this->ville, true);
        return in_array($ville, $villes);
    }

    // ============================================================
    // MUTATEURS
    // ============================================================

    /**
     * Setter pour le code fournisseur (uppercase)
     */
    public function setCodeFournisseurAttribute($value)
    {
        $this->attributes['code_fournisseur'] = $value ? strtoupper(trim($value)) : null;
    }

    /**
     * Setter pour l'email (lowercase)
     */
    public function setEmailAttribute($value)
    {
        $this->attributes['email'] = $value ? strtolower(trim($value)) : null;
    }

    /**
     * Setter pour la ville (JSON)
     */
    public function setVilleAttribute($value)
    {
        if (is_array($value)) {
            $this->attributes['ville'] = json_encode(array_values(array_filter($value)));
        } else {
            $this->attributes['ville'] = $value;
        }
    }

    /**
     * Getter pour la ville (array)
     */
    public function getVilleAttribute($value)
    {
        return $value ? json_decode($value, true) : [];
    }

    /**
     * Setter pour les produits (JSON)
     */
    public function setProduitsAttribute($value)
    {
        if (is_array($value)) {
            $this->attributes['produits'] = json_encode(array_values(array_filter($value)));
        } else {
            $this->attributes['produits'] = $value;
        }
    }

    /**
     * Getter pour les produits (array)
     */
    public function getProduitsAttribute($value)
    {
        return $value ? json_decode($value, true) : [];
    }

    // ============================================================
    // BOOT
    // ============================================================

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($fournisseur) {
            if (empty($fournisseur->statut)) {
                $fournisseur->statut = 'ACTIF';
            }
            if (empty($fournisseur->code_fournisseur)) {
                $dernierId = self::max('id') ?? 0;
                $fournisseur->code_fournisseur = 'FOURN-' . str_pad($dernierId + 1, 4, '0', STR_PAD_LEFT);
            }
        });

        static::created(function ($fournisseur) {
            Log::info('Fournisseur créé', [
                'id' => $fournisseur->id,
                'nom' => $fournisseur->nom,
                'code' => $fournisseur->code_fournisseur
            ]);
        });

        static::updating(function ($fournisseur) {
            if ($fournisseur->isDirty('statut')) {
                Log::info('Changement de statut fournisseur', [
                    'fournisseur_id' => $fournisseur->id,
                    'ancien_statut' => $fournisseur->getOriginal('statut'),
                    'nouveau_statut' => $fournisseur->statut
                ]);
            }
        });

        static::deleting(function ($fournisseur) {
            if ($fournisseur->peutEtreSupprime() === false) {
                throw new \Exception('Impossible de supprimer un fournisseur qui a des commandes ou réceptions');
            }
        });
    }



    /**
     * Obtenir les statistiques complètes des commandes
     */
    public function getStatistiquesCommandesAttribute()
    {
        $commandes = $this->commandes()
            ->with('lignes')
            ->get();

        $totalCommandes = $commandes->count();

        if ($totalCommandes === 0) {
            return [
                'total_commandes' => 0,
                'total_commandes_recues' => 0,
                'total_commandes_annulees' => 0,
                'total_articles_commandes' => 0,
                'total_articles_recus' => 0,
                'taux_reception' => 0,
                'montant_total_estime' => 0,
                'derniere_commande' => null,
                'premiere_commande' => null,
                'frequence_moyenne_jours' => 0,
                'taux_fidelite' => 0,
                'top_produits' => []
            ];
        }

        $totalArticlesCommandes = $commandes->sum(fn($c) => $c->lignes->sum('quantite'));
        $totalArticlesRecus = $commandes->sum(fn($c) => $c->lignes->sum('quantite_recue'));

        // Calcul du montant total estimé
        $montantTotal = $commandes->sum(function ($c) {
            return $c->lignes->sum(function ($l) {
                $prix = $l->metadata['prix_unitaire_ht'] ?? 0;
                return $prix * $l->quantite;
            });
        });

        // Calcul de la fréquence moyenne
        $datesCommandes = $commandes->pluck('date_commande')->filter()->sort();
        $frequenceMoyenne = 0;
        if ($datesCommandes->count() >= 2) {
            $ecarts = [];
            $prev = null;
            foreach ($datesCommandes as $date) {
                if ($prev) {
                    $ecarts[] = $prev->diffInDays($date);
                }
                $prev = $date;
            }
            $frequenceMoyenne = count($ecarts) > 0 ? round(array_sum($ecarts) / count($ecarts)) : 0;
        }

        // Top produits commandés
        $produitsCount = [];
        foreach ($commandes as $commande) {
            foreach ($commande->lignes as $ligne) {
                $nomProduit = $ligne->produit?->nom ?? $ligne->libelle;
                if (!isset($produitsCount[$nomProduit])) {
                    $produitsCount[$nomProduit] = 0;
                }
                $produitsCount[$nomProduit] += $ligne->quantite;
            }
        }
        arsort($produitsCount);
        $topProduits = array_slice($produitsCount, 0, 5, true);

        return [
            'total_commandes' => $totalCommandes,
            'total_commandes_recues' => $commandes->whereIn('statut', ['RECUE', 'RECUE_PARTIELLE'])->count(),
            'total_commandes_annulees' => $commandes->where('statut', 'ANNULEE')->count(),
            'total_articles_commandes' => $totalArticlesCommandes,
            'total_articles_recus' => $totalArticlesRecus,
            'taux_reception' => $totalArticlesCommandes > 0
                ? round(($totalArticlesRecus / $totalArticlesCommandes) * 100, 2)
                : 0,
            'montant_total_estime' => $montantTotal,
            'derniere_commande' => $datesCommandes->last()?->format('Y-m-d H:i:s'),
            'premiere_commande' => $datesCommandes->first()?->format('Y-m-d H:i:s'),
            'frequence_moyenne_jours' => $frequenceMoyenne,
            'taux_fidelite' => $totalCommandes > 0
                ? round(($commandes->where('statut', 'RECUE')->count() / $totalCommandes) * 100, 2)
                : 0,
            'top_produits' => $topProduits
        ];
    }

    /**
     * Obtenir le classement des fournisseurs (Top N)
     */
    public static function getTopFournisseurs($limit = 10, $criteria = 'nb_commandes')
    {
        $query = self::withCount('commandes')
            ->withSum('commandes', 'total_ttc')
            ->having('commandes_count', '>', 0);

        switch ($criteria) {
            case 'nb_commandes':
                $query->orderBy('commandes_count', 'desc');
                break;
            case 'montant':
                $query->orderBy('commandes_sum_total_ttc', 'desc');
                break;
            default:
                $query->orderBy('commandes_count', 'desc');
        }

        $fournisseurs = $query->limit($limit)->get();

        return $fournisseurs->map(function ($fournisseur) {
            $stats = $fournisseur->statistiques_commandes;
            return [
                'id' => $fournisseur->id,
                'nom' => $fournisseur->nom,
                'code_fournisseur' => $fournisseur->code_fournisseur,
                'email' => $fournisseur->email,
                'telephone' => $fournisseur->telephone,
                'total_commandes' => $fournisseur->commandes_count,
                'total_articles' => $stats['total_articles_commandes'],
                'montant_total' => $fournisseur->commandes_sum_total_ttc ?? 0,
                'taux_reception' => $stats['taux_reception'],
                'taux_fidelite' => $stats['taux_fidelite'],
                'derniere_commande' => $stats['derniere_commande']
            ];
        });
    }
}
