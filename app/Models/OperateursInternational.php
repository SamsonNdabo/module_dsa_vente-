<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\TauxChange;

class OperateursInternational extends Model
{
    protected $table      = 'operateurs_international';
    public    $timestamps = false;

    protected $fillable = [
        'code_operateur',
        'nom_operateur',
        'type_operateur',
        'international',
        'commission_envoi',
        'commission_reception',
        'taux_change',
        'pays_desservis',
        'api_endpoint',
        'api_key',
        'statut',
    ];

    protected $casts = [
        'commission_envoi'     => 'decimal:4',
        'commission_reception' => 'decimal:4',
        'taux_change'          => 'decimal:4',
        'pays_desservis'       => 'array',
        'created_at'           => 'datetime',
    ];

    // ============================================================
    // RELATIONS
    // ============================================================

    public function transferts()
    {
        return $this->hasMany(TransfertInternational::class, 'operateur_transfert_id');
    }

    // ============================================================
    // SCOPES
    // ============================================================

    public function scopeActif($query)
    {
        return $query->where('statut', 'ACTIF');
    }

    public function scopeDesservePays($query, string $pays)
    {
        return $query->whereJsonContains('pays_desservis', $pays);
    }

    // ============================================================
    // LOGIQUE MÉTIER
    // ============================================================

    // Calculer les frais d'envoi

    public function calculerFraisEnvoi(float $montant): float
    {
        return round($montant * ($this->commission_envoi / 100), 4);
    }

    // Calculer les frais de réception
    public function calculerFraisReception(float $montant): float
    {
        return round($montant * ($this->commission_reception / 100), 4);
    }


    // Vérifier si un pays est desservi
    public function desservPays(string $pays): bool
    {
        return \in_array($pays, $this->pays_desservis ?? []);
    }


    public function peutEtreDesactive(): bool
    {
        // Vérifier qu'il n'y a pas de transferts en cours
        return !$this->transferts()
            ->whereNotIn('statut_transfert', ['RECU', 'ANNULE', 'REMBOURSE'])
            ->exists();
    }

    public function activer(): bool
    {
        $this->statut = 'ACTIF';
        return $this->save();
    }

    public function desactiver(): bool
    {
        if (!$this->peutEtreDesactive()) return false;
        $this->statut = 'INACTIF';
        return $this->save();
    }

    public function getTauxEffectif(int $deviseSourceId, int $deviseCibleId): ?float
    {
        // Priorité 1 → taux propre à l'opérateur
        if ($this->taux_change) {
            return (float) $this->taux_change;
        }

        // Priorité 2 → taux du marché dans TauxChange
        $tauxMarche = TauxChange::where('devise_source', $deviseSourceId)
            ->where('devise_cible', $deviseCibleId)
            ->orderBy('created_at', 'desc')
            ->value('taux');

        return $tauxMarche ? (float) $tauxMarche : null;
    }

    public function convertir(int $deviseSourceId, int $deviseCibleId, float $montant): array
    {
        // Même devise
        if ($deviseSourceId === $deviseCibleId) {
            return [
                'montant_initial'  => $montant,
                'montant_converti' => $montant,
                'taux_utilise'     => 1,
                'sens'             => 'identique',
            ];
        }
        $succursaleId = Auth()->user()->succursale_id;
        // 1. Taux propre à l'opérateur (si défini) : on le considère comme un taux fixe (achat = vente = ce taux)
        if ($this->taux_change) {
            // On se comporte comme un sens direct avec un taux unique → on divise (comme un achat de devise cible)
            // Ou on pourrait simplement multiplier ? En général, un taux opérateur est souvent "1 EUR = 655.96 FCFA" etc.
            // On va considérer que le taux opérateur est un prix d'achat de la devise cible.
            // Si le client donne source, il reçoit cible = montant / taux_change.
            $montantConverti = round($montant / (float) $this->taux_change, 4);
            return [
                'montant_initial'  => $montant,
                'montant_converti' => $montantConverti,
                'taux_utilise'     => $this->taux_change,
                'sens'             => 'operateur',
            ];
        }

        // 2. Appliquer la cascade standard (succursale depuis l'opérateur ? ou succursale du contexte ?)
        // Ici on n'a pas de caisse ni de succursale. On peut tenter de récupérer la succursale depuis le modèle
        // si l'opérateur est lié à une succursale (il y a peut-être une relation ?)
        // Sinon, on appelle getTauxApplicables avec caisseId = null et succursaleId = 0 (ignorer)

        [$tauxAchat, $tauxVente, $sens] = \App\Models\OperationChange::getTauxApplicables(
            $deviseSourceId,
            $deviseCibleId,
            null,           // pas de caisse
            $succursaleId,
            null, null      // pas de taux forcé
        );

        if ($tauxAchat == 0 || $tauxVente == 0 || $sens === null) {
            return [
                'montant_initial'  => $montant,
                'montant_converti' => $montant,
                'taux_utilise'     => null,
                'sens'             => 'aucun_taux',
            ];
        }

        // Calcul standard
        $montantConverti = ($sens === 'direct')
            ? round($montant / $tauxAchat, 4)
            : round($montant * $tauxVente, 4);

        return [
            'montant_initial'  => $montant,
            'montant_converti' => $montantConverti,
            'taux_utilise'     => ($sens === 'direct') ? $tauxAchat : $tauxVente,
            'taux_achat'       => $tauxAchat,
            'taux_vente'       => $tauxVente,
            'sens'             => $sens,
        ];
    }

    public static function getNameById(string $id) {
        return self::where('id', $id)->firstOrFail()->nom_operateur;
    }
}