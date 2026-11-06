<?php

namespace App\Workflow;

use App\Models\Kyc\Demande;
use App\Models\Utilisateur;
use App\Services\Client_Kyc\ConsentementService;
use App\Services\Client_Kyc\RegleKycService;
use Illuminate\Validation\ValidationException;

class CompteHandler implements DemandeHandler
{
    public function avant(Demande $demande, string $cible, ?Utilisateur $acteur, array $donnees): void
    {
        if ($cible === 'DOSSIER_SOUMIS') {
            if (! $demande->produit_dsa_id || ! $demande->partenaire_id) {
                throw ValidationException::withMessages([
                    'produit_dsa_id' => 'Produit et partenaire obligatoires.',
                ]);
            }

            $consentement = app(ConsentementService::class);
            if (! $consentement->aConsenti($demande->client, 'PARTAGE_PARTENAIRE')) {
                throw ValidationException::withMessages([
                    'consentement' => 'Le consentement du client pour le partage de ses données avec le partenaire est requis avant soumission.',
                ]);
            }

            $typeProduit = $demande->payload['type_produit'] ?? 'STOCK';
            $eval = app(RegleKycService::class)->evaluer(
                $demande->client,
                $demande->partenaire_id,
                $demande->produit_dsa_id,
                $typeProduit
            );

            if ($eval['sans_regle']) {
                throw ValidationException::withMessages([
                    'kyc' => 'Aucune règle KYC définie par ce partenaire pour ce produit.',
                ]);
            }

            if (! $eval['complet']) {
                $messages = [];
                if (! empty($eval['manquantes'])) $messages[] = 'Documents manquants : ' . implode(', ', $eval['manquantes']);
                if (! empty($eval['expirees']))   $messages[] = 'Documents expirés : ' . implode(', ', $eval['expirees']);
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

        // 🆕 ÉPARGNE : si le payload contient compte_epargne_id, mettre à jour le compte épargne
        $compteEpargneId = $demande->payload['compte_epargne_id'] ?? null;
        if ($compteEpargneId) {
            $compteEpargne = \App\Models\CompteEpargne::find($compteEpargneId);
            if ($compteEpargne) {
                if ($demande->statut === 'COMPTE_OUVERT' || $demande->statut === 'ACTIF') {
                    $compteEpargne->update([
                        'statut'            => 'OUVERT',
                        'date_ouverture'    => now()->toDateString(),
                        'numero_compte'     => $donnees['numero_compte'] ?? $compteEpargne->numero_compte,
                    ]);
                } elseif ($demande->statut === 'REJETE') {
                    $compteEpargne->update(['statut' => 'BLOQUE']);
                }
            }
        }

        if ($demande->statut === 'DOSSIER_SOUMIS') {
            app(RegleKycService::class)->avancerSiPret($demande);
        }
    }
}