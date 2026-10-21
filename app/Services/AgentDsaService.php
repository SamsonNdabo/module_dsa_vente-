<?php

namespace App\Services;

use App\Enums\AgentStatut;
use App\Models\AgentDsa;
use App\Models\Role;
use App\Models\Utilisateur;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AgentDsaService
{
    /**
     * Crée le compte Utilisateur + le profil agent dans une seule transaction.
     * Retourne [agent, mot_de_passe_temporaire] : à communiquer une seule fois (mail/SMS).
     */
    public function creer(array $d, Utilisateur $auteur): array
    {
        return DB::transaction(function () use ($d, $auteur) {
            $role = Role::where('code', 'AGENT_DSA')->firstOrFail();
            $motDePasse = Str::password(12);

            $user = Utilisateur::create([
                'nom_complet' => trim($d['prenom'].' '.$d['nom']),
                'email' => $d['email'],
                'telephone' => $d['telephone'],
                'nom_utilisateur' => $d['nom_utilisateur'],
                'password_hash' => Hash::make($motDePasse),
                'role_id' => $role->id,
                'succursale_id' => $d['succursale_id'] ?? null,
                'statut' => AgentStatut::ACTIF->value,
                'date_expiration_mdp' => now(), // mot de passe temporaire : changement à imposer au 1er login
            ]);

            $agent = AgentDsa::create([
                'code' => 'TMP-'.Str::uuid(), // remplacé juste après (à migrer vers la table de séquences)
                'utilisateur_id' => $user->id,
                'succursale_id' => $d['succursale_id'] ?? null,
                'zone_id' => $d['zone_id'] ?? null,
                'superviseur_id' => $d['superviseur_id'] ?? null,
                'prenom' => $d['prenom'],
                'nom' => $d['nom'],
                'sexe' => $d['sexe'],
                'adresse' => $d['adresse'] ?? null,
                'province' => $d['province'],
                'ville' => $d['ville'],
                'commune' => $d['commune'] ?? null,
                'photo_path' => $d['photo_path'] ?? null,
                'piece_identite_type' => $d['piece_identite_type'],
                'piece_identite_numero' => $d['piece_identite_numero'],
                'piece_identite_fichier' => $d['piece_identite_fichier'] ?? null,
                'date_embauche' => $d['date_embauche'],
                'statut' => AgentStatut::ACTIF,
                'created_by' => $auteur->id,
            ]);
            $agent->update(['code' => sprintf('AGT-%05d', $agent->id)]);

            $agent->statutHistoriques()->create([
                'ancien_statut' => null,
                'nouveau_statut' => AgentStatut::ACTIF->value,
                'motif' => 'Création',
                'effectue_par' => $auteur->id,
            ]);

            $agent->affectations()->create([
                'zone_id' => $agent->zone_id,
                'superviseur_id' => $agent->superviseur_id,
                'date_debut' => now(),
                'effectue_par' => $auteur->id,
            ]);

            $auteur->logAudit('DSA_AGENT_CREE', null, ['agent_id' => $agent->id, 'code' => $agent->code]);

            return [$agent->fresh(['utilisateur', 'zone']), $motDePasse];
        });
    }

    /** Modification du profil (hors statut et affectation). Garde l'Utilisateur synchronisé. */
    public function modifier(AgentDsa $agent, array $d, Utilisateur $auteur): AgentDsa
    {
        return DB::transaction(function () use ($agent, $d, $auteur) {
            $avant = $agent->only(array_keys($d));

            $agent->update(collect($d)->except(['email', 'telephone'])->all());

            $user = $agent->utilisateur;
            $user->fill(array_filter([
                'nom_complet' => $agent->nom_complet,
                'email' => $d['email'] ?? null,
                'telephone' => $d['telephone'] ?? null,
            ]))->save();

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
                'statut' => $cible,
                'date_resiliation' => $cible === AgentStatut::RESILIE ? now()->toDateString() : $agent->date_resiliation,
            ]);

            $agent->statutHistoriques()->create([
                'ancien_statut' => $ancien->value,
                'nouveau_statut' => $cible->value,
                'motif' => $motif,
                'effectue_par' => $auteur->id,
            ]);

            // Le login vérifie utilisateurs.statut : on le synchronise.
            $user = $agent->utilisateur;
            $user->update(['statut' => $cible->value]);

            if ($cible !== AgentStatut::ACTIF) {
                $user->revokeAllTokens();                 // coupe immédiatement les appareils mobiles
                Cache::forget('user-is-online-'.$user->id);
            }

            $auteur->logAudit('DSA_AGENT_STATUT', ['statut' => $ancien->value], [
                'agent_id' => $agent->id, 'statut' => $cible->value, 'motif' => $motif,
            ]);

            return $agent->fresh(['utilisateur', 'zone']);
        });
    }

    public function affecter(AgentDsa $agent, ?int $zoneId, ?int $superviseurId, Utilisateur $auteur): AgentDsa
    {
        return DB::transaction(function () use ($agent, $zoneId, $superviseurId, $auteur) {
            $agent->affectations()->whereNull('date_fin')->update(['date_fin' => now()]);

            $agent->update(['zone_id' => $zoneId, 'superviseur_id' => $superviseurId]);

            $agent->affectations()->create([
                'zone_id' => $zoneId,
                'superviseur_id' => $superviseurId,
                'date_debut' => now(),
                'effectue_par' => $auteur->id,
            ]);

            $auteur->logAudit('DSA_AGENT_AFFECTE', null, [
                'agent_id' => $agent->id, 'zone_id' => $zoneId, 'superviseur_id' => $superviseurId,
            ]);

            return $agent->fresh(['utilisateur', 'zone']);
        });
    }
}
