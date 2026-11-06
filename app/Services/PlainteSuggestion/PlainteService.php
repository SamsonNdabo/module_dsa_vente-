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
        return Plainte::query()
            // 1. On ne sélectionne QUE les colonnes utiles (pas de categorie_label)
            ->select([
                'plaintes.id',
                'plaintes.reference',
                'plaintes.client_id',
                'plaintes.utilisateur_id',
                'plaintes.objet',
                'plaintes.description',
                'plaintes.priorite',
                'plaintes.statut',
                'plaintes.zone',
                'plaintes.categorie',
                'plaintes.created_at',
            ])
            // 2. On charge les relations avec les VRAIES colonnes de votre BDD
            ->with([
                'client:id,nom_complet,categorie_client', // <-- Uniquement categorie_client
                'utilisateur:id,nom_complet',
            ])
            // 3. On compte les relations (pour les badges 📎 et 🕐)
            ->withCount(['piecesJointes', 'historiques'])
            
            // 4. Filtres
            ->when($filtres['statut'] ?? null,         fn ($q, $v) => $q->where('plaintes.statut', $v))
            ->when($filtres['categorie'] ?? null,      fn ($q, $v) => $q->where('plaintes.categorie', $v))
            ->when($filtres['priorite'] ?? null,       fn ($q, $v) => $q->where('plaintes.priorite', $v))
            ->when($filtres['zone'] ?? null,           fn ($q, $v) => $q->where('plaintes.zone', $v))
            ->when($filtres['utilisateur_id'] ?? null, fn ($q, $v) => $q->where('plaintes.utilisateur_id', $v))
            ->when($filtres['client_id'] ?? null,      fn ($q, $v) => $q->where('plaintes.client_id', $v))
            ->when($filtres['partenaire'] ?? null,     fn ($q, $v) => $q->whereHas(
                'client',
                fn ($qq) => $qq->where('categorie_client', $v)
            ))
            ->when($filtres['date_debut'] ?? null,     fn ($q, $v) => $q->whereDate('plaintes.created_at', '>=', $v))
            ->when($filtres['date_fin'] ?? null,       fn ($q, $v) => $q->whereDate('plaintes.created_at', '<=', $v))
            ->when($filtres['annee'] ?? null,          function ($q, $v) {
                $q->whereYear('plaintes.created_at', (int) $v);
            })
            ->when($filtres['recherche'] ?? null,      function ($q, $v) {
                $q->where(function ($sub) use ($v) {
                    $sub->where('plaintes.reference', 'like', "%{$v}%")
                        ->orWhere('plaintes.objet', 'like', "%{$v}%")
                        ->orWhere('plaintes.description', 'like', "%{$v}%");
                });
            })
            
            // 5. Tri sécurisé
            ->orderBy('plaintes.' . ($filtres['tri'] ?? 'created_at'), $filtres['ordre'] ?? 'desc')
            
            // 6. Pagination
            ->paginate($filtres['per_page'] ?? $perPage);
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
        ?int $auteurId = null,
        ?string $priorite = null
    ): Plainte {
        return DB::transaction(function () use ($plainte, $utilisateur, $commentaire, $auteurId, $priorite) {
            $ancienId = $plainte->utilisateur_id;

            $updates = [
                'utilisateur_id'   => $utilisateur->id,
                'date_affectation' => now(),
                'statut'           => $plainte->statut === Plainte::STATUT_NOUVELLE
                                        ? Plainte::STATUT_EN_COURS
                                        : $plainte->statut,
            ];

            if ($priorite) {
                $updates['priorite'] = $priorite;
            }

            $plainte->update($updates);

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

    // ============================================================
    // KPI PRINCIPAL
    // ============================================================
    public function kpi(array $filtres = []): array
    {
        $base = Plainte::query()
            ->when(
                !empty($filtres['annee']),
                fn ($q) => $q->whereYear('plaintes.created_at', (int) $filtres['annee'])
            )
            ->when(
                !empty($filtres['date_debut']) && $filtres['date_debut'] !== 'null',
                fn ($q) => $q->whereDate('plaintes.created_at', '>=', $filtres['date_debut'])
            )
            ->when(
                !empty($filtres['date_fin']) && $filtres['date_fin'] !== 'null',
                fn ($q) => $q->whereDate('plaintes.created_at', '<=', $filtres['date_fin'])
            )
            ->when(
                !empty($filtres['zone']) && $filtres['zone'] !== 'null',
                fn ($q) => $q->where('plaintes.zone', $filtres['zone'])
            );

        return [
            'global'             => $this->agreger($base),
            'par_zone'           => $this->agregerPar($base, 'zone'),
            'par_agent'          => $this->agregerParAgent($base),
            'par_partenaire'     => $this->agregerParPartenaire($base),
            'par_statut'         => $this->agregerPar($base, 'statut'),
            'par_categorie'      => $this->agregerPar($base, 'categorie'),
            'par_priorite'       => $this->agregerParPriorite($base),
            'par_mois'           => $this->agregerParMois($base),
            'dernieres_plaintes' => $this->dernieresPlaintes($filtres),
        ];
    }

    protected function dernieresPlaintes(array $filtres = [], int $limite = 8): array
    {
        return Plainte::with(['client:id,nom_complet', 'utilisateur:id,nom_complet'])
            ->select([
                'plaintes.id',
                'plaintes.reference',
                'plaintes.objet',
                'plaintes.zone',
                'plaintes.statut',
                'plaintes.priorite',
                'plaintes.utilisateur_id',
                'plaintes.created_at',
            ])
            ->when(
                !empty($filtres['annee']),
                fn ($q) => $q->whereYear('plaintes.created_at', (int) $filtres['annee'])
            )
            ->when(
                !empty($filtres['date_debut']) && $filtres['date_debut'] !== 'null',
                fn ($q) => $q->whereDate('plaintes.created_at', '>=', $filtres['date_debut'])
            )
            ->when(
                !empty($filtres['date_fin']) && $filtres['date_fin'] !== 'null',
                fn ($q) => $q->whereDate('plaintes.created_at', '<=', $filtres['date_fin'])
            )
            ->when(
                !empty($filtres['zone']) && $filtres['zone'] !== 'null',
                fn ($q) => $q->where('plaintes.zone', $filtres['zone'])
            )
            ->orderByDesc('plaintes.created_at')
            ->limit($limite)
            ->get()
            ->map(fn ($p) => [
                'id'         => $p->id,
                'reference'  => $p->reference,
                'objet'      => $p->objet,
                'zone'       => $p->zone,
                'statut'     => $p->statut,
                'priorite'   => $p->priorite,
                'client'     => $p->client?->nom_complet,
                'agent'      => $p->utilisateur?->nom_complet,
                'created_at' => $p->created_at?->toISOString(),
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
            ->select('plaintes.priorite', DB::raw('COUNT(*) as nombre_total'))
            ->whereNotNull('plaintes.priorite')
            ->groupBy('plaintes.priorite')
            ->pluck('nombre_total', 'priorite')
            ->toArray();
    }

    protected function agregerParMois($query): array
    {
        $parMois = [];
        for ($m = 1; $m <= 12; $m++) {
            $parMois[$m] = [
                'nouvelles' => 0,
                'en_cours'  => 0,
                'resolues'  => 0,
                'rejetees'  => 0,
            ];
        }

        $rows = (clone $query)
            ->selectRaw("
                MONTH(plaintes.created_at) AS mois,
                SUM(CASE WHEN plaintes.statut = 'NOUVELLE' THEN 1 ELSE 0 END) AS nouvelles,
                SUM(CASE WHEN plaintes.statut = 'EN_COURS' THEN 1 ELSE 0 END) AS en_cours,
                SUM(CASE WHEN plaintes.statut = 'RESOLUE'  THEN 1 ELSE 0 END) AS resolues,
                SUM(CASE WHEN plaintes.statut = 'REJETEE'  THEN 1 ELSE 0 END) AS rejetees
            ")
            ->whereNotNull('plaintes.created_at')
            ->groupBy(DB::raw('MONTH(plaintes.created_at)'))
            ->orderBy(DB::raw('MONTH(plaintes.created_at)'))
            ->get();

        foreach ($rows as $r) {
            $m = (int) $r->mois;
            if ($m >= 1 && $m <= 12) {
                $parMois[$m] = [
                    'nouvelles' => (int) $r->nouvelles,
                    'en_cours'  => (int) $r->en_cours,
                    'resolues'  => (int) $r->resolues,
                    'rejetees'  => (int) $r->rejetees,
                ];
            }
        }

        return $parMois;
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
                SUM(CASE WHEN plaintes.statut IN ('RESOLUE','REJETEE') THEN 1 ELSE 0 END) AS cloturees,
                SUM(CASE WHEN plaintes.statut = 'RESOLUE' THEN 1 ELSE 0 END) AS resolues,
                SUM(CASE WHEN plaintes.statut = 'REJETEE' THEN 1 ELSE 0 END) AS rejetees
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
                SUM(CASE WHEN plaintes.statut IN ('RESOLUE','REJETEE') THEN 1 ELSE 0 END) AS cloturees,
                SUM(CASE WHEN plaintes.statut = 'RESOLUE' THEN 1 ELSE 0 END) AS resolues,
                SUM(CASE WHEN plaintes.statut = 'REJETEE' THEN 1 ELSE 0 END) AS rejetees
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