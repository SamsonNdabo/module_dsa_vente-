<?php

namespace App\Workflow;

use App\Models\Demande;
use App\Models\Utilisateur;
use App\Services\ConsentementService;
use App\Services\RegleKycService;
use Illuminate\Validation\ValidationException;

class CompteHandler implements DemandeHandler
{
    public function avant(Demande $demande, string $cible, ?Utilisateur $acteur, array $donnees): void
    {
        if ($cible === 'DOSSIER_SOUMIS') {
            // 1. Produit et partenaire obligatoires
            if (! $demande->produit_dsa_id || ! $demande->partenaire_id) {
                throw ValidationException::withMessages([
                    'produit_dsa_id' => 'Produit et partenaire obligatoires.',
                ]);
            }

            // 2. Consentement PARTAGE_PARTENAIRE obligatoire
            $consentement = app(ConsentementService::class);
            if (! $consentement->aConsenti($demande->client, 'PARTAGE_PARTENAIRE')) {
                throw ValidationException::withMessages([
                    'consentement' => 'Le consentement du client pour le partage de ses données avec le partenaire est requis avant soumission.',
                ]);
            }

            // 3. Évaluation des règles KYC du partenaire
            $eval = app(RegleKycService::class)->evaluer(
                $demande->client,
                $demande->partenaire_id,
                $demande->produit_dsa_id
            );

            if ($eval['sans_regle']) {
                throw ValidationException::withMessages([
                    'kyc' => 'Aucune règle KYC définie par ce partenaire pour ce produit : configuration requise avant soumission.',
                ]);
            }

            if (! $eval['complet']) {
                $messages = [];
                if (! empty($eval['manquantes'])) {
                    $messages[] = 'Documents manquants : ' . implode(', ', $eval['manquantes']);
                }
                if (! empty($eval['expirees'])) {
                    $messages[] = 'Documents expirés ou hors durée de validité : ' . implode(', ', $eval['expirees']);
                }
                throw ValidationException::withMessages(['kyc' => $messages]);
            }
        }

        if ($cible === 'COMPTE_OUVERT' && blank($donnees['numero_compte'] ?? null)) {
            throw ValidationException::withMessages([
                'numero_compte' => 'Le numéro de compte est obligatoire.',
            ]);
        }
    }

    public function apres(Demande $demande, string $ancien, ?Utilisateur $acteur, array $donnees): void
    {
        $maj = ['statut' => $demande->statut];

        if ($demande->statut === 'COMPTE_OUVERT') {
            $maj += [
                'numero_compte'        => $donnees['numero_compte'],
                'reference_partenaire' => $donnees['reference_partenaire'] ?? null,
                'date_ouverture'       => now(),
            ];
        } elseif ($demande->statut === 'ACTIF') {
            $maj += ['date_activation' => now()];
        } elseif ($demande->statut === 'REJETE') {
            $maj += ['motif_rejet' => $demande->motif_decision];
        }

        if ($demande->compte) {
            $demande->compte->update($maj);
        }

        // KYC déjà validé et exigences remplies : on avance automatiquement
        if ($demande->statut === 'DOSSIER_SOUMIS') {
            app(RegleKycService::class)->avancerSiPret($demande);
        }
    }
}