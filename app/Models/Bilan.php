<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable; //  AJOUT

/**
 * Class Bilan
 * 
 * @property int $id
 * @property int $periode_id
 * @property string $type_bilan          // 'initial', 'fermeture', 'provisoire'
 * @property bool $est_provisoire        // true = provisoire, false = définitif
 * @property bool $provient_cloture      // true = issu d'une clôture automatique
 * @property string $date_bilan
 * @property int|null $entite_id
 * @property int|null $succursale_id
 * @property bool|null $is_actif
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * 
 * @property PeriodesComptable $periode_comptable
 * @property Entite|null $entite
 * @property Succursale|null $succursale
 * @property Collection|BilanLigne[] $bilan_lignes
 *
 * @package App\Models
 */
class Bilan extends Model
{
    use Auditable; //  AJOUT

    protected $table = 'bilans';
    
    public $timestamps = true;

    protected $casts = [
        'periode_id'       => 'int',
        'est_provisoire'   => 'boolean',
        'provient_cloture' => 'boolean',
        'date_bilan'       => 'date',
        'entite_id'        => 'int',
        'succursale_id'    => 'int',
        'is_actif'         => 'boolean',
    ];

    protected $fillable = [
        'periode_id',
        'type_bilan',
        'est_provisoire',
        'provient_cloture',
        'date_bilan',
        'entite_id',
        'succursale_id',
        'is_actif',
    ];

    // =============================================
    // BOOT - AUDIT AUTOMATIQUE
    // =============================================

    protected static function boot()
    {
        parent::boot();

        // Audit automatique sur les événements clés
        static::created(function ($bilan) {
            $bilan->logAudit('CREATION_BILAN', null, [
                'type_bilan' => $bilan->type_bilan,
                'periode_id' => $bilan->periode_id,
                'date_bilan' => $bilan->date_bilan,
                'est_provisoire' => $bilan->est_provisoire,
            ], 'INFO');
        });

        static::updated(function ($bilan) {
            $changed = $bilan->getDirty();
            if (!empty($changed)) {
                $bilan->logAudit('MODIFICATION_BILAN', $bilan->getOriginal(), $changed, 'HAUTE');
            }
        });

        static::deleted(function ($bilan) {
            $bilan->logAudit('SUPPRESSION_BILAN', $bilan->toArray(), null, 'CRITIQUE');
        });
    }

    // =============================================
    // RELATIONS
    // =============================================
    
    public function periode_comptable()
    {
        return $this->belongsTo(PeriodesComptable::class, 'periode_id');
    }

    public function entite()
    {
        return $this->belongsTo(Entite::class, 'entite_id');
    }

    public function succursale()
    {
        return $this->belongsTo(Succursale::class, 'succursale_id');
    }

    public function bilan_lignes()
    {
        return $this->hasMany(BilanLigne::class, 'bilan_id');
    }

    // =============================================
    // SCOPES DE BASE
    // =============================================
    
    /**
     * Scope pour le bilan actif
     */
    public function scopeActif($query)
    {
        return $query->where('is_actif', true);
    }

    /**
     * Scope pour le type de bilan
     */
    public function scopeType($query, $type)
    {
        return $query->where('type_bilan', $type);
    }

    /**
     * Scope pour les bilans initiaux
     */
    public function scopeInitial($query)
    {
        return $query->where('type_bilan', 'initial');
    }

    /**
     * Scope pour les bilans de fermeture
     */
    public function scopeFermeture($query)
    {
        return $query->where('type_bilan', 'fermeture');
    }

    /**
     * Scope pour les bilans provisoires (type = 'provisoire')
     */
    public function scopeProvisoire($query)
    {
        return $query->where('type_bilan', 'provisoire');
    }

    /**
     * Scope pour une entité
     */
    public function scopePourEntite($query, $entiteId)
    {
        return $query->where('entite_id', $entiteId);
    }

    /**
     * Scope pour une succursale
     */
    public function scopePourSuccursale($query, $succursaleId)
    {
        return $query->where('succursale_id', $succursaleId);
    }

