<?php

namespace App\Services;

use App\Models\AgentDsa;
use App\Models\Client;
use App\Models\Utilisateur;
use App\Services\ObjectifMesurableService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClientDsaService
{
    public function __construct(private SequenceService $sequences) {}

    /**
     * Crée un Client (unifié POS + DSA).
     *
     * Plus de distinction POS/DSA : tous les clients sont dans `clients`.
     * Type de client : PERSONNE_PHYSIQUE / ENTREPRISE / AUTRE.
     */
    public function creer(array $data, Utilisateur $auteur): Client
    {
        return DB::transaction(function () use ($data, $auteur) {
            // ─── Anti-doublon : téléphone normalisé + nom complet
            $telNormalise = $this->normaliserTelephone($data['telephone']);
            $doublon = Client::where('telephone_normalise', $telNormalise)
                ->where('nom_complet', $data['nom_complet'])
                ->exists();

            if ($doublon) {
                throw ValidationException::withMessages([
                    'telephone' => 'Un client avec ce téléphone et ce nom existe déjà.',
                ]);
            }

            // ─── Idempotence offline
            if (! empty($data['local_uuid'])) {
                $existant = Client::where('local_uuid', $data['local_uuid'])->first();
                if ($existant) {
                    return $existant;
                }
            }

            $agent = AgentDsa::find($data['agent_dsa_id'] ?? null);

            $client = Client::create([
                // Rattachement
                'agent_dsa_id'        => $data['agent_dsa_id'] ?? null,
                'zone_id'             => $data['zone_id'] ?? $agent?->zone_id,

                // Identité
                'code_client'         => $this->sequences->suivant('CLIENT', 'CLI'),
                'type_client'         => $data['type_client'],
                'nom_complet'         => $data['nom_complet'],
                'sexe'                => $data['sexe'] ?? null,
                'date_naissance'      => $data['date_naissance'] ?? null,

                // Contact
                'telephone'           => $data['telephone'],
                'telephone_normalise' => $telNormalise,
                'email'               => $data['email'] ?? null,
                'adresse'             => $data['adresse'],

                // Personne physique
                'profession'          => $data['profession'] ?? null,
                'activite_economique' => $data['activite_economique'] ?? null,

                // Entreprise
                'raison_sociale'      => $data['raison_sociale'] ?? null,
                'numero_rc'           => $data['numero_rc'] ?? null,
                'numero_impot'        => $data['numero_impot'] ?? null,
                'representant_legal'  => $data['representant_legal'] ?? null,

                // Identité légale
                'piece_identite_type'   => $data['piece_identite_type'] ?? null,
                'piece_identite_numero' => $data['piece_identite_numero'] ?? null,

                // Statuts
                'categorie_client'    => 'STANDARD',
                'kyc_statut'          => 'AUCUN',
                'statut'              => 'ACTIF',
                'source'              => 'DSA',

                // Divers
                'local_uuid'          => $data['local_uuid'] ?? null,
                'metadata'            => $data['metadata'] ?? null,
                'cree_par'            => $auteur->id,
            ]);

            $auteur->logAudit('DSA_CLIENT_CREE', null, [
                'client_id'   => $client->id,
                'code_client' => $client->code_client,
                'type_client' => $client->type_client,
            ]);

            // 🎯 Incrément automatique de l'objectif CLIENTS
            if ($client->agent_dsa_id) {
                try {
                    app(ObjectifMesurableService::class)
                        ->incrementerObjectifsClients($client->agent_dsa_id, $auteur);
                } catch (\Exception $e) {
                    \Log::warning('[CLIENT] Incrément objectif CLIENTS échoué', [
                        'client_id' => $client->id,
                        'agent_id'  => $client->agent_dsa_id,
                        'error'     => $e->getMessage(),
                    ]);
                }
            }

            return $client->load(['agentDsa', 'zone']);
        });
    }

    public function modifier(Client $client, array $data, Utilisateur $auteur): Client
    {
        return DB::transaction(function () use ($client, $data, $auteur) {
            $avant = $client->only(array_keys($data));

            // Recalcul téléphone normalisé si téléphone changé
            if (array_key_exists('telephone', $data) && $data['telephone'] !== $client->telephone) {
                $data['telephone_normalise'] = $this->normaliserTelephone($data['telephone']);

                $doublon = Client::where('telephone_normalise', $data['telephone_normalise'])
                    ->where('nom_complet', $data['nom_complet'] ?? $client->nom_complet)
                    ->where('id', '!=', $client->id)
                    ->exists();

                if ($doublon) {
                    throw ValidationException::withMessages([
                        'telephone' => 'Un client avec ce téléphone et ce nom existe déjà.',
                    ]);
                }
            }

            $data['modifie_par'] = $auteur->id;
            $client->update($data);

            $auteur->logAudit('DSA_CLIENT_MODIFIE', $avant, $data);

            return $client->fresh(['agentDsa', 'zone']);
        });
    }

    public function changerStatut(Client $client, string $statut, Utilisateur $auteur): Client
    {
        if (! in_array($statut, Client::STATUTS, true)) {
            throw ValidationException::withMessages([
                'statut' => 'Statut invalide.',
            ]);
        }

        $client->update(['statut' => $statut, 'modifie_par' => $auteur->id]);
        $auteur->logAudit('DSA_CLIENT_STATUT', null, [
            'client_id' => $client->id,
            'statut'    => $statut,
        ]);

        return $client->fresh();
    }

    /* ------------------------------------------------------------------ */
    /* Helpers privés                                                     */
    /* ------------------------------------------------------------------ */

    private function normaliserTelephone(string $tel): string
    {
        $digits = preg_replace('/\D/', '', $tel);
        $digits = ltrim($digits, '0');

        if (str_starts_with($digits, '243')) {
            return '+' . $digits;
        }

        return '+243' . $digits;
    }
}