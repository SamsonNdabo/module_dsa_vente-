<?php

namespace App\Services;

use App\Models\ClientDsa;         // ← CORRIGÉ : ClientDsa (pas Client POS)
use App\Models\Demande;
use App\Models\KycDossier;
use App\Models\KycPiece;
use App\Models\RegleKycPartenaire;
use Illuminate\Support\Collection;

/** Évalue un client DSA contre les exigences KYC fixées par le PARTENAIRE. */
class RegleKycService
{
    public function __construct(private WorkflowService $workflow) {}

    /**
     * Règles actives applicables : produit + partenaire (tous produits).
     * Pour un même type_document, la règle du produit prime sur celle du partenaire.
     */
    public function reglesPour(int $partenaireId, ?int $produitId): Collection
    {
        return RegleKycPartenaire::where('partenaire_id', $partenaireId)
            ->where('actif', true)
            ->where(function ($q) use ($produitId) {
                $q->whereNull('produit_dsa_id');
                if ($produitId) {
                    $q->orWhere('produit_dsa_id', $produitId);
                }
            })
            ->get()
            ->groupBy('type_document')
            ->map(fn ($groupe) => $groupe->sortByDesc(fn ($r) => $r->produit_dsa_id !== null ? 1 : 0)->first())
            ->values();
    }

    /**
     * @return array{sans_regle: bool, complet: bool, valide: bool, manquantes: array, expirees: array, a_valider: array}
     */
    public function evaluer(ClientDsa $client, int $partenaireId, ?int $produitId): array   // ← CORRIGÉ
    {
        $regles = $this->reglesPour($partenaireId, $produitId);
        $identite = config('dsa_workflow.kyc_defaut.pieces_identite');

        if ($regles->isEmpty()) {
            if (config('dsa_workflow.kyc_sans_regle') !== 'defaut') {
                return ['sans_regle' => true, 'complet' => false, 'valide' => false,
                    'manquantes' => [], 'expirees' => [], 'a_valider' => []];
            }

            $regles = collect(config('dsa_workflow.kyc_defaut.regles'))->map(
                fn ($type) => new RegleKycPartenaire(['type_document' => $type, 'obligatoire' => true, 'validite_jours' => null])
            );
        }

        // ← CORRIGÉ : client_dsa_id au lieu de client_id
        $pieces = KycPiece::whereHas('dossier', fn ($q) => $q->where('client_dsa_id', $client->id)->where('statut', '!=', 'REJETE'))
            ->where('statut', '!=', 'REJETE')
            ->get();

        // ← CORRIGÉ : client_dsa_id
        $aSignature = KycDossier::where('client_dsa_id', $client->id)
            ->where('statut', '!=', 'REJETE')->whereNotNull('signature_ref')->exists();

        $manquantes = $expirees = $aValider = [];

        foreach ($regles->where('obligatoire', true) as $regle) {
            $type = $regle->type_document;

            if ($type === 'SIGNATURE') {
                if (! $aSignature) {
                    $manquantes[] = $type;
                }
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

    /** Fait avancer DOSSIER_SOUMIS → KYC_VALIDE si le KYC du client est validé ET les exigences remplies. */
    public function avancerSiPret(Demande $compte): void
    {
        if ($compte->statut !== 'DOSSIER_SOUMIS' || $compte->client?->kyc_statut !== 'VALIDE') {
            return;
        }

        $eval = $this->evaluer($compte->client, $compte->partenaire_id, $compte->produit_dsa_id);

        if ($eval['valide']) {
            $this->workflow->transitionner($compte, 'KYC_VALIDE', null, null, true);
        }
    }

    public function avancerDossiersEnAttente(int $clientDsaId): void   // ← param renommé
    {
        Demande::where('type', 'OUVERTURE_COMPTE')
            ->where('client_dsa_id', $clientDsaId)                     // ← CORRIGÉ
            ->where('statut', 'DOSSIER_SOUMIS')
            ->get()
            ->each(fn (Demande $d) => $this->avancerSiPret($d));
    }
}