<?php

namespace App\Services\PlainteSuggestion;

use App\Models\PlainteSuggestion\Suggestion;
use App\Models\PlainteSuggestion\SuggestionHistorique;
use App\Models\Utilisateur;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SuggestionService
{
    // =========================================================
    // 📋 LISTE FILTRÉE
    // =========================================================
    public function lister(array $filtres = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Suggestion::with([
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

    // =========================================================
    // 🔍 DÉTAIL
    // =========================================================
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

    // =========================================================
    // ➕ CRÉATION
    // =========================================================
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

    // =========================================================
    // ✏️ MISE À JOUR
    // =========================================================
    public function mettreAJour(Suggestion $suggestion, array $data): Suggestion
    {
        $suggestion->update($data);
        return $suggestion->fresh();
    }

    // =========================================================
    // 🗑️ SUPPRESSION
    // =========================================================
    public function supprimer(Suggestion $suggestion): bool
    {
        return $suggestion->delete();
    }

    // =========================================================
    // 👤 AFFECTATION
    // =========================================================
    public function affecter(
        Suggestion $suggestion,
        Utilisateur $utilisateur,
        ?string $commentaire = null,
        ?int $auteurId = null
    ): Suggestion {
        return DB::transaction(function () use ($suggestion, $utilisateur, $commentaire, $auteurId) {
            $ancienId = $suggestion->utilisateur_id;

            $suggestion->update([
                'utilisateur_id'   => $utilisateur->id,
                'date_affectation' => now(),
                'statut'           => $suggestion->statut === Suggestion::STATUT_NOUVELLE
                                        ? Suggestion::STATUT_EN_COURS
                                        : $suggestion->statut,
            ]);

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

    // =========================================================
    // 🔁 CHANGEMENT STATUT + calcul délai
    // =========================================================
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

    // =========================================================
    // 📊 KPI
    // =========================================================
    public function kpi(array $filtres = []): array
    {
        $base = Suggestion::query();

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
            ->join('utilisateurs', 'utilisateurs.id', '=', 'suggestions.utilisateur_id')
            ->selectRaw("
                utilisateurs.id AS utilisateur_id,
                utilisateurs.nom_complet AS agent,
                COUNT(suggestions.id) AS nombre_total,
                ROUND(AVG(suggestions.delai_resolution_heures), 2) AS delai_moyen_heures,
                SUM(CASE WHEN suggestions.statut IN ('NOUVELLE','EN_COURS') THEN 1 ELSE 0 END) AS ouvertes,
                SUM(CASE WHEN suggestions.statut IN ('RESOLUE','REJETEE') THEN 1 ELSE 0 END) AS cloturees
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
                SUM(CASE WHEN suggestions.statut IN ('RESOLUE','REJETEE') THEN 1 ELSE 0 END) AS cloturees
            ")
            ->groupBy('clients.categorie_client')
            ->get();
    }

    // =========================================================
    // 🆔 GÉNÉRATION RÉFÉRENCE
    // =========================================================
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