    // =============================================
    // SCOPES PROVISOIRE / DÉFINITIF
    // =============================================

    /**
     * Scope pour les bilans provisoires (est_provisoire = true)
     */
    public function scopeEstProvisoire($query)
    {
        return $query->where('est_provisoire', true);
    }

    /**
     * Scope pour les bilans définitifs (est_provisoire = false)
     */
    public function scopeEstDefinitif($query)
    {
        return $query->where('est_provisoire', false);
    }

    /**
     * Scope pour les bilans issus d'une clôture automatique
     */
    public function scopeIssuCloture($query)
    {
        return $query->where('provient_cloture', true);
    }

    /**
     * Scope pour les bilans créés manuellement
     */
    public function scopeManuel($query)
    {
        return $query->where('provient_cloture', false);
    }

    // =============================================
    // SCOPES COMBINÉS (métier)
    // =============================================

    /**
     * Bilan de Fermeture PROVISOIRE
     */
    public function scopeFermetureProvisoire($query)
    {
        return $query->where('type_bilan', 'fermeture')
                     ->where('est_provisoire', true);
    }

    /**
     * Bilan de Fermeture DÉFINITIF = BILAN FINAL
     */
    public function scopeFermetureDefinitif($query)
    {
        return $query->where('type_bilan', 'fermeture')
                     ->where('est_provisoire', false);
    }

    /**
     * Bilan Initial PROVISOIRE
     */
    public function scopeInitialProvisoire($query)
    {
        return $query->where('type_bilan', 'initial')
                     ->where('est_provisoire', true);
    }

    /**
     * Bilan Initial DÉFINITIF
     */
    public function scopeInitialDefinitif($query)
    {
        return $query->where('type_bilan', 'initial')
                     ->where('est_provisoire', false);
    }

    // =============================================
    // MÉTHODES UTILES
    // =============================================
    
    /**
     * Vérifier si le bilan est équilibré
     */
    public function estEquilibre(): bool
    {
        $totalActif = $this->bilan_lignes()
            ->where('type_compte', 'actif')
            ->sum('montant');
            
        $totalPassif = $this->bilan_lignes()
            ->where('type_compte', 'passif')
            ->sum('montant');
            
        return abs($totalActif - $totalPassif) < 0.01;
    }

    /**
     * Obtenir le total actif
     */
    public function getTotalActifAttribute(): float
    {
        return (float) $this->bilan_lignes()
            ->where('type_compte', 'actif')
            ->sum('montant');
    }

    /**
     * Obtenir le total passif
     */
    public function getTotalPassifAttribute(): float
    {
        return (float) $this->bilan_lignes()
            ->where('type_compte', 'passif')
            ->sum('montant');
    }

    /**
     * Obtenir l'écart actif/passif
     */
    public function getEcartAttribute(): float
    {
        return round($this->total_actif - $this->total_passif, 2);
    }

    // =============================================
    // MÉTHODES PROVISOIRE / DÉFINITIF
    // =============================================

    /**
     * Vérifier si ce bilan est provisoire
     */
    public function estProvisoire(): bool
    {
        return $this->est_provisoire === true;
    }

    /**
     * Vérifier si ce bilan est définitif (verrouillé)
     */
    public function estDefinitif(): bool
    {
        return $this->est_provisoire === false;
    }

    /**
     * Vérifier si ce bilan est un Bilan Final (fermeture définitive)
     */
    public function estBilanFinal(): bool
    {
        return $this->type_bilan === 'fermeture' && $this->est_provisoire === false;
    }

    /**
     * Vérifier si ce bilan est modifiable
     */
    public function estModifiable(): bool
    {
        return $this->est_provisoire === true;
    }

    /**
     * Verrouiller ce bilan (le passer en définitif)
     */
    public function verrouiller(): bool
    {
        $result = $this->update(['est_provisoire' => false]);
        if ($result) {
            $this->logAudit('VERROUILLAGE_BILAN', ['est_provisoire' => true], ['est_provisoire' => false], 'HAUTE');
        }
        return $result;
    }

