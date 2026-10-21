<?php

namespace App\Services\PlainteSuggestion;

use App\Models\PlainteSuggestion\SuggestionHistorique;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class SuggestionHistoriqueService
{
    // =========================================================
    // 📋 LISTE FILTRÉE PAR SUGGESTION
    // =========================================================
    public function lister(int $suggestionId, array $filtres = []): LengthAwarePaginator
    {
        $query = SuggestionHistorique::with([
                'utilisateur:id,nom_complet',
                'ancienUtilisateur:id,nom_complet',
                'nouveauUtilisateur:id,nom_complet',
            ])
            ->where('suggestion_id', $suggestionId);

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
    public function creer(array $data): SuggestionHistorique
    {
        return SuggestionHistorique::create($data);
    }

    // =========================================================
    // 🔍 FILTRES SPÉCIFIQUES
    // =========================================================
    public function parAction(int $suggestionId, string $action): Collection
    {
        return SuggestionHistorique::where('suggestion_id', $suggestionId)
            ->where('action', $action)
            ->orderByDesc('created_at')
            ->get();
    }

    public function parUtilisateur(int $suggestionId, int $utilisateurId): Collection
    {
        return SuggestionHistorique::where('suggestion_id', $suggestionId)
            ->where('utilisateur_id', $utilisateurId)
            ->orderByDesc('created_at')
            ->get();
    }

    public function dernierChangement(int $suggestionId): ?SuggestionHistorique
    {
        return SuggestionHistorique::where('suggestion_id', $suggestionId)
            ->orderByDesc('created_at')
            ->first();
    }

    // =========================================================
    // 📊 STATISTIQUES
    // =========================================================
    public function compterParAction(int $suggestionId): array
    {
        return SuggestionHistorique::where('suggestion_id', $suggestionId)
            ->selectRaw('action, COUNT(*) as total')
            ->groupBy('action')
            ->pluck('total', 'action')
            ->toArray();
    }

    // =========================================================
    // 🗑️ SUPPRESSION (rarement utilisée)
    // =========================================================
    public function supprimerTout(int $suggestionId): int
    {
        return SuggestionHistorique::where('suggestion_id', $suggestionId)->delete();
    }
}