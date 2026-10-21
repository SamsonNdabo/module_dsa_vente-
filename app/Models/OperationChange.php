<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Exceptions\BusinessException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Devise;
use App\Services\EcritureService;
use App\Models\Utilisateur;

class OperationChange extends Model
{
    protected $table = 'operation_change';
    protected $appends = ['taux_affiche'];

    protected $fillable = [
        'reference',
        'type_operation',
        'succursale_id',
        'caisse_id',
        'devise_source_id',
        'devise_cible_id',
        'id_creator',
        'montant_source',
        'montant_cible',
        'taux_achat',
        'taux_vente',
        'niveau_alerte',
        'commission',
        'operateur_id',
        'statut',
        'date_operation',
        'valide_par',
        'taux_achat_force',
        'taux_vente_force',
        'motif_taux_force', 
        'date_validation',
        'taux_reference',
        'spread_achat',
        'spread_vente',
        'exchange_rate_id',
        'sens',
        'date_annulation',
        'annule_par',
        'motif_annulation',
        'client_id'
    ];

    protected $casts = [
        'montant_source' => 'decimal:4',
        'montant_cible'  => 'decimal:4',
        'taux_achat'     => 'decimal:4',
        'taux_vente'     => 'decimal:4',
        'commission'     => 'decimal:4',
        'date_operation' => 'date',
        'date_validation' => 'date',
    ];

    // ============================================================
    // RELATIONS
    // ============================================================


    public function getTauxAfficheAttribute()
    {
        // Utiliser le champ type_operation pour déterminer le bon taux
        if (str_starts_with($this->type_operation, 'ACHAT')) {
            return $this->taux_achat;
        }
        // Pour 'VENTE' ou 'VENTE-T'
        return $this->taux_vente;
    }
    public function succursale()
    {
        return $this->belongsTo(Succursale::class, 'succursale_id');
    }

    public function caisse()
    {
        return $this->belongsTo(Caisses::class, 'caisse_id');
    }

    public function deviseSource()
    {
        return $this->belongsTo(Devise::class, 'devise_source_id');
    }

    public function deviseCible()
    {
        return $this->belongsTo(Devise::class, 'devise_cible_id');
    }

    public function operateur()
    {
        return $this->belongsTo(Utilisateur::class, 'id_creator');
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }    
    
    public function validateur()
    {
        return $this->belongsTo(Utilisateur::class, 'valide_par');
    }

    public function exchangeRate()
    {
        return $this->belongsTo(ExchangeRate::class);
    }

    // ============================================================
    // SCOPES
    // ============================================================
    public function scopeEnAttenteValidation($query)
    {
        return $query->where('statut', 'EN_ATTENTE_VALIDATION');
    }

    // ============================================================
    // LOGIQUE MÉTIER
    // ============================================================

    public static function genererReference(): string
    {
        $date      = now()->format('Y-m-d');
        $derniere  = self::whereDate('created_at', now())->count() + 1;
        $sequence  = str_pad($derniere, 5, '0', STR_PAD_LEFT);
        return "CHG-{$date}-{$sequence}";
    }

