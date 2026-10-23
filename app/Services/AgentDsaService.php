<?php

namespace App\Services;

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
     *
     * - Zone obligatoire
     * - Superviseur déduit de la zone (zone.superviseur_id)
     * - Succursale non renseignée (agents = zone uniquement)
     * - Mot de passe saisi par l'admin
     *
     * @return array{agent: AgentDsa}
     */
    public function creer(array $d, Utilisateur $auteur): array
    {
        return DB::transaction(function () use ($d, $auteur) {
            // ⚠️ Zone obligatoire
            $zone = Zone::find($d['zone_id']);
            if (! $zone) {
                throw ValidationException::withMessages([
                    'zone_id' => 'La zone sélectionnée est introuvable.',
                ]);
            }

            // ⚠️ TOUJOURS créer l'utilisateur POS
            $user = $this->creerUtilisateurPos($d, $auteur);

            // Créer l'agent DSA
            $agent = AgentDsa::create([
                'code'                   => 'TMP-' . Str::uuid(),
                'utilisateur_id'         => $user->id,
                'zone_id'                => $zone->id,
                'superviseur_id'         => $zone->superviseur_id,
                'prenom'                 => $d['prenom'],
                'nom'                    => $d['nom'],
                'sexe'                   => $d['sexe'],
                'telephone'              => $d['telephone'] ?? null,
                'adresse'                => $d['adresse'] ?? null,
                'province'               => $d['province'],
                'ville'                  => $d['ville'],
                'commune'                => $d['commune'] ?? null,
                'photo_path'             => $d['photo_path'] ?? null,
                'piece_identite_type'    => $d['piece_identite_type'],
                'piece_identite_numero'  => $d['piece_identite_numero'],
                'piece_identite_fichier' => $d['piece_identite_fichier'] ?? null,
                'date_embauche'          => $d['date_embauche'],
                'date_resiliation'       => null,
                'statut'                 => AgentStatut::ACTIF,
                'objectifs'              => $d['objectifs'] ?? null,
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

            // Affectation initiale
            $agent->affectations()->create([
                'zone_id'        => $zone->id,
                'superviseur_id' => $zone->superviseur_id,
                'date_debut'     => now(),
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
     * Mot de passe SAISI par l'admin.
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
        ]);

        $auteur->logAudit('POS_UTILISATEUR_CREE', null, [
            'utilisateur_id' => $user->id,
            'email'          => $d['email'],
            'role'           => 'AGENT_DSA',
            'source'         => 'creation_agent_dsa',
        ]);

        return $user;
    }

    /** Modification du profil (hors statut et affectation). */
    public function modifier(AgentDsa $agent, array $d, Utilisateur $auteur): AgentDsa
    {
        return DB::transaction(function () use ($agent, $d, $auteur) {
            $avant = $agent->only(array_keys($d));

            $agent->update(collect($d)->except(['email', 'telephone'])->all());

            if ($agent->utilisateur) {
                $user = $agent->utilisateur;
                $user->fill(array_filter([
                    'nom_complet' => $agent->nom_complet,
                    'email'       => $d['email'] ?? null,
                    'telephone'   => $d['telephone'] ?? null,
                ]))->save();
            }

            $auteur->logAudit('DSA_AGENT_MODIFIE', $avant, $d);

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
                'date_resiliation' => $cible === AgentStatut::RESILIE ? now()->toDateString() : $agent->date_resiliation,
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

            $agent->affectations()->whereNull('date_fin')->update(['date_fin' => now()]);

            // ⚠️ superviseur déduit de la zone
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