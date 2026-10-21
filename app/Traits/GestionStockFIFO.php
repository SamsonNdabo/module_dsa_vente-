<?php
// app/Traits/GestionStockFIFO.php

namespace App\Traits;

use Illuminate\Support\Facades\Log;

trait GestionStockFIFO
{
    /**
     * Réserver du stock (augmente quantite_reserve)
     */
    public function reserver($quantite)
    {
        $stockPhysiqueTotal = $this->quantite_disponible + $this->quantite_reserve;
        
        if (($this->quantite_reserve + $quantite) > ($stockPhysiqueTotal * 0.8)) {
            throw new \Exception("Réservation refusée. Vous ne pouvez pas réserver plus de 80% du stock total.");
        }
        
        $stockDisponible = $this->quantite_disponible - $this->quantite_reserve;
        if ($stockDisponible < $quantite) {
            throw new \Exception("Stock insuffisant. Disponible: {$stockDisponible}, Demandé: {$quantite}");
        }
    
        $this->quantite_reserve += $quantite;
        $this->save();

        $this->logAudit('RESERVATION_STOCK', [
            'quantite_reservee' => $quantite,
            'stock_disponible_apres' => $this->quantite_disponible - $this->quantite_reserve,
            'stock_reserve_apres' => $this->quantite_reserve
        ]);
    
        return $this;
    }

    /**
     * Confirmer une sortie (diminue quantite_reserve)
     */
    public function confirmerSortie($quantite)
    {
        if ($this->quantite_reserve < $quantite) {
            throw new \Exception("Réservation insuffisante. Réservé: {$this->quantite_reserve}, À confirmer: {$quantite}");
        }

        $this->quantite_reserve -= $quantite;
        $this->save();

        $this->logAudit('CONFIRMATION_SORTIE', [
            'quantite_sortie' => $quantite,
            'stock_reserve_restant' => $this->quantite_reserve
        ]);

        return $this;
    }

    /**
     * Libérer une réservation (diminue quantite_reserve, augmente quantite_disponible)
     */
    public function libererReservation($quantite = null)
    {
        $quantite = $quantite ?? $this->quantite_reserve;
        
        if ($this->quantite_reserve < $quantite) {
            throw new \Exception("Réservation insuffisante à libérer");
        }

        $this->quantite_reserve -= $quantite;
        $this->quantite_disponible += $quantite;
        $this->save();

        $this->logAudit('LIBERATION_RESERVATION', [
            'quantite_libere' => $quantite
        ]);

        return $this;
    }

    /**
     * Ajouter du stock (augmente quantite_disponible)
     */
    public function ajouterStock($quantite, $type = 'ENTREE', $raison = null)
    {
        $ancienneQuantite = $this->quantite_disponible;
        $this->quantite_disponible += $quantite;
        $this->date_dernier_entree = now();
        $this->save();

        $this->verifierSeuils();

        $this->logAudit('AUGMENTATION_STOCK', [
            'ancienne_quantite' => $ancienneQuantite,
            'nouvelle_quantite' => $this->quantite_disponible,
            'quantite_ajoutee' => $quantite,
            'type' => $type,
            'raison' => $raison
        ]);

        if ($this->estEnAlerte && method_exists($this, 'notifierAlerte')) {
            $this->notifierAlerte();
        }
        
        return $this;
    }

    /**
     * Diminuer du stock (diminue quantite_disponible)
     */
    public function diminuerStock($quantite, $type = 'SORTIE', $raison = null)
    {
        if ($this->quantite_disponible < $quantite) {
            throw new \Exception("Stock insuffisant. Disponible: {$this->quantite_disponible}, Demandé: {$quantite}");
        }

        $ancienneQuantite = $this->quantite_disponible;
        $this->quantite_disponible -= $quantite;
        $this->date_dernier_sortie = now();
        $this->save();

        $this->verifierSeuils();

        $this->logAudit('DIMINUTION_STOCK', [
            'ancienne_quantite' => $ancienneQuantite,
            'nouvelle_quantite' => $this->quantite_disponible,
            'quantite_retiree' => $quantite,
            'type' => $type,
            'raison' => $raison
        ]);

        return $this;
    }

    /**
     * Obtenir le stock d'un produit dans la même succursale
     */
    public function getStockPourProduit($produitId)
    {
        if ($this->produit_id == $produitId) return $this->quantite_disponible;
        $stock = self::where('succursale_id', $this->succursale_id)
            ->where('produit_id', $produitId)->first();
        return $stock ? $stock->quantite_disponible : 0;
    }

    /**
     * Stock disponible (hors réservations)
     */
    public function stockDisponible(): int
    {
        return $this->quantite_disponible - $this->quantite_reserve;
    }

    /**
     * Vérifier les seuils et déclencher les alertes
     */
    protected function verifierSeuils()
    {
        $etaitEnAlerte = !is_null($this->date_alerte_stock);
        $estEnAlerte = $this->est_en_alerte;

        if ($estEnAlerte && !$etaitEnAlerte) {
            $this->date_alerte_stock = now();
            $this->save();
            if (method_exists($this, 'notifierSeuilCritique')) {
                $this->notifierSeuilCritique();
            }
        } elseif (!$estEnAlerte && $etaitEnAlerte) {
            $this->date_alerte_stock = null;
            $this->save();
            if (method_exists($this, 'notifierRetourNormal')) {
                $this->notifierRetourNormal();
            }
        }
    }

    /**
     * Mettre à jour la rotation du stock
     */
    public function mettreAJourRotation($quantite_vendue, $periode_jours = 30)
    {
        if ($periode_jours <= 0) return;
        $rotation = ($quantite_vendue / $periode_jours) * 30;
        $this->rotation_stock = round($rotation, 2);
        $this->stock_moyen = round(($this->stock_moyen + $this->quantite_disponible) / 2, 2);
        $this->save();
    }
}