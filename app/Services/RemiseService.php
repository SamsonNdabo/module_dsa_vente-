<?php
// app/Services/RemiseService.php

namespace App\Services;

use App\Models\Client;
use App\Models\Produit;
use App\Models\RemiseRegle;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class RemiseService
{
    /**
     * Calculer les remises pour un client - OPTIMISÉ
     */
    public function calculerRemises(Client $client, Collection $lignes, ?float $sousTotal = null): array
    {
        // Si client non validé KYC → pas de remise
        if (!$client->kyc_complet) {
            return [
                'remise_globale' => 0,
                'remises_lignes' => [],
                'total_remise' => 0,
                'remises_appliquees' => []
            ];
        }

        // Récupérer les règles actives une seule fois
        $regles = RemiseRegle::actifs()
            ->orderBy('priorite', 'desc')
            ->get();

        if ($regles->isEmpty()) {
            return [
                'remise_globale' => 0,
                'remises_lignes' => [],
                'total_remise' => 0,
                'remises_appliquees' => []
            ];
        }

        //  Récupérer tous les produits en UNE SEULE REQUÊTE
        $produitIds = $lignes->pluck('produit_id')->filter()->unique()->toArray();
        $produits = [];
        if (!empty($produitIds)) {
            $produits = Produit::select(['id', 'nom', 'categorie_id', 'type', 'prix_vente'])
                ->whereIn('id', $produitIds)
                ->get()
                ->keyBy('id')
                ->toArray();
        }

        $remisesLignes = [];
        $totalRemise = 0;
        $remisesAppliquees = [];

        // Appliquer les remises par ligne - optimisé
        foreach ($lignes as $index => $ligne) {
            $produitId = $ligne['produit_id'] ?? null;
            $produit = isset($produits[$produitId]) ? (object) $produits[$produitId] : null;
            
            //  Compatibilité : utiliser 'prix_unitaire' (comme le frontend)
            $prixUnitaire = $ligne['prix_unitaire'] ?? $ligne['prix_unitaire_ht'] ?? 0;
            $montantLigne = ($ligne['quantite'] ?? 0) * $prixUnitaire;

            if ($montantLigne <= 0) {
                continue;
            }

            $meilleureRemise = 0;
            $meilleureRegle = null;

            foreach ($regles as $regle) {
                // Application par ligne uniquement pour PRODUIT et CATEGORIE
                if (!in_array($regle->application, ['PRODUIT', 'CATEGORIE'])) {
                    continue;
                }

                if ($regle->estApplicable($client, $montantLigne, $produit)) {
                    $remise = $regle->calculerRemise($montantLigne);
                    if ($remise > $meilleureRemise) {
                        $meilleureRemise = $remise;
                        $meilleureRegle = $regle;
                    }
                }
            }

            if ($meilleureRemise > 0 && $meilleureRegle) {
                $remisesLignes[$index] = [
                    'remise' => $meilleureRemise,
                    'type' => $meilleureRegle->type,
                    'regle_id' => $meilleureRegle->id,
                    'regle_nom' => $meilleureRegle->nom
                ];
                $totalRemise += $meilleureRemise;
                $remisesAppliquees[] = $meilleureRegle->nom;
            }
        }

        // Remise globale - optimisée
        $remiseGlobale = 0;
        $remiseGlobaleRegle = null;
        
        //  Compatibilité : calcul du montant global avec 'prix_unitaire'
        $montantGlobal = $sousTotal ?? $lignes->sum(function($l) {
            $prix = $l['prix_unitaire'] ?? $l['prix_unitaire_ht'] ?? 0;
            return ($l['quantite'] ?? 0) * $prix;
        });

        foreach ($regles as $regle) {
            if ($regle->application === 'GLOBALE' && $regle->estApplicable($client, $montantGlobal)) {
                $remise = $regle->calculerRemise($montantGlobal);
                if ($remise > $remiseGlobale) {
                    $remiseGlobale = $remise;
                    $remiseGlobaleRegle = $regle;
                }
            }
        }

        if ($remiseGlobale > 0 && $remiseGlobaleRegle) {
            $totalRemise += $remiseGlobale;
            $remisesAppliquees[] = $remiseGlobaleRegle->nom;
        }

        return [
            'remise_globale' => $remiseGlobale,
            'remises_lignes' => $remisesLignes,
            'total_remise' => $totalRemise,
            'remises_appliquees' => array_unique($remisesAppliquees)
        ];
    }

    /**
     * Créer une règle de remise
     */
    public function creerRegle(array $data): RemiseRegle
    {
        try {
            return RemiseRegle::create($data);
        } catch (\Exception $e) {
            Log::error('Erreur création règle remise', [
                'data' => $data,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Mettre à jour une règle de remise
     */
    public function updateRegle(int $id, array $data): RemiseRegle
    {
        try {
            $regle = RemiseRegle::findOrFail($id);
            $regle->update($data);
            return $regle;
        } catch (\Exception $e) {
            Log::error('Erreur mise à jour règle remise', [
                'regle_id' => $id,
                'data' => $data,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Activer/Désactiver une règle de remise
     */
    public function toggleRegle(int $id): RemiseRegle
    {
        try {
            $regle = RemiseRegle::findOrFail($id);
            $regle->actif = !$regle->actif;
            $regle->save();
            return $regle;
        } catch (\Exception $e) {
            Log::error('Erreur toggle règle remise', [
                'regle_id' => $id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Supprimer une règle de remise
     */
    public function deleteRegle(int $id): bool
    {
        try {
            return (bool) RemiseRegle::findOrFail($id)->delete();
        } catch (\Exception $e) {
            Log::error('Erreur suppression règle remise', [
                'regle_id' => $id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Liste des règles de remise - OPTIMISÉ
     */
    public function listRegles(array $filters = [])
    {
        try {
            $query = RemiseRegle::select([
                'id', 'nom', 'description', 'type', 'valeur',
                'application', 'cible', 'cible_valeur',
                'montant_minimum', 'nombre_achats_requis',
                'montant_achats_requis', 'kyc_requis',
                'date_debut', 'date_fin', 'actif', 'priorite',
                'conditions', 'created_at', 'updated_at'
            ]);

            if (isset($filters['actif'])) {
                $query->where('actif', $filters['actif']);
            }

            if (isset($filters['type'])) {
                $query->where('type', $filters['type']);
            }

            if (isset($filters['application'])) {
                $query->where('application', $filters['application']);
            }

            return $query->orderBy('priorite', 'desc')->get();

        } catch (\Exception $e) {
            Log::error('Erreur liste règles remise', [
                'filters' => $filters,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Obtenir les règles applicables à un client
     */
    public function getReglesApplicables(int $clientId): array
    {
        try {
            $client = Client::select(['id', 'categorie_client', 'nombre_achats', 'total_achats', 'statut'])
                ->findOrFail($clientId);

            $regles = RemiseRegle::actifs()
                ->select([
                    'id', 'nom', 'description', 'type', 'valeur',
                    'application', 'cible', 'cible_valeur',
                    'montant_minimum', 'nombre_achats_requis',
                    'montant_achats_requis', 'kyc_requis',
                    'date_debut', 'date_fin', 'priorite'
                ])
                ->get();

            $applicables = [];
            foreach ($regles as $regle) {
                if ($regle->estApplicable($client)) {
                    $applicables[] = $regle;
                }
            }

            return $applicables;

        } catch (\Exception $e) {
            Log::error('Erreur récupération règles applicables', [
                'client_id' => $clientId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}