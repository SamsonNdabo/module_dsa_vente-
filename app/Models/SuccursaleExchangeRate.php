<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Succursale;
use App\Models\Devise;
use App\Models\Utilisateur;
use App\Models\ExchangeRate;

class SuccursaleExchangeRate extends Model
{
    protected $fillable = [
        'succursale_id', 'devise_source', 'devise_destination',
        'taux_reference', 'spread_achat', 'spread_vente',
        'date_effet', 'actif', 'modifie_par', 'ancien_taux_reference',
        'motif', 'statut', 'valide_par', 'date_validation',
        'notes_validation',
    ];

    protected $casts = [
        'taux_reference' => 'decimal:6',
        'spread_achat' => 'decimal:6',
        'spread_vente' => 'decimal:6',
        'date_effet' => 'datetime',
        'actif' => 'boolean',
    ];

    public function modificateur()
    {
        return $this->belongsTo(Utilisateur::class, 'modifie_par');
    }

    public function validateur()
    {
        return $this->belongsTo(Utilisateur::class, 'valide_par');
    }
    
    public function succursale()
    {
        return $this->belongsTo(Succursale::class);
    }

    public function getTauxAchat(): float
    {
        return (float) $this->taux_reference - (float) $this->spread_achat;
    }

    public function getTauxVente(): float
    {
        return (float) $this->taux_reference + (float) $this->spread_vente;
    }

    public static function vueEnsemble(int $deviseSourceId, int $deviseCibleId): array
    {
        $source = Devise::find($deviseSourceId)?->code;
        $dest   = Devise::find($deviseCibleId)?->code;

        if (!$source || !$dest) {
            return [];
        }

        // Récupérer le taux global actif pour cette paire
        $tauxGlobal = ExchangeRate::where('devise_source', $source)
            ->where('devise_destination', $dest)
            ->where('statut', 'VALIDE')
            ->orderBy('date_effet', 'desc')
            ->first();

        // Si le taux global n'existe pas, essayer l'inverse
        if (!$tauxGlobal) {
            $tauxGlobal = ExchangeRate::where('devise_source', $dest)
                ->where('devise_destination', $source)
                ->where('statut', 'VALIDE')
                ->orderBy('date_effet', 'desc')
                ->first();
            if ($tauxGlobal) {
                // Inverser les codes pour l'affichage
                $source = $dest;
                $dest = $source;
            }
        }

        $succursales = Succursale::where('statut', 'ACTIVE')
            ->select('id', 'nom', 'code', 'ville')
            ->get();

        $rapport = $succursales->map(function ($succursale) use ($source, $dest, $tauxGlobal) {
            // Récupérer le taux personnalisé de la succursale
            $tauxSuccursale = self::where('succursale_id', $succursale->id)
                ->where('devise_source', $source)
                ->where('devise_destination', $dest)
                ->where('statut', 'VALIDE')
                ->orderBy('date_effet', 'desc')
                ->first();

            $tauxEnAttente = self::where('succursale_id', $succursale->id)
                ->where('devise_source', $source)
                ->where('devise_destination', $dest)
                ->where('statut', 'EN_ATTENTE')
                ->orderBy('created_at', 'desc')
                ->first();

            if ($tauxSuccursale) {
                $sourceType = 'succursale';
                $tauxAchat = $tauxSuccursale->getTauxAchat('direct');
                $tauxVente = $tauxSuccursale->getTauxVente('direct');
                $statut = $tauxSuccursale->statut;
                $tauxId = $tauxSuccursale->id;
            } elseif ($tauxGlobal) {
                $sourceType = 'global';
                $tauxAchat = $tauxGlobal->getTauxAchat('direct');
                $tauxVente = $tauxGlobal->getTauxVente('direct');
                $statut = $tauxGlobal->statut;
                $tauxId = null;
            } else {
                $sourceType = 'aucun';
                $tauxAchat = null;
                $tauxVente = null;
                $statut = null;
                $tauxId = null;
            }

            $tauxGlobalAchat = $tauxGlobal ? $tauxGlobal->getTauxAchat('direct') : null;
            $tauxGlobalVente = $tauxGlobal ? $tauxGlobal->getTauxVente('direct') : null;

            return [
                'succursale_id'      => $succursale->id,
                'nom'                => $succursale->nom,
                'code'               => $succursale->code,
                'ville'              => $succursale->ville,
                'taux_id'            => $tauxId,
                'taux_achat'         => $tauxAchat,
                'taux_vente'         => $tauxVente,
                'statut'             => $statut,
                'source'             => $sourceType,
                'ecart_achat_global' => $tauxGlobalAchat && $tauxAchat ? round($tauxAchat - $tauxGlobalAchat, 4) : null,
                'ecart_vente_global' => $tauxGlobalVente && $tauxVente ? round($tauxVente - $tauxGlobalVente, 4) : null,
                'en_attente'         => $tauxEnAttente ? [
                    'id'          => $tauxEnAttente->id,
                    'taux_achat'  => $tauxEnAttente->getTauxAchat('direct'),
                    'taux_vente'  => $tauxEnAttente->getTauxVente('direct'),
                    'modifie_par' => $tauxEnAttente->modifie_par,
                    'motif'       => $tauxEnAttente->motif,
                    'created_at'  => $tauxEnAttente->created_at,
                ] : null,
            ];
        });

        return [
            'taux_achat_global'          => $tauxGlobal ? $tauxGlobal->getTauxAchat('direct') : null,
            'taux_vente_global'          => $tauxGlobal ? $tauxGlobal->getTauxVente('direct') : null,
            'date_taux_global'           => $tauxGlobal ? $tauxGlobal->date_effet : null,
            'total_succursales'          => $succursales->count(),
            'succursales_sur_global'     => $rapport->where('source', 'global')->count(),
            'succursales_sur_succursale' => $rapport->where('source', 'succursale')->count(),
            'succursales_en_attente'     => $rapport->whereNotNull('en_attente')->count(),
            'rapport'                    => $rapport,
        ];
    }

