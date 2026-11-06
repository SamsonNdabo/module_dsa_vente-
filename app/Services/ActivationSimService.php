<?php

namespace App\Services;

use App\Models\ActivationSim;
use App\Models\Client;
use App\Models\Facture;
use App\Models\Produit;
use App\Models\Utilisateur;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ActivationSimService
{
    public function __construct(private SequenceService $sequences) {}

    /**
     * Activer une SIM en une seule action : preuve + activation.
     *
     * Le client et l'agent sont auto-remplis :
     *  - client_id : passé explicitement OU déduit via facture/commande
     *  - agent_dsa_id : utilisateur connecté (si agent DSA) OU auteur
     *  - activated_at : now()
     *
     * La preuve (photo fichier opérateur) est OBLIGATOIRE.
     */
    public function activer(
        array $data,
        UploadedFile $preuve,
        Utilisateur $auteur
    ): ActivationSim {
        return DB::transaction(function () use ($data, $preuve, $auteur) {
            // 1. Résolution client (obligatoire)
            $clientId = $data['client_id'] ?? null;
            $factureId = $data['facture_id'] ?? null;
            $commandeId = $data['commande_id'] ?? null;

            if (! $clientId && $factureId) {
                $facture = Facture::find($factureId);
                $clientId = $facture?->client_id;
            }
            if (! $clientId && $commandeId) {
                $commande = \App\Models\CommandeClient::find($commandeId);
                $clientId = $commande?->client_id;
            }

            if (! $clientId) {
                throw ValidationException::withMessages([
                    'client_id' => 'Client obligatoire (ou facture/commande liée).',
                ]);
            }

            $client = Client::findOrFail($clientId);

            // 2. Résolution produit (obligatoire)
            $produit = Produit::findOrFail($data['produit_id']);
            if (! $produit->actif) {
                throw ValidationException::withMessages([
                    'produit_id' => "Le produit '{$produit->nom}' est inactif.",
                ]);
            }
            if (! $produit->gere_imei) {
                throw ValidationException::withMessages([
                    'produit_id' => "Le produit '{$produit->nom}' ne nécessite pas d'activation SIM.",
                ]);
            }

            // 3. Identifiant (obligatoire, unique)
            $identifiant = trim((string) $data['identifiant']);
            if ($identifiant === '') {
                throw ValidationException::withMessages([
                    'identifiant' => "L'identifiant SIM (ICCID/n° série) est obligatoire.",
                ]);
            }
            if (ActivationSim::where('identifiant', $identifiant)->exists()) {
                throw ValidationException::withMessages([
                    'identifiant' => "Cet identifiant '{$identifiant}' est déjà enregistré.",
                ]);
            }

            // 4. Référence
            $reference = $this->sequences->suivant('ACTIVATION_SIM', 'SIM');

            // 5. Agent = utilisateur connecté (ou agent DSA lié au client)
            $agentId = $auteur->agentDsa?->id ?? $client->agent_dsa_id;

            // 6. Créer l'activation AVEC la preuve en une seule fois
            $activation = ActivationSim::create([
                'reference'          => $reference,
                'client_id'          => $client->id,
                'agent_dsa_id'       => $agentId,
                'produit_id'         => $produit->id,
                'facture_id'         => $factureId,
                'commande_id'        => $commandeId,
                'identifiant'        => $identifiant,
                'statut'             => 'ACTIVEE',
                'activated_at'       => now(),
                'notes'              => $data['notes'] ?? null,
            ]);

            // 7. Stocker la preuve
            $chemin = $preuve->store("dsa/activations_sim/{$activation->id}", 'local');

            $activation->update([
                'preuve_fichier_ref'   => $chemin,
                'preuve_mime_type'     => $preuve->getMimeType(),
                'preuve_taille_octets' => $preuve->getSize(),
                'preuve_uploaded_by'   => $auteur->id,
            ]);

            // 8. Incrément objectif SIM (best effort, ne bloque pas)
            if ($agentId) {
                try {
                    app(ObjectifMesurableService::class)
                        ->incrementerObjectifsSim($agentId, $auteur);
                } catch (\Exception $e) {
                    Log::warning('[SIM] Incrément objectif SIM échoué', [
                        'activation_id' => $activation->id,
                        'agent_id'      => $agentId,
                        'error'         => $e->getMessage(),
                    ]);
                }
            }

            // 9. Audit
            $auteur->logAudit('SIM_ACTIVEE', null, [
                'activation_id' => $activation->id,
                'reference'     => $activation->reference,
                'identifiant'   => $activation->identifiant,
            ]);

            return $activation->fresh();
        });
    }

    /**
     * Supprimer une activation (rare — réservé aux admin).
     */
    public function supprimer(ActivationSim $activation, Utilisateur $auteur): void
    {
        DB::transaction(function () use ($activation, $auteur) {
            if ($activation->preuve_fichier_ref) {
                Storage::disk('local')->delete($activation->preuve_fichier_ref);
            }

            $auteur->logAudit('SIM_ACTIVATION_SUPPRIMEE', null, [
                'activation_id' => $activation->id,
                'reference'     => $activation->reference,
            ]);

            $activation->delete();
        });
    }

    /**
     * Télécharger la preuve.
     */
    public function telechargerPreuve(ActivationSim $activation): array
    {
        if (! $activation->preuve_fichier_ref) {
            throw ValidationException::withMessages([
                'preuve' => 'Aucune preuve uploadée pour cette activation.',
            ]);
        }

        $path = Storage::disk('local')->path($activation->preuve_fichier_ref);

        if (! file_exists($path)) {
            throw ValidationException::withMessages([
                'preuve' => 'Fichier de preuve introuvable sur le disque.',
            ]);
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return [
            'path'         => $path,
            'mime_type'    => $activation->preuve_mime_type ?? 'application/octet-stream',
            'nom_original' => 'preuve_sim_' . $activation->reference . '.' . $extension,
        ];
    }
}