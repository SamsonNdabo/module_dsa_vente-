<?php

namespace App\Services;

use App\Models\AgentDsa;
use App\Models\Client;         // ← CHANGÉ
use App\Models\Demande;
use App\Models\KycDossier;
use App\Models\KycPiece;
use App\Models\Utilisateur;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class KycService
{
    private const ETATS_AJOUT_PIECE = ['BROUILLON', 'A_CORRIGER', 'VALIDE'];
    private const ETATS_SIGNATURE   = ['BROUILLON', 'A_CORRIGER'];

    public function __construct(
        private WorkflowService $workflow,
        private RegleKycService $regles
    ) {}

    public function ouvrirDossier(Client $client, Utilisateur $auteur, ?AgentDsa $agent, ?string $localUuid = null): Demande
    {
        return DB::transaction(function () use ($client, $auteur, $agent, $localUuid) {
            Client::lockForUpdate()->findOrFail($client->id);

            $actif = Demande::where('type', 'KYC')
                ->where('client_id', $client->id)            // ← CHANGÉ
                ->where('statut', '!=', 'REJETE')
                ->first();

            if ($actif) {
                return $actif;
            }

            $demande = $this->workflow->creer('KYC', [
                'client_id'    => $client->id,               // ← CHANGÉ
                'agent_dsa_id' => $agent?->id ?? $client->agent_dsa_id,
                'local_uuid'   => $localUuid,
            ], $auteur);

            KycDossier::create([
                'client_id'  => $client->id,                 // ← CHANGÉ
                'demande_id' => $demande->id,
                'statut'     => $demande->statut,
            ]);

            $client->update(['kyc_statut' => $demande->statut]);

            return $demande;
        });
    }

    public function ajouterPiece(Demande $demande, array $d, UploadedFile $fichier): KycPiece
    {
        $this->exigerEtat($demande, self::ETATS_AJOUT_PIECE);

        $chemin = $fichier->store("dsa/kyc/{$demande->client_id}", 'local');   // ← CHANGÉ

        return $demande->kycDossier->pieces()->create([
            'type_document'   => $d['type_document'],
            'numero'          => $d['numero'] ?? null,
            'date_expiration' => $d['date_expiration'] ?? null,
            'fichier_ref'     => $chemin,
            'mime_type'       => $fichier->getMimeType(),
            'taille_octets'   => $fichier->getSize(),
            'statut'          => 'EN_ATTENTE',
        ]);
    }

    public function enregistrerSignature(Demande $demande, UploadedFile $fichier): KycDossier
    {
        $this->exigerEtat($demande, self::ETATS_SIGNATURE);

        $demande->kycDossier->update([
            'signature_ref' => $fichier->store("dsa/kyc/{$demande->client_id}/signature", 'local'),   // ← CHANGÉ
        ]);

        return $demande->kycDossier;
    }

    public function controleSoumission(KycDossier $dossier): array
    {
        $min = config('dsa_workflow.kyc_dossier_minimum');
        $manquants = [];

        if ($dossier->pieces()->where('statut', '!=', 'REJETE')->count() < $min['pieces']) {
            $manquants[] = "au moins {$min['pieces']} pièce(s)";
        }
        if ($min['signature'] && ! $dossier->signature_ref) {
            $manquants[] = 'signature';
        }

        return $manquants;
    }

    public function validerPiece(KycPiece $piece, string $statut, ?string $motif, Utilisateur $acteur): KycPiece
    {
        if (! in_array($statut, ['VALIDE', 'REJETE'], true)) {
            throw ValidationException::withMessages(['statut' => 'Statut de pièce invalide.']);
        }
        if ($statut === 'REJETE' && blank($motif)) {
            throw ValidationException::withMessages(['motif' => 'Le motif est obligatoire pour un rejet.']);
        }

        return DB::transaction(function () use ($piece, $statut, $motif, $acteur) {
            $piece = KycPiece::lockForUpdate()->findOrFail($piece->id);

            if ($piece->statut !== 'EN_ATTENTE') {
                throw ValidationException::withMessages(['statut' => "Pièce déjà traitée ({$piece->statut})."]);
            }

            $piece->update([
                'statut'      => $statut,
                'validee_at'  => $statut === 'VALIDE' ? now() : null,
                'validee_par' => $acteur->id,
                'motif_rejet' => $statut === 'REJETE' ? $motif : null,
            ]);

            $acteur->logAudit('DSA_KYC_PIECE_' . $statut, null, ['piece_id' => $piece->id, 'motif' => $motif]);

            if ($statut === 'VALIDE') {
                $clientId = $piece->dossier?->client_id;

                if ($clientId) {
                    $this->regles->avancerDossiersEnAttente($clientId);
                } else {
                    \Log::warning('[KYC] Pièce validée mais dossier sans client_id', [
                        'piece_id'   => $piece->id,
                        'dossier_id' => $piece->kyc_dossier_id,
                    ]);
                }
            }

            return $piece;
        });
    }

    private function exigerEtat(Demande $demande, array $autorises): void
    {
        if (! in_array($demande->statut, $autorises, true)) {
            throw ValidationException::withMessages([
                'statut' => "Dossier {$demande->statut} : modification interdite (en cours de validation ou clôturé).",
            ]);
        }
    }
}