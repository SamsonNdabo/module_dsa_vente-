<?php

namespace App\Services;

use App\Models\Campagne;
use App\Models\CampagneObjectif;
use App\Models\Utilisateur;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CampagneObjectifService
{
    /**
     * Définit / met à jour les types d'objectifs d'une campagne.
     * Les types non présents dans $types seront supprimés (si sans objectifs agents).
     */
    public function definirTypes(Campagne $campagne, array $types, Utilisateur $auteur): void
    {
        if (in_array($campagne->statut, ['TERMINEE', 'ANNULEE'], true)) {
            throw ValidationException::withMessages([
                'statut' => 'Impossible de modifier les objectifs d\'une campagne terminée ou annulée.',
            ]);
        }

        DB::transaction(function () use ($campagne, $types, $auteur) {
            // ⚠️ Dédoublonner les types par (type_objectif + produit_dsa_id)
            $typesUniques = [];
            foreach ($types as $t) {
                $cle = $t['type_objectif'] . '|' . ($t['produit_dsa_id'] ?? 'null');
                if (! isset($typesUniques[$cle])) {
                    $typesUniques[$cle] = $t;
                }
            }
            $types = array_values($typesUniques);

            $clesSoumises = array_keys($typesUniques);

            // Supprimer les types non présents
            $campagne->objectifsTypes()->get()->each(function (CampagneObjectif $o) use ($clesSoumises) {
                $cle = $o->type_objectif . '|' . ($o->produit_dsa_id ?? 'null');
                if (! in_array($cle, $clesSoumises, true)) {
                    if ($o->objectifsAgents()->exists()) {
                        throw ValidationException::withMessages([
                            'types' => "Impossible de retirer « {$o->libelleType()} » : des agents y sont rattachés.",
                        ]);
                    }
                    $o->delete();
                }
            });

            // Créer / mettre à jour
            foreach ($types as $i => $t) {
                $where = [
                    'campagne_id'    => $campagne->id,
                    'type_objectif'  => $t['type_objectif'],
                    'produit_dsa_id' => $t['produit_dsa_id'] ?? null,
                ];

                $payload = [
                    'libelle'      => $t['libelle'] ?? null,
                    'valeur_cible' => $t['valeur_cible'],
                    'unite'        => $t['unite'] ?? null,
                    'actif'        => $t['actif'] ?? true,
                    'ordre'        => $t['ordre'] ?? $i,
                ];

                CampagneObjectif::updateOrCreate($where, $payload);
            }

            $auteur->logAudit('DSA_CAMPAGNE_TYPES_DEFINIS', null, [
                'campagne_id' => $campagne->id,
                'types'       => array_column($types, 'type_objectif'),
            ]);
        });
    }
}