    public static function effectuer(
        int    $succursaleId,
        int    $deviseSourceId,
        int    $deviseCibleId,
        float  $montantSource,
        ?int   $caisseSecId    = null,
        ?int   $operateurId    = null,
        bool   $inclureDansTransfert = false,
        bool   $force          = false,
        bool   $risqueDoubleEnregistrement = false,
        ?int   $clientId       = null,
        ?float $tauxAchatForce = null,
        ?float $tauxVenteForce = null,
        ?string $motifTauxForce = null,
        ?string $alerte        = null,
        int     $creator
    ): ?array {
    
        $sourceCode = Devise::find($deviseSourceId)?->code;
        $destCode = Devise::find($deviseCibleId)?->code;
    
        if ($sourceCode === null || $destCode === null) {
            return ['erreur' => 'Devise introuvable.'];
        }
    
        // Récupérer le taux actif (direct ou inverse)
        $tauxData = ExchangeRate::getTauxActif($sourceCode, $destCode, $caisseSecId, $succursaleId);
        if (!$tauxData) {
            return ['erreur' => "Aucun taux de référence trouvé pour la paire {$sourceCode}/{$destCode}."];
        }
    
        $exchangeRate = $tauxData['rate'];
        $sens = $tauxData['sens']; // 'direct' ou 'inverse'
        $niveau = $tauxData['niveau']; // 'caisse', 'succursale' ou 'global'
    
        $tauxReference = (float) $exchangeRate->taux_reference;
    
        // Récupérer les taux d'achat et de vente en fonction du sens
        $tauxAchat = $exchangeRate->getTauxAchat($sens);
        $tauxVente = $exchangeRate->getTauxVente($sens);
        Log::info('tauxAchat: ' . $tauxAchat . ', tauxVente: ' . $tauxVente . ', sens: ' . $sens);
    
        // Si des taux forcés sont fournis, on les utilise
        if ($tauxAchatForce !== null && $tauxVenteForce !== null) {
            $tauxAchat = $tauxAchatForce;
            $tauxVente = $tauxVenteForce;
            Log::info('Taux forcés utilisés', ['tauxAchat' => $tauxAchat, 'tauxVente' => $tauxVente]);
        }
    
        // Déterminer le type d'opération et le montant cible
        $cdfCode = 'CDF';
        if ($sourceCode === $cdfCode) {
            // Client achète la devise cible (ex: USD)
            $typeOperation = 'VENTE';
            $tauxApplique = $tauxVente; // taux de vente
            $montantCible = round($montantSource / $tauxApplique, 4);
            $commission = round(($tauxVente - $tauxReference) * $montantCible, 4);
        } else {
            // Client vend la devise source (ex: USD)
            $typeOperation = 'ACHAT';
            $tauxApplique = $tauxAchat; // taux d'achat
            $montantCible = round($montantSource * $tauxApplique, 4);
            $commission = round(($tauxReference - $tauxAchat) * $montantSource, 4);
        }
    
        if ($commission < 0) $commission = 0;
    
        // 4. Contrôle caisse (si fournie)
        if ($caisseSecId) {
            $caisse = Caisses::find($caisseSecId);
            if (!$caisse) return ['erreur' => 'Caisse introuvable'];
            if ($caisse->type_caisse !== 'PHYSIQUE') return ['erreur' => 'Un change nécessite une caisse physique'];
    
            // Vérifier solde devise cible (ce que la caisse donne au client)
            $statutSolde = $caisse->verifierSolde($montantCible, $deviseCibleId);
            if ($statutSolde === 'solde_insuffisant') {
                return ['erreur' => 'Solde insuffisant dans la caisse pour la devise cible'];
            }
            if ($statutSolde === 'seuil_minimal_atteint' && !$force) {
                $soldeCaisse = SoldeCaisse::where('caisse_id', $caisseSecId)
                    ->where('devise_id', $deviseCibleId)->first();
                return [
                    'avertissement'  => true,
                    'message'        => 'Le seuil minimal de la caisse sera atteint',
                    'solde_actuel'   => (float) $soldeCaisse->solde,
                    'montant_requis' => $montantCible,
                    'solde_minimal'  => (float) $soldeCaisse->solde_minimal,
                    'solde_apres'    => round((float) $soldeCaisse->solde - $montantCible, 4),
                ];
            }
    
            // Plafond devise source
            $soldeSource = SoldeCaisse::where('caisse_id', $caisseSecId)
                ->where('devise_id', $deviseSourceId)->first();
            if ($soldeSource && $soldeSource->solde_maximal !== null &&
                ((float) $soldeSource->solde + $montantSource) > (float) $soldeSource->solde_maximal) {
                return [
                    'erreur'         => 'Plafond maximal de la caisse dépassé pour la devise source',
                    'solde_actuel'   => (float) $soldeSource->solde,
                    'montant_ajoute' => $montantSource,
                    'solde_maximal'  => (float) $soldeSource->solde_maximal,
                ];
            }
    
            if (!$risqueDoubleEnregistrement) {
                $caisse->debiter($montantCible, $deviseCibleId, null, null, $operateurId);
                $caisse->crediter($montantSource, $deviseSourceId, null, null, $operateurId);
            }
        }
    
        // ---- Récupérer l'ID du taux global correspondant pour exchange_rate_id ----
        $globalRate = ExchangeRate::where('devise_source', $sourceCode)
            ->where('devise_destination', $destCode)
            ->where('actif', true)
            ->orderBy('date_effet', 'desc')
            ->first();
        if (!$globalRate) {
            // Essayer l'inverse
            $globalRate = ExchangeRate::where('devise_source', $destCode)
                ->where('devise_destination', $sourceCode)
                ->where('actif', true)
                ->orderBy('date_effet', 'desc')
                ->first();
        }
        $exchangeRateId = $globalRate ? $globalRate->id : null;
    
        // ---- Préparer les IDs des taux personnalisés pour traçabilité ----
        $caisseExchangeRateId = null;
        $succursaleExchangeRateId = null;
        if ($niveau === 'caisse') {
            $caisseExchangeRateId = $exchangeRate->id;
        } elseif ($niveau === 'succursale') {
            $succursaleExchangeRateId = $exchangeRate->id;
        }
    
        // 6. Création de l'opération
        $operation = self::create([
            'reference'        => self::genererReference(),
            'type_operation'   => $typeOperation,
            'succursale_id'    => $succursaleId,
            'caisse_id'        => $caisseSecId,
            'devise_source_id' => $deviseSourceId,
            'devise_cible_id'  => $deviseCibleId,
            'montant_source'   => $montantSource,
            'montant_cible'    => $montantCible,
            'taux_achat'       => ($sourceCode === $cdfCode) ? null : $tauxApplique,
            'taux_vente'       => ($sourceCode === $cdfCode) ? $tauxApplique : null,
            'taux_reference'   => $tauxReference,
            'spread_achat'     => (float) $exchangeRate->spread_achat,
            'spread_vente'     => (float) $exchangeRate->spread_vente,
            'commission'       => $commission,
            'exchange_rate_id' => $exchangeRateId, // ✅ toujours un ID de exchange_rates
            'caisse_exchange_rate_id'   => $caisseExchangeRateId,
            'succursale_exchange_rate_id' => $succursaleExchangeRateId,
            'operateur_id'     => $operateurId,
            'statut'           => 'COMPLETEE',
            'date_operation'   => now()->format('Y-m-d'),
            'client_id'        => $clientId,
            'niveau_alerte'    => $alerte ?? 'NORMAL',
            'taux_achat_force' => $tauxAchatForce,
            'taux_vente_force' => $tauxVenteForce,
            'motif_taux_force' => $motifTauxForce,
            'id_creator'       => $creator,
        ]);
        
        // ── Étape 10 : Écriture comptable ────────────────────────────
        $deviseCible = Devise::getCodeById($deviseCibleId);
        $deviseSource = Devise::getCodeById($deviseSourceId);
        
        // Recuperer juste le prénom du user
        $nomUser = auth()->user()->nom_utilisateur;
        $nomUser = Utilisateur::extrairePrenom($nomUser);
        
        $ecriture = new EcritureService();

            Log::info('Change_Fonction Effectuer', [
                'montant' => ($deviseCible === 'CDF') ? $montantSource : $montantCible,
                'dviseCible' => $deviseCible,
                'deviseSource' => $deviseSource,
                'reference' => $operation->reference,
                'commission' => $commission,
            ]);

            $ecriture->creerEcritureSimple([
                'montant' => ($deviseCible === 'CDF') ? $montantSource : $montantCible,
                'code' => 'FIT',
                'entite_id' => 2,
                'operation_libelle' => 'Change ' .$deviseSource. ' - ' .$deviseCible,
                'libelle' =>'Change de ' .$montantSource. ' ' .$deviseSource. ' montant remis ' .$montantCible. ' ' .$deviseCible,
                'date_ecriture' => now()->format('Y-m-d'),
                'reference' => $operation->reference
            ]);

            Log::info('Operation de change effectuer avec succès ×× écriture incluse');
            
        return [
            'operation'  => $operation->load(['deviseSource', 'deviseCible', 'succursale']),
            'commission' => $commission,
            'taux_achat' => ($sourceCode === $cdfCode) ? null : $tauxApplique,
            'taux_vente' => ($sourceCode === $cdfCode) ? $tauxApplique : null,
        ];
    }

