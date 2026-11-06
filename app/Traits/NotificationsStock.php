<?php
// app/Traits/NotificationsStock.php

namespace App\Traits;

use App\Models\Utilisateur;
use Illuminate\Support\Facades\Log;

trait NotificationsStock
{
    /**
     * Notifier une alerte de stock
     */
    protected function notifierAlerte()
    {
        if (!method_exists($this, 'notifierUtilisateurs')) {
            Log::warning('La méthode notifierUtilisateurs n\'existe pas', [
                'stock_id' => $this->id
            ]);
            return;
        }

        $this->notifierUtilisateurs([
            'type' => 'ALERTE_STOCK',
            'titre' => "⚠️ Alerte stock - {$this->produit->nom}",
            'message' => "Le stock de {$this->produit->nom} est à {$this->quantite_disponible} unités (seuil: {$this->quantite_minimale})",
            'donnees' => [
                'stock_id' => $this->id,
                'produit_id' => $this->produit_id,
                'produit_nom' => $this->produit->nom,
                'quantite' => $this->quantite_disponible,
                'seuil' => $this->quantite_minimale
            ]
        ]);
    }

    /**
     * Notifier un seuil critique atteint
     */
    protected function notifierSeuilCritique()
    {
        $utilisateurs = Utilisateur::whereHas('role', function($q) {
            $q->whereIn('code', ['CHEF_AGENCE', 'SUPER_ADMIN', 'GESTIONNAIRE_STOCK']);
        })->get();

        foreach ($utilisateurs as $user) {
            if (method_exists($user, 'notifier')) {
                $user->notifier([
                    'type' => 'SEUIL_CRITIQUE',
                    'titre' => "🔴 Seuil critique atteint - {$this->produit->nom}",
                    'message' => "Le stock a atteint un seuil critique. Approvisionnement nécessaire.",
                    'donnees' => [
                        'stock_id' => $this->id,
                        'produit' => $this->produit->nom,
                        'quantite' => $this->quantite_disponible
                    ]
                ]);
            }
        }
    }

    /**
     * Notifier le retour à la normale
     */
    protected function notifierRetourNormal()
    {
        if (!method_exists($this, 'notifierUtilisateurs')) {
            return;
        }

        $this->notifierUtilisateurs([
            'type' => 'RETOUR_NORMAL',
            'titre' => " Retour à la normale - {$this->produit->nom}",
            'message' => "Le stock de {$this->produit->nom} est revenu à un niveau normal.",
            'donnees' => [
                'stock_id' => $this->id,
                'produit' => $this->produit->nom,
                'quantite' => $this->quantite_disponible
            ]
        ]);
    }

    /**
     * Notifier une demande d'approvisionnement
     */
    public function notifierDemandeApprovisionnement($quantite)
    {
        $responsables = Utilisateur::whereHas('role', function($q) {
            $q->whereIn('code', ['CHEF_AGENCE', 'GESTIONNAIRE_STOCK']);
        })->where('succursale_id', $this->succursale_id)
        ->get();

        foreach ($responsables as $user) {
            if (method_exists($user, 'notifier')) {
                $user->notifier([
                    'type' => 'DEMANDE_APPRO',
                    'titre' => "📦 Demande d'approvisionnement",
                    'message' => "Demande de {$quantite} {$this->produit->nom} pour le stock",
                    'donnees' => [
                        'stock_id' => $this->id,
                        'produit' => $this->produit->nom,
                        'quantite' => $quantite
                    ]
                ]);
            }
        }
    }

    /**
     * Notifier une rupture de stock
     */
    public function notifierRupture()
    {
        if (!method_exists($this, 'notifierUtilisateurs')) {
            return;
        }

        $this->notifierUtilisateurs([
            'type' => 'RUPTURE_STOCK',
            'titre' => "⛔ Rupture de stock - {$this->produit->nom}",
            'message' => "Le produit {$this->produit->nom} est en rupture de stock",
            'donnees' => [
                'stock_id' => $this->id,
                'produit' => $this->produit->nom
            ],
            'priorite' => 'HAUTE'
        ]);
    }
}