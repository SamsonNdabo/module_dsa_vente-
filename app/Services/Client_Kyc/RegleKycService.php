<?php

namespace App\Services\Client_Kyc;

use App\Models\Client;
use App\Models\Kyc\Demande;
use App\Models\Kyc\KycDossier;
use App\Models\Kyc\KycPiece;
use App\Models\Kyc\RegleKycPartenaire;
use Illuminate\Support\Collection;

class RegleKycService
{
    public function __construct(private WorkflowService $workflow) {}

    /**
     * Règles applicables au produit :
     *   - globales (produits_json NULL ou vide)
     *   - celles qui contiennent {type: $typeProduit, id: $produitId}
     *
     * ✅ Utilise le scope JSON `applicablesA` du model.
     */
    public function reglesPour(int $partenaireId, ?int $produitId, ?string $typeProduit = 'STOCK'): Collection
    {
        $query = RegleKycPartenaire::where('partenaire_id', $partenaireId)
            ->where('actif', true);

        if ($produitId) {
            $query->applicablesA($typeProduit ?? 'STOCK', $produitId);
        }

        return $query->get()
            ->groupBy('type_document')
            ->map(function ($groupe) use ($produitId) {
                // Priorité aux règles spécifiques au produit
                return $groupe->sortByDesc(function ($r) use ($produitId) {
                    if (! $produitId) return 0;
                    $json = $r->produits_json ?? [];
                    foreach ($json as $p) {
                        if (($p['id'] ?? null) == $produitId) return 1;
                    }
                    return 0;
                })->first();
            })
            ->values();
    }

    /**
     * Évalue les exigences KYC pour un client vis-à-vis d'un partenaire/produit.
     */
    public function evaluer(Client $client, int $partenaireId, ?int $produitId, ?string $typeProduit = 'STOCK'): array
    {
        $regles   = $this->reglesPour($partenaireId, $produitId, $typeProduit);
        $identite = config('dsa_workflow.kyc_defaut.pieces_identite');

        if ($regles->isEmpty()) {
            if (config('dsa_workflow.kyc_sans_regle') !== 'defaut') {
                return [
                    'sans_regle' => true,
                    'complet'    => false,
                    'valide'     => false,
                    'manquantes' => [],
                    'expirees'   => [],
                    'a_valider'  => [],
                ];
            }

            $regles = collect(config('dsa_workflow.kyc_defaut.regles'))->map(
                fn ($type) => new RegleKycPartenaire([
                    'type_document'  => $type,
                    'obligatoire'    => true,
                    'validite_jours' => null,
                ])
            );
        }

        $pieces = KycPiece::whereHas('dossier', fn ($q) => $q
                ->where('client_id', $client->id)
                ->where('statut', '!=', 'REJETE'))
            ->where('statut', '!=', 'REJETE')
            ->get();

        $aSignature = KycDossier::where('client_id', $client->id)
            ->where('statut', '!=', 'REJETE')
            ->whereNotNull('signature_ref')
            ->exists();

        $manquantes = $expirees = $aValider = [];

        foreach ($regles->where('obligatoire', true) as $regle) {
            $type = $regle->type_document;

            if ($type === 'SIGNATURE') {
                if (! $aSignature) $manquantes[] = $type;
                continue;
            }

            $candidates = $type === 'IDENTITE'
                ? $pieces->whereIn('type_document', $identite)
                : $pieces->where('type_document', $type);

            if ($candidates->isEmpty()) {
                $manquantes[] = $type;
                continue;
            }

            $valables = $candidates->filter(fn (KycPiece $p) => $p->estValable($regle->validite_jours));

            if ($valables->isEmpty()) {
                $expirees[] = $type;
                continue;
            }

            if (! $valables->contains('statut', 'VALIDE')) {
                $aValider[] = $type;
            }
        }

        $complet = ! $manquantes && ! $expirees;

        return [
            'sans_regle' => false,
            'complet'    => $complet,
            'valide'     => $complet && ! $aValider,
            'manquantes' => $manquantes,
            'expirees'   => $expirees,
            'a_valider'  => $aValider,
        ];
    }

    public function avancerSiPret(Demande $compte): void
    {
        if ($compte->statut !== 'DOSSIER_SOUMIS' || $compte->client?->kyc_statut !== 'VALIDE') {
            return;
        }

        $eval = $this->evaluer(
            $compte->client,
            $compte->partenaire_id,
            $compte->produit_dsa_id,
            $compte->payload['type_produit'] ?? 'STOCK'   // ⬅️ STOCK par défaut
        );

        if ($eval['valide']) {
            $this->workflow->transitionner($compte, 'KYC_VALIDE', null, null, true);
        }
    }

    public function avancerDossiersEnAttente(int $clientId): void
    {
        Demande::where('type', 'OUVERTURE_COMPTE')
            ->where('client_id', $clientId)
            ->where('statut', 'DOSSIER_SOUMIS')
            ->get()
            ->each(fn (Demande $d) => $this->avancerSiPret($d));
    }
}