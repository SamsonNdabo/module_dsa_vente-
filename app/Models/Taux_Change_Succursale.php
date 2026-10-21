<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use App\Models\TauxChange;

class Taux_Change_Succursale extends Model
{
    protected $table = 'taux_change_succursale';

    protected $fillable = [
        'succursale_id',
        'devise_source',
        'devise_cible',
        'taux_achat',
        'taux_vente',
        'ancien_taux_achat',
        'ancien_taux_vente',
        'date_taux',
        'modifie_par',
        'motif',
        'statut',
        'valide_par',
        'date_validation',
        'notes_validation',
    ];

    protected $casts = [
        'taux_achat'        => 'decimal:4',
        'taux_vente'        => 'decimal:4',
        'ancien_taux_achat' => 'decimal:4',
        'ancien_taux_vente' => 'decimal:4',
        'date_taux'         => 'date',
        'date_validation'   => 'datetime',
    ];

    // ============================================================
    // RELATIONS
    // ============================================================

    public function succursale()
    {
        return $this->belongsTo(Succursale::class, 'succursale_id');
    }

    public function deviseSource()
    {
        return $this->belongsTo(Devise::class, 'devise_source');
    }

    public function deviseCible()
    {
        return $this->belongsTo(Devise::class, 'devise_cible');
    }

    public function modificateur()
    {
        return $this->belongsTo(Utilisateur::class, 'modifie_par');
    }

    public function validateur()
    {
        return $this->belongsTo(Utilisateur::class, 'valide_par');
    }

    // ============================================================
    // SCOPES
    // ============================================================

    public function scopeActif($query)
    {
        return $query->where('statut', 'EN_ATTENTE')
                     ->orWhere('statut', 'VALIDE');
    }

    public function scopeEnAttente($query)
    {
        return $query->where('statut', 'EN_ATTENTE');
    }

    public function scopeValide($query)
    {
        return $query->where('statut', 'VALIDE');
    }

    public function scopeParSuccursale($query, int $succursaleId)
    {
        return $query->where('succursale_id', $succursaleId);
    }

    // ============================================================
    // LOGIQUE MÉTIER
    // ============================================================

    public static function getTauxActif(
        int $succursaleId,
        int $deviseSourceId,
        int $deviseCibleId
    ): ?self {
        $tauxEnAttente = self::where('succursale_id', $succursaleId)
            ->where('devise_source', $deviseSourceId)
            ->where('devise_cible', $deviseCibleId)
            ->where('statut', 'EN_ATTENTE')
            ->orderBy('created_at', 'desc')
            ->first();

        if ($tauxEnAttente) return $tauxEnAttente;

        return self::where('succursale_id', $succursaleId)
            ->where('devise_source', $deviseSourceId)
            ->where('devise_cible', $deviseCibleId)
            ->where('statut', 'VALIDE')
            ->orderBy('created_at', 'desc')
            ->first();
    }

    public static function getDernierTauxValide(
        int $succursaleId,
        int $deviseSourceId,
        int $deviseCibleId
    ): ?self {
        return self::where('succursale_id', $succursaleId)
            ->where('devise_source', $deviseSourceId)
            ->where('devise_cible', $deviseCibleId)
            ->where('statut', 'VALIDE')
            ->orderBy('created_at', 'desc')
            ->first();
    }