    public function annuler(int $utilisateurId, ?string $motif = null): void
    {
        if ($this->statut === 'ANNULEE') {
            throw new BusinessException('Cette opération est déjà annulée.');
        }
        if (!in_array($this->statut, ['COMPLETEE', 'EN_ATTENTE_VALIDATION'])) {
            throw new BusinessException('Seules les opérations complétées ou en attente de validation peuvent être annulées.');
        }

        DB::transaction(function () use ($utilisateurId, $motif) {
            // Récupérer la transaction globale
            $transactionGlobale = transaction_globale::where('operation_change_id', $this->id)->first();
            if (!$transactionGlobale) {
                throw new BusinessException('Aucune transaction globale associée à cette opération.');
            }

            $caisse = $this->caisse;
            if (!$caisse) {
                throw new BusinessException('Caisse associée introuvable.');
            }

            // Inverser les mouvements
            if (!$caisse->crediter(
                (float) $this->montant_cible,
                $this->devise_cible_id,
                "Annulation de l'opération de change #{$this->reference}",
                "ANNUL-" . $this->reference,
                $utilisateurId,
                'ANNULATION',
                $this->id
            )) {
                throw new BusinessException("Échec du crédit inverse pour la devise cible.");
            }

            if (!$caisse->debiter(
                (float) $this->montant_source,
                $this->devise_source_id,
                "Annulation de l'opération de change #{$this->reference}",
                "ANNUL-" . $this->reference,
                $utilisateurId,
                'ANNULATION',
                $this->id
            )) {
                throw new BusinessException("Échec du débit inverse pour la devise source.");
            }

            // Annuler la commission
            $commission = Commission::where('transaction_globale_id', $transactionGlobale->id)->first();
            if ($commission) {
                $commission->statut = 'ANNULEE';
                $commission->save();
            }

            // Mettre à jour la transaction globale
            $transactionGlobale->statut = 'ANNULEE';
            $transactionGlobale->save();

            // Mettre à jour l'opération
            $this->statut = 'ANNULEE';
            $this->date_annulation = now();
            $this->annule_par = $utilisateurId;
            $this->motif_annulation = $motif;
            $this->save();
        });
    }

