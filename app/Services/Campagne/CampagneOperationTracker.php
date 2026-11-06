<?php

namespace App\Services\Campagne;

use App\Models\AgentDsa;
use App\Models\Campagne\CampagneAgent;
use App\Models\Campagne\CampagneOperation;
use App\Models\Utilisateur;
use Illuminate\Support\Facades\Log;


class CampagneOperationTracker
{
    public function __construct(private CampagneService $campagneService) {}

    /**
     * @param  Utilisateur  $auteur       L'utilisateur qui déclenche l'action (agent DSA connecté)
     * @param  string       $typeOperation CREATION_CLIENT | VENTE | VISITE | PLAINTE | SUGGESTION | COLLECTE_EPARGNE
     * @param  int          $sourceId     ID de l'entité source (client, vente, visite…)
     * @param  array        $extra        Champs additionnels : id_produit, quantite_operation, montant_operation, date_operation
     */
    public function tracker(
        Utilisateur $auteur,
        string $typeOperation,
        int $sourceId,
        array $extra = []
    ): ?CampagneOperation {
        // 1) Retrouver l'agent DSA lié au compte connecté
        $agent = AgentDsa::where('utilisateur_id', $auteur->id)->first();

        if (! $agent) {
            return null;
        }

        // 2) Retrouver son affectation campagne ACTIVE et VALIDÉE
        //    (on ne tient pas compte des affectations EN_ATTENTE / REJETEE / RETIREE)
        $campagneAgent = CampagneAgent::where('id_agent', $agent->id)
            ->where('statut_affectation', 'ACTIVE')
            ->whereNull('date_fin_affectation')
            ->whereHas('campagne', fn ($q) => $q->where('statut', 'ACTIVE'))
            ->first();

        if (! $campagneAgent) {
            return null;
        }

        try {
            return $this->campagneService->enregistrerOperation([
                'id_campagne'         => $campagneAgent->id_campagne,
                'id_agent'            => $agent->id,
                'type_operation'      => $typeOperation,
                'id_operation_source' => $sourceId,
                'id_produit'          => $extra['id_produit']          ?? null,
                'quantite_operation'  => $extra['quantite_operation']  ?? 1,
                'montant_operation'   => $extra['montant_operation']   ?? 0,
                'date_operation'      => $extra['date_operation']      ?? now(),
            ]);
        } catch (\Throwable $e) {
            // On ne casse JAMAIS la création du client / de la vente
            // à cause d'une erreur de tracking campagne.
            Log::error('[CAMPAGNE_TRACKER] Échec enregistrement opération', [
                'type_operation' => $typeOperation,
                'source_id'      => $sourceId,
                'agent_id'       => $agent->id,
                'error'          => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Enregistre les opérations VENTE d'une facture payée.
     *
     * - 1 opération PAR PRODUIT DISTINCT de la facture
     * - id_operation_source = "{facture_id}:{produit_id}"
     * - id_produit = produit_id
     * - quantite_operation = Σ quantités du produit
     * - montant_operation = Σ total_ttc des lignes du produit
     */
    public function trackerVenteFacture(Facture $facture, ?Utilisateur $auteur = null): void
    {
        if ($facture->statut !== 'PAYEE') {
            return;
        }

        $auteur ??= auth()->user();
        if (!$auteur) {
            return;
        }

        $facture->loadMissing('lignes');

        if ($facture->lignes->isEmpty()) {
            Log::warning('[CAMPAGNE_TRACKER] Facture payée sans ligne', [
                'facture_id' => $facture->id,
                'reference'  => $facture->reference,
            ]);
            return;
        }

        $parProduit = $facture->lignes
            ->filter(fn ($l) => $l->produit_id)
            ->groupBy('produit_id')
            ->map(fn ($lignesProduit, $produitId) => [
                'produit_id' => (int) $produitId,
                'quantite'   => (int) $lignesProduit->sum('quantite'),
                'montant'    => (float) $lignesProduit->sum('total_ttc'),
                'source_id'  => $facture->id . ':' . $produitId,
            ])
            ->values();

        foreach ($parProduit as $vente) {
            try {
                $this->tracker(
                    auteur:        $auteur,
                    typeOperation: 'VENTE',
                    sourceId:      $vente['source_id'],
                    extra: [
                        'id_produit'         => $vente['produit_id'],
                        'quantite_operation' => $vente['quantite'],
                        'montant_operation'  => $vente['montant'],
                    ]
                );
            } catch (\Throwable $e) {
                Log::error('[CAMPAGNE_TRACKER] Échec tracking vente', [
                    'facture_id' => $facture->id,
                    'produit_id' => $vente['produit_id'],
                    'error'      => $e->getMessage(),
                ]);
            }
        }
    }
}