    /**
     * Déverrouiller ce bilan (le repasser en provisoire)
     * ⚠️ À utiliser avec précaution !
     */
    public function deverrouiller(): bool
    {
        $result = $this->update(['est_provisoire' => true]);
        if ($result) {
            $this->logAudit('DEVERROUILLAGE_BILAN', ['est_provisoire' => false], ['est_provisoire' => true], 'CRITIQUE');
        }
        return $result;
    }

    /**
     * Obtenir le libellé du statut
     */
    public function getStatutLibelleAttribute(): string
    {
        if ($this->type_bilan === 'fermeture' && !$this->est_provisoire) {
            return 'BILAN FINAL';
        }
        
        $type = match($this->type_bilan) {
            'initial'   => 'Bilan Initial',
            'fermeture' => 'Bilan de Fermeture',
            'provisoire'=> 'Bilan Provisoire',
            default     => 'Bilan'
        };
        
        $statut = $this->est_provisoire ? 'PROVISOIRE' : 'DÉFINITIF';
        
        return "{$type} {$statut}";
    }

    /**
     * Vérifier si ce bilan peut être synchronisé
     */
    public function peutEtreSynchronise(): bool
    {
        return $this->est_provisoire === true && $this->provient_cloture === true;
    }

    // =============================================
    // GÉNÉRATION BILAN DE FERMETURE
    // =============================================

    /**
     * Générer le bilan de fermeture PROVISOIRE à partir de ce bilan initial
     */
    public function genererBilanFermetureProvisoire(): Bilan
    {
        // Désactiver ce bilan
        $this->update(['is_actif' => false]);
        
        // Créer le bilan de fermeture PROVISOIRE
        $bilanFermeture = self::create([
            'periode_id'       => $this->periode_id,
            'type_bilan'       => 'fermeture',
            'est_provisoire'   => true,
            'provient_cloture' => true,
            'date_bilan'       => $this->periode_comptable->date_fin ?? now(),
            'entite_id'        => $this->entite_id,
            'succursale_id'    => $this->succursale_id,
            'is_actif'         => true,
        ]);
        
        // Copier les lignes avec les soldes mis à jour
        foreach ($this->bilan_lignes as $ligne) {
            BilanLigne::create([
                'bilan_id'      => $bilanFermeture->id,
                'compte_numero' => $ligne->compte_numero,
                'compte_nom'    => $ligne->compte_nom,
                'type_compte'   => $ligne->type_compte,
                'montant'       => $ligne->montant,
            ]);
        }

        // Audit
        $this->logAudit('GENERATION_BILAN_FERMETURE', null, [
            'bilan_initial_id' => $this->id,
            'bilan_fermeture_id' => $bilanFermeture->id,
            'periode_id' => $this->periode_id,
        ], 'HAUTE');
        
        return $bilanFermeture;
    }

    /**
     * Transformer ce bilan de fermeture provisoire en BILAN FINAL
     */
    public function transformerEnBilanFinal(): bool
    {
        if ($this->type_bilan !== 'fermeture') {
            return false;
        }
        
        $result = $this->update([
            'est_provisoire' => false,
            'is_actif'       => false,
        ]);

        if ($result) {
            $this->logAudit('TRANSFORMATION_BILAN_FINAL', 
                ['est_provisoire' => true, 'is_actif' => true], 
                ['est_provisoire' => false, 'is_actif' => false], 
                'CRITIQUE'
            );
        }
        
        return $result;
    }

    // =============================================
    // MÉTHODES D'AUDIT SUPPLÉMENTAIRES
    // =============================================

    /**
     * Enregistrer une action manuelle d'audit sur le bilan
     */
    public function auditAction(string $action, array $details = [], string $importance = 'INFO'): void
    {
        $this->logAudit($action, null, $details, $importance);
    }
}