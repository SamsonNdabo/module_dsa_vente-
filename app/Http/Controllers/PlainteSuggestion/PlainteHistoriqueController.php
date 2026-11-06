<?php

namespace App\Http\Controllers\PlainteSuggestion;

use App\Http\Controllers\Controller;
use App\Models\PlainteSuggestion\Plainte;
use App\Models\PlainteSuggestion\PlainteHistorique;
use App\Services\PlainteSuggestion\PlainteHistoriqueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlainteHistoriqueController extends Controller
{
    public function __construct(
        protected PlainteHistoriqueService $service
    ) {}

    // =========================================================
    // 📋 LISTE DES HISTORIQUES D'UNE PLAINTE
    // Retourne un tableau simple (pas de pagination)
    // pour que le frontend puisse lire facilement created_at
    // =========================================================
    public function index(Request $request, $plainteId): JsonResponse
    {
        $plainte = $this->resoudrePlainte($plainteId);

        $historiques = PlainteHistorique::where('plainte_id', $plainte->id)
            ->with([
                'utilisateur:id,nom_complet',
                'ancienUtilisateur:id,nom_complet',
                'nouveauUtilisateur:id,nom_complet',
            ])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($h) => [
                'id'             => $h->id,
                'plainte_id'     => $h->plainte_id,
                'action'         => $h->action,
                'commentaire'    => $h->commentaire,
                'ancien_statut'  => $h->ancien_statut,
                'nouveau_statut' => $h->nouveau_statut,
                'created_at'     => $h->created_at?->toISOString(),   //  CRUCIAL
                'updated_at'     => $h->updated_at?->toISOString(),
                'utilisateur'    => $h->utilisateur ? [
                    'id'          => $h->utilisateur->id,
                    'nom_complet' => $h->utilisateur->nom_complet,
                ] : null,
                'ancien_utilisateur' => $h->ancienUtilisateur ? [
                    'id'          => $h->ancienUtilisateur->id,
                    'nom_complet' => $h->ancienUtilisateur->nom_complet,
                ] : null,
                'nouveau_utilisateur' => $h->nouveauUtilisateur ? [
                    'id'          => $h->nouveauUtilisateur->id,
                    'nom_complet' => $h->nouveauUtilisateur->nom_complet,
                ] : null,
            ]);

        return response()->json($historiques);
    }

    // =========================================================
    // 🔍 HISTORIQUES PAR ACTION
    // =========================================================
    public function parAction($plainteId, string $action): JsonResponse
    {
        return response()->json(
            $this->service->parAction($plainteId, $action)
        );
    }

    // =========================================================
    // 🔍 HISTORIQUES PAR UTILISATEUR
    // =========================================================
    public function parUtilisateur($plainteId, int $utilisateurId): JsonResponse
    {
        return response()->json(
            $this->service->parUtilisateur($plainteId, $utilisateurId)
        );
    }

    // =========================================================
    // 🔍 DERNIER CHANGEMENT
    // =========================================================
    public function dernier($plainteId): JsonResponse
    {
        return response()->json(
            $this->service->dernierChangement($plainteId)
        );
    }

    // =========================================================
    // 📊 STATS PAR ACTION
    // =========================================================
    public function stats($plainteId): JsonResponse
    {
        return response()->json(
            $this->service->compterParAction($plainteId)
        );
    }

    // =========================================================
    //  HELPER : Résoudre une plainte par ID ou référence
    // =========================================================
    private function resoudrePlainte($plainteId): Plainte
    {
        if (is_numeric($plainteId) && ctype_digit((string) $plainteId)) {
            return Plainte::findOrFail((int) $plainteId);
        }
        return Plainte::where('reference', $plainteId)->firstOrFail();
    }
}