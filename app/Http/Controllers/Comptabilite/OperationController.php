<?php

namespace App\Http\Controllers\Comptabilite;

use App\Models\OperationsType;
use App\Models\Entite;
use App\Models\Succursale;
use App\Models\OperationsJournal;
use App\Services\OperationManuelService;
use App\Services\OperationAutoService;
use App\Traits\FiltersComptables;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;

class OperationController extends Controller
{
    use FiltersComptables;

    protected OperationManuelService $operationManuelService;
    protected OperationAutoService $autoService;

    private array $entiteCache = [];
    private array $typeCache = [];

    public function __construct(
        OperationManuelService $operationManuelService,
        OperationAutoService $autoService
    ) {
        $this->operationManuelService = $operationManuelService;
        $this->autoService = $autoService;
    }

    // =========================================================================
    // AFFICHAGE ET LISTES
    // =========================================================================

    /**
     * ✅ ADAPTÉ : Retour JSON structuré pour React
     */
    public function index(Request $request)
    {
        $filters = $this->buildOperationFilters($request);
        $operations = $this->operationManuelService->getAll($filters);
        $entites = Entite::where('is_active', 1)->orderBy('code', 'asc')->get();
        $operationTypes = OperationsType::orderBy('code', 'asc')->get();

        return response()->json([
            'success' => true,
            'operations' => $operations,
            'entites' => $entites,
            'operationTypes' => $operationTypes,
        ]);
    }

    public function show($id)
    {
        return response()->json(
            $this->operationManuelService->getById($id)
        );
    }

    public function getOperationsList(Request $request)
    {
        $filters = [
            'entite_id' => $request->input('entite_id'),
            'operation_type_id' => $request->input('operation_type_id'),
            'search' => $request->input('search'),
            'code' => $request->input('code'),
        ];

        foreach ($filters as $key => $value) {
            if (empty($value) || $value === 'all' || $value === '') {
                $filters[$key] = null;
            }
        }

        $operations = $this->operationManuelService->getAll($filters);

        return response()->json($operations);
    }

    // =========================================================================
    // CRUD OPÉRATIONS
    // =========================================================================

    public function store(Request $request)
    {
        $this->validateOperationRequest($request);

        if (empty($request->entite_ids) && empty($request->entite_codes)) {
            return $this->errorResponse(
                "Les champs 'entite_ids' ou 'entite_codes' sont requis.",
                422
            );
        }

        $data = $request->all();
        $data['entite_ids'] = $this->resoudreEntiteIds(
            $request->entite_ids ?? [],
            $request->entite_codes ?? []
        );

        $result = $this->operationManuelService->create($data);

        return $result['success']
            ? response()->json($result, 201)
            : response()->json($result, 400);
    }

    public function update(Request $request, $id)
    {
        $this->validateOperationRequest($request);

        if (empty($request->entite_ids) && empty($request->entite_codes)) {
            return $this->errorResponse(
                "Les champs 'entite_ids' ou 'entite_codes' sont requis.",
                422
            );
        }

        $data = $request->all();
        $data['entite_ids'] = $this->resoudreEntiteIds(
            $request->entite_ids ?? [],
            $request->entite_codes ?? []
        );

        $result = $this->operationManuelService->update($id, $data);

        return $result['success']
            ? response()->json($result)
            : response()->json($result, 400);
    }

    public function destroy($id)
    {
        $result = $this->operationManuelService->delete($id);

        return $result['success']
            ? response()->json($result)
            : response()->json($result, 400);
    }

    // =========================================================================
    // CODES ET VALIDATION
    // =========================================================================

    public function previewCode(Request $request)
    {
        $operationTypeId = $request->input('operation_type_id');

        if (!$operationTypeId) {
            return response()->json(['code' => '']);
        }

        return response()->json([
            'code' => $this->operationManuelService->previewCode($operationTypeId)
        ]);
    }

    public function checkCompte(Request $request)
    {
        return response()->json(
            $this->operationManuelService->checkCompte(
                $request->input('compte_numero')
            )
        );
    }

    public function getOperationTypesByEntite($entiteId)
    {
        if (!is_numeric($entiteId)) {
            $entite = $this->trouverEntiteParCode($entiteId);
            $entiteId = $entite ? $entite->id : null;
        }

        if (!$entiteId) {
            return response()->json([]);
        }

        return response()->json(
            $this->operationManuelService->getOperationTypesByEntite($entiteId)
        );
    }

    // =========================================================================
    // GESTION DES TYPES D'OPÉRATIONS
    // =========================================================================

    public function getOperationTypes()
    {
        return response()->json(
            OperationsType::orderBy('code')->get()
        );
    }

    public function getOperationType($id)
    {
        return response()->json(
            OperationsType::findOrFail($id)
        );
    }

    public function storeOperationType(Request $request)
    {
        $request->validate([
            'code' => 'required|string|max:20|unique:operations_types,code',
            'nom' => 'required|string|max:255'
        ]);

        try {
            $type = OperationsType::create([
                'code' => strtoupper($request->code),
                'nom' => $request->nom,
                'is_active' => true
            ]);

            Log::info("✅ Type d'opération créé", ['code' => $type->code]);

            return response()->json([
                'success' => true,
                'message' => 'Type d\'opération créé avec succès',
                'operation_type' => $type
            ]);
        } catch (\Exception $e) {
            Log::error("❌ Erreur création type opération: " . $e->getMessage());
            return $this->errorResponse($e->getMessage());
        }
    }

