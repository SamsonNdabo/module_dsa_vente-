<?php

namespace App\Services\Agent;

use App\Enums\AgentStatut;
use App\Models\AgentDsa;
use App\Models\Role;
use App\Models\Utilisateur;
use App\Models\Zone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AgentDsaService
{
    /**
     * Crée un agent DSA + son compte POS (TOUJOURS).
     * Les données personnelles vont dans Utilisateur, les données métier dans AgentDsa.
     */
    public function creer(array $d, Utilisateur $auteur): array
    {
        return DB::transaction(function () use ($d, $auteur) {
            $zone = Zone::find($d['zone_id']);
            if (! $zone) {
                throw ValidationException::withMessages([
                    'zone_id' => 'La zone sélectionnée est introuvable.',
                ]);
            }

            // 1) Créer l'utilisateur POS (données personnelles)
            $user = $this->creerUtilisateurPos($d, $auteur);

            // 2) Créer l'agent DSA (données métier uniquement)
            $agent = AgentDsa::create([
                'code'                   => 'TMP-' . Str::uuid(),
                'utilisateur_id'         => $user->id,
                'zone_id'                => $zone->id,
                'superviseur_id'         => $zone->superviseur_id,
                'date_embauche'          => $d['date_embauche'],
                'date_resiliation'       => null,
                'statut'                 => AgentStatut::ACTIF,
                'objectifs'              => $d['objectifs'] ?? null,
                'piece_identite_type'    => $d['piece_identite_type'],
                'piece_identite_numero'  => $d['piece_identite_numero'],
                'piece_identite_fichier' => $d['piece_identite_fichier'] ?? null,
                'created_by'             => $auteur->id,
            ]);

            $agent->update(['code' => sprintf('AGT-%05d', $agent->id)]);

            // Historique statut
            $agent->statutHistoriques()->create([
                'ancien_statut'  => null,
                'nouveau_statut' => AgentStatut::ACTIF->value,
                'motif'          => 'Création',
                'effectue_par'   => $auteur->id,
            ]);


            $auteur->logAudit('DSA_AGENT_CREE', null, [
                'agent_id'       => $agent->id,
                'code'           => $agent->code,
                'utilisateur_id' => $user->id,
                'zone_id'        => $zone->id,
            ]);

            return [
                'agent' => $agent->fresh(['utilisateur', 'zone']),
            ];
        });
    }

    /**
     * Crée l'utilisateur POS de l'agent (toujours).
     * ⚠️ TOUTES les données personnelles vont ici (prenom, nom, sexe, province, ville, etc.).
     *    Aucune duplication dans AgentDsa.
     */
    private function creerUtilisateurPos(array $d, Utilisateur $auteur): Utilisateur
    {
        $role = Role::where('code', 'AGENT_DSA')->first();
        if (! $role) {
            throw ValidationException::withMessages([
                'role' => 'Le rôle AGENT_DSA n\'existe pas. Créez-le d\'abord.',
            ]);
        }

        // nom_utilisateur : saisi ou auto-généré
        $nomUtilisateur = $d['nom_utilisateur'] ?? null;
        if (! $nomUtilisateur) {
            $baseNom = strtolower($d['prenom'] . '.' . $d['nom']);
            $baseNom = preg_replace('/[^a-z0-9.]/', '', $baseNom);
            $nomUtilisateur = $baseNom;
            $i = 1;
            while (Utilisateur::where('nom_utilisateur', $nomUtilisateur)->exists()) {
                $nomUtilisateur = $baseNom . $i++;
            }
        }

        // matricule unique
        $matricule = 'AGT-DSA-' . strtoupper(substr(uniqid(), -6));

        // ⚠️ Données personnelles stockées UNIQUEMENT dans Utilisateur
        //    (prenom + nom → nom_complet, province/ville/commune/sexe → préférences JSON
        //     ou colonnes dédiées si vous les ajoutez à `utilisateurs`)
        $preferences = array_filter([
            'sexe'     => $d['sexe'] ?? null,
            'adresse'  => $d['adresse'] ?? null,
            'province' => $d['province'] ?? null,
            'ville'    => $d['ville'] ?? null,
            'commune'  => $d['commune'] ?? null,
            'photo'    => $d['photo_path'] ?? null,
        ], fn ($v) => $v !== null);

        $user = Utilisateur::create([
            'role_id'             => $role->id,
            'succursale_id'       => null,
            'matricule'           => $matricule,
            'nom_complet'         => trim($d['prenom'] . ' ' . $d['nom']),
            'email'               => $d['email'],
            'telephone'           => $d['telephone'] ?? null,
            'nom_utilisateur'     => $nomUtilisateur,
            'password_hash'       => Hash::make($d['mot_de_passe']),
            'statut'              => AgentStatut::ACTIF->value,
            'date_expiration_mdp' => now()->addMonths(3),
            'preferences'         => $preferences ?: null,
            'photo_profil'        => $d['photo_path'] ?? null,
        ]);

        $auteur->logAudit('POS_UTILISATEUR_CREE', null, [
            'utilisateur_id' => $user->id,
            'email'          => $d['email'],
            'role'           => 'AGENT_DSA',
            'source'         => 'creation_agent_dsa',
        ]);

        return $user;
    }

    /**
     * Modification du profil (hors statut et affectation).
     * Met à jour les données métier dans AgentDsa et les données personnelles dans Utilisateur.
     */
    public function modifier(AgentDsa $agent, array $d, Utilisateur $auteur): AgentDsa
    {
        return DB::transaction(function () use ($agent, $d, $auteur) {
            // Champs métier autorisés dans AgentDsa
            $champsAgent = collect($d)->only([
                'objectifs',
                'piece_identite_type',
                'piece_identite_numero',
                'piece_identite_fichier',
                'date_embauche',
            ])->all();

            $avant = $agent->only(array_keys($champsAgent));
            $agent->update($champsAgent);

            // Champs personnels → Utilisateur uniquement (PAS de doublon)
            if ($agent->utilisateur) {
                $user = $agent->utilisateur;

                $personnels = [];
                if (isset($d['prenom'], $d['nom'])) {
                    $personnels['nom_complet'] = trim($d['prenom'] . ' ' . $d['nom']);
                }
                if (isset($d['email']))     { $personnels['email']     = $d['email']; }
                if (isset($d['telephone'])) { $personnels['telephone'] = $d['telephone']; }
                if (isset($d['photo_path'])){ $personnels['photo_profil'] = $d['photo_path']; }

                if ($personnels) {
                    $user->fill($personnels);
                }

                // Le reste (sexe, adresse, province…) → preferences
                $prefs = array_filter([
                    'sexe'     => $d['sexe']     ?? null,
                    'adresse'  => $d['adresse']  ?? null,
                    'province' => $d['province'] ?? null,
                    'ville'    => $d['ville']    ?? null,
                    'commune'  => $d['commune']  ?? null,
                ], fn ($v) => $v !== null);

                if ($prefs) {
                    $user->preferences = array_merge($user->preferences ?? [], $prefs);
                }

                if ($personnels || $prefs) {
                    $user->save();
                }
            }

            $auteur->logAudit('DSA_AGENT_MODIFIE', $avant, $champsAgent);

            return $agent->fresh(['utilisateur', 'zone']);
        });
    }

    public function changerStatut(AgentDsa $agent, AgentStatut $cible, ?string $motif, Utilisateur $auteur): AgentDsa
    {
        return DB::transaction(function () use ($agent, $cible, $motif, $auteur) {
            $agent = AgentDsa::lockForUpdate()->findOrFail($agent->id);

            if (! $agent->statut->peutPasserA($cible)) {
                throw ValidationException::withMessages([
                    'statut' => "Transition {$agent->statut->value} → {$cible->value} non autorisée.",
                ]);
            }

            if ($cible !== AgentStatut::ACTIF && blank($motif)) {
                throw ValidationException::withMessages(['motif' => 'Le motif est obligatoire.']);
            }

            $ancien = $agent->statut;

            $agent->update([
                'statut'           => $cible,
                'date_resiliation' => $cible === AgentStatut::RESILIE
                    ? now()->toDateString()
                    : $agent->date_resiliation,
            ]);

            $agent->statutHistoriques()->create([
                'ancien_statut'  => $ancien->value,
                'nouveau_statut' => $cible->value,
                'motif'          => $motif,
                'effectue_par'   => $auteur->id,
            ]);

            if ($agent->utilisateur) {
                $user = $agent->utilisateur;
                $user->update(['statut' => $cible->value]);

                if ($cible !== AgentStatut::ACTIF) {
                    $user->revokeAllTokens();
                    Cache::forget('user-is-online-' . $user->id);
                }
            }

            $auteur->logAudit('DSA_AGENT_STATUT', ['statut' => $ancien->value], [
                'agent_id' => $agent->id,
                'statut'   => $cible->value,
                'motif'    => $motif,
            ]);

            return $agent->fresh(['utilisateur', 'zone']);
        });
    }

    public function affecter(AgentDsa $agent, int $zoneId, Utilisateur $auteur): AgentDsa
    {
        return DB::transaction(function () use ($agent, $zoneId, $auteur) {
            $zone = Zone::findOrFail($zoneId);

            // Clôturer l'affectation en cours
            $agent->affectations()->whereNull('date_fin')->update(['date_fin' => now()]);

            // ⚠️ superviseur déduit de la zone (pas dupliqué ailleurs)
            $agent->update([
                'zone_id'        => $zone->id,
                'superviseur_id' => $zone->superviseur_id,
            ]);

            $agent->affectations()->create([
                'zone_id'        => $zone->id,
                'superviseur_id' => $zone->superviseur_id,
                'date_debut'     => now(),
                'effectue_par'   => $auteur->id,
            ]);

            $auteur->logAudit('DSA_AGENT_AFFECTE', null, [
                'agent_id'       => $agent->id,
                'zone_id'        => $zone->id,
                'superviseur_id' => $zone->superviseur_id,
            ]);

            return $agent->fresh(['utilisateur', 'zone']);
        });
    }
}