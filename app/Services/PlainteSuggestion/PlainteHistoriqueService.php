<?php

namespace App\Services\PlainteSuggestion;

use App\Models\PlainteSuggestion\Plainte;
use App\Models\PlainteSuggestion\PlainteHistorique;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class PlainteHistoriqueService
{
    // =========================================================
    // 🔍 RÉSOUDRE UNE PLAINTE (ID ou Référence)
    // =========================================================
    public function trouverPlainte($plainteId): Plainte
    {
        // Si c'est un nombre pur → on cherche par ID
        if (is_numeric($plainteId) && ctype_digit((string) $plainteId)) {
            return Plainte::findOrFail((int) $plainteId);
        }

        // Sinon → on cherche par référence (ex: "PL-2026-0002")
        return Plainte::where('reference', $plainteId)->firstOrFail();
    }

    // =========================================================
    // 📋 LISTE FILTRÉE PAR PLAINTE
    // =========================================================
    public function lister($plainteId, array $filtres = []): LengthAwarePaginator
    {
        $plainte = $this->trouverPlainte($plainteId);

        $query = PlainteHistorique::with([
                'utilisateur:id,nom_complet',
                'ancienUtilisateur:id,nom_complet',
                'nouveauUtilisateur:id,nom_complet',
            ])
            ->where('plainte_id', $plainte->id);

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
    // 🔍 FILTRES SPÉCIFIQUES (tous utilisent trouverPlainte)
    // =========================================================
    public function parAction($plainteId, string $action): Collection
    {
        $plainte = $this->trouverPlainte($plainteId);

        return PlainteHistorique::where('plainte_id', $plainte->id)
            ->where('action', $action)
            ->orderByDesc('created_at')
            ->get();
    }

    public function parUtilisateur($plainteId, int $utilisateurId): Collection
    {
        $plainte = $this->trouverPlainte($plainteId);

        return PlainteHistorique::where('plainte_id', $plainte->id)
            ->where('utilisateur_id', $utilisateurId)
            ->orderByDesc('created_at')
            ->get();
    }

    public function dernierChangement($plainteId): ?PlainteHistorique
    {
        $plainte = $this->trouverPlainte($plainteId);

        return PlainteHistorique::where('plainte_id', $plainte->id)
            ->orderByDesc('created_at')
            ->first();
    }

    // =========================================================
    // 📊 STATISTIQUES
    // =========================================================
    public function compterParAction($plainteId): array
    {
        $plainte = $this->trouverPlainte($plainteId);

        return PlainteHistorique::where('plainte_id', $plainte->id)
            ->selectRaw('action, COUNT(*) as total')
            ->groupBy('action')
            ->pluck('total', 'action')
            ->toArray();
    }

    // =========================================================
    // 🗑️ SUPPRESSION
    // =========================================================
    public function supprimerTout($plainteId): int
    {
        $plainte = $this->trouverPlainte($plainteId);

        return PlainteHistorique::where('plainte_id', $plainte->id)->delete();
    }
}