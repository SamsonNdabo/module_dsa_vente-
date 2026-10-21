<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReceiptConfig extends Model
{
    protected $table = 'receipt_configs';

    protected $fillable = [
        'nom_societe',
        'enseigne',
        'logo_path',
        'adresse',
        'telephone',
        'email',
        'site_web',
        'largeur_impression',
        'format_papier',
        'prefixe_facture',
    ];

    public static function getConfig(): self
    {
        return self::firstOrCreate(
            ['id' => 1],
            [
                'nom_societe' => 'STAREHE HOLDING',
                'enseigne' => 'SHOP MODERNE',
                'largeur_impression' => '80mm',
                'format_papier' => 'THERMIQUE',
                'prefixe_facture' => 'FX-',
            ]
        );
    }
    public function getLogoUrlAttribute(): ?string
    {
        if (empty($this->logo_path)) {
            return null;
        }
        // Si c'est déjà une URL complète, on la retourne telle quelle
        if (filter_var($this->logo_path, FILTER_VALIDATE_URL)) {
            return $this->logo_path;
        }
        // Sinon, on génère une URL publique
        return asset($this->logo_path);
    }
}