    public static function definirParAdmin(array $data): self
    {
        $source = Devise::find($data['devise_source'])?->code;
        $dest   = Devise::find($data['devise_cible'])?->code;

        if (!$source || !$dest) {
            throw new \Exception('Devise introuvable.');
        }

        // Récupérer l'ancien taux valide pour cette succursale
        $ancienTaux = self::where('succursale_id', $data['succursale_id'])
            ->where('devise_source', $source)
            ->where('devise_destination', $dest)
            ->where('statut', 'VALIDE')
            ->orderBy('date_effet', 'desc')
            ->first();

        // Déterminer le taux de référence à partir du taux d'achat et de vente
        // On prend la moyenne comme approximation
        $tauxAchat = (float) $data['taux_achat'];
        $tauxVente = (float) $data['taux_vente'];
        $tauxReference = round(($tauxAchat + $tauxVente) / 2, 6);

        $spreadAchat = round($tauxReference - $tauxAchat, 6);
        $spreadVente = round($tauxVente - $tauxReference, 6);

        // Désactiver l'ancien taux actif si existant
        self::where('succursale_id', $data['succursale_id'])
            ->where('devise_source', $source)
            ->where('devise_destination', $dest)
            ->where('actif', true)
            ->update(['actif' => false]);

        // Créer le nouveau taux
        $taux = self::create([
            'succursale_id'         => $data['succursale_id'],
            'devise_source'         => $source,
            'devise_destination'    => $dest,
            'taux_reference'        => $tauxReference,
            'spread_achat'          => $spreadAchat,
            'spread_vente'          => $spreadVente,
            'date_effet'            => $data['date_taux'],
            'actif'                 => true,
            'modifie_par'           => $data['modifie_par'],
            'ancien_taux_reference' => $ancienTaux ? $ancienTaux->taux_reference : null,
            'motif'                 => $data['motif'],
            'statut'                => 'VALIDE',
            'valide_par'            => $data['modifie_par'],
            'date_validation'       => now(),
            'notes_validation'      => 'Défini directement par l\'admin',
        ]);

        return $taux;
    }

