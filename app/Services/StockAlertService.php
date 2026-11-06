<?php
// app/Services/StockAlertService.php

namespace App\Services;

use App\Models\Utilisateur;
use App\Models\LotStock;
use App\Models\Succursale;
use App\Models\MouvementStock;
use App\Traits\Notifiable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class StockAlertService
{
    use Notifiable;

    /**
     * Vérifie les lots expirants et retourne les alertes formatées
     *  Utilise la table lots_stock
     *  Traite automatiquement les lots expirés (change le statut UNIQUEMENT)
     */
    public function verifierLotsExpirants(Utilisateur $user): array
    {
        try {
            $alertes = $this->getEmptyAlertStructure();

            if (!$this->userHasStockAccess($user)) {
                return $alertes;
            }

            $succursalesIds = $this->getAuthorizedSuccursalesIds($user);

            if (empty($succursalesIds)) {
                return $alertes;
            }

            //  Traiter d'abord les lots expirés (changer leur statut UNIQUEMENT)
            $this->traiterLotsExpires($succursalesIds);

            //  Récupérer les lots proches et expirés
            $lotsData = $this->fetchLotData($succursalesIds);
            $lotsProches = $lotsData['proches'];
            $lotsExpires = $lotsData['expires'];
            $lotsCritiques = $lotsData['critiques'];

            $succursalesConcernees = $this->buildSuccursalesConcernees($lotsProches, $lotsExpires);

            $alertes = [
                'total' => $lotsProches->count() + $lotsExpires->count(),
                'critique' => $lotsCritiques->count(),
                'proche' => $lotsProches->count() - $lotsCritiques->count(),
                'expire' => $lotsExpires->count(),
                'details' => [
                    'proches' => $this->formatLotsProches($lotsProches->take(5)),
                    'expires' => $this->formatLotsExpires($lotsExpires->take(5))
                ],
                'succursales_concernees' => array_values($succursalesConcernees)
            ];

            // Envoyer les notifications si nécessaire
            if ($lotsCritiques->count() > 0 || $lotsExpires->count() > 0) {
                $this->envoyerNotificationsExpiration($lotsCritiques, $lotsExpires, $user);

                Log::info('Alertes expiration envoyées immédiatement après connexion', [
                    'user_id' => $user->id,
                    'lots_critiques' => $lotsCritiques->count(),
                    'lots_expires' => $lotsExpires->count()
                ]);
            }

            return $alertes;

        } catch (\Exception $e) {
            Log::error('Erreur lors de la vérification des lots expirants', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return $this->getErrorAlertStructure();
        }
    }

    /**
     *  Traiter les lots expirés (changer le statut UNIQUEMENT)
     * 
     * NE crée PAS de mouvement de sortie
     * NE diminue PAS la quantité du stock
     * Les produits physiques sont toujours là, ils ne sont juste plus vendables
     */
    public function traiterLotsExpires(array $succursalesIds = null): array
    {
        $result = [
            'success' => true,
            'total_trouves' => 0,
            'total_traites' => 0,
            'erreurs' => [],
            'details' => []
        ];

        try {
            $query = LotStock::where('statut', 'ACTIF')
                ->whereNotNull('date_expiration')
                ->where('date_expiration', '<=', now())
                ->where('quantite_restante', '>', 0);

            if ($succursalesIds !== null && !empty($succursalesIds)) {
                $query->whereHas('stockSuccursale', function ($q) use ($succursalesIds) {
                    $q->whereIn('succursale_id', $succursalesIds);
                });
            }

            $lotsExpires = $query->with(['produit', 'stockSuccursale.succursale'])->get();

            $result['total_trouves'] = $lotsExpires->count();

            foreach ($lotsExpires as $lot) {
                try {
                    //  UNIQUEMENT changer le statut du lot
                    // PAS de mouvement de sortie
                    // PAS de diminution du stock
                    // PAS de mouvement de perte
                    $lot->statut = 'EXPIRE';
                    $lot->motif_statut = 'Expiration automatique le ' . now()->format('d/m/Y H:i');
                    $lot->save();

                    $result['total_traites']++;
                    $result['details'][] = [
                        'lot_id' => $lot->id,
                        'numero_lot' => $lot->numero_lot,
                        'produit' => $lot->produit?->nom,
                        'quantite_restante' => $lot->quantite_restante,
                        'date_expiration' => $lot->date_expiration?->format('d/m/Y'),
                        'succursale' => $lot->stockSuccursale?->succursale?->nom
                    ];

                    Log::info('Lot marqué comme EXPIRE (automatique)', [
                        'lot_id' => $lot->id,
                        'numero_lot' => $lot->numero_lot,
                        'produit_id' => $lot->produit_id,
                        'quantite_restante' => $lot->quantite_restante
                    ]);

                } catch (\Exception $e) {
                    $result['erreurs'][] = [
                        'lot_id' => $lot->id,
                        'message' => $e->getMessage()
                    ];
                    Log::error('Erreur lors du marquage du lot expiré', [
                        'lot_id' => $lot->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            if ($result['total_trouves'] > 0) {
                Log::info('Marquage des lots expirés terminé', [
                    'total_trouves' => $result['total_trouves'],
                    'total_traites' => $result['total_traites'],
                    'erreurs' => count($result['erreurs'])
                ]);
            }

        } catch (\Exception $e) {
            $result['success'] = false;
            $result['erreurs'][] = $e->getMessage();
            Log::error('Erreur lors du marquage des lots expirés', [
                'error' => $e->getMessage()
            ]);
        }

        return $result;
    }

    /**
     * Récupère tous les lots proches et expirés depuis la table lots_stock
     *  Utilise LotStock au lieu de MouvementStock
     */
    private function fetchLotData(array $succursalesIds): array
    {
        // Lots proches (expirant dans les 30 jours) - UNIQUEMENT les lots ACTIFS
        $lotsProches = LotStock::where('statut', 'ACTIF')
            ->where('quantite_restante', '>', 0)
            ->whereNotNull('date_expiration')
            ->where('date_expiration', '<=', now()->addDays(30))
            ->where('date_expiration', '>', now())
            ->whereHas('stockSuccursale', function ($q) use ($succursalesIds) {
                $q->whereIn('succursale_id', $succursalesIds);
            })
            ->with(['produit', 'stockSuccursale.succursale'])
            ->get();

        // Lots critiques (expirant dans les 7 jours)
        $lotsCritiques = $lotsProches->filter(function ($lot) {
            $jours = now()->startOfDay()->diffInDays($lot->date_expiration, false);
            return $jours <= 7 && $jours > 0;
        });

        // Lots expirés (date_expiration <= aujourd'hui) - UNIQUEMENT les lots ACTIFS
        $lotsExpires = LotStock::where('statut', 'ACTIF')
            ->where('quantite_restante', '>', 0)
            ->whereNotNull('date_expiration')
            ->where('date_expiration', '<=', now())
            ->whereHas('stockSuccursale', function ($q) use ($succursalesIds) {
                $q->whereIn('succursale_id', $succursalesIds);
            })
            ->with(['produit', 'stockSuccursale.succursale'])
            ->get();

        return [
            'proches' => $lotsProches,
            'critiques' => $lotsCritiques,
            'expires' => $lotsExpires
        ];
    }

    /**
     * Construit le tableau des succursales concernées
     */
    private function buildSuccursalesConcernees($lotsProches, $lotsExpires): array
    {
        $succursalesConcernees = [];

        foreach ($lotsProches as $lot) {
            $succursaleId = $lot->stockSuccursale->succursale_id;
            $succursaleNom = $lot->stockSuccursale->succursale->nom;

            if (!isset($succursalesConcernees[$succursaleId])) {
                $succursalesConcernees[$succursaleId] = [
                    'nom' => $succursaleNom,
                    'id' => $succursaleId,
                    'lots_proches' => 0,
                    'lots_critiques' => 0,
                    'lots_expires' => 0
                ];
            }
            $succursalesConcernees[$succursaleId]['lots_proches']++;
        }

        // Comptage des lots critiques
        foreach ($lotsProches as $lot) {
            $jours = now()->startOfDay()->diffInDays($lot->date_expiration, false);
            if ($jours <= 7 && $jours > 0) {
                $succursaleId = $lot->stockSuccursale->succursale_id;
                if (isset($succursalesConcernees[$succursaleId])) {
                    $succursalesConcernees[$succursaleId]['lots_critiques']++;
                }
            }
        }

        // Comptage des lots expirés
        foreach ($lotsExpires as $lot) {
            $succursaleId = $lot->stockSuccursale->succursale_id;
            if (!isset($succursalesConcernees[$succursaleId])) {
                $succursalesConcernees[$succursaleId] = [
                    'nom' => $lot->stockSuccursale->succursale->nom,
                    'id' => $succursaleId,
                    'lots_proches' => 0,
                    'lots_critiques' => 0,
                    'lots_expires' => 0
                ];
            }
            $succursalesConcernees[$succursaleId]['lots_expires']++;
        }

        return $succursalesConcernees;
    }

    /**
     * Formate les lots proches pour l'affichage
     */
    private function formatLotsProches($lots): array
    {
        return $lots->map(function ($lot) {
            $jours = now()->startOfDay()->diffInDays($lot->date_expiration, false);
            return [
                'lot_id' => $lot->id,
                'numero_lot' => $lot->numero_lot,
                'produit' => $lot->produit->nom,
                'succursale' => $lot->stockSuccursale->succursale->nom,
                'quantite' => $lot->quantite_restante,
                'date_expiration' => $lot->date_expiration->format('d/m/Y'),
                'jours_restants' => $jours
            ];
        })->toArray();
    }

    /**
     * Formate les lots expirés pour l'affichage
     */
    private function formatLotsExpires($lots): array
    {
        return $lots->map(function ($lot) {
            return [
                'lot_id' => $lot->id,
                'numero_lot' => $lot->numero_lot,
                'produit' => $lot->produit->nom,
                'succursale' => $lot->stockSuccursale->succursale->nom,
                'quantite' => $lot->quantite_restante,
                'date_expiration' => $lot->date_expiration->format('d/m/Y')
            ];
        })->toArray();
    }

    /**
     * Vérifie si l'utilisateur a accès au stock
     */
    private function userHasStockAccess(Utilisateur $user): bool
    {
        return $user->hasPermission('manage-stock-agence') ||
            $user->hasPermission('view-stock-agence') ||
            $user->role?->code === 'SUPER_ADMIN' ||
            $user->role?->code === 'CHEF_AGENCE' ||
            $user->role?->code === 'GESTIONNAIRE_STOCK';
    }

    /**
     * Récupère les succursales autorisées pour l'utilisateur
     */
    private function getAuthorizedSuccursalesIds(Utilisateur $user): array
    {
        if ($user->hasPermission('manage-stock-agence') || $user->role?->code === 'SUPER_ADMIN') {
            return Succursale::pluck('id')->toArray();
        }

        if ($user->succursale_id) {
            return [$user->succursale_id];
        }

        return [];
    }

    /**
     * Envoie les notifications d'expiration
     */
    private function envoyerNotificationsExpiration($lotsCritiques, $lotsExpires, Utilisateur $user): void
    {
        foreach ($lotsCritiques as $lot) {
            $this->envoyerAlerteLot($lot, 'critique', $user);
        }

        foreach ($lotsExpires as $lot) {
            $this->envoyerAlerteLot($lot, 'expire', $user);
        }
    }

    /**
     * Envoie une alerte pour un lot spécifique
     */
    private function envoyerAlerteLot($lot, string $type, Utilisateur $user): void
    {
        $produit = $lot->produit;
        $stock = $lot->stockSuccursale;
        $succursale = $stock->succursale;
        $jours = now()->startOfDay()->diffInDays($lot->date_expiration, false);

        $alertData = $this->buildAlertData($lot, $type, $produit, $succursale, $jours);

        $this->notifierUtilisateurs([
            'type' => 'LOT_' . strtoupper($type),
            'titre' => $alertData['titre'],
            'message' => $alertData['message'],
            'donnees' => $alertData['donnees'],
            'priorite' => $type === 'expire' ? 'URGENTE' : 'HAUTE',
            'succursale_id' => $succursale->id,
            'produit_id' => $produit->id
        ]);
    }

    /**
     * Construit les données d'alerte
     */
    private function buildAlertData($lot, string $type, $produit, $succursale, int $jours): array
    {
        $titre = match ($type) {
            'expire' => "❌ LOT EXPIRE - {$produit->nom}",
            'critique' => "🔴 ALERTE CRITIQUE - {$produit->nom}",
            default => "⚠️ EXPIRATION PROCHAINE - {$produit->nom}"
        };

        $message = match ($type) {
            'expire' => "URGENT: Un lot de {$produit->nom} est EXPIRÉ et a été bloqué. Quantité: {$lot->quantite_restante} unités. Veuillez procéder à la destruction ou au retour.",
            'critique' => "URGENT: Un lot de {$produit->nom} expire dans {$jours} jours. Quantité: {$lot->quantite_restante} unités.",
            default => "Un lot de {$produit->nom} expire dans {$jours} jours. Quantité: {$lot->quantite_restante} unités."
        };

        return [
            'titre' => $titre,
            'message' => $message,
            'donnees' => [
                'lot_id' => $lot->id,
                'numero_lot' => $lot->numero_lot,
                'produit_id' => $produit->id,
                'produit_nom' => $produit->nom,
                'quantite' => $lot->quantite_restante,
                'date_expiration' => $lot->date_expiration->format('d/m/Y'),
                'jours_restants' => $jours,
                'succursale' => $succursale->nom,
                'succursale_id' => $succursale->id,
                'type' => $type
            ]
        ];
    }

    /**
     * Structure d'alerte vide par défaut
     */
    private function getEmptyAlertStructure(): array
    {
        return [
            'total' => 0,
            'critique' => 0,
            'proche' => 0,
            'expire' => 0,
            'details' => [
                'proches' => [],
                'expires' => []
            ],
            'succursales_concernees' => []
        ];
    }

    /**
     * Structure d'alerte en cas d'erreur
     */
    private function getErrorAlertStructure(): array
    {
        return $this->getEmptyAlertStructure() + [
            'error' => 'Impossible de vérifier les stocks'
        ];
    }
}