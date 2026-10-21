<?php

namespace App\Services;

use App\Models\Campagne;
use App\Models\Partenaire;
use App\Models\Utilisateur;
use App\Models\ObjectifMesurable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CampagneService
{
    public function __construct(private SequenceService $sequences) {}

    public function creer(array $data, Utilisateur $auteur): Campagne
{
    return DB::transaction(function () use ($data, $auteur) {
        $partenaire = Partenaire::whereKey($data['partenaire_id'])->where('actif', true)->first();
        if (! $partenaire) {
            throw ValidationException::withMessages(['partenaire_id' => 'Partenaire introuvable ou inactif.']);
        }

        if (! empty($data['date_fin']) && $data['date_fin'] < $data['date_debut']) {
            throw ValidationException::withMessages(['date_fin' => 'La date de fin doit être postérieure à la date de début.']);
        }

        $campagne = Campagne::create([
            'partenaire_id' => $partenaire->id,
            'code'          => $data['code'] ?? $this->sequences->suivant('CAMPAGNE', 'CAMP'),
            'nom'           => $data['nom'],
            'description'   => $data['description'] ?? null,
            'budget'        => $data['budget'] ?? null,          // ← nouveau
            'devise_id'     => $data['devise_id'] ?? null,       // ← nouveau
            'date_debut'    => $data['date_debut'],
            'date_fin'      => $data['date_fin'] ?? null,
            'statut'        => $data['statut'] ?? 'PLANIFIEE',
            'metadata'      => $data['metadata'] ?? null,
            'cree_par'      => $auteur->id,
        ]);

        $auteur->logAudit('DSA_CAMPAGNE_CREEE', null, ['campagne_id' => $campagne->id]);
        return $campagne;
    });
}

    public function modifier(Campagne $campagne, array $data, Utilisateur $auteur): Campagne
    {
        $avant = $campagne->only(array_keys($data));
        $campagne->update($data);
        $auteur->logAudit('DSA_CAMPAGNE_MODIFIEE', $avant, $data);
        return $campagne->fresh();
    }

    public function changerStatut(Campagne $campagne, string $statut, Utilisateur $auteur): Campagne
        {
            return DB::transaction(function () use ($campagne, $statut, $auteur) {
                $avant = $campagne->statut;
                $campagne->changerStatut($statut); // validation interne + save

                // 🎯 Cascade sur les objectifs mesurables
                $campagne->objectifs->each(function (\App\Models\ObjectifMesurable $obj) use ($statut) {
                    $nouveauStatut = \App\Models\ObjectifMesurable::statutCibleSelonCampagne(
                        $statut,
                        $obj->statut
                    );

                    if ($nouveauStatut !== $obj->statut) {
                        $obj->update(['statut' => $nouveauStatut]);
                    }
                });

                // Recalculer les valeurs globales (au cas où des objectifs seraient annulés)
                $campagne->objectifsTypes->each(fn ($o) => $o->recalculerRealisee());

                $auteur->logAudit('DSA_CAMPAGNE_STATUT', ['statut' => $avant], [
                    'campagne_id' => $campagne->id,
                    'statut'      => $statut,
                ]);

                return $campagne->fresh();
            });
        }
}