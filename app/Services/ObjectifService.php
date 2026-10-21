<?php

namespace App\Services;

use App\Models\ObjectifMesurable;
use App\Models\Utilisateur;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ObjectifService
{
    public function __construct(private SequenceService $sequences) {}

    public function creer(array $data, Utilisateur $auteur): ObjectifMesurable
    {
        return DB::transaction(function () use ($data, $auteur) {
            if (($data['periode_fin'] ?? null) < ($data['periode_debut'] ?? null)) {
                throw ValidationException::withMessages([
                    'periode_fin' => 'La date de fin doit être postérieure à la date de début.',
                ]);
            }

            $objectif = ObjectifMesurable::create([
                'campagne_id'     => $data['campagne_id'] ?? null,
                'partenaire_id'   => $data['partenaire_id'],
                'agent_dsa_id'    => $data['agent_dsa_id'] ?? null,
                'produit_dsa_id'  => $data['produit_dsa_id'] ?? null,
                'zone_id'         => $data['zone_id'] ?? null,
                'code'            => $data['code'] ?? $this->sequences->suivant('OBJECTIF', 'OBJ'),
                'libelle'         => $data['libelle'],
                'type_objectif'   => $data['type_objectif'],
                'valeur_cible'    => $data['valeur_cible'],
                'valeur_realisee' => $data['valeur_realisee'] ?? 0,
                'unite'           => $data['unite'] ?? null,
                'periode_debut'   => $data['periode_debut'],
                'periode_fin'     => $data['periode_fin'],
                'statut'          => $data['statut'] ?? 'ACTIF',
                'metadata'        => $data['metadata'] ?? null,
                'cree_par'        => $auteur->id,
            ]);

            $auteur->logAudit('DSA_OBJECTIF_CREE', null, ['objectif_id' => $objectif->id]);
            return $objectif;
        });
    }

    public function modifier(ObjectifMesurable $objectif, array $data, Utilisateur $auteur): ObjectifMesurable
    {
        $avant = $objectif->only(array_keys($data));
        $objectif->update($data);

        // Recalcul statut si valeur réalisée change
        if (array_key_exists('valeur_realisee', $data)) {
            $this->recalculerStatut($objectif);
        }

        $auteur->logAudit('DSA_OBJECTIF_MODIFIE', $avant, $data);
        return $objectif->fresh();
    }

    public function incrementer(ObjectifMesurable $objectif, float $valeur, Utilisateur $auteur): ObjectifMesurable
    {
        return DB::transaction(function () use ($objectif, $valeur, $auteur) {
            $objectif->valeur_realisee = (float) $objectif->valeur_realisee + $valeur;
            $this->recalculerStatut($objectif);
            $objectif->save();

            $auteur->logAudit('DSA_OBJECTIF_INCR', ['valeur_realisee' => (float) $objectif->valeur_realisee - $valeur], [
                'objectif_id' => $objectif->id,
                'increment'   => $valeur,
            ]);

            return $objectif->fresh();
        });
    }

    public function recalculerStatut(ObjectifMesurable $objectif): void
    {
        $cible = (float) $objectif->valeur_cible;
        $realise = (float) $objectif->valeur_realisee;

        if ($realise > $cible) {
            $objectif->statut = 'DEPASSE';
        } elseif ($realise >= $cible) {
            $objectif->statut = 'ATTEINT';
        } elseif ($objectif->statut !== 'ANNULE') {
            $objectif->statut = 'ACTIF';
        }
    }
}