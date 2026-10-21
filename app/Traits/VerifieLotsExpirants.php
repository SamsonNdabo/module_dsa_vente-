<?php
// app/Traits/VerifieLotsExpirants.php

namespace App\Traits;

use App\Models\LotStock;
use App\Jobs\CheckExpiringLotsJob;
use Illuminate\Support\Facades\Log;

trait VerifieLotsExpirants
{
    /**
     * Vérifier les lots expirants (à partir de la table lots_stock)
     * 
     * @param \App\Models\Utilisateur $user
     * @return array
     */
    protected function verifierLotsExpirants($user)
    {
        try {
            $alertes = [
                'total' => 0,
                'critique' => 0,
                'proche' => 0,
                'expire' => 0,
                'details' => [],
                'succursales_concernees' => []
            ];

            // Vérifier si l'utilisateur a accès au stock
            $aAccesStock = $user->hasPermission('manage-stock-agence') || 
                           $user->hasPermission('view-stock-agence') ||
                           $user->role?->code === 'SUPER_ADMIN' ||
                           $user->role?->code === 'CHEF_AGENCE' ||
                           $user->role?->code === 'GESTIONNAIRE_STOCK';

            if (!$aAccesStock) {
                return $alertes;
            }

            // Déterminer les succursales accessibles
            $succursalesIds = [];
            if ($user->hasPermission('manage-stock-agence') || $user->role?->code === 'SUPER_ADMIN') {
                $succursalesIds = \App\Models\Succursale::pluck('id')->toArray();
            } elseif ($user->succursale_id) {
                $succursalesIds = [$user->succursale_id];
            }

            if (empty($succursalesIds)) {
                return $alertes;
            }

            // ============================================================
            // 1. LOTS PROCHES (30 jours) - via table lots_stock
            // ============================================================
            $lotsProches = LotStock::where('statut', 'ACTIF')
                ->where('quantite_restante', '>', 0)
                ->whereNotNull('date_expiration')
                ->where('date_expiration', '<=', now()->addDays(30))
                ->where('date_expiration', '>', now())
                ->whereHas('stockSuccursale', function($q) use ($succursalesIds) {
                    $q->whereIn('succursale_id', $succursalesIds);
                })
                ->with(['produit', 'stockSuccursale.succursale'])
                ->get();

            // ============================================================
            // 2. LOTS CRITIQUES (7 jours) - Sous-ensemble
            // ============================================================
            $lotsCritiques = $lotsProches->filter(function($lot) {
                $jours = now()->startOfDay()->diffInDays($lot->date_expiration, false);
                return $jours <= 7 && $jours > 0;
            });

            // ============================================================
            // 3. LOTS EXPIRÉS (<= aujourd'hui)
            // ============================================================
            $lotsExpires = LotStock::where('statut', 'ACTIF') // ou peu importe, on les détecte
                ->where('quantite_restante', '>', 0)
                ->whereNotNull('date_expiration')
                ->where('date_expiration', '<=', now())
                ->whereHas('stockSuccursale', function($q) use ($succursalesIds) {
                    $q->whereIn('succursale_id', $succursalesIds);
                })
                ->with(['produit', 'stockSuccursale.succursale'])
                ->get();

            // ============================================================
            // 4. COLLECTER LES SUCCURSALES CONCERNÉES
            // ============================================================
            $succursalesConcernees = collect();
            
            foreach ($lotsProches as $lot) {
                $succursalesConcernees->push([
                    'id' => $lot->stockSuccursale->succursale->id,
                    'nom' => $lot->stockSuccursale->succursale->nom
                ]);
            }
            
            foreach ($lotsExpires as $lot) {
                $succursalesConcernees->push([
                    'id' => $lot->stockSuccursale->succursale->id,
                    'nom' => $lot->stockSuccursale->succursale->nom
                ]);
            }
            
            $succursalesConcernees = $succursalesConcernees->unique('id')->values();

            // ============================================================
            // 5. CONSTRUIRE LE TABLEAU DES ALERTES
            // ============================================================
            $alertes = [
                'total' => $lotsProches->count() + $lotsExpires->count(),
                'critique' => $lotsCritiques->count(),
                'proche' => $lotsProches->count() - $lotsCritiques->count(),
                'expire' => $lotsExpires->count(),
                'succursales_concernees' => $succursalesConcernees->toArray(),
                'details' => [
                    'proches' => $lotsProches->take(5)->map(function($lot) {
                        $jours = now()->startOfDay()->diffInDays($lot->date_expiration, false);
                        return [
                            'lot_id' => $lot->id,
                            'numero_lot' => $lot->numero_lot,
                            'produit_id' => $lot->produit_id,
                            'produit' => $lot->produit->nom,
                            'succursale_id' => $lot->stockSuccursale->succursale_id,
                            'succursale' => $lot->stockSuccursale->succursale->nom,
                            'quantite' => $lot->quantite_restante,
                            'date_expiration' => $lot->date_expiration->format('d/m/Y'),
                            'jours_restants' => $jours,
                            'est_critique' => $jours <= 7
                        ];
                    }),
                    'expires' => $lotsExpires->take(5)->map(function($lot) {
                        return [
                            'lot_id' => $lot->id,
                            'numero_lot' => $lot->numero_lot,
                            'produit_id' => $lot->produit_id,
                            'produit' => $lot->produit->nom,
                            'succursale_id' => $lot->stockSuccursale->succursale_id,
                            'succursale' => $lot->stockSuccursale->succursale->nom,
                            'quantite' => $lot->quantite_restante,
                            'date_expiration' => $lot->date_expiration->format('d/m/Y'),
                            'jours_expire' => now()->startOfDay()->diffInDays($lot->date_expiration, false) * -1
                        ];
                    })
                ]
            ];

            // ============================================================
            // 6. STATISTIQUES SUPPLÉMENTAIRES
            // ============================================================
            $alertes['stats'] = [
                'quantite_totale_proche' => $lotsProches->sum('quantite_restante'),
                'quantite_totale_critique' => $lotsCritiques->sum('quantite_restante'),
                'quantite_totale_expiree' => $lotsExpires->sum('quantite_restante'),
                'valeur_totale_expiree' => $lotsExpires->sum(function($lot) {
                    return ($lot->cout_unitaire ?? 0) * $lot->quantite_restante;
                })
            ];

            // ============================================================
            // 7. DISPATCH DES JOBS POUR ALERTES CRITIQUES
            // ============================================================
            if ($lotsCritiques->count() > 0 || $lotsExpires->count() > 0) {
                try {
                    CheckExpiringLotsJob::dispatch(7, null, true)->onQueue('high')->delay(now()->addSeconds(5));
                } catch (\Exception $e) {
                    Log::warning('Impossible de dispatcher le job CheckExpiringLotsJob', [
                        'error' => $e->getMessage()
                    ]);
                }
            }

            return $alertes;

        } catch (\Exception $e) {
            Log::error('Erreur vérification lots expirants', [
                'error' => $e->getMessage(),
                'user_id' => $user->id ?? null,
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'total' => 0, 
                'critique' => 0, 
                'proche' => 0, 
                'expire' => 0,
                'details' => [],
                'succursales_concernees' => [],
                'stats' => [
                    'quantite_totale_proche' => 0,
                    'quantite_totale_critique' => 0,
                    'quantite_totale_expiree' => 0,
                    'valeur_totale_expiree' => 0
                ],
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Vérifier UNIQUEMENT les lots critiques (7 jours)
     */
    protected function verifierLotsCritiques($user)
    {
        $result = $this->verifierLotsExpirants($user);
        
        return [
            'total' => $result['critique'],
            'lots' => $result['details']['proches']->filter(function($lot) {
                return isset($lot['est_critique']) && $lot['est_critique'] === true;
            })->values(),
            'quantite_totale' => $result['stats']['quantite_totale_critique'] ?? 0
        ];
    }

    /**
     * Vérifier UNIQUEMENT les lots expirés
     */
    protected function verifierLotsExpires($user)
    {
        $result = $this->verifierLotsExpirants($user);
        
        return [
            'total' => $result['expire'],
            'lots' => $result['details']['expires'],
            'quantite_totale' => $result['stats']['quantite_totale_expiree'] ?? 0,
            'valeur_totale' => $result['stats']['valeur_totale_expiree'] ?? 0
        ];
    }

    /**
     * Vérifier si des lots critiques existent
     */
    protected function aDesLotsCritiques($user): bool
    {
        $result = $this->verifierLotsExpirants($user);
        return $result['critique'] > 0;
    }

    /**
     * Vérifier si des lots expirés existent
     */
    protected function aDesLotsExpires($user): bool
    {
        $result = $this->verifierLotsExpirants($user);
        return $result['expire'] > 0;
    }
}