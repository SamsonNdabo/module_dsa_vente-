<?php

namespace App\Observers;

use App\Models\Campagne\CampagneOperation;
use App\Models\Campagne\CampagneAgent;
use App\Models\Campagne\CampagneAgentStock;
use Illuminate\Support\Facades\Log;

class CampagneOperationObserver
{
    /**
     * Se déclenche après la création d'une nouvelle opération.
     */
    public function created(CampagneOperation $campagneOperation): void
    {
        // On vérifie si l'opération compte dans l'objectif et si c'est une vente.
        // Vous pouvez adapter cette condition selon vos besoins (ex: inclure les épargnes).
        if ($campagneOperation->compte_dans_objectif && $campagneOperation->type_operation === 'VENTE') {
            
            // 1. On recherche l'affectation de l'agent pour cette campagne.
            $campagneAgent = CampagneAgent::where('id_campagne', $campagneOperation->id_campagne)
                ->where('id_agent', $campagneOperation->id_agent)
                ->first();

            if ($campagneAgent) {
                // 2. On recherche le stock du produit spécifique remis à cet agent.
                $stockAgent = CampagneAgentStock::where('id_campagne_agent', $campagneAgent->id)
                    ->where('id_produit', $campagneOperation->id_produit)
                    ->first();

                if ($stockAgent) {
                    // 3. On incrémente la quantité vendue.
                    // On utilise la quantité de l'opération (ou 1 par défaut).
                    $quantite = $campagneOperation->quantite_operation ?? 1;
                    
                    $stockAgent->increment('quantite_vendue', $quantite);
                    
                    // On enregistre l'action dans les logs pour le suivi.
                    Log::info("Stock de l'agent mis à jour automatiquement", [
                        'id_campagne'    => $campagneOperation->id_campagne,
                        'id_agent'       => $campagneOperation->id_agent,
                        'id_produit'     => $campagneOperation->id_produit,
                        'quantite_vendue'=> $quantite,
                        'stock_restant'  => $stockAgent->fresh()->stock_restant
                    ]);
                } else {
                    Log::warning("Produit vendu non trouvé dans le stock de l'agent", [
                        'id_agent'   => $campagneOperation->id_agent,
                        'id_produit' => $campagneOperation->id_produit
                    ]);
                }
            }
        }
    }

    /**
     * Se déclenche après la mise à jour d'une opération.
     */
    public function updated(CampagneOperation $campagneOperation): void
    {
        // Si l'opération est annulée (le champ compte_dans_objectif passe à false),
        // on doit restaurer le stock de l'agent.
        if ($campagneOperation->wasChanged('compte_dans_objectif') && !$campagneOperation->compte_dans_objectif) {
            
            $campagneAgent = CampagneAgent::where('id_campagne', $campagneOperation->id_campagne)
                ->where('id_agent', $campagneOperation->id_agent)
                ->first();

            if ($campagneAgent) {
                $stockAgent = CampagneAgentStock::where('id_campagne_agent', $campagneAgent->id)
                    ->where('id_produit', $campagneOperation->id_produit)
                    ->first();

                if ($stockAgent) {
                    $quantite = $campagneOperation->quantite_operation ?? 1;
                    
                    // On décrémente la quantité vendue car l'opération est annulée.
                    $stockAgent->decrement('quantite_vendue', $quantite);
                    
                    Log::info("Stock restauré suite à l'annulation d'une opération", [
                        'id_agent'       => $campagneOperation->id_agent,
                        'id_produit'     => $campagneOperation->id_produit,
                        'quantite_rendue'=> $quantite
                    ]);
                }
            }
        }
    }

    /**
     * Se déclenche après la suppression d'une opération.
     */
    public function deleted(CampagneOperation $campagneOperation): void
    {
        // Si l'opération supprimée comptait dans l'objectif,
        // on doit restaurer le stock de l'agent.
        if ($campagneOperation->compte_dans_objectif) {
            
            $campagneAgent = CampagneAgent::where('id_campagne', $campagneOperation->id_campagne)
                ->where('id_agent', $campagneOperation->id_agent)
                ->first();

            if ($campagneAgent) {
                $stockAgent = CampagneAgentStock::where('id_campagne_agent', $campagneAgent->id)
                    ->where('id_produit', $campagneOperation->id_produit)
                    ->first();

                if ($stockAgent) {
                    $quantite = $campagneOperation->quantite_operation ?? 1;
                    
                    // On décrémente la quantité vendue car l'opération est supprimée.
                    $stockAgent->decrement('quantite_vendue', $quantite);
                }
            }
        }
    }

    /**
     * Se déclenche après la restauration d'une opération (Soft Deletes).
     */
    public function restored(CampagneOperation $campagneOperation): void
    {
        // Logique à ajouter si vous utilisez les suppressions logiques (Soft Deletes).
    }

    /**
     * Se déclenche après la suppression définitive d'une opération.
     */
    public function forceDeleted(CampagneOperation $campagneOperation): void
    {
        // Logique à ajouter si vous utilisez les suppressions forcées.
    }
}