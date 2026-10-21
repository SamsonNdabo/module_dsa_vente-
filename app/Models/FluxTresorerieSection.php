<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class FluxTresorerieSection
 * 
 * @property int $id
 * @property int|null $societe_id
 * @property string $code
 * @property string $libelle
 * @property string $type_section
 * @property string $type_flux
 * @property string|null $comptes_racines
 * @property int $sens
 * @property int $ordre
 * @property bool $actif
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @package App\Models
 */
class FluxTresorerieSection extends Model
{
    protected $table = 'flux_tresorerie_sections';

    protected $casts = [
        'societe_id' => 'int',
        'sens' => 'int',
        'ordre' => 'int',
        'actif' => 'bool',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $fillable = [
        'societe_id',
        'code',
        'libelle',
        'type_section',
        'type_flux',
        'comptes_racines',
        'sens',
        'ordre',
        'actif',
    ];

    // =============================================
    // CONSTANTES (pour l'affichage uniquement)
    // =============================================

    const TYPES_SECTION = [
        'exploitation' => 'Flux d\'Exploitation',
        'investissement' => 'Flux d\'Investissement',
        'financement' => 'Flux de Financement',
    ];

    const TYPES_FLUX = [
        'encaissement' => 'Encaissement',
        'decaissement' => 'Décaissement',
    ];

    const SENS = [
        1 => 'Positif (+)',
        -1 => 'Négatif (-)',
    ];

    // =============================================
    // SCOPES
    // =============================================

    public function scopeActives($query)
    {
        return $query->where('actif', true);
    }

    public function scopeParType($query, $type)
    {
        return $query->where('type_section', $type);
    }

    public function scopeOrdonnees($query)
    {
        return $query->orderBy('ordre');
    }

    public function scopePourSociete($query, $societeId = null)
    {
        if ($societeId) {
            return $query->where('societe_id', $societeId);
        }
        return $query->whereNull('societe_id');
    }

    // =============================================
    // ACCESSEURS
    // =============================================

    /**
     * Retourne les racines de comptes sous forme de tableau
     */
    public function getComptesRacinesArrayAttribute(): array
    {
        if (empty($this->comptes_racines)) {
            return [];
        }
        return array_map('trim', explode(',', $this->comptes_racines));
    }

    /**
     * Construit une regex pour le WHERE SQL
     */
    public function getComptesRegexAttribute(): string
    {
        $racines = $this->comptes_racines_array;
        if (empty($racines)) {
            return '';
        }
        return '^(' . implode('|', $racines) . ')';
    }

    /**
     * Libellé avec le signe (+ ou -)
     */
    public function getLibelleAvecSigneAttribute(): string
    {
        $signe = $this->sens > 0 ? '+' : '-';
        return $signe . ' ' . $this->libelle;
    }

    /**
     * Type de section formaté pour l'affichage
     */
    public function getTypeSectionLibelleAttribute(): string
    {
        return self::TYPES_SECTION[$this->type_section] ?? ucfirst($this->type_section);
    }

    /**
     * Type de flux formaté pour l'affichage
     */
    public function getTypeFluxLibelleAttribute(): string
    {
        return self::TYPES_FLUX[$this->type_flux] ?? ucfirst($this->type_flux);
    }

    /**
     * Sens formaté pour l'affichage
     */
    public function getSensLibelleAttribute(): string
    {
        return self::SENS[$this->sens] ?? 'Inconnu';
    }
}