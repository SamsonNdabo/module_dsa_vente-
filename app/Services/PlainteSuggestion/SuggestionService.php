<?php

namespace App\Services\PlainteSuggestion;

use App\Models\PlainteSuggestion\Suggestion;
use App\Models\PlainteSuggestion\SuggestionHistorique;
use App\Models\Utilisateur;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SuggestionService
{
    public function lister(array $filtres = [], int $perPage = 15): LengthAwarePaginator
    {
        return Suggestion::query()
            // 1. On ne sélectionne QUE les colonnes utiles
            ->select([
                'suggestions.id',
                'suggestions.reference',
                'suggestions.client_id',
                'suggestions.utilisateur_id',
                'suggestions.objet',
                'suggestions.description',
                'suggestions.priorite',
                'suggestions.statut',
                'suggestions.zone',
                'suggestions.categorie',
                'suggestions.created_at',
            ])
            // 2. On charge uniquement les colonnes qui existent vraiment
            ->with([
                'client:id,nom_complet,categorie_client',
                'utilisateur:id,nom_complet',
            ])
            // 3. On compte les relations
            ->withCount(['piecesJointes', 'historiques'])
            
            // 4. Filtres
            ->when($filtres['statut'] ?? null,         fn ($q, $v) => $q->where('suggestions.statut', $v))
            ->when($filtres['categorie'] ?? null,      fn ($q, $v) => $q->where('suggestions.categorie', $v))
            ->when($filtres['priorite'] ?? null,       fn ($q, $v) => $q->where('suggestions.priorite', $v))
            ->when($filtres['zone'] ?? null,           fn ($q, $v) => $q->where('suggestions.zone', $v))
            ->when($filtres['utilisateur_id'] ?? null, fn ($q, $v) => $q->where('suggestions.utilisateur_id', $v))
            ->when($filtres['client_id'] ?? null,      fn ($q, $v) => $q->where('suggestions.client_id', $v))
            ->when($filtres['partenaire'] ?? null,     fn ($q, $v) => $q->whereHas(
                'client',
                fn ($qq) => $qq->where('categorie_client', $v)
            ))
            ->when($filtres['annee'] ?? null,          fn ($q, $v) => $q->whereYear('suggestions.created_at', (int) $v))
            ->when($filtres['date_debut'] ?? null,     fn ($q, $v) => $q->whereDate('suggestions.created_at', '>=', $v))
            ->when($filtres['date_fin'] ?? null,       fn ($q, $v) => $q->whereDate('suggestions.created_at', '<=', $v))
            ->when($filtres['recherche'] ?? null,      function ($q, $v) {
                $q->where(function ($sub) use ($v) {
                    $sub->where('suggestions.reference', 'like', "%{$v}%")
                        ->orWhere('suggestions.objet', 'like', "%{$v}%")
                        ->orWhere('suggestions.description', 'like', "%{$v}%");
                });
            })
            
            // 5. Tri sécurisé
            ->orderBy('suggestions.' . ($filtres['tri'] ?? 'created_at'), $filtres['ordre'] ?? 'desc')
            
            // 6. Pagination
            ->paginate($filtres['per_page'] ?? $perPage);
    }

    public function trouver(int $id): Suggestion
    {
        return Suggestion::with([
            'client',
            'utilisateur',
            'piecesJointes.uploader',
            'historiques.utilisateur',
            'historiques.ancienUtilisateur',
            'historiques.nouveauUtilisateur',
        ])->findOrFail($id);
    }

    public function creer(array $data, ?int $auteurId = null): Suggestion
    {
        return DB::transaction(function () use ($data, $auteurId) {
            $data['reference'] = $this->genererReference();
            $data['statut']    = Suggestion::STATUT_NOUVELLE;

            /** @var Suggestion $suggestion */
            $suggestion = Suggestion::create($data);

            SuggestionHistorique::create([
                'suggestion_id'  => $suggestion->id,
                'utilisateur_id' => $auteurId,
                'action'         => SuggestionHistorique::ACTION_CREATION,
                'nouveau_statut' => $suggestion->statut,
                'commentaire'    => 'Création de la suggestion',
            ]);

            return $suggestion;
        });
    }

    public function mettreAJour(Suggestion $suggestion, array $data): Suggestion
    {
        $suggestion->update($data);
        return $suggestion->fresh();
    }

    public function supprimer(Suggestion $suggestion): bool
    {
        return $suggestion->delete();
    }

    public function affecter(
        Suggestion $suggestion,
        Utilisateur $utilisateur,
        ?string $commentaire = null,
        ?int $auteurId = null,
        ?string $priorite = null
    ): Suggestion {
        return DB::transaction(function () use ($suggestion, $utilisateur, $commentaire, $auteurId, $priorite) {
            $ancienId = $suggestion->utilisateur_id;

            $updates = [
                'utilisateur_id'   => $utilisateur->id,
                'date_affectation' => now(),
                'statut'           => $suggestion->statut === Suggestion::STATUT_NOUVELLE
                                        ? Suggestion::STATUT_EN_COURS
                                        : $suggestion->statut,
            ];

            if ($priorite) {
                $updates['priorite'] = $priorite;
            }

            $suggestion->update($updates);

            SuggestionHistorique::create([
                'suggestion_id'          => $suggestion->id,
                'utilisateur_id'         => $auteurId,
                'action'                 => SuggestionHistorique::ACTION_AFFECTATION,
                'ancien_utilisateur_id'  => $ancienId,
                'nouveau_utilisateur_id' => $utilisateur->id,
                'commentaire'            => $commentaire ?? "Affectation à {$utilisateur->nom_complet}",
            ]);

            return $suggestion->fresh();
        });
    }

    public function changerStatut(
        Suggestion $suggestion,
        string $nouveauStatut,
        ?string $commentaire = null,
        ?int $auteurId = null
    ): Suggestion {
        return DB::transaction(function () use ($suggestion, $nouveauStatut, $commentaire, $auteurId) {
            $ancienStatut = $suggestion->statut;
            $updates      = ['statut' => $nouveauStatut];

            if (in_array($nouveauStatut, [Suggestion::STATUT_RESOLUE, Suggestion::STATUT_REJETEE])) {
                $updates['date_resolution']         = now();
                $updates['delai_resolution_heures'] = $suggestion->created_at->diffInHours(now());
                $updates['commentaire_resolution']  = $commentaire;
            }

            $suggestion->update($updates);

            SuggestionHistorique::create([
                'suggestion_id'  => $suggestion->id,
                'utilisateur_id' => $auteurId,
                'action'         => $nouveauStatut === Suggestion::STATUT_REJETEE
                                        ? SuggestionHistorique::ACTION_REJET
                                        : ($nouveauStatut === Suggestion::STATUT_RESOLUE
                                            ? SuggestionHistorique::ACTION_RESOLUTION
                                            : SuggestionHistorique::ACTION_CHANGEMENT_STATUT),
                'ancien_statut'  => $ancienStatut,
                'nouveau_statut' => $nouveauStatut,
                'commentaire'    => $commentaire,
            ]);

            return $suggestion->fresh();
        });
    }

    public function kpi(array $filtres = []): array
    {
        $base = Suggestion::query()
            ->when(
                !empty($filtres['annee']),
                fn ($q) => $q->whereYear('suggestions.created_at', (int) $filtres['annee'])
            )
            ->when(
                !empty($filtres['date_debut']) && $filtres['date_debut'] !== 'null',
                fn ($q) => $q->whereDate('suggestions.created_at', '>=', $filtres['date_debut'])
            )
            ->when(
                !empty($filtres['date_fin']) && $filtres['date_fin'] !== 'null',
                fn ($q) => $q->whereDate('suggestions.created_at', '<=', $filtres['date_fin'])
            )
            ->when(
                !empty($filtres['zone']) && $filtres['zone'] !== 'null',
                fn ($q) => $q->where('suggestions.zone', $filtres['zone'])
            );

        return [
            'global'                => $this->agreger($base),
            'par_zone'              => $this->agregerPar($base, 'zone'),
            'par_agent'             => $this->agregerParAgent($base),
            'par_partenaire'        => $this->agregerParPartenaire($base),
            'par_statut'            => $this->agregerPar($base, 'statut'),
            'par_categorie'         => $this->agregerPar($base, 'categorie'),
            'par_priorite'          => $this->agregerParPriorite($base),
            'par_mois'              => $this->agregerParMois($base),
            'dernieres_suggestions' => $this->dernieresSuggestions($filtres),
        ];
    }

    protected function agregerParMois($query): array
    {
        $result = (clone $query)
            ->selectRaw("
                MONTH(suggestions.created_at) as mois,
                SUM(CASE WHEN suggestions.statut = 'NOUVELLE' THEN 1 ELSE 0 END) as nouvelles,
                SUM(CASE WHEN suggestions.statut = 'EN_COURS' THEN 1 ELSE 0 END) as en_cours,
                SUM(CASE WHEN suggestions.statut = 'RESOLUE' THEN 1 ELSE 0 END) as resolues,
                SUM(CASE WHEN suggestions.statut = 'REJETEE' THEN 1 ELSE 0 END) as rejetees
            ")
            ->whereNotNull('suggestions.created_at')
            ->groupBy('mois')
            ->orderBy('mois')
            ->get();

        $data = [];
        for ($m = 1; $m <= 12; $m++) {
            $data[$m] = [
                'nouvelles' => 0,
                'en_cours'  => 0,
                'resolues'  => 0,
                'rejetees'  => 0,
            ];
        }

        foreach ($result as $row) {
            $mois = (int) $row->mois;
            if ($mois >= 1 && $mois <= 12) {
                $data[$mois] = [
                    'nouvelles' => (int) $row->nouvelles,
                    'en_cours'  => (int) $row->en_cours,
                    'resolues'  => (int) $row->resolues,
                    'rejetees'  => (int) $row->rejetees,
                ];
            }
        }

        ksort($data);
        return $data;
    }

    protected function dernieresSuggestions(array $filtres = [], int $limite = 8): array
    {
        return Suggestion::with(['client:id,nom_complet', 'utilisateur:id,nom_complet'])
            ->select([
                'suggestions.id',
                'suggestions.reference',
                'suggestions.objet',
                'suggestions.zone',
                'suggestions.statut',
                'suggestions.priorite',
                'suggestions.utilisateur_id',
                'suggestions.created_at',
            ])
            ->when(
                !empty($filtres['annee']),
                fn ($q) => $q->whereYear('suggestions.created_at', (int) $filtres['annee'])
            )
            ->when(
                !empty($filtres['date_debut']) && $filtres['date_debut'] !== 'null',
                fn ($q) => $q->whereDate('suggestions.created_at', '>=', $filtres['date_debut'])
            )
            ->when(
                !empty($filtres['date_fin']) && $filtres['date_fin'] !== 'null',
                fn ($q) => $q->whereDate('suggestions.created_at', '<=', $filtres['date_fin'])
            )
            ->when(
                !empty($filtres['zone']) && $filtres['zone'] !== 'null',
                fn ($q) => $q->where('suggestions.zone', $filtres['zone'])
            )
            ->orderByDesc('suggestions.created_at')
            ->limit($limite)
            ->get()
            ->map(fn ($s) => [
                'id'         => $s->id,
                'reference'  => $s->reference,
                'objet'      => $s->objet,
                'zone'       => $s->zone,
                'statut'     => $s->statut,
                'priorite'   => $s->priorite,
                'client'     => $s->client?->nom_complet,
                'agent'      => $s->utilisateur?->nom_complet,
                'created_at' => $s->created_at?->toISOString(),
            ])
            ->toArray();
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
        return (clone $query)
            ->selectRaw("
                {$colonne},
                COUNT(*) AS nombre_total,
                ROUND(AVG(delai_resolution_heures), 2) AS delai_moyen_heures,
                SUM(CASE WHEN statut IN ('NOUVELLE','EN_COURS') THEN 1 ELSE 0 END) AS ouvertes,
                SUM(CASE WHEN statut IN ('RESOLUE','REJETEE') THEN 1 ELSE 0 END) AS cloturees,
                SUM(CASE WHEN statut = 'RESOLUE' THEN 1 ELSE 0 END) AS resolues,
                SUM(CASE WHEN statut = 'REJETEE' THEN 1 ELSE 0 END) AS rejetees
            ")
            ->whereNotNull($colonne)
            ->groupBy($colonne)
            ->get();
    }

    protected function agregerParPriorite($query): array
    {
        return (clone $query)
            ->select('suggestions.priorite', DB::raw('COUNT(*) as nombre_total'))
            ->whereNotNull('suggestions.priorite')
            ->groupBy('suggestions.priorite')
            ->pluck('nombre_total', 'priorite')
            ->toArray();
    }

    protected function agregerParAgent($query)
    {
        return (clone $query)
            ->join('utilisateurs', 'utilisateurs.id', '=', 'suggestions.utilisateur_id')
            ->selectRaw("
                utilisateurs.id AS utilisateur_id,
                utilisateurs.nom_complet AS agent,
                COUNT(suggestions.id) AS nombre_total,
                ROUND(AVG(suggestions.delai_resolution_heures), 2) AS delai_moyen_heures,
                SUM(CASE WHEN suggestions.statut IN ('NOUVELLE','EN_COURS') THEN 1 ELSE 0 END) AS ouvertes,
                SUM(CASE WHEN suggestions.statut IN ('RESOLUE','REJETEE') THEN 1 ELSE 0 END) AS cloturees,
                SUM(CASE WHEN suggestions.statut = 'RESOLUE' THEN 1 ELSE 0 END) AS resolues,
                SUM(CASE WHEN suggestions.statut = 'REJETEE' THEN 1 ELSE 0 END) AS rejetees
            ")
            ->whereNotNull('suggestions.utilisateur_id')
            ->groupBy('utilisateurs.id', 'utilisateurs.nom_complet')
            ->get();
    }

    protected function agregerParPartenaire($query)
    {
        return (clone $query)
            ->join('clients', 'clients.id', '=', 'suggestions.client_id')
            ->selectRaw("
                clients.categorie_client AS partenaire,
                COUNT(suggestions.id) AS nombre_total,
                ROUND(AVG(suggestions.delai_resolution_heures), 2) AS delai_moyen_heures,
                SUM(CASE WHEN suggestions.statut IN ('NOUVELLE','EN_COURS') THEN 1 ELSE 0 END) AS ouvertes,
                SUM(CASE WHEN suggestions.statut IN ('RESOLUE','REJETEE') THEN 1 ELSE 0 END) AS cloturees,
                SUM(CASE WHEN suggestions.statut = 'RESOLUE' THEN 1 ELSE 0 END) AS resolues,
                SUM(CASE WHEN suggestions.statut = 'REJETEE' THEN 1 ELSE 0 END) AS rejetees
            ")
            ->groupBy('clients.categorie_client')
            ->get();
    }

    public function genererReference(): string
    {
        $annee = now()->year;

        $dernier = Suggestion::withTrashed()
            ->whereYear('created_at', $annee)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        $numero = $dernier
            ? ((int) substr($dernier->reference, -4)) + 1
            : 1;

        return sprintf('SG-%d-%04d', $annee, $numero);
    }
}