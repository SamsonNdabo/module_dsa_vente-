<?php

namespace App\Services;

use App\Models\AgentAffectation;
use App\Models\Campagne;
use App\Models\ObjectifMesurable;
use App\Models\Utilisateur;
use Illuminate\Support\Facades\DB;

class ObjectifMesurableService
{
    /**
     * Incrémente l'objectif CLIENTS d'un agent pour toutes les campagnes ACTIVES
     * auxquelles il est affecté. Appelé après création d'un ClientDsa.
     *
     * @return int Nombre d'objectifs incrémentés
     */
    public function incrementerObjectifsClients(int $agentDsaId, Utilisateur $acteur): int
    {
        // ⚠️ Ne récupérer QUE les campagnes ACTIVE
        $campagnesActives = AgentAffectation::where('agent_dsa_id', $agentDsaId)
            ->whereNull('date_fin')
            ->whereHas('campagne', fn ($q) => $q->where('statut', 'ACTIVE')) // ← le filtre est déjà là ✅
            ->pluck('campagne_id')
            ->unique()
            ->values();

        if ($campagnesActives->isEmpty()) {
            return 0;
        }

        $count = 0;

        DB::transaction(function () use ($campagnesActives, $agentDsaId, $acteur, &$count) {
            foreach ($campagnesActives as $campagneId) {
                $objectif = ObjectifMesurable::where('campagne_id', $campagneId)
                    ->where('agent_dsa_id', $agentDsaId)
                    ->where('type_objectif', 'CLIENTS')
                    ->where('statut', 'ACTIF')  // ← uniquement ACTIF
                    ->first();

                if (! $objectif) {
                    continue;
                }

                $avant = (float) $objectif->valeur_realisee;
                $objectif->valeur_realisee = $avant + 1;
                $objectif->recalculerStatutEtGlobal();

                $acteur->logAudit('DSA_OBJ_CLIENTS_INCR_AUTO', [
                    'valeur_realisee' => $avant,
                ], [
                    'objectif_id'  => $objectif->id,
                    'campagne_id'  => $campagneId,
                    'agent_id'     => $agentDsaId,
                    'source'       => 'creation_client_dsa',
                ]);

                $count++;
            }
        });

        return $count;
    }
}