    public function updateOperationType(Request $request, $id)
    {
        $type = OperationsType::findOrFail($id);

        $request->validate([
            'code' => 'required|string|max:20|unique:operations_types,code,' . $id,
            'nom' => 'required|string|max:255'
        ]);

        try {
            $type->update([
                'code' => strtoupper($request->code),
                'nom' => $request->nom
            ]);

            Log::info("✅ Type d'opération modifié", ['id' => $id]);

            return response()->json([
                'success' => true,
                'message' => 'Type d\'opération modifié avec succès',
                'operation_type' => $type
            ]);
        } catch (\Exception $e) {
            Log::error("❌ Erreur modification type opération: " . $e->getMessage());
            return $this->errorResponse($e->getMessage());
        }
    }

    public function destroyOperationType($id)
    {
        try {
            $type = OperationsType::findOrFail($id);

            $operations = OperationsJournal::where('operation_type_id', $id)->get();
            $count = $operations->count();

            foreach ($operations as $operation) {
                $this->operationManuelService->delete($operation->id);
            }

            $type->delete();

            Log::info("✅ Type d'opération supprimé", [
                'id' => $id,
                'operations_supprimees' => $count
            ]);

            return response()->json([
                'success' => true,
                'message' => "Type d'opération et ses {$count} opération(s) supprimés avec succès"
            ]);
        } catch (\Exception $e) {
            Log::error("❌ Erreur suppression type opération: " . $e->getMessage());
            return $this->errorResponse($e->getMessage());
        }
    }

    // =========================================================================
    // GESTION DES OPÉRATIONS AUTOMATIQUES
    // =========================================================================

    public function getAutoOperations(Request $request)
    {
        return response()->json(
            $this->autoService->getAll(
                $request->input('code'),
                $request->input('entite_id')
            )
        );
    }

    public function getAutoOperation($id)
    {
        return response()->json(
            $this->autoService->getById($id)
        );
    }

    public function updateAutoOperation(Request $request, $id)
    {
        $request->validate([
            'libelle' => 'required|string|max:255',
            'compte_debit' => 'required|string|max:20',
            'compte_credit' => 'required|string|max:20',
            'type_calcul' => 'required|in:meme_montant,pourcentage,montant_fixe',
            'montant_fixe' => 'nullable|numeric',
            'pourcentage' => 'nullable|numeric|min:0|max:100',
            'sens_operation' => 'required|in:avant,apres'
        ]);

        $result = $this->autoService->update($id, $request->all());

        return $result['success']
            ? response()->json($result)
            : response()->json($result, 400);
    }

    public function destroyAutoOperation($id)
    {
        $result = $this->autoService->delete($id);

        return $result['success']
            ? response()->json($result)
            : response()->json($result, 400);
    }

    public function getAutoOperationParent($id)
    {
        $parent = $this->autoService->getParentOperation($id);

        return $parent
            ? response()->json($parent)
            : response()->json([
                'success' => false,
                'message' => 'Aucune opération parent trouvée'
            ]);
    }

    // =========================================================================
    // MÉTHODES PRIVÉES
    // =========================================================================

    private function buildOperationFilters(Request $request): array
    {
        $filters = [
            'code' => $request->input('code'),
            'entite_id' => $request->input('entite_id'),
            'operation_type_id' => $request->input('operation_type_id'),
            'search' => $request->input('search'),
        ];

        foreach ($filters as $key => $value) {
            if (empty($value) || $value === 'all' || $value === '') {
                $filters[$key] = null;
            }
        }

        if (!empty($filters['code']) && empty($filters['entite_id'])) {
            $filters['entite_id'] = $this->resolveEntiteId($filters['code'], null);
        }

        return $filters;
    }

    private function resolveEntiteId(?string $code, $entiteId)
    {
        if (!empty($entiteId)) {
            return $entiteId;
        }

        if (!empty($code)) {
            $entite = $this->trouverEntiteParCode($code);
            return $entite ? $entite->id : null;
        }

        return null;
    }

    private function validateOperationRequest(Request $request): void
    {
        $request->validate([
            'operation_type_id' => 'required|exists:operations_types,id',
            'entite_ids' => 'nullable|array|min:1',
            'entite_ids.*' => 'exists:entites,id',
            'entite_codes' => 'nullable|array|min:1',
            'entite_codes.*' => 'string|max:50',
            'libelle' => 'required|string|max:255',
            'compte_debit' => 'required|string|max:20',
            'compte_credit' => 'required|string|max:20',
            'has_tva' => 'boolean',
            'taux_tva' => 'nullable|numeric|min:0|max:100',
            'sens_tva' => 'nullable|in:debit,credit',
            'auto_operations' => 'nullable|array'
        ]);
    }

    private function trouverEntiteParCode(string $code): ?Entite
    {
        $code = strtoupper(trim($code));

        if (isset($this->entiteCache[$code])) {
            return $this->entiteCache[$code];
        }

        $entite = Entite::where('code', $code)->first();

        if (!$entite) {
            $succursale = Succursale::where('code', $code)->first();
            if ($succursale && $succursale->entite) {
                $entite = $succursale->entite;
            }
        }

        $this->entiteCache[$code] = $entite;

        return $entite;
    }

    private function resoudreEntiteIds(array $entiteIds, array $entiteCodes): array
    {
        $ids = $entiteIds;

        foreach ($entiteCodes as $code) {
            $entite = $this->trouverEntiteParCode($code);
            if ($entite) {
                $ids[] = $entite->id;
            } else {
                Log::warning("Code entité non trouvé", ['code' => $code]);
            }
        }

        return array_unique($ids);
    }

    private function errorResponse(string $message, int $code = 400)
    {
        return response()->json([
            'success' => false,
            'message' => $message
        ], $code);
    }
}