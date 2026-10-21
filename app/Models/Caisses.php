<?php

namespace App\Models;

use Carbon\Carbon;
use App\Exceptions\BusinessException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Caisses extends Model
{
    use SoftDeletes;

    // Constantes
    const CATEGORIE_COFFRE = 'COFFRE';
    const CATEGORIE_PRINCIPALE = 'PRINCIPALE';
    const CATEGORIE_SECONDAIRE = 'SECONDAIRE';
    protected $table = 'caisses';

    protected $fillable = [
        'code',
        'nom_caisse',
        'type_caisse',
        'categorie',
        'id_creator',
        'succursale_id',
        'caissier_attribue',
        'parent_id',
        'statut',
        'inactif',
        'date_derniere_operation',
        'service_mobile_money_id',
    ];

    protected $casts = [
        'inactif'                 => 'boolean',
        'date_derniere_operation' => 'datetime',
    ];

    // ============================================================
    // RELATIONS
    // ============================================================

    public function parent()
    {
        return $this->belongsTo(Caisses::class, 'parent_id');
    }

    public function enfants()
    {
        return $this->hasMany(Caisses::class, 'parent_id');
    }
    public function succursale()
    {
        return $this->belongsTo(Succursale::class, 'succursale_id');
    }

    public function devise()
    {
        return $this->belongsTo(Devise::class, 'devise_id');
    }

    public function caissier()
    {
        return $this->belongsTo(Utilisateur::class, 'caissier_attribue');
    }

    public function journaux()
    {
        return $this->hasMany(Journal_Caisses::class, 'caisse_id');
    }

    public function transactions()
    {
        return $this->hasMany(Transaction_Globale::class, 'caisse_id');
    }

    public function soldes()
    {
        return $this->hasMany(SoldeCaisse::class, 'caisse_id');
    }

    public function permissions()
    {
        return $this->hasOne(CaissePermission::class, 'caisse_id');
    }

    public function serviceMobileMoney()
    {
        return $this->belongsTo(ServiceMobileMoney::class, 'service_mobile_money_id');
    }

    // ============================================================
    // SCOPES
    // ============================================================

    public function scopeOuverte($query)
    {
        return $query->where('statut', 'OUVERTE');
    }

    public function scopeFermee($query)
    {
        return $query->where('statut', 'FERMEE');
    }

    public function scopeBloquee($query)
    {
        return $query->where('statut', 'BLOQUEE');
    }

    public function scopeExistante($query)
    {
        return $query->where('inactif', 0);
    }

    public function scopeInexistante($query)
    {
        return $query->where('inactif', 1);
    }

    public function scopeParSuccursale($query, int $succursaleId)
    {
        return $query->where('succursale_id', $succursaleId);
    }

    public function scopeDisponible($query)
    {
        return $query->where('inactif', 0)->where('statut', 'FERMEE');
    }

    // ============================================================
    // LOGIQUE MÉTIER
    // ============================================================
    
    public function getParentNomAttribute(): ?string
    {
        return $this->parent ? $this->parent->nom_caisse : null;
    }
    
    public function isCoffre(): bool
    {
        return $this->categorie === self::CATEGORIE_COFFRE;
    }

    public function isPrincipale(): bool
    {
        return $this->categorie === self::CATEGORIE_PRINCIPALE;
    }

    public function isSecondaire(): bool
    {
        return $this->categorie === self::CATEGORIE_SECONDAIRE;
    }
    public function existePhysiquement(): bool
    {
        return !$this->inactif;
    }

    public function peutEtreOuverte(): bool
    {
        return $this->existePhysiquement()
            && trim($this->statut) == 'FERMEE';
    }

    public function peutEtreEnAttente(): bool
    {
        return trim($this->statut) === 'OUVERTE'
            && $this->existePhysiquement();
    }

    public function peutEtreFermee(): bool
    {
        return \in_array($this->statut, ['OUVERTE', 'EN_ATTENTE']);
    }

    public function peutEtreBloquee(): bool
    {
        return \in_array($this->statut, ['OUVERTE', 'FERMEE', 'EN_ATTENTE'])
            && $this->existePhysiquement();
    }

    public function peutEtreDebloquee(): bool
    {
        return $this->statut === 'BLOQUEE'
            && $this->existePhysiquement();
    }

    public function peutEtreOpereeDirectement(): bool
    {
        // Le coffre est toujours opérable
        if ($this->isCoffre()) {
            return true;
        }

        // Une caisse secondaire (quel que soit son type) est opérable si elle est ouverte
        if ($this->isSecondaire()) {
            // On peut restreindre aux types autorisés à recevoir/émettre des fonds
            // Généralement tous les types sont autorisés (PHYSIQUE, MOBILE_MONEY, BANCAIRE)
            return in_array($this->type_caisse, ['PHYSIQUE', 'MOBILE_MONEY', 'BANCAIRE']);
        }

        // Les caisses principales ne sont jamais opérables directement
        return false;
    }

    public function getSolde(int $deviseId): float
    {
        return (float) ($this->solde_consolide[$deviseId] ?? 0);
    }

    public function verifierSolde(float $montant, int $deviseId): string
    {
        if (!$this->peutEtreOpereeDirectement()) {
            return 'Cette caisse est principale contactez votre administrateur pour plus de détails {verriferSolde}';
        }

        $soldeCaisse = SoldeCaisse::where('caisse_id', $this->id)
            ->where('devise_id', $deviseId)
            ->first();

        if (!$soldeCaisse) return 'solde_insuffisant';

        $soldeApres = (float) $soldeCaisse->solde - $montant;

        // Solde réellement insuffisant — on ne peut pas physiquement payer
        if ($soldeApres < 0) return 'solde_insuffisant';

        // Seuil minimal serait atteint ou déjà atteint — avertissement seulement
        if ($soldeApres < (float) $soldeCaisse->solde_minimal) return 'seuil_minimal_atteint';

        return 'ok';
    }   

    public function soldeSuffisant(float $montant, int $deviseId): bool
    {
        if (!$this->peutEtreOpereeDirectement()) return false;

        $soldeCaisse = SoldeCaisse::where('caisse_id', $this->id)
            ->where('devise_id', $deviseId)
            ->first();

        if (!$soldeCaisse) return false;

        return ((float) $soldeCaisse->solde - $montant) >= 0;
    }

    public function soldeDepasse(float $montant, int $deviseId): bool
    {
        if (!$this->peutEtreOpereeDirectement()) return false;
        
        $soldeCaisse = SoldeCaisse::where('caisse_id', $this->id)
            ->where('devise_id', $deviseId)
            ->first();

        if (!$soldeCaisse) return false;

        return $soldeCaisse->solde_maximal !== null
            && ((float) $soldeCaisse->solde + $montant) > (float) $soldeCaisse->solde_maximal;
    }

    public function crediter(
        float   $montant,
        int     $deviseId,
        ?string $description   = null,
        ?string $reference     = null,
        ?int    $utilisateurId = null,
        string  $typeMouvement = 'TRANSFERT_ENTRANT',
        ?int    $idType        = null
    ): bool {
        if (!$this->existePhysiquement()) {
            throw new BusinessException("La caisse '{$this->nom_caisse}' n'existe physiquement pas.");
        }
        if ($this->soldeDepasse($montant, $deviseId)) {
            throw new BusinessException("Le plafond maximal de la caisse '{$this->nom_caisse}' serait dépassé pour cette devise.");
        }
        if (!$this->peutEtreOpereeDirectement()) {
            throw new BusinessException("La caisse '{$this->nom_caisse}' ne peut pas être opérée directement.");
        }

        // Création du solde s'il n'existe pas
        $solde = SoldeCaisse::firstOrCreate(
            ['caisse_id' => $this->id, 'devise_id' => $deviseId],
            ['solde' => 0, 'solde_minimal' => 0, 'solde_maximal' => null]
        );

        $ancienSolde = (float) $solde->solde;

        // Mise à jour atomique
        $updated = SoldeCaisse::where('caisse_id', $this->id)
                    ->where('devise_id', $deviseId)
                    ->increment('solde', $montant);

        if ($updated === 0) {
            throw new BusinessException("Échec de l'incrémentation du solde de la caisse '{$this->nom_caisse}'.");
        }

        $solde->refresh();

        JournalMouvementCaisse::enregistrer(
            $this->id,
            $deviseId,
            $typeMouvement,
            $montant,
            $ancienSolde,
            (float) $solde->solde,
            $reference,
            $description,
            $utilisateurId,
            $idType
        );

        $this->date_derniere_operation = Carbon::now();
        $this->save();

        return true;
    }

    public function debiter(
        float   $montant,
        int     $deviseId,
        ?string $description   = null,
        ?string $reference     = null,
        ?int    $utilisateurId = null,
        string  $typeMouvement = 'TRANSFERT_SORTANT',
        ?int    $idType        = null
    ): bool {
        if (!$this->existePhysiquement()) {
            throw new BusinessException("La caisse '{$this->nom_caisse}' n'existe physiquement pas.");
        }
        if (!$this->soldeSuffisant($montant, $deviseId)) {
            throw new BusinessException("Solde insuffisant dans la caisse '{$this->nom_caisse}' pour la devise demandée.");
        }
        if (!$this->peutEtreOpereeDirectement()) {
            throw new BusinessException("La caisse '{$this->nom_caisse}' ne peut pas être opérée directement.");
        }

        // Mise à jour atomique avec condition de solde suffisant
        $updated = SoldeCaisse::where('caisse_id', $this->id)
                    ->where('devise_id', $deviseId)
                    ->where('solde', '>=', $montant)
                    ->decrement('solde', $montant);

        if ($updated === 0) {
            throw new BusinessException("Solde insuffisant ou ligne de solde introuvable pour la caisse '{$this->nom_caisse}'.");
        }

        $solde = SoldeCaisse::where('caisse_id', $this->id)
                    ->where('devise_id', $deviseId)
                    ->first();

        $nouveauSolde = (float) ($solde ? $solde->solde : 0);
        $ancienSolde = $nouveauSolde + $montant;

        JournalMouvementCaisse::enregistrer(
            $this->id,
            $deviseId,
            $typeMouvement,
            $montant,
            $ancienSolde,
            $nouveauSolde,
            $reference,
            $description,
            $utilisateurId,
            $idType
        );

        $this->date_derniere_operation = Carbon::now();
        $this->save();

        return true;
    }

    public function ouvrir(): bool
    {
        if (!$this->peutEtreOuverte()) return false;
        $this->statut = 'OUVERTE';
        return $this->save();
    }

    public function fermer(): bool
    {
        if (!$this->peutEtreFermee()) return false;
        $this->statut = 'FERMEE';
        return $this->save();
    }

    public function enAttente(): bool
    {
        if (!$this->peutEtreFermee()) return false;
        $this->statut = 'EN_ATTENTE';
        return $this->save();
    }

    public function peutEtreValidee(): bool
    {
        return $this->statut === 'EN_ATTENTE'
            && $this->existePhysiquement();
    }

    public function bloquer(): bool
    {
        if (!$this->peutEtreBloquee()) return false;
        $this->statut = 'BLOQUEE';
        return $this->save();
    }

    public function debloquer(): bool
    {
        if (!$this->peutEtreDebloquee()) return false;
        $this->statut = 'FERMEE';
        return $this->save();
    }

    public function desactiver(): bool
    {
        if ($this->statut == 'OUVERTE') return false;
        $this->inactif = 1;
        return $this->save();
    }

    public function reactiver(): bool
    {
        $this->inactif = 0;
        return $this->save();
    }

    public static function coffrePourSuccursale($succursaleId): ?self
    {
        return static::where('categorie', self::CATEGORIE_COFFRE)
                    ->where('succursale_id', $succursaleId)
                    ->first()
            ??   // fallback global (sans succursale)
                static::where('categorie', self::CATEGORIE_COFFRE)
                    ->whereNull('succursale_id')
                    ->first();
    }

    public function getSoldeConsolideAttribute(): \Illuminate\Support\Collection
    {
        if ($this->isPrincipale() && $this->enfants()->exists()) {
            return SoldeCaisse::whereIn('caisse_id', $this->enfants()->pluck('id'))
                ->get()
                ->groupBy('devise_id')
                ->map(fn($group) => $group->sum('solde'));
        }

        // Comportement normal : soldes de la caisse elle-même
        return $this->soldes->pluck('solde', 'devise_id');
    }

    // Pour les permissions, on se base sur la caisse principale associée (soit elle-même si c'est une caisse principale ou un coffre, soit sa parent si c'est une secondaire)
    public function caissePourPermissions(): self
    {
        if ($this->isSecondaire() && $this->parent) {
            return $this->parent;
        }
        return $this;
    }

    // Vérifie si une action est autorisée pour cette caisse en se basant sur les permissions de la caisse principale associée
    public function peut(string $action): bool
    {
        $principale = $this->caissePourPermissions();
        // Si c'est un coffre ou qu'aucune permission n'est définie, on autorise tout par défaut
        if ($principale->isCoffre() || !$principale->permissions) {
            return true; // par défaut, le coffre et les caisses sans config ont tous les droits
        }
        return $principale->permissions->allows($action);
    }


    /**
     * Retourne la première caisse secondaire physique active (statut OUVERTE, inactif = 0).
     */
    public function secondairePhysique(): ?self
    {
        return $this->enfants()
            ->where('type_caisse', 'PHYSIQUE')
            ->where('statut', 'OUVERTE')
            ->where('inactif', 0)
            ->first();
    }

    /**
     * Retourne la caisse secondaire MOBILE_MONEY correspondant au service demandé.
     * Si $serviceMobileMoneyId est null, retourne la première secondaire MOBILE_MONEY ouverte.
     */
    public function secondaireMobileMoney(?int $serviceMobileMoneyId = null): ?self
    {
        $query = $this->enfants()
            ->where('type_caisse', 'MOBILE_MONEY')
            ->where('statut', 'OUVERTE')
            ->where('inactif', 0);

        if ($serviceMobileMoneyId !== null) {
            $query->where('service_mobile_money_id', $serviceMobileMoneyId);
        }

        return $query->first();
    }

    /**
     * Retourne la caisse secondaire BANCAIRE correspondant au service demandé.
     * Si $serviceBancaireId est null, retourne la première secondaire BANCAIRE ouverte.
     */
    public function secondaireBancaire(?int $serviceBancaireId = null): ?self
    {
        $query = $this->enfants()
            ->where('type_caisse', 'BANCAIRE')
            ->where('statut', 'OUVERTE')
            ->where('inactif', 0);

        if ($serviceBancaireId !== null) {
            $query->where('service_mobile_money_id', $serviceBancaireId);
        }

        return $query->first();
    }

}