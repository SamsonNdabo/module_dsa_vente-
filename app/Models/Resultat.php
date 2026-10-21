<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class Resultat extends Model
{
    protected $table = 'resultats';

    protected $casts = [
        'periode_id' => 'int',
        'entite_id' => 'int',
        'succursale_id' => 'int',
        'total_actif' => 'float',
        'total_passif' => 'float',
        'total_charges' => 'float',
        'total_produits' => 'float',
        'resultat_net' => 'float',
        'actif_detail' => 'json',
        'passif_detail' => 'json',
        'charges_detail' => 'json',
        'produits_detail' => 'json',
        'is_latest' => 'boolean',
        'calculated_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $fillable = [
        'periode_id',
        'entite_id',
        'succursale_id',
        'total_actif',
        'total_passif',
        'total_charges',
        'total_produits',
        'resultat_net',
        'type_resultat',
        'actif_detail',
        'passif_detail',
        'charges_detail',
        'produits_detail',
        'is_latest',
        'calculated_at',
    ];

    // =========================================================================
    // 🔥 BOOT - LOG AUTOMATIQUE
    // =========================================================================

    protected static function booted(): void
    {
        static::created(function (Resultat $resultat) {
            try {
                $entite = $resultat->entite ? $resultat->entite->code : 'Toutes';
                $periode = $resultat->periodeComptable ? $resultat->periodeComptable->code : 'N/A';
                $typeResultat = $resultat->resultat_net >= 0 ? 'BÉNÉFICE' : 'PERTE';
                
                SysLogComptable::create([
                    'utilisateur_id' => auth()->id() ?? 1,
                    'action' => 'calcul_resultat',
                    'description' => "Résultat calculé - Entité: {$entite} - Période: {$periode} - {$typeResultat}: " . number_format(abs($resultat->resultat_net), 2, ',', ' ') . " $",
                    'ip_address' => request()->ip() ?? '127.0.0.1',
                    'user_agent' => request()->userAgent() ?? 'System',
                    'created_at' => now(),
                ]);
            } catch (\Exception $e) {
                \Log::error('❌ Erreur log calcul résultat', ['error' => $e->getMessage()]);
            }
        });
    }

    /**
     * Marquer tous les résultats précédents comme obsolètes pour ce filtre.
     */
    public static function markPreviousAsObsolete(int $periodeId, ?int $entiteId = null, ?int $succursaleId = null): void
    {
        self::where('periode_id', $periodeId)
            ->when($entiteId, fn($q) => $q->where('entite_id', $entiteId))
            ->when($succursaleId, fn($q) => $q->where('succursale_id', $succursaleId))
            ->update(['is_latest' => false]);
    }

    /**
     * Récupérer le dernier résultat pour une combinaison période/entité/succursale.
     */
    public static function getLatest(int $periodeId, ?int $entiteId = null, ?int $succursaleId = null): ?self
    {
        return self::where('periode_id', $periodeId)
            ->where('is_latest', true)
            ->when($entiteId, fn($q) => $q->where('entite_id', $entiteId))
            ->when($succursaleId, fn($q) => $q->where('succursale_id', $succursaleId))
            ->orderBy('calculated_at', 'desc')
            ->first();
    }

    // Relations
    public function entite()
    {
        return $this->belongsTo(Entite::class, 'entite_id');
    }

    public function periodeComptable()
    {
        return $this->belongsTo(PeriodesComptable::class, 'periode_id');
    }

    public function succursale()
    {
        return $this->belongsTo(Succursale::class, 'succursale_id');
    }
}