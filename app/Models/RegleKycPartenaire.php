<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegleKycPartenaire extends Model
{
    protected $table = 'regles_kyc_partenaires';

    protected $fillable = [
        'partenaire_id',
        'type_document',
        'obligatoire',
        'validite_jours',
        'actif',
        'produits_json',   // ← NOUVEAU : liste N produits
    ];

    protected $casts = [
        'obligatoire'    => 'boolean',
        'actif'          => 'boolean',
        'validite_jours' => 'integer',
        'produits_json'  => 'array',   // ← JSON → array PHP automatique
    ];

    public const TYPES_PRODUIT = ['STOCK', 'EPARGNE'];

    /* ─── Relations ─── */

    public function partenaire(): BelongsTo
    {
        return $this->belongsTo(Partenaire::class);
    }

    /* ─── Accessors ─── */

    /**
     * Retourne les produits résolus (modèles complets) :
     * [['id' => 1, 'nom' => 'X', 'code' => 'A', 'type_produit' => 'STOCK'], ...]
     */
    public function getProduitsResolusAttribute(): array
    {
        $produits = $this->produits_json ?? [];
        $resolus = [];

        foreach ($produits as $p) {
            if (! isset($p['type'], $p['id'])) continue;

            $model = match ($p['type']) {
                'STOCK'   => Produit::find($p['id']),
                'EPARGNE' => ProduitEpargne::find($p['id']),
                default   => null,
            };

            if ($model) {
                $resolus[] = [
                    'id'           => $model->id,
                    'nom'          => $model->nom ?? null,
                    'code'         => $model->code ?? null,
                    'type_produit' => $p['type'],
                ];
            }
        }

        return $resolus;
    }

    /**
     * Résumé textuel pour l'affichage : "STOCK: 3,7 | EPARGNE: 1"
     */
    public function getProduitsResumeAttribute(): string
    {
        if (empty($this->produits_json)) {
            return 'Tous les produits';
        }

        $stock   = [];
        $epargne = [];

        foreach ($this->produits_json as $p) {
            if (($p['type'] ?? null) === 'STOCK')   $stock[] = $p['id'];
            if (($p['type'] ?? null) === 'EPARGNE') $epargne[] = $p['id'];
        }

        $parts = [];
        if (! empty($stock))   $parts[] = 'STOCK: ' . implode(',', $stock);
        if (! empty($epargne)) $parts[] = 'EPARGNE: ' . implode(',', $epargne);

        return implode(' | ', $parts);
    }

    /* ─── Scopes ─── */

    public function scopeActifs($q)
    {
        return $q->where('actif', 1);
    }

    public function scopePourPartenaire($q, int $partenaireId)
    {
        return $q->where('partenaire_id', $partenaireId);
    }

    public function scopeGlobaux($q)
    {
        return $q->whereNull('produits_json')
            ->orWhereJsonLength('produits_json', 0);
    }

    /**
     * Règles applicables à un produit donné (globales + celles qui contiennent ce produit).
     */
    public function scopeApplicablesA($q, string $typeProduit, int $produitId)
    {
        return $q->where(function ($w) use ($typeProduit, $produitId) {
            // Globales
            $w->whereNull('produits_json')
              ->orWhereJsonLength('produits_json', 0);

            // Contient ce produit (via JSON_SEARCH sur id + type)
            $w->orWhereJsonContains('produits_json', [
                'type' => $typeProduit,
                'id'   => $produitId,
            ]);
        });
    }
}