    public static function vueEnsemble(int $deviseSource, int $deviseCible): array
    {
        $tauxGlobal = TauxChange::where('devise_source', $deviseSource)
            ->where('devise_cible', $deviseCible)
            ->orderBy('created_at', 'desc')
            ->first();

        $succursales = DB::table('succursale')
            ->where('statut', 'ACTIVE')
            ->select('id', 'nom', 'code', 'ville')
            ->get();

        $rapport = $succursales->map(function ($succursale) use ($deviseSource, $deviseCible, $tauxGlobal) {

            $tauxSuccursale = self::getTauxActif($succursale->id, $deviseSource, $deviseCible);

            $tauxEnAttente = self::where('succursale_id', $succursale->id)
                ->where('devise_source', $deviseSource)
                ->where('devise_cible', $deviseCible)
                ->where('statut', 'EN_ATTENTE')
                ->orderBy('created_at', 'desc')
                ->first();

            if ($tauxSuccursale) {
                $source        = 'succursale';
                $tauxAchat     = $tauxSuccursale->taux_achat;
                $tauxVente     = $tauxSuccursale->taux_vente;
                $statut        = $tauxSuccursale->statut;
                $tauxId        = $tauxSuccursale->id;
            } elseif ($tauxGlobal) {
                $source        = 'global';
                $tauxAchat     = $tauxGlobal->taux_achat;
                $tauxVente     = $tauxGlobal->taux_vente;
                $statut        = 'VALIDE';
                $tauxId        = null;
            } else {
                $source        = 'aucun';
                $tauxAchat     = null;
                $tauxVente     = null;
                $statut        = null;
                $tauxId        = null;
            }

            return [
                'succursale_id'      => $succursale->id,
                'nom'                => $succursale->nom,
                'code'               => $succursale->code,
                'ville'              => $succursale->ville,
                'taux_id'            => $tauxId,
                'taux_achat'         => $tauxAchat,
                'taux_vente'         => $tauxVente,
                'statut'             => $statut,
                'source'             => $source,
                'ecart_achat_global' => $tauxGlobal && $tauxAchat
                                        ? round((float) $tauxAchat - (float) $tauxGlobal->taux_achat, 4)
                                        : null,
                'ecart_vente_global' => $tauxGlobal && $tauxVente
                                        ? round((float) $tauxVente - (float) $tauxGlobal->taux_vente, 4)
                                        : null,
                'en_attente'         => $tauxEnAttente ? [
                    'id'            => $tauxEnAttente->id,
                    'taux_achat'    => $tauxEnAttente->taux_achat,
                    'taux_vente'    => $tauxEnAttente->taux_vente,
                    'modifie_par'   => $tauxEnAttente->modifie_par,
                    'motif'         => $tauxEnAttente->motif,
                    'created_at'    => $tauxEnAttente->created_at,
                ] : null,
            ];
        });

        return [
            'taux_achat_global'          => $tauxGlobal?->taux_achat ?? null,
            'taux_vente_global'          => $tauxGlobal?->taux_vente ?? null,
            'date_taux_global'           => $tauxGlobal?->date_taux ?? null,
            'total_succursales'          => $succursales->count(),
            'succursales_sur_global'     => $rapport->where('source', 'global')->count(),
            'succursales_sur_succursale' => $rapport->where('source', 'succursale')->count(),
            'succursales_en_attente'     => $rapport->whereNotNull('en_attente')->count(),
            'rapport'                    => $rapport,
        ];
    }

    public static function definirParAdmin(array $data): self
    {
        $ancienTaux = self::getTauxActif(
            $data['succursale_id'],
            $data['devise_source'],
            $data['devise_cible']
        );

        return self::create([
            'succursale_id'     => $data['succursale_id'],
            'devise_source'     => $data['devise_source'],
            'devise_cible'      => $data['devise_cible'],
            'taux_achat'        => $data['taux_achat'],
            'taux_vente'        => $data['taux_vente'],
            'ancien_taux_achat' => $ancienTaux?->taux_achat ?? null,
            'ancien_taux_vente' => $ancienTaux?->taux_vente ?? null,
            'date_taux'         => $data['date_taux'],
            'modifie_par'       => $data['modifie_par'],
            'motif'             => $data['motif'],
            'statut'            => 'VALIDE',
            'valide_par'        => $data['modifie_par'],
            'date_validation'   => now(),
            'notes_validation'  => 'Défini directement par l\'admin',
        ]);
    }

