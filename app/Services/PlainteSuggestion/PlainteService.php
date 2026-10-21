<?php

namespace App\Services\PlainteSuggestion;

use App\Models\PlainteSuggestion\Plainte;
use App\Models\PlainteSuggestion\PlainteHistorique;
use App\Models\Utilisateur;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PlainteService
{
    public function lister(array $filtres = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Plainte::with([
            'client',
            'utilisateur',
            'piecesJointes.uploader',
            'historiques.utilisateur',
            'historiques.ancienUtilisateur',
            'historiques.nouveauUtilisateur',
        ])->withCount(['piecesJointes', 'historiques']);

        $query->when($filtres['statut'] ?? null,         fn ($q, $v) => $q->where('statut', $v))
              ->when($filtres['categorie'] ?? null,      fn ($q, $v) => $q->where('categorie', $v))
              ->when($filtres['priorite'] ?? null,       fn ($q, $v) => $q->where('priorite', $v))
              ->when($filtres['zone'] ?? null,           fn ($q, $v) => $q->where('zone', $v))
              ->when($filtres['utilisateur_id'] ?? null, fn ($q, $v) => $q->where('utilisateur_id', $v))
              ->when($filtres['client_id'] ?? null,      fn ($q, $v) => $q->where('client_id', $v))
              ->when($filtres['partenaire'] ?? null,     fn ($q, $v) => $q->whereHas(
                    'client', fn ($qq) => $qq->where('categorie_client', $v)))
              ->when($filtres['date_debut'] ?? null,     fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
              ->when($filtres['date_fin'] ?? null,       fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
              ->when($filtres['recherche'] ?? null,      function ($q, $v) {
                    $q->where(function ($sub) use ($v) {
                        $sub->where('reference', 'like', "%{$v}%")
                            ->orWhere('objet', 'like', "%{$v}%")
                            ->orWhere('description', 'like', "%{$v}%");
                    });
              });

        $query->orderBy($filtres['tri'] ?? 'created_at', $filtres['ordre'] ?? 'desc');

        return $query->paginate($filtres['per_page'] ?? $perPage);
    }

    public function trouver(int $id): Plainte
    {
        return Plainte::with([
            'client',
            'utilisateur',
            'piecesJointes.uploader',
            'historiques.utilisateur',
            'historiques.ancienUtilisateur',
            'historiques.nouveauUtilisateur',
        ])->findOrFail($id);
    }

    public function creer(array $data, ?int $auteurId = null): Plainte
    {
        return DB::transaction(function () use ($data, $auteurId) {
            $data['reference'] = $this->genererReference();
            $data['statut']    = Plainte::STATUT_NOUVELLE;

            $plainte = Plainte::create($data);

            PlainteHistorique::create([
                'plainte_id'     => $plainte->id,
                'utilisateur_id' => $auteurId,
                'action'         => PlainteHistorique::ACTION_CREATION,
                'nouveau_statut' => $plainte->statut,
                'commentaire'    => 'Création de la plainte',
            ]);

            return $plainte;
        });
    }

    public function mettreAJour(Plainte $plainte, array $data): Plainte
    {
        $plainte->update($data);
        return $plainte->fresh();
    }

    public function supprimer(Plainte $plainte): bool
    {
        return $plainte->delete();
    }

    public function affecter(
        Plainte $plainte,
        Utilisateur $utilisateur,
        ?string $commentaire = null,
        ?int $auteurId = null
    ): Plainte {
        return DB::transaction(function () use ($plainte, $utilisateur, $commentaire, $auteurId) {
            $ancienId = $plainte->utilisateur_id;

            $plainte->update([
                'utilisateur_id'   => $utilisateur->id,
                'date_affectation' => now(),
                'statut'           => $plainte->statut === Plainte::STATUT_NOUVELLE
                                        ? Plainte::STATUT_EN_COURS
                                        : $plainte->statut,
            ]);

            PlainteHistorique::create([
                'plainte_id'             => $plainte->id,
                'utilisateur_id'         => $auteurId,
                'action'                 => PlainteHistorique::ACTION_AFFECTATION,
                'ancien_utilisateur_id'  => $ancienId,
                'nouveau_utilisateur_id' => $utilisateur->id,
                'commentaire'            => $commentaire ?? "Affectation à {$utilisateur->nom_complet}",
            ]);

            return $plainte->fresh();
        });
    }

    public function changerStatut(
        Plainte $plainte,
        string $nouveauStatut,
        ?string $commentaire = null,
        ?int $auteurId = null
    ): Plainte {
        return DB::transaction(function () use ($plainte, $nouveauStatut, $commentaire, $auteurId) {
            $ancienStatut = $plainte->statut;
            $updates      = ['statut' => $nouveauStatut];

            if (in_array($nouveauStatut, [Plainte::STATUT_RESOLUE, Plainte::STATUT_REJETEE])) {
                $updates['date_resolution']         = now();
                $updates['delai_resolution_heures'] = $plainte->created_at->diffInHours(now());
                $updates['commentaire_resolution']  = $commentaire;
            }

            $plainte->update($updates);

            PlainteHistorique::create([
                'plainte_id'     => $plainte->id,
                'utilisateur_id' => $auteurId,
                'action'         => $nouveauStatut === Plainte::STATUT_REJETEE
                                        ? PlainteHistorique::ACTION_REJET
                                        : ($nouveauStatut === Plainte::STATUT_RESOLUE
                                            ? PlainteHistorique::ACTION_RESOLUTION
                                            : PlainteHistorique::ACTION_CHANGEMENT_STATUT),
                'ancien_statut'  => $ancienStatut,
                'nouveau_statut' => $nouveauStatut,
                'commentaire'    => $commentaire,
            ]);

            return $plainte->fresh();
        });
    }

    public function kpi(array $filtres = []): array
    {
        $base = Plainte::query();

        $base->when($filtres['date_debut'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
             ->when($filtres['date_fin'] ?? null,   fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
             ->when($filtres['zone'] ?? null,       fn ($q, $v) => $q->where('zone', $v));

        return [
            'global'         => $this->agreger($base),
            'par_zone'       => $this->agregerPar($base, 'zone'),
            'par_agent'      => $this->agregerParAgent($base),
            'par_partenaire' => $this->agregerParPartenaire($base),
            'par_statut'     => $this->agregerPar($base, 'statut'),
            'par_categorie'  => $this->agregerPar($base, 'categorie'),
        ];
    }

    protected function agreger($query)
    {
        return (clone $query)->selectRaw("
            COUNT(*) AS nombre_total,
            ROUND(AVG(delai_resolution_heures), 2) AS delai_moyen_heures,
            SUM(CASE WHEN statut IN ('NOUVELLE','EN_COURS') THEN 1 ELSE 0 END) AS ouvertes,
            SUM(CASE WHEN statut IN ('RESOLUE','REJETEE') THEN 1 ELSE 0 END) AS cloturees
        ")->first();
    }

    protected function agregerPar($query, string $colonne)
    {
        return (clone $query)->selectRaw("
            {$colonne},
            COUNT(*) AS nombre_total,
            ROUND(AVG(delai_resolution_heures), 2) AS delai_moyen_heures,
            SUM(CASE WHEN statut IN ('NOUVELLE','EN_COURS') THEN 1 ELSE 0 END) AS ouvertes,
            SUM(CASE WHEN statut IN ('RESOLUE','REJETEE') THEN 1 ELSE 0 END) AS cloturees
        ")->whereNotNull($colonne)->groupBy($colonne)->get();
    }

    protected function agregerParAgent($query)
    {
        return (clone $query)
            ->join('utilisateurs', 'utilisateurs.id', '=', 'plaintes.utilisateur_id')
            ->selectRaw("
                utilisateurs.id AS utilisateur_id,
                utilisateurs.nom_complet AS agent,
                COUNT(plaintes.id) AS nombre_total,
                ROUND(AVG(plaintes.delai_resolution_heures), 2) AS delai_moyen_heures,
                SUM(CASE WHEN plaintes.statut IN ('NOUVELLE','EN_COURS') THEN 1 ELSE 0 END) AS ouvertes,
                SUM(CASE WHEN plaintes.statut IN ('RESOLUE','REJETEE') THEN 1 ELSE 0 END) AS cloturees
            ")
            ->whereNotNull('plaintes.utilisateur_id')
            ->groupBy('utilisateurs.id', 'utilisateurs.nom_complet')
            ->get();
    }

    protected function agregerParPartenaire($query)
    {
        return (clone $query)
            ->join('clients', 'clients.id', '=', 'plaintes.client_id')
            ->selectRaw("
                clients.categorie_client AS partenaire,
                COUNT(plaintes.id) AS nombre_total,
                ROUND(AVG(plaintes.delai_resolution_heures), 2) AS delai_moyen_heures,
                SUM(CASE WHEN plaintes.statut IN ('NOUVELLE','EN_COURS') THEN 1 ELSE 0 END) AS ouvertes,
                SUM(CASE WHEN plaintes.statut IN ('RESOLUE','REJETEE') THEN 1 ELSE 0 END) AS cloturees
            ")
            ->groupBy('clients.categorie_client')
            ->get();
    }

    public function genererReference(): string
    {
        $annee = now()->year;

        $dernier = Plainte::withTrashed()
            ->whereYear('created_at', $annee)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        $numero = $dernier
            ? ((int) substr($dernier->reference, -4)) + 1
            : 1;

        return sprintf('PL-%d-%04d', $annee, $numero);
    }
}