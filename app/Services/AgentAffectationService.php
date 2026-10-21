<?php

namespace App\Services;

use App\Models\AgentAffectation;
use App\Models\AgentDsa;
use App\Models\Utilisateur;
use Illuminate\Support\Facades\DB;

class AgentAffectationService
{
    public function affecter(
        AgentDsa $agent,
        array $data,
        Utilisateur $auteur
    ): AgentAffectation {
        return DB::transaction(function () use ($agent, $data, $auteur) {
            // ⚠️ Vérifier si l'affectation existe déjà (même agent + même campagne, sans date_fin)
            if (! empty($data['campagne_id'])) {
                $existante = AgentAffectation::where('agent_dsa_id', $agent->id)
                    ->where('campagne_id', $data['campagne_id'])
                    ->whereNull('date_fin')
                    ->first();

                if ($existante) {
                    // Déjà affecté → on ne fait rien (évite les doublons)
                    return $existante;
                }
            }

            // Clôturer les affectations ouvertes du même type (comportement existant)
            AgentAffectation::where('agent_dsa_id', $agent->id)
                ->whereNull('date_fin')
                ->when($data['zone_id'] ?? null, fn ($q) => $q->whereNotNull('zone_id'))
                ->when($data['campagne_id'] ?? null, fn ($q) => $q->whereNotNull('campagne_id'))
                ->when($data['partenaire_id'] ?? null, fn ($q) => $q->whereNotNull('partenaire_id'))
                ->update(['date_fin' => now()]);

            $affectation = AgentAffectation::create([
                'agent_dsa_id'   => $agent->id,
                'zone_id'        => $data['zone_id'] ?? null,
                'superviseur_id' => $data['superviseur_id'] ?? null,
                'campagne_id'    => $data['campagne_id'] ?? null,
                'partenaire_id'  => $data['partenaire_id'] ?? null,
                'date_debut'     => $data['date_debut'] ?? now(),
                'date_fin'       => null,
                'effectue_par'   => $auteur->id,
            ]);

            if (! empty($data['zone_id'])) {
                $agent->update(['zone_id' => $data['zone_id']]);
            }

            $auteur->logAudit('DSA_AGENT_AFFECTE', null, [
                'agent_id'       => $agent->id,
                'affectation_id' => $affectation->id,
                'data'           => $data,
            ]);

            return $affectation;
        });
    }
}