    public static function reinitialiserToutes(array $paires, int $modifiePar, string $motif): array
    {
        $succursales = DB::table('succursale')
            ->where('statut', 'ACTIVE')
            ->select('id', 'nom', 'code')
            ->get();

        $rapportPaires = [];

        foreach ($paires as $paire) {

            $tauxGlobalActuel = TauxChange::where('devise_source', $paire['devise_source'])
                ->where('devise_cible', $paire['devise_cible'])
                ->orderBy('created_at', 'desc')
                ->first();

            $tauxChange = (float) $paire['taux_achat'] !== (float) ($tauxGlobalActuel?->taux_achat ?? -1)
                       || (float) $paire['taux_vente']  !== (float) ($tauxGlobalActuel?->taux_vente  ?? -1);

            // Mettre à jour le taux global si changé
            if ($tauxChange || !$tauxGlobalActuel) {
                TauxChange::create([
                    'devise_source'     => $paire['devise_source'],
                    'devise_cible'      => $paire['devise_cible'],
                    'taux_achat'        => $paire['taux_achat'],
                    'taux_vente'        => $paire['taux_vente'],
                    'ancien_taux_achat' => $tauxGlobalActuel?->taux_achat ?? null,
                    'ancien_taux_vente' => $tauxGlobalActuel?->taux_vente ?? null,
                    'date_taux'         => now()->format('Y-m-d'),
                    'modifie_par'       => $modifiePar,
                    'motif'             => $motif,
                ]);
            }

            $detailSuccursales = [];

            foreach ($succursales as $succursale) {

                $ancienTaux = self::getTauxActif(
                    $succursale->id,
                    $paire['devise_source'],
                    $paire['devise_cible']
                );

                self::create([
                    'succursale_id'     => $succursale->id,
                    'devise_source'     => $paire['devise_source'],
                    'devise_cible'      => $paire['devise_cible'],
                    'taux_achat'        => $paire['taux_achat'],
                    'taux_vente'        => $paire['taux_vente'],
                    'ancien_taux_achat' => $ancienTaux?->taux_achat ?? null,
                    'ancien_taux_vente' => $ancienTaux?->taux_vente ?? null,
                    'date_taux'         => now()->format('Y-m-d'),
                    'modifie_par'       => $modifiePar,
                    'motif'             => $motif,
                    'statut'            => 'VALIDE',
                    'valide_par'        => $modifiePar,
                    'date_validation'   => now(),
                    'notes_validation'  => 'Réinitialisation admin — ' . $motif,
                ]);

                $detailSuccursales[] = [
                    'succursale_id'     => $succursale->id,
                    'nom'               => $succursale->nom,
                    'code'              => $succursale->code,
                    'ancien_taux_achat' => $ancienTaux?->taux_achat ?? null,
                    'ancien_taux_vente' => $ancienTaux?->taux_vente ?? null,
                    'nouveau_taux_achat'=> $paire['taux_achat'],
                    'nouveau_taux_vente'=> $paire['taux_vente'],
                ];
            }

            $deviseSource = DB::table('devise')->find($paire['devise_source']);
            $deviseCible  = DB::table('devise')->find($paire['devise_cible']);

            $rapportPaires[] = [
                'devise_source'       => $deviseSource->code ?? $paire['devise_source'],
                'devise_cible'        => $deviseCible->code  ?? $paire['devise_cible'],
                'taux_achat_applique' => $paire['taux_achat'],
                'taux_vente_applique' => $paire['taux_vente'],
                'taux_global_change'  => $tauxChange,
                'succursales'         => $detailSuccursales,
            ];
        }

        return [
            'paires'         => $rapportPaires,
            'nb_succursales' => $succursales->count(),
        ];
    }

    public function valider(int $validateurId, ?string $notes = null): bool
    {
        $this->statut           = 'VALIDE';
        $this->valide_par       = $validateurId;
        $this->date_validation  = now()->format('Y-m-d H:i:s');
        $this->notes_validation = $notes;
        return $this->save();
    }

    public function rejeter(int $validateurId, string $notes): bool
    {
        $this->statut           = 'REJETE';
        $this->valide_par       = $validateurId;
        $this->date_validation  = now()->format('Y-m-d H:i:s');
        $this->notes_validation = $notes;
        return $this->save();
    }
}