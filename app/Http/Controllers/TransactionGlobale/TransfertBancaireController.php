<?php

namespace App\Http\Controllers\TransactionGlobale;

use App\Http\Controllers\Controller;
use App\Models\TransfertBancaire;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TransfertBancaireController extends Controller
{
    /**
     * Liste paginée des transferts bancaires avec filtres optionnels.
     *
     * GET /api/transferts-bancaires
     *
     * Query params acceptés :
     *  - per_page         (int, défaut 15, max 100)
     *  - page             (int, défaut 1)
     *  - statut           (string : PAYE, EN_ATTENTE, ANNULE, ...)
     *  - type_operation   (string : DEPOT, RETRAIT)
     *  - id_operateur_bancaire (int)
     *  - succursale_id    (int)
     *  - caisse_id        (int)
     *  - devise_id        (int)
     *  - reference        (string : recherche partielle sur reference_transfert)
     *  - date_debut       (date Y-m-d)
     *  - date_fin         (date Y-m-d)
     *  - sort             (string : ex "created_at" ou "-created_at", défaut "-created_at")
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // ── 1. Validation des paramètres d'entrée ──────────────
            $validated = $request->validate([
                'per_page'               => 'sometimes|integer|min:1|max:100',
                'page'                   => 'sometimes|integer|min:1',
                'statut'                 => 'sometimes|string|max:50',
                'type_operation'         => 'sometimes|string|max:50',
                'id_operateur_bancaire'  => 'sometimes|integer|exists:transfert_international,id',
                'succursale_id'          => 'sometimes|integer|exists:succursale,id',
                'caisse_id'              => 'sometimes|integer|exists:caisse,id',
                'devise_id'              => 'sometimes|integer|exists:devise,id',
                'reference'              => 'sometimes|string|max:100',
                'date_debut'             => 'sometimes|date_format:Y-m-d',
                'date_fin'               => 'sometimes|date_format:Y-m-d|after_or_equal:date_debut',
                'sort'                   => 'sometimes|string|max:50',
            ]);

            $perPage = (int) ($validated['per_page'] ?? 15);

            // ── 2. Construction de la requête ──────────────────────
            $query = TransfertBancaire::query()
                ->with([
                    'transactionGlobale:id,reference,type,statut,montant_local,devise_id',
                    'operateurBancaire:id,nom_operateur,code_operateur',
                    'creator:id,nom_utilisateur',
                    'succursale:id,nom',
                    'caisse:id,nom_caisse',
                    'devise:id,code,nom',
                ]);

            // Filtres optionnels
            if (!empty($validated['statut'])) {
                $query->where('statut', $validated['statut']);
            }

            if (!empty($validated['type_operation'])) {
                $query->where('type_operation', $validated['type_operation']);
            }

            if (!empty($validated['id_operateur_bancaire'])) {
                $query->where('id_operateur_bancaire', $validated['id_operateur_bancaire']);
            }

            if (!empty($validated['succursale_id'])) {
                $query->where('succursale_id', $validated['succursale_id']);
            }

            if (!empty($validated['caisse_id'])) {
                $query->where('caisse_id', $validated['caisse_id']);
            }

            if (!empty($validated['devise_id'])) {
                $query->where('devise_id', $validated['devise_id']);
            }

            if (!empty($validated['reference'])) {
                $query->where('reference_transfert', 'LIKE', '%' . $validated['reference'] . '%');
            }

            if (!empty($validated['date_debut'])) {
                $query->whereDate('created_at', '>=', $validated['date_debut']);
            }

            if (!empty($validated['date_fin'])) {
                $query->whereDate('created_at', '<=', $validated['date_fin']);
            }

            // ── 3. Tri (sécurisé par whitelist) ────────────────────
            $sortableColumns = [
                'created_at', 'updated_at', 'montant',
                'statut', 'type_operation', 'reference_transfert',
            ];

            $sort       = $validated['sort'] ?? '-created_at';
            $direction  = str_starts_with($sort, '-') ? 'desc' : 'asc';
            $column     = ltrim($sort, '-');

            if (!in_array($column, $sortableColumns, true)) {
                $column = 'created_at';
                $direction = 'desc';
            }

            $query->orderBy($column, $direction);

            // ── 4. Pagination ──────────────────────────────────────
            $transferts = $query->paginate($perPage);

            // ── 5. Réponse JSON ────────────────────────────────────
            return response()->json([
                'success' => true,
                'message' => 'Liste des transferts bancaires récupérée avec succès.',
                'data'    => $transferts->items(),
                'meta'    => [
                    'current_page' => $transferts->currentPage(),
                    'per_page'     => $transferts->perPage(),
                    'total'        => $transferts->total(),
                    'last_page'    => $transferts->lastPage(),
                    'from'         => $transferts->firstItem(),
                    'to'           => $transferts->lastItem(),
                ],
                'links'   => [
                    'first' => $transferts->url(1),
                    'prev'  => $transferts->previousPageUrl(),
                    'next'  => $transferts->nextPageUrl(),
                    'last'  => $transferts->url($transferts->lastPage()),
                ],
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            // Erreurs de validation → 422
            return response()->json([
                'success' => false,
                'message' => 'Paramètres invalides.',
                'errors'  => $e->errors(),
            ], 422);

        } catch (\Throwable $e) {
            // Toute autre erreur → 500 + log
            Log::error('Erreur TransfertBancaireController@index', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'params'  => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => "Une erreur est survenue lors de la récupération des transferts bancaires.",
            ], 500);
        }
    }
}