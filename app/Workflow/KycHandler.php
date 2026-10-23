<?php

namespace App\Workflow;

use App\Models\Demande;
use App\Models\Utilisateur;
use App\Services\ConsentementService;
use App\Services\KycService;
use App\Services\RegleKycService;
use App\Services\WorkflowService;
use Illuminate\Validation\ValidationException;

class KycHandler implements DemandeHandler
{
    public function avant(Demande $demande, string $cible, ?Utilisateur $acteur, array $donnees): void
    {
        if ($cible === 'SOUMIS') {
            // 1. Consentement KYC_DATA obligatoire pour soumettre
            $client = $demande->client;

            if (! $client) {
                throw ValidationException::withMessages([
                    'client_id' => 'Le client associé à cette demande est introuvable. La demande est obsolète (créée avant la fusion clients_dsa → clients).',
                ]);
            }

            $consentement = app(ConsentementService::class);
            if (! $consentement->aConsenti($client, 'KYC_DATA')) {
                throw ValidationException::withMessages([
                    'consentement' => 'Le consentement du client pour le traitement des données KYC est requis avant soumission.',
                ]);
            }

            // 2. Contrôle minimal du dossier (pièces + signature)
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

        // 1. Mise à jour du dossier KYC
        if ($demande->kycDossier) {
            $demande->kycDossier->update([
                'statut'       => $demande->statut,
                'decision_at'  => $decision ? now() : null,
                'decision_par' => $decision ? $acteur?->id : null,
            ]);
        }

        // 2. Mise à jour du statut KYC du client (denormalized)
        if ($demande->client) {
            $demande->client->update(['kyc_statut' => $demande->statut]);
        }

        // 3. Auto-validation des pièces EN_ATTENTE si dossier VALIDE
        if ($demande->statut === 'VALIDE' && $demande->kycDossier) {
            $demande->kycDossier->pieces()
                ->where('statut', 'EN_ATTENTE')
                ->update([
                    'statut'      => 'VALIDE',
                    'validee_at'  => now(),
                    'validee_par' => $acteur?->id,
                ]);

            // Faire avancer les demandes d'ouverture de compte en attente
            if ($demande->client_id) {
                app(RegleKycService::class)->avancerDossiersEnAttente($demande->client_id);
            }
        }

        // 4. Cascade REJET → rejeter les ouvertures de compte en attente
        if ($demande->statut === 'REJETE' && $demande->client_id) {
            $workflow = app(WorkflowService::class);

            Demande::where('type', 'OUVERTURE_COMPTE')
                ->where('client_id', $demande->client_id)
                ->where('statut', 'DOSSIER_SOUMIS')
                ->get()
                ->each(fn (Demande $c) => $workflow->transitionner(
                    $c,
                    'REJETE',
                    'KYC rejeté',
                    null,
                    true
                ));
        }
    }
}