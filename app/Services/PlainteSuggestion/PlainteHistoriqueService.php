<?php

namespace App\Services\PlainteSuggestion;

use App\Models\PlainteSuggestion\PlainteHistorique;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class PlainteHistoriqueService
{
    // =========================================================
    // 📋 LISTE FILTRÉE PAR PLAINTE
    // =========================================================
    public function lister(int $plainteId, array $filtres = []): LengthAwarePaginator
    {
        $query = PlainteHistorique::with([
                'utilisateur:id,nom_complet',
                'ancienUtilisateur:id,nom_complet',
                'nouveauUtilisateur:id,nom_complet',
            ])
            ->where('plainte_id', $plainteId);

        $query->when($filtres['action'] ?? null, fn ($q, $v) => $q->where('action', $v))
              ->when($filtres['utilisateur_id'] ?? null, fn ($q, $v) => $q->where('utilisateur_id', $v))
              ->when($filtres['date_debut'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
              ->when($filtres['date_fin'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v));

        $query->orderBy($filtres['tri'] ?? 'created_at', $filtres['ordre'] ?? 'desc');

        return $query->paginate($filtres['per_page'] ?? 30);
    }

    // =========================================================
    // ➕ CRÉATION D'UNE LIGNE D'HISTORIQUE
    // =========================================================
    public function creer(array $data): PlainteHistorique
    {
        return PlainteHistorique::create($data);
    }

    // =========================================================
    // 🔍 FILTRES SPÉCIFIQUES
    // =========================================================
    public function parAction(int $plainteId, string $action): Collection
    {
        return PlainteHistorique::where('plainte_id', $plainteId)
            ->where('action', $action)
            ->orderByDesc('created_at')
            ->get();
    }

    public function parUtilisateur(int $plainteId, int $utilisateurId): Collection
    {
        return PlainteHistorique::where('plainte_id', $plainteId)
            ->where('utilisateur_id', $utilisateurId)
            ->orderByDesc('created_at')
            ->get();
    }

    public function dernierChangement(int $plainteId): ?PlainteHistorique
    {
        return PlainteHistorique::where('plainte_id', $plainteId)
            ->orderByDesc('created_at')
            ->first();
    }

    // =========================================================
    // 📊 STATISTIQUES
    // =========================================================
    public function compterParAction(int $plainteId): array
    {
        return PlainteHistorique::where('plainte_id', $plainteId)
            ->selectRaw('action, COUNT(*) as total')
            ->groupBy('action')
            ->pluck('total', 'action')
            ->toArray();
    }

    // =========================================================
    // 🗑️ SUPPRESSION (rarement utilisée)
    // =========================================================
    public function supprimerTout(int $plainteId): int
    {
        return PlainteHistorique::where('plainte_id', $plainteId)->delete();
    }
}