    public static function reinitialiserToutes(array $paires, int $modifiePar, string $motif): array
    {
        $succursales = Succursale::where('statut', 'ACTIVE')
            ->select('id', 'nom', 'code')
            ->get();

        $rapportPaires = [];

        foreach ($paires as $paire) {
            $source = Devise::find($paire['devise_source'])?->code;
            $dest   = Devise::find($paire['devise_cible'])?->code;

            if (!$source || !$dest) {
                continue; // ou lever une exception
            }

            // Mettre à jour le taux global (exchange_rates)
            $tauxAchat = (float) $paire['taux_achat'];
            $tauxVente = (float) $paire['taux_vente'];
            $tauxReference = round(($tauxAchat + $tauxVente) / 2, 6);
            $spreadAchat = round($tauxReference - $tauxAchat, 6);
            $spreadVente = round($tauxVente - $tauxReference, 6);

            // Vérifier si un taux global existe déjà
            $global = ExchangeRate::where('devise_source', $source)
                ->where('devise_destination', $dest)
                ->first();

            if ($global) {
                $global->taux_reference = $tauxReference;
                $global->spread_achat = $spreadAchat;
                $global->spread_vente = $spreadVente;
                $global->date_effet = now();
                $global->modifie_par = $modifiePar;
                $global->ancien_taux_reference = $global->taux_reference;
                $global->motif = $motif;
                $global->save();
            } else {
                ExchangeRate::create([
                    'devise_source'         => $source,
                    'devise_destination'    => $dest,
                    'taux_reference'        => $tauxReference,
                    'spread_achat'          => $spreadAchat,
                    'spread_vente'          => $spreadVente,
                    'date_effet'            => now(),
                    'actif'                 => true,
                    'modifie_par'           => $modifiePar,
                    'motif'                 => $motif,
                    'statut'                => 'VALIDE',
                    'valide_par'            => $modifiePar,
                    'date_validation'       => now(),
                    'notes_validation'      => 'Réinitialisation admin — ' . $motif,
                ]);
            }

            // Réinitialiser pour chaque succursale
            $detailSuccursales = [];
            foreach ($succursales as $succursale) {
                // Récupérer l'ancien taux
                $ancienTaux = self::where('succursale_id', $succursale->id)
                    ->where('devise_source', $source)
                    ->where('devise_destination', $dest)
                    ->where('statut', 'VALIDE')
                    ->orderBy('date_effet', 'desc')
                    ->first();

                // Créer le nouveau taux
                $taux = self::create([
                    'succursale_id'         => $succursale->id,
                    'devise_source'         => $source,
                    'devise_destination'    => $dest,
                    'taux_reference'        => $tauxReference,
                    'spread_achat'          => $spreadAchat,
                    'spread_vente'          => $spreadVente,
                    'date_effet'            => now(),
                    'actif'                 => true,
                    'modifie_par'           => $modifiePar,
                    'ancien_taux_reference' => $ancienTaux ? $ancienTaux->taux_reference : null,
                    'motif'                 => $motif,
                    'statut'                => 'VALIDE',
                    'valide_par'            => $modifiePar,
                    'date_validation'       => now(),
                    'notes_validation'      => 'Réinitialisation admin — ' . $motif,
                ]);

                // Désactiver les anciens taux actifs pour cette succursale
                self::where('succursale_id', $succursale->id)
                    ->where('devise_source', $source)
                    ->where('devise_destination', $dest)
                    ->where('id', '!=', $taux->id)
                    ->update(['actif' => false]);

                $detailSuccursales[] = [
                    'succursale_id'     => $succursale->id,
                    'nom'               => $succursale->nom,
                    'code'              => $succursale->code,
                    'ancien_taux_achat' => $ancienTaux ? $ancienTaux->getTauxAchat('direct') : null,
                    'ancien_taux_vente' => $ancienTaux ? $ancienTaux->getTauxVente('direct') : null,
                    'nouveau_taux_achat'=> $tauxAchat,
                    'nouveau_taux_vente'=> $tauxVente,
                ];
            }

            $deviseSource = Devise::find($paire['devise_source']);
            $deviseCible  = Devise::find($paire['devise_cible']);

            $rapportPaires[] = [
                'devise_source'       => $deviseSource->code ?? $paire['devise_source'],
                'devise_cible'        => $deviseCible->code ?? $paire['devise_cible'],
                'taux_achat_applique' => $tauxAchat,
                'taux_vente_applique' => $tauxVente,
                'taux_global_change'  => true, // simplifié
                'succursales'         => $detailSuccursales,
            ];
        }

        return [
            'paires'         => $rapportPaires,
            'nb_succursales' => $succursales->count(),
        ];
    }
    
}