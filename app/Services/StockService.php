<?php

namespace App\Services;

use App\Models\MouvementStock;
use App\Models\StockSuccursale;
use App\Models\LotStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StockService
{
    protected LotStockService $lotService;

    public function __construct(LotStockService $lotService)
    {
        $this->lotService = $lotService;
    }

    /**
     * Réceptionner du stock (entrée)
     * - Crée un mouvement ENTREE (ou type personnalisé)
     * - Crée un lot si nécessaire
     * - Met à jour StockSuccursale
     */
    public function receptionner(
        StockSuccursale $stock,
        int $quantite,
        array $options = []
    ): MouvementStock {
        return $stock->receptionner($quantite, $options);
    }

    /**
     * Expédier du stock (sortie)
     * - Crée un mouvement SORTIE (ou type personnalisé)
     * - Consomme les lots FIFO/FEFO
     * - Met à jour StockSuccursale
     */
    public function expedier(
        StockSuccursale $stock,
        int $quantite,
        array $options = []
    ): MouvementStock {
        return $stock->expedier($quantite, $options);
    }

    /**
     * Ajuster le stock (augmentation ou diminution)
     * - Délègue à receptionner ou expedier avec le type AJUSTEMENT
     * - Un seul mouvement par ajustement
     */
    public function ajuster(
        StockSuccursale $stock,
        int $nouvelleQuantite,
        array $options = []
    ): MouvementStock {
        return $stock->ajuster($nouvelleQuantite, $options);
    }

    /**
     * Annuler un mouvement (centralisé)
     * - Délègue à annulerEntree ou annulerSortie selon le type
     * - Pour un ajustement, effectue l'inverse
     * - Marque le mouvement original comme ANNULE
     * - Crée un mouvement ANNULATION compensateur
     */
    public function annuler(MouvementStock $mouvement, string $raison): MouvementStock
    {
        if ($mouvement->statut === 'ANNULE') {
            throw new \Exception('Ce mouvement est déjà annulé.');
        }

        $stock = StockSuccursale::where('succursale_id', $mouvement->succursale_id)
            ->where('produit_id', $mouvement->produit_id)
            ->first();

        if (!$stock) {
            throw new \Exception('Stock introuvable pour ce mouvement.');
        }

        DB::beginTransaction();

        try {
            $mouvementAnnulation = null;

            switch ($mouvement->type_mouvement) {
                case 'ENTREE':
                case 'RETOUR':
                    $mouvementAnnulation = $stock->annulerEntree($mouvement, $raison);
                    break;

                case 'SORTIE':
                case 'TRANSFERT':
                    $mouvementAnnulation = $stock->annulerSortie($mouvement, $raison);
                    break;

                case 'AJUSTEMENT':
                    // Vérifier les mouvements postérieurs
                    $mouvementsPosterieurs = MouvementStock::where('produit_id', $mouvement->produit_id)
                        ->where('succursale_id', $mouvement->succursale_id)
                        ->where('id', '>', $mouvement->id)
                        ->where('statut', '!=', 'ANNULE')
                        ->exists();

                    if ($mouvementsPosterieurs) {
                        throw new \Exception('Impossible d\'annuler cet ajustement car des mouvements postérieurs existent.');
                    }

                    $ancienne = $mouvement->metadata['ancienne_quantite'] ?? $stock->quantite_disponible;
                    $mouvementAnnulation = $this->ajuster($stock, $ancienne, [
                        'raison' => 'Annulation d\'ajustement : ' . $raison,
                        'reference_document' => 'ANNUL-' . $mouvement->reference_mouvement,
                        'metadata' => [
                            'type_annulation' => 'AJUSTEMENT_INVERSE',
                            'mouvement_annule_id' => $mouvement->mouvement_id,
                        ],
                    ]);
                    break;

                default:
                    throw new \Exception('Type de mouvement non supporté pour l\'annulation.');
            }

            // Marquer le mouvement original comme annulé
            $mouvement->statut = 'ANNULE';
            $mouvement->save();

            DB::commit();

            Log::info('Mouvement annulé avec succès', [
                'mouvement_annule_id' => $mouvement->mouvement_id,
                'type' => $mouvement->type_mouvement,
                'raison' => $raison,
            ]);

            return $mouvementAnnulation;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Trouver ou créer un stock pour un produit dans une succursale
     */
    public function trouverOuCreerStock(int $succursaleId, int $produitId, array $defaults = []): StockSuccursale
    {
        $stock = StockSuccursale::where('succursale_id', $succursaleId)
            ->where('produit_id', $produitId)
            ->lockForUpdate()
            ->first();

        if (!$stock) {
            $stock = StockSuccursale::create([
                'succursale_id' => $succursaleId,
                'produit_id' => $produitId,
                'quantite_disponible' => $defaults['quantite_disponible'] ?? 0,
                'quantite_reserve' => $defaults['quantite_reserve'] ?? 0,
                'quantite_minimale' => $defaults['quantite_minimale'] ?? 5,
                'is_depot' => $defaults['is_depot'] ?? true,
                'emplacement' => $defaults['emplacement'] ?? 'Stock principal',
            ]);
            Log::info('Stock créé automatiquement', [
                'succursale_id' => $succursaleId,
                'produit_id' => $produitId,
                'stock_id' => $stock->id,
            ]);
        }

        return $stock;
    }
}