    /**
     * Retourne [tauxAchat, tauxVente, sens] où sens = 'direct' | 'inverse'.
     * Si aucun taux trouvé, retourne [0, 0, null].
     */
    public static function getTauxApplicables(
        int $deviseSourceId,
        int $deviseCibleId,
        ?int $caisseId,
        ?int $succursaleId,
        ?float $tauxAchatForce = null,
        ?float $tauxVenteForce = null
    ): array {
        // Si les deux taux sont forcés, on les renvoie avec sens 'direct' (par convention)
        if ($tauxAchatForce !== null && $tauxVenteForce !== null) {
            return [(float) $tauxAchatForce, (float) $tauxVenteForce, 'direct'];
        }
        

        $tauxAchat = $tauxAchatForce;
        $tauxVente = $tauxVenteForce;
        $sens = null;

        // Priorité 1 – Taux caisse
        if ($caisseId && ($tauxAchat === null || $tauxVente === null)) {
            [$row, $sens] = self::findTauxAvecSens(TauxChangeCaisse::class, $caisseId, $deviseSourceId, $deviseCibleId);
            if ($row) {
                if ($tauxAchat === null) $tauxAchat = (float) $row->taux_achat;
                if ($tauxVente === null) $tauxVente = (float) $row->taux_vente;
            }
        }

        // Priorité 2 – Taux succursale
        if (($tauxAchat === null || $tauxVente === null)) {
            [$row, $sensFromSucc] = self::findTauxAvecSens(Taux_Change_Succursale::class, $succursaleId, $deviseSourceId, $deviseCibleId);
            if ($row) {
                if ($tauxAchat === null) $tauxAchat = (float) $row->taux_achat;
                if ($tauxVente === null) $tauxVente = (float) $row->taux_vente;
                if ($sens === null) $sens = $sensFromSucc;
            }
        }

        // Priorité 3 – Taux global
        if (($tauxAchat === null || $tauxVente === null)) {
            $rowGlobal = TauxChange::where('devise_source', $deviseSourceId)
                ->where('devise_cible', $deviseCibleId)
                ->latest()
                ->first();
            if ($rowGlobal) {
                if ($tauxAchat === null) $tauxAchat = (float) $rowGlobal->taux_achat;
                if ($tauxVente === null) $tauxVente = (float) $rowGlobal->taux_vente;
                if ($sens === null) $sens = 'direct';
            } else {
                // Essai inverse global
                $rowGlobal = TauxChange::where('devise_source', $deviseCibleId)
                    ->where('devise_cible', $deviseSourceId)
                    ->latest()
                    ->first();
                if ($rowGlobal) {
                    if ($tauxAchat === null) $tauxAchat = (float) $rowGlobal->taux_achat;
                    if ($tauxVente === null) $tauxVente = (float) $rowGlobal->taux_vente;
                    if ($sens === null) $sens = 'inverse';
                }
            }
        }

        return [(float)($tauxAchat ?? 0), (float)($tauxVente ?? 0), $sens];
    }

