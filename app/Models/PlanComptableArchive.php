<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Class PlanComptableArchive
 * 
 * @property int $id
 * @property int $original_id
 * @property string $numero
 * @property string $nom
 * @property int $classe_id
 * @property string|null $position_bilan
 * @property string|null $position_tfr
 * @property string $fonctionnement_debit
 * @property string $fonctionnement_credit
 * @property bool|null $is_active
 * @property int|null $deleted_by
 * @property Carbon $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property User|null $supprimePar
 *
 * @package App\Models
 */
class PlanComptableArchive extends Model
{
    // Pas besoin de SoftDeletes ici car c'est déjà une table d'archives
    // Les enregistrements sont déjà supprimés de la table principale
    
    protected $table = 'plan_comptable_archives';
    
    // Désactiver les timestamps automatiques car la table a déjà deleted_at comme timestamp
    public $timestamps = true;

    protected $casts = [
        'original_id' => 'int',
        'classe_id' => 'int',
        'is_active' => 'bool',
        'deleted_by' => 'int',
        'deleted_at' => 'datetime',  // Important : caster en datetime
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $fillable = [
        'original_id',
        'numero',
        'nom',
        'classe_id',
        'position_bilan',
        'position_tfr',
        'fonctionnement_debit',
        'fonctionnement_credit',
        'is_active',
        'deleted_by',
        'deleted_at',
        'created_at',
        'updated_at',
    ];

    /**
     * Relation avec l'utilisateur qui a supprimé le compte
     */
    public function supprimePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    /**
     * Relation avec la classe comptable
     */
    public function classe(): BelongsTo
    {
        return $this->belongsTo(CompteClass::class, 'classe_id');
    }

    /**
     * Récupère les informations formatées de l'archive
     */
    public function getInformationsArchivage(): array
    {
        return [
            'id' => $this->id,
            'original_id' => $this->original_id,
            'numero' => $this->numero,
            'nom' => $this->nom,
            'classe' => $this->classe ? $this->classe->nom : 'N/A',
            'supprime_par' => $this->supprimePar ? $this->supprimePar->name : 'Inconnu',
            'date_suppression' => $this->deleted_at ? $this->deleted_at->format('d/m/Y H:i:s') : 'N/A',
            'date_creation_compte' => $this->created_at ? $this->created_at->format('d/m/Y H:i:s') : 'N/A',
        ];
    }

    /**
     * Scope pour filtrer par utilisateur qui a supprimé
     */
    public function scopeSupprimePar($query, $userId)
    {
        return $query->where('deleted_by', $userId);
    }

    /**
     * Scope pour filtrer par date de suppression
     */
    public function scopeSupprimeEntre($query, $dateDebut, $dateFin)
    {
        return $query->whereBetween('deleted_at', [$dateDebut, $dateFin]);
    }

    /**
     * Scope pour les archives récentes (30 derniers jours)
     */
    public function scopeRecent($query)
    {
        return $query->where('deleted_at', '>=', now()->subDays(30));
    }

    /**
     * Restaurer ce compte archivé
     */
    public function restaurer(): ?PlanComptable
    {
        return DB::transaction(function () {
            // Vérifier si le numéro existe déjà
            $existe = PlanComptable::where('numero', $this->numero)->exists();
            
            if ($existe) {
                throw new \Exception("Le numéro de compte {$this->numero} existe déjà dans le plan comptable.");
            }

            // Créer le compte restauré
            $compte = PlanComptable::create([
                'numero' => $this->numero,
                'nom' => $this->nom,
                'classe_id' => $this->classe_id,
                'position_bilan' => $this->position_bilan,
                'position_tfr' => $this->position_tfr,
                'fonctionnement_debit' => $this->fonctionnement_debit,
                'fonctionnement_credit' => $this->fonctionnement_credit,
                'is_active' => true,
            ]);

            // Mettre à jour les écritures qui avaient ce numéro
            DB::table('ecritures_comptables')
                ->where('compte_numero', 'SUPPRIME_' . $this->numero . '_%')
                ->update(['compte_numero' => $this->numero]);

            return $compte;
        });
    }
}