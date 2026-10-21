<?php

namespace App\Services;

use App\Models\AgentDsa;
use App\Models\Client;
use App\Models\ClientDsa;
use App\Models\Utilisateur;
use App\Services\ObjectifMesurableService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClientDsaService
{
    public function __construct(private SequenceService $sequences) {}

    /**
     * Crée un client DSA selon 3 cas :
     *  - Cas 1  : lien vers un Client POS existant (client_id fourni)
     *  - Cas 2a : client DSA seul (client_id = NULL)
     *  - Cas 2b : création d'un nouveau Client POS + lien automatique (creer_client_pos = true)
     */
    public function creer(array $data, Utilisateur $auteur): ClientDsa
    {
        return DB::transaction(function () use ($data, $auteur) {
            $clientId   = $data['client_id'] ?? null;
            $clientPos  = null;

            // Cas 2b : créer un nouveau client POS
            if (! $clientId && ($data['creer_client_pos'] ?? false)) {
                $clientPos = $this->creerClientPos($data);
                $clientId  = $clientPos->id;
            }

            // Cas 1 : vérifier le client POS
            if ($clientId && ! $clientPos) {
                $clientPos = Client::find($clientId);
                if (! $clientPos) {
                    throw ValidationException::withMessages([
                        'client_id' => 'Le client POS sélectionné est introuvable.',
                    ]);
                }
            }

            // Anti-doublon : téléphone normalisé + nom complet
            $telNormalise = $this->normaliserTelephone($data['telephone']);
            $doublon = ClientDsa::where('telephone_normalise', $telNormalise)
                ->where('nom_complet', $data['nom_complet'])
                ->exists();

            if ($doublon) {
                throw ValidationException::withMessages([
                    'telephone' => 'Un client DSA avec ce téléphone et ce nom existe déjà.',
                ]);
            }

            // Idempotence offline
            if (! empty($data['local_uuid'])) {
                $existant = ClientDsa::where('local_uuid', $data['local_uuid'])->first();
                if ($existant) {
                    return $existant;
                }
            }

            $agent = AgentDsa::find($data['agent_dsa_id'] ?? null);

            $clientDsa = ClientDsa::create([
                'client_id'              => $clientId,
                'agent_dsa_id'           => $data['agent_dsa_id'] ?? null,
                'zone_id'                => $data['zone_id'] ?? $agent?->zone_id,
                'code_client'            => $this->sequences->suivant('CLIENT', 'CLI'),
                'nom_complet'            => $data['nom_complet'],
                'sexe'                   => $data['sexe'],
                'date_naissance'         => $data['date_naissance'],
                'adresse'                => $data['adresse'],
                'telephone'              => $data['telephone'],
                'telephone_normalise'    => $telNormalise,
                'email'                  => $data['email'] ?? null,
                'profession'             => $data['profession'],
                'activite_economique'    => $data['activite_economique'],
                'piece_identite_type'    => $data['piece_identite_type'] ?? null,
                'piece_identite_numero'  => $data['piece_identite_numero'] ?? null,
                'kyc_statut'             => 'AUCUN',
                'statut'                 => 'ACTIF',
                'source'                 => 'DSA',
                'local_uuid'             => $data['local_uuid'] ?? null,
                'metadata'               => $data['metadata'] ?? null,
                'cree_par'               => $auteur->id,
            ]);

            $auteur->logAudit('DSA_CLIENT_CREE', null, [
                'client_dsa_id' => $clientDsa->id,
                'code_client'   => $clientDsa->code_client,
                'client_pos_id' => $clientId,
            ]);
            // 🎯 Incrément automatique de l'objectif CLIENTS si l'agent est en campagne ACTIVE
            if ($clientDsa->agent_dsa_id) {
                try {
                    app(ObjectifMesurableService::class)
                        ->incrementerObjectifsClients($clientDsa->agent_dsa_id, $auteur);
                } catch (\Exception $e) {
                    \Log::warning('[CLIENT-DSA] Incrément objectif CLIENTS échoué', [
                        'client_dsa_id' => $clientDsa->id,
                        'agent_id'      => $clientDsa->agent_dsa_id,
                        'error'         => $e->getMessage(),
                    ]);
                }
            }

            return $clientDsa->load(['clientPos', 'agentDsa', 'zone']);
        });
    }

    public function modifier(ClientDsa $clientDsa, array $data, Utilisateur $auteur): ClientDsa
    {
        return DB::transaction(function () use ($clientDsa, $data, $auteur) {
            $avant = $clientDsa->only(array_keys($data));

            // Recalcul téléphone normalisé si téléphone changé
            if (array_key_exists('telephone', $data) && $data['telephone'] !== $clientDsa->telephone) {
                $data['telephone_normalise'] = $this->normaliserTelephone($data['telephone']);

                // Re-vérifier anti-doublon
                $doublon = ClientDsa::where('telephone_normalise', $data['telephone_normalise'])
                    ->where('nom_complet', $data['nom_complet'] ?? $clientDsa->nom_complet)
                    ->where('id', '!=', $clientDsa->id)
                    ->exists();

                if ($doublon) {
                    throw ValidationException::withMessages([
                        'telephone' => 'Un client DSA avec ce téléphone et ce nom existe déjà.',
                    ]);
                }
            }

            $data['modifie_par'] = $auteur->id;
            $clientDsa->update($data);

            $auteur->logAudit('DSA_CLIENT_MODIFIE', $avant, $data);

            return $clientDsa->fresh(['clientPos', 'agentDsa', 'zone']);
        });
    }

    public function changerStatut(ClientDsa $clientDsa, string $statut, Utilisateur $auteur): ClientDsa
    {
        if (! in_array($statut, ClientDsa::STATUTS, true)) {
            throw ValidationException::withMessages([
                'statut' => 'Statut invalide.',
            ]);
        }

        $clientDsa->update(['statut' => $statut, 'modifie_par' => $auteur->id]);
        $auteur->logAudit('DSA_CLIENT_STATUT', null, [
            'client_dsa_id' => $clientDsa->id,
            'statut'        => $statut,
        ]);

        return $clientDsa->fresh();
    }

    /* ------------------------------------------------------------------ */
    /* Helpers privés                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Normalise un numéro de téléphone : chiffres uniquement + préfixe +243.
     */
    private function normaliserTelephone(string $tel): string
    {
        $digits = preg_replace('/\D/', '', $tel);

        // Retire le 0 initial (format RDC local)
        $digits = ltrim($digits, '0');

        // Si déjà préfixé +243 (243 au début), on garde
        if (str_starts_with($digits, '243')) {
            return '+' . $digits;
        }

        return '+243' . $digits;
    }

    /**
     * Crée un Client POS à partir des données DSA.
     * Reprend nom_complet, email, telephone, adresse.
     */
    private function creerClientPos(array $data): Client
    {
        // Vérifier l'unicité email côté POS
        if (! empty($data['email']) && Client::where('email', $data['email'])->exists()) {
            throw ValidationException::withMessages([
                'email' => 'Cet email existe déjà dans le POS.',
            ]);
        }

        return Client::create([
            'nom_complet'      => $data['nom_complet'],
            'email'            => $data['email'] ?? null,
            'telephone'        => $data['telephone'],
            'adresse'          => $data['adresse'],
            'categorie_client' => 'STANDARD',
            'statut'           => 'ACTIF',
            'nombre_achats'    => 0,
            'total_achats'     => 0,
            'password'         => null, // pas de login POS pour un client créé depuis DSA
        ]);
    }
}