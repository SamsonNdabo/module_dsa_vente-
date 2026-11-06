<?php

namespace App\Workflow;

use App\Models\Kyc\Demande;
use App\Models\Utilisateur;
use App\Services\Client_Kyc\ConsentementService;
use App\Services\Client_Kyc\KycService;
use App\Services\Client_Kyc\RegleKycService;
use App\Services\Client_Kyc\WorkflowService;
use Illuminate\Validation\ValidationException;

class KycHandler implements DemandeHandler
{
    public function avant(Demande $demande, string $cible, ?Utilisateur $acteur, array $donnees): void
    {
        if ($cible === 'SOUMIS') {
            $client = $demande->client;

            if (! $client) {
                throw ValidationException::withMessages([
                    'client_id' => 'Le client associé à cette demande est introuvable.',
                ]);
            }

            $consentement = app(ConsentementService::class);
            if (! $consentement->aConsenti($client, 'KYC_DATA')) {
                throw ValidationException::withMessages([
                    'consentement' => 'Le consentement du client pour le traitement des données KYC est requis avant soumission.',
                ]);
            }

            $manquants = app(KycService::class)->controleSoumission($demande->kycDossier);

            if (! empty($manquants)) {
                throw ValidationException::withMessages([
                    'pieces' => 'Éléments manquants : ' . implode(', ', $manquants),
                ]);
            }
        }
    }

    public function apres(Demande $demande, string $ancien, ?Utilisateur $acteur, array $donnees): void
    {
        $decision = in_array($demande->statut, ['VALIDE', 'REJETE', 'A_CORRIGER'], true);

        if ($demande->kycDossier) {
            $demande->kycDossier->update([
                'statut'       => $demande->statut,
                'decision_at'  => $decision ? now() : null,
                'decision_par' => $decision ? $acteur?->id : null,
            ]);
        }

        if ($demande->client) {
            $demande->client->update(['kyc_statut' => $demande->statut]);
        }

        if ($demande->statut === 'VALIDE' && $demande->kycDossier) {
            $demande->kycDossier->pieces()
                ->where('statut', 'EN_ATTENTE')
                ->update([
                    'statut'      => 'VALIDE',
                    'validee_at'  => now(),
                    'validee_par' => $acteur?->id,
                ]);

            if ($demande->client_id) {
                app(RegleKycService::class)->avancerDossiersEnAttente($demande->client_id);
            }
        }

        if ($demande->statut === 'REJETE' && $demande->client_id) {
            $workflow = app(WorkflowService::class);

            Demande::where('type', 'OUVERTURE_COMPTE')
                ->where('client_id', $demande->client_id)
                ->where('statut', 'DOSSIER_SOUMIS')
                ->get()
                ->each(fn (Demande $c) => $workflow->transitionner($c, 'REJETE', 'KYC rejeté', null, true));
        }
    }
}