    /**
     * Cherche une paire dans une table donnée.
     * Essaie d'abord (source → cible), puis (cible → source).
     * Retourne [row, sens] ou [null, null].
     */
    private static function findTauxAvecSens(string $model, int $foreignId, int $source, int $cible): array
    {
        $foreignKey = match ($model) {
            TauxChangeCaisse::class       => 'caisse_id',
            Taux_Change_Succursale::class => 'succursale_id',
            default                       => throw new \InvalidArgumentException("Modèle inconnu : $model"),
        };

        // Essai direct
        $row = $model::where($foreignKey, $foreignId)
            ->where('devise_source', $source)
            ->where('devise_cible', $cible)
            ->latest()
            ->first();
        if ($row) {
            return [$row, 'direct'];
        }

        // Essai inverse
        $row = $model::where($foreignKey, $foreignId)
            ->where('devise_source', $cible)
            ->where('devise_cible', $source)
            ->latest()
            ->first();
        if ($row) {
            return [$row, 'inverse'];
        }

        return [null, null];
    }


    /**
     * Extrait les taux achat/vente d'une ligne en tenant compte du sens demandé (source, cible).
     * Si la ligne est dans le sens (source, cible), on prend directement taux_achat/taux_vente.
     * Si elle est dans le sens inverse (cible, source), on inverse les taux :
     *   - achat demandé = 1 / taux_vente de la ligne
     *   - vente demandé = 1 / taux_achat de la ligne
     */
    private static function extraireTaux(object $row, int $source, int $cible): array
    {
        if ($row->devise_source == $source && $row->devise_cible == $cible) {
            return [
                'achat' => (float) $row->taux_achat,
                'vente' => (float) $row->taux_vente,
            ];
        } else {
            // Inverse
            if ((float) $row->taux_achat == 0 || (float) $row->taux_vente == 0) {
                return ['achat' => null, 'vente' => null];
            }
            return [
                'achat' => 1 / (float) $row->taux_vente,
                'vente' => 1 / (float) $row->taux_achat,
            ];
        }
    }

}