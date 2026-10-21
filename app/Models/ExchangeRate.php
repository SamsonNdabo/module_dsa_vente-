<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class ExchangeRate extends Model
{
    protected $fillable = [
        'devise_source',
        'devise_destination',
        'taux_reference',
        'spread_achat',
        'spread_vente',
        'date_effet',
        'actif',
        'modifie_par',
        'ancien_taux_reference',
        'motif',
        'statut',
        'valide_par',
        'date_validation',
        'notes_validation',
    ];

    protected $casts = [
        'taux_reference' => 'decimal:6',
        'spread_achat' => 'decimal:6',
        'spread_vente' => 'decimal:6',
        'date_effet' => 'datetime',
        'actif' => 'boolean',
    ];

    // RELATIONS

    public function modificateur()
    {
        return $this->belongsTo(Utilisateur::class, 'modifie_par');
    }

    public function validateur()
    {
        return $this->belongsTo(Utilisateur::class, 'valide_par');
    }

    /**
     * Récupère le taux actif pour une paire de devises.
     */

public static function getTauxActif(
    string $source,
    string $dest,
    ?int $caisseId = null,
    ?int $succursaleId = null
): ?array {
    Log::info('=== getTauxActif ===', [
        'source' => $source,
        'dest' => $dest,
        'caisseId' => $caisseId,
        'succursaleId' => $succursaleId,
    ]);

    // 1. Taux caisse (si caisseId fourni)
    if ($caisseId) {
        Log::info('Recherche taux caisse', ['caisseId' => $caisseId]);
        // D'abord chercher VALIDE
        $taux = CaisseExchangeRate::where('caisse_id', $caisseId)
            ->where('devise_source', $source)
            ->where('devise_destination', $dest)
            ->where('statut', 'VALIDE')
            ->where('actif', true)
            ->orderBy('date_effet', 'desc')
            ->first();
        if ($taux) {
            Log::info('Taux caisse VALIDE trouvé (direct)');
            return ['rate' => $taux, 'sens' => 'direct', 'niveau' => 'caisse'];
        }
        // Essai inverse VALIDE
        $taux = CaisseExchangeRate::where('caisse_id', $caisseId)
            ->where('devise_source', $dest)
            ->where('devise_destination', $source)
            ->where('statut', 'VALIDE')
            ->where('actif', true)
            ->orderBy('date_effet', 'desc')
            ->first();
        if ($taux) {
            Log::info('Taux caisse VALIDE trouvé (inverse)');
            return ['rate' => $taux, 'sens' => 'inverse', 'niveau' => 'caisse'];
        }
/*
        // Si aucun VALIDE, chercher EN_ATTENTE (si on veut les utiliser immédiatement)
        $taux = CaisseExchangeRate::where('caisse_id', $caisseId)
            ->where('devise_source', $source)
            ->where('devise_destination', $dest)
            ->where('statut', 'EN_ATTENTE')
            ->where('actif', true)
            ->orderBy('date_effet', 'desc')
            ->first();
        if ($taux) {
            Log::info('Taux caisse EN_ATTENTE trouvé (direct)');
            return ['rate' => $taux, 'sens' => 'direct', 'niveau' => 'caisse'];
        }
        $taux = CaisseExchangeRate::where('caisse_id', $caisseId)
            ->where('devise_source', $dest)
            ->where('devise_destination', $source)
            ->where('statut', 'EN_ATTENTE')
            ->where('actif', true)
            ->orderBy('date_effet', 'desc')
            ->first();
        if ($taux) {
            Log::info('Taux caisse EN_ATTENTE trouvé (inverse)');
            return ['rate' => $taux, 'sens' => 'inverse', 'niveau' => 'caisse'];
        }
*/
        Log::info('Aucun taux caisse trouvé');
    }

    // 2. Taux succursale (si succursaleId fourni)
    if ($succursaleId) {
        Log::info('Recherche taux succursale', ['succursaleId' => $succursaleId]);
        // D'abord VALIDE
        $taux = SuccursaleExchangeRate::where('succursale_id', $succursaleId)
            ->where('devise_source', $source)
            ->where('devise_destination', $dest)
            ->where('statut', 'VALIDE')
            ->where('actif', true)
            ->orderBy('date_effet', 'desc')
            ->first();
        if ($taux) {
            Log::info('Taux succursale VALIDE trouvé (direct)');
            return ['rate' => $taux, 'sens' => 'direct', 'niveau' => 'succursale'];
        }
        $taux = SuccursaleExchangeRate::where('succursale_id', $succursaleId)
            ->where('devise_source', $dest)
            ->where('devise_destination', $source)
            ->where('statut', 'VALIDE')
            ->where('actif', true)
            ->orderBy('date_effet', 'desc')
            ->first();
        if ($taux) {
            Log::info('Taux succursale VALIDE trouvé (inverse)');
            return ['rate' => $taux, 'sens' => 'inverse', 'niveau' => 'succursale'];
        }
/*
        // Sinon EN_ATTENTE
        $taux = SuccursaleExchangeRate::where('succursale_id', $succursaleId)
            ->where('devise_source', $source)
            ->where('devise_destination', $dest)
            ->where('statut', 'EN_ATTENTE')
            ->where('actif', true)
            ->orderBy('date_effet', 'desc')
            ->first();
        if ($taux) {
            Log::info('Taux succursale EN_ATTENTE trouvé (direct)');
            return ['rate' => $taux, 'sens' => 'direct', 'niveau' => 'succursale'];
        }
        $taux = SuccursaleExchangeRate::where('succursale_id', $succursaleId)
            ->where('devise_source', $dest)
            ->where('devise_destination', $source)
            ->where('statut', 'EN_ATTENTE')
            ->where('actif', true)
            ->orderBy('date_effet', 'desc')
            ->first();
        if ($taux) {
            Log::info('Taux succursale EN_ATTENTE trouvé (inverse)');
            return ['rate' => $taux, 'sens' => 'inverse', 'niveau' => 'succursale'];
        }
*/
        Log::info('Aucun taux succursale trouvé');
    }

    // 3. Taux global
    Log::info('Recherche taux global');
    $taux = self::where('devise_source', $source)
        ->where('devise_destination', $dest)
        ->where('actif', true)
        ->orderBy('date_effet', 'desc')
        ->first();
    if ($taux) {
        Log::info('Taux global trouvé (direct)');
        return ['rate' => $taux, 'sens' => 'direct', 'niveau' => 'global'];
    }
    $taux = self::where('devise_source', $dest)
        ->where('devise_destination', $source)
        ->where('actif', true)
        ->orderBy('date_effet', 'desc')
        ->first();
    if ($taux) {
        Log::info('Taux global trouvé (inverse)');
        return ['rate' => $taux, 'sens' => 'inverse', 'niveau' => 'global'];
    }

    Log::info('Aucun taux trouvé');
    return null;
}

    /**
     * Calcul du taux d'achat (client vend, shop achète).
     */
    public function getTauxAchat(string $sens = 'direct'): float
    {
        if ($sens === 'direct') {
            // Client vend la devise source, le shop achète
            return (float) $this->taux_reference - (float) $this->spread_achat;
        } else {
            // Pour l'inverse (ex: CDF -> USD), le "taux achat" de CDF est l'inverse du "taux vente" de USD
            return 1 / ((float) $this->taux_reference + (float) $this->spread_vente);
        }
    }

    /**
     * Calcul du taux de vente (client achète, shop vend).
     */
    public function getTauxVente(string $sens = 'direct'): float
    {
        if ($sens === 'inverse') {
            // Client achète la devise destination, le shop vend
            return (float) $this->taux_reference + (float) $this->spread_vente;
        } else {
            // Pour l'inverse (ex: CDF -> USD), le "taux vente" de CDF est l'inverse du "taux achat" de USD
            return 1 / ((float) $this->taux_reference - (float) $this->spread_achat);
        }
    }
    
    /**
     * Convertit un montant d'une devise donnée en USD.
     * Utilise la cascade de taux via ExchangeRate, en passant par CDF pour fiabilité.
     *
     * @param float $montant
     * @param int $deviseId
     * @return float|null
     */
    public static function convertirEnUsd(float $montant, int $deviseId, ?int $caisseId, int $succursaleId): ?float
    {
        $usdId = Devise::where('code', 'USD')->value('id');
        $cdfId = Devise::where('code', 'CDF')->value('id');
    
        if (!$usdId || !$cdfId) {
            Log::error('Devise USD ou CDF introuvable.');
            return null;
        }
    
        if ($deviseId == $usdId) {
            return $montant;
        }
    
        $sourceCode = Devise::find($deviseId)?->code;
        if (!$sourceCode) {
            Log::error("Devise source introuvable (ID: {$deviseId}).");
            return null;
        }
    
        // ---- Étape 1 : Convertir la devise source en CDF ----
        $montantCdf = null;
        if ($sourceCode === 'CDF') {
            $montantCdf = $montant;
        } else {
            $tauxData = ExchangeRate::getTauxActif($sourceCode, 'CDF', $caisseId, $succursaleId);
            
            if (!$tauxData) {
                Log::error("Aucun taux de change trouvé pour la paire {$sourceCode}/CDF.");
                return null;
            }
    
            $rate = $tauxData['rate'];
            $sens = $tauxData['sens'];
            $tauxAchat = $rate->getTauxAchat($sens);
            $tauxVente = $rate->getTauxVente($sens);

    
            // Logique inspirée de la fonction convert
            if ($sens === 'direct') {
                // source -> CDF : on multiplie par le taux d'achat
                $montantCdf = $montant / $tauxAchat;
            } else {
                // inverse (CDF -> source) : on divise par le taux de vente
                $montantCdf = $montant * $tauxVente;
            }
        }
    
        if ($montantCdf === null || $montantCdf <= 0) {
            Log::error("Échec de la conversion en CDF pour la devise {$sourceCode}.");
            return null;
        }
    
        // ---- Étape 2 : Convertir CDF en USD ----
        $tauxDataUsd = ExchangeRate::getTauxActif($sourceCode, 'USD', $caisseId, $succursaleId);
        if (!$tauxDataUsd) {
            Log::error("Aucun taux de change trouvé pour la paire USD/CDF.");
            return null;
        }
    
        $rateUsd = $tauxDataUsd['rate'];
        $sensUsd = $tauxDataUsd['sens'];
        $tauxAchatUsd = $rateUsd->getTauxAchat($sensUsd);
        $tauxVenteUsd = $rateUsd->getTauxVente($sensUsd);

                
        if ($sensUsd === 'direct') {
            Log::error("Une conversion de ce type ne se fait que si le taux est inverse.");
            return null;
        } else {
            // inverse (CDF -> USD) : on multiplie par le taux d'achat
            $montantUsd = $montantCdf / $tauxVenteUsd;
            Log::info($montantCdf. '==V=='. $tauxVenteUsd);
        }
    
        return round($montantUsd, 4);
    }
    
}