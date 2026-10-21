<?php
// app/Jobs/CheckExpiringLotsJob.php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\Middleware\RateLimited;
use App\Models\MouvementStock;
use App\Models\StockSuccursale;
use App\Traits\Notifiable;
use Illuminate\Support\Facades\Log;

class CheckExpiringLotsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Notifiable;

    /**
     * Nombre de tentatives en cas d'échec
     */
    public $tries = 3;
    
    /**
     * Délai entre les tentatives (en secondes)
     */
    public $backoff = [60, 300, 600];
    
    /**
     * Timeout du job (secondes)
     */
    public $timeout = 300;
    
    /**
     * Nombre de jours avant expiration
     */
    protected $days;
    
    /**
     * ID de la succursale (optionnel)
     */
    protected $succursaleId;
    
    /**
     * Forcer l'envoi des alertes
     */
    protected $force;

    /**
     * Create a new job instance.
     */
    public function __construct($days = 30, $succursaleId = null, $force = false)
    {
        $this->days = $days;
        $this->succursaleId = $succursaleId;
        $this->force = $force;
    }

    /**
     * Get the middleware the job should pass through.
     */
    public function middleware(): array
    {
        return [
            new RateLimited('expiration')
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('CheckExpiringLotsJob démarré', [
            'days' => $this->days,
            'succursale_id' => $this->succursaleId,
            'force' => $this->force
        ]);

        try {
            // 1. Traiter les lots proches d'expiration (30 jours et 7 jours)
            $this->verifierLotsProchesExpiration();

            // 2. Traiter les lots expirés
            $this->verifierEtTraiterLotsExpires();

            Log::info('CheckExpiringLotsJob terminé avec succès');

        } catch (\Exception $e) {
            Log::error('Erreur dans CheckExpiringLotsJob', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw $e;
        }
    }

    /**
     * Vérifier les lots proches d'expiration
     * Gère les alertes à 30 jours et 7 jours
     */
    protected function verifierLotsProchesExpiration(): void
    {
        $query = MouvementStock::where('type_mouvement', 'ENTREE')
            ->where('est_lot_fifo', true)
            ->where('quantite_restante_lot', '>', 0)
            ->whereNotNull('date_expiration_lot')
            ->where('date_expiration_lot', '<=', now()->addDays($this->days))
            ->where('date_expiration_lot', '>', now()) // Uniquement les lots non expirés
            ->with(['produit', 'stockDestination.succursale']);

        if ($this->succursaleId) {
            $query->whereHas('stockDestination', function($q) {
                $q->where('succursale_id', $this->succursaleId);
            });
        }

        $lots = $query->get();

        Log::info('Lots proches d\'expiration trouvés', ['count' => $lots->count()]);

        foreach ($lots as $lot) {
            $this->traiterLotExpirant($lot);
        }
    }

    /**
     * Traiter un lot proche de l'expiration
     * Responsable uniquement des alertes (30j et 7j)
     * Utilise metadata pour éviter les alertes répétitives
     */
    protected function traiterLotExpirant($lot): void
    {
        $stock = $lot->stockDestination;
        
        if (!$stock) {
            Log::warning('Stock non trouvé pour le lot', ['lot_id' => $lot->mouvement_id]);
            return;
        }

        $aujourdhui = now()->startOfDay();
        $expiration = $lot->date_expiration_lot->startOfDay();
        $joursAvantExpiration = (int) $aujourdhui->diffInDays($expiration, false);

        // Initialiser metadata si null
        $metadata = $lot->metadata ?? [];
        if (!isset($metadata['expiration_alertes'])) {
            $metadata['expiration_alertes'] = [
                'proche' => false,
                'critique' => false,
                'expire' => false
            ];
        }

        $alertes = &$metadata['expiration_alertes'];

        // =============================================
        // ALERTE PROCHE EXPIRATION (30 jours)
        // =============================================
        if ($joursAvantExpiration <= 30 && $joursAvantExpiration > 7 && $joursAvantExpiration > 0) {
            if (!$alertes['proche'] || $this->force) {
                $this->envoyerAlerteExpiration($lot, $stock, $joursAvantExpiration, 'proche');
                $alertes['proche'] = true;
                
                Log::info('Alerte expiration envoyée (30 jours)', [
                    'lot_id' => $lot->mouvement_id,
                    'produit' => $lot->produit->nom,
                    'jours' => $joursAvantExpiration
                ]);
            } else {
                Log::info('Alerte expiration ignorée (déjà envoyée)', [
                    'lot_id' => $lot->mouvement_id,
                    'produit' => $lot->produit->nom
                ]);
            }
        }

        // =============================================
        // ALERTE CRITIQUE (7 jours)
        // =============================================
        if ($joursAvantExpiration <= 7 && $joursAvantExpiration > 0) {
            if (!$alertes['critique'] || $this->force) {
                $this->envoyerAlerteExpiration($lot, $stock, $joursAvantExpiration, 'critique');
                $alertes['critique'] = true;
                
                Log::info('Alerte critique envoyée (7 jours)', [
                    'lot_id' => $lot->mouvement_id,
                    'produit' => $lot->produit->nom,
                    'jours' => $joursAvantExpiration
                ]);
            } else {
                Log::info('Alerte critique ignorée (déjà envoyée)', [
                    'lot_id' => $lot->mouvement_id,
                    'produit' => $lot->produit->nom
                ]);
            }
        }

        // Sauvegarder les modifications
        $lot->metadata = $metadata;
        $lot->save();
    }

    /**
     * Vérifier et traiter les lots expirés
     * Responsable uniquement des lots déjà expirés
     */
    protected function verifierEtTraiterLotsExpires(): void
    {
        $expiredLots = MouvementStock::where('type_mouvement', 'ENTREE')
            ->where('est_lot_fifo', true)
            ->where('quantite_restante_lot', '>', 0)
            ->whereNotNull('date_expiration_lot')
            ->where('date_expiration_lot', '<=', now())
            ->with(['produit', 'stockDestination.succursale'])
            ->get();

        Log::info('Lots expirés à traiter', ['count' => $expiredLots->count()]);

        foreach ($expiredLots as $lot) {
            $this->traiterLotExpire($lot);
        }
    }

    /**
     * Traiter un lot expiré
     * Crée une perte, met à jour le stock et marque le lot comme traité
     */
    protected function traiterLotExpire($lot): void
    {
        $stock = $lot->stockDestination;
        
        if (!$stock) {
            Log::warning('Stock non trouvé pour le lot expiré', [
                'lot_id' => $lot->mouvement_id
            ]);
            return;
        }

        // Vérifier dans metadata si le lot a déjà été traité
        $metadata = $lot->metadata ?? [];
        if (isset($metadata['expiration_traite']) && $metadata['expiration_traite'] === true) {
            Log::info('Lot expiré déjà traité', [
                'lot_id' => $lot->mouvement_id
            ]);
            return;
        }

        // Vérifier si un mouvement de perte existe déjà pour ce lot
        $perteExistante = MouvementStock::where('type_mouvement', 'AJUSTEMENT')
            ->where('raison', 'Perte suite expiration lot FIFO')
            ->where('metadata->lot_origine_id', $lot->mouvement_id)
            ->exists();

        if ($perteExistante) {
            Log::warning('Mouvement de perte déjà existant pour le lot', [
                'lot_id' => $lot->mouvement_id
            ]);
            // Marquer comme traité dans metadata
            $metadata['expiration_traite'] = true;
            $lot->metadata = $metadata;
            $lot->save();
            return;
        }

        $quantiteExpiree = $lot->quantite_restante_lot;

        try {
            // =============================================
            // 1. ENVOYER L'ALERTE D'EXPIRATION
            // =============================================
            if (!isset($metadata['expiration_alertes']['expire']) || 
                !$metadata['expiration_alertes']['expire'] || 
                $this->force) {
                
                $this->envoyerAlerteExpiration($lot, $stock, 0, 'expire');
                
                if (!isset($metadata['expiration_alertes'])) {
                    $metadata['expiration_alertes'] = [];
                }
                $metadata['expiration_alertes']['expire'] = true;
                
                Log::info('Alerte expiration envoyée (lot expiré)', [
                    'lot_id' => $lot->mouvement_id,
                    'produit' => $lot->produit->nom
                ]);
            }

            // =============================================
            // 2. CRÉER LE MOUVEMENT DE PERTE (AJUSTEMENT)
            // =============================================
            $perte = MouvementStock::create([
                'produit_id' => $lot->produit_id,
                'stock_source_id' => $stock->stock_id,
                'stock_destination_id' => $stock->stock_id,
                'type_mouvement' => 'AJUSTEMENT',
                'quantite' => $quantiteExpiree,
                'quantite_restante_lot' => 0,
                'quantite_perdue' => $quantiteExpiree,
                'est_lot_fifo' => false,
                'reference' => 'EXPIRATION_LOT_' . $lot->mouvement_id,
                'notes' => 'Perte suite expiration du lot #' . $lot->mouvement_id,
                'date_expiration_lot' => $lot->date_expiration_lot,
                'cout_unitaire' => $lot->cout_unitaire ?? null,
                'cout_total' => $lot->cout_total ?? null,
                'responsable_id' => null, // Système
                'raison' => 'Perte suite expiration lot FIFO',
                'metadata' => [
                    'origine' => 'expiration_lot',
                    'lot_origine_id' => $lot->mouvement_id,
                    'date_expiration' => $lot->date_expiration_lot->format('Y-m-d'),
                    'quantite_perdue' => $quantiteExpiree,
                    'produit_nom' => $lot->produit->nom,
                    'succursale_id' => $stock->succursale_id,
                    'action' => 'expiration_auto',
                    'timestamp' => now()->toISOString()
                ]
            ]);

            Log::info('Mouvement de perte créé pour lot expiré', [
                'lot_origine' => $lot->mouvement_id,
                'mouvement_perte_id' => $perte->mouvement_id,
                'quantite' => $quantiteExpiree
            ]);

            // =============================================
            // 3. METTRE À JOUR LE STOCK
            // =============================================
            $this->retirerStockExpire($stock, $quantiteExpiree, $lot->mouvement_id);

            // =============================================
            // 4. MARQUER LE LOT COMME TRAITÉ
            // =============================================
            $lot->quantite_restante_lot = 0;
            $metadata['expiration_traite'] = true;
            $lot->metadata = $metadata;
            $lot->save();

            Log::warning('Lot expiré traité avec succès', [
                'lot_id' => $lot->mouvement_id,
                'produit' => $lot->produit->nom,
                'quantite_retiree' => $quantiteExpiree,
                'stock_restant' => $stock->quantite_disponible
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur lors du traitement du lot expiré', [
                'lot_id' => $lot->mouvement_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Marquer comme traité pour éviter les boucles même en cas d'erreur
            // Mais seulement si l'erreur n'est pas critique
            if (strpos($e->getMessage(), 'Stock insuffisant') === false) {
                $metadata['expiration_traite'] = true;
                $lot->metadata = $metadata;
                $lot->save();
            }
        }
    }

    /**
     * Retirer la quantité expirée du stock
     */
    protected function retirerStockExpire($stock, $quantite, $lotId): void
    {
        if ($stock->quantite_disponible < $quantite) {
            Log::error('Stock insuffisant pour retirer le lot expiré', [
                'stock_id' => $stock->stock_id,
                'quantite_disponible' => $stock->quantite_disponible,
                'quantite_a_retirer' => $quantite,
                'lot_id' => $lotId
            ]);
            throw new \Exception('Stock insuffisant pour retirer le lot expiré');
        }

        $ancienneQuantite = $stock->quantite_disponible;
        $stock->quantite_disponible -= $quantite;
        $stock->save();

        Log::info('Stock retiré pour lot expiré', [
            'stock_id' => $stock->stock_id,
            'succursale_id' => $stock->succursale_id,
            'produit_id' => $stock->produit_id,
            'quantite_retiree' => $quantite,
            'ancienne_quantite' => $ancienneQuantite,
            'nouvelle_quantite' => $stock->quantite_disponible,
            'lot_origine' => $lotId
        ]);
    }

    /**
     * Envoyer une alerte d'expiration
     */
    protected function envoyerAlerteExpiration($lot, $stock, $jours, $type): void
    {
        $produit = $lot->produit;
        $succursale = $stock->succursale;
        
        // Déterminer le niveau de priorité
        $priorite = match($type) {
            'expire' => 'URGENTE',
            'critique' => 'HAUTE',
            default => 'NORMALE'
        };
        
        // Déterminer le titre et le message
        $titre = match($type) {
            'expire' => "❌ LOT EXPIRE - {$produit->nom}",
            'critique' => "🔴 ALERTE CRITIQUE - {$produit->nom}",
            'proche' => "⚠️ EXPIRATION PROCHAINE - {$produit->nom}"
        };
        
        $message = match($type) {
            'expire' => "URGENT: Un lot de {$produit->nom} est EXPIRÉ et a été retiré du stock automatiquement. Quantité: {$lot->quantite_restante_lot} unités.",
            'critique' => "URGENT: Un lot de {$produit->nom} expire dans {$jours} jours. Quantité: {$lot->quantite_restante_lot} unités.",
            'proche' => "Un lot de {$produit->nom} expire dans {$jours} jours. Quantité: {$lot->quantite_restante_lot} unités."
        };
        
        $this->notifierUtilisateurs([
            'type' => 'LOT_' . strtoupper($type),
            'titre' => $titre,
            'message' => $message,
            'donnees' => [
                'lot_id' => $lot->mouvement_id,
                'produit_id' => $produit->id,
                'produit_nom' => $produit->nom,
                'quantite' => $lot->quantite_restante_lot,
                'date_expiration' => $lot->date_expiration_lot->format('d/m/Y'),
                'jours_restants' => $jours,
                'succursale' => $succursale->nom,
                'succursale_id' => $succursale->id,
                'type' => $type
            ],
            'priorite' => $priorite,
            'succursale_id' => $succursale->id,
            'produit_id' => $produit->id
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('CheckExpiringLotsJob a échoué', [
            'error' => $exception->getMessage(),
            'days' => $this->days,
            'succursale_id' => $this->succursaleId,
            'trace' => $exception->getTraceAsString()
        ]);
    }
}