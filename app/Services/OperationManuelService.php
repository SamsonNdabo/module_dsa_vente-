<?php
// app/Services/OperationManuelService.php

namespace App\Services;

use App\Models\OperationsJournal;
use App\Models\OperationsType;
use App\Models\Entite;
use App\Models\PlanComptable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OperationManuelService
{
    protected OperationAutoService $autoService;

    public function __construct(OperationAutoService $autoService)
    {
        $this->autoService = $autoService;
    }

    /**
     * Générer un code pour OperationsJournal
     * Format: [TYPE_CODE][NUMERO] (ex: ACHAT001)
     */
    public function generateOperationCode(int $operationTypeId): ?string
    {
        $operationType = OperationsType::find($operationTypeId);
        
        if (!$operationType) {
            return null;
        }
        
        $typeCode = $operationType->code;
        $count = OperationsJournal::where('operation_type_id', $operationTypeId)->count();
        $nextNumber = str_pad($count + 1, 3, '0', STR_PAD_LEFT);
        
        return $typeCode . $nextNumber;
    }

    /**
     * Générer un aperçu du code opération
     */
    public function previewCode(int $operationTypeId): string
    {
        return $this->generateOperationCode($operationTypeId) ?? '';
    }

    /**
     * Récupérer toutes les opérations avec filtres
     */
    public function getAll(array $filters = [])
    {
        $query = OperationsJournal::with(['operations_type', 'entites', 'operations_autos']);
        
        if (!empty($filters['entite_id']) && $filters['entite_id'] !== 'all') {
            $query->whereHas('entites', function($q) use ($filters) {
                $q->where('entites.id', $filters['entite_id']);
            });
        }
        
        if (!empty($filters['operation_type_id']) && $filters['operation_type_id'] !== 'all') {
            $query->where('operation_type_id', $filters['operation_type_id']);
        }
        
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function($q) use ($search) {
                $q->where('code_operation', 'LIKE', "%{$search}%")
                  ->orWhere('libelle', 'LIKE', "%{$search}%")
                  ->orWhere('compte_debit', 'LIKE', "%{$search}%")
                  ->orWhere('compte_credit', 'LIKE', "%{$search}%");
            });
        }
        
        return $query->orderBy('code_operation', 'asc')->get();
    }

    /**
     * Récupérer la liste complète des opérations
     */
    public function getAllList()
    {
        return OperationsJournal::with(['operations_type', 'entites', 'operations_autos'])
            ->orderBy('code_operation', 'asc')
            ->get();
    }

    /**
     * Récupérer une opération par son ID avec tous les détails
     */
    public function getById(int $id)
    {
        $operation = OperationsJournal::with(['operations_type', 'entites', 'operations_autos'])->findOrFail($id);
        
        // Enrichir avec les noms des comptes
        $operation->compte_debit_nom = PlanComptable::where('numero', $operation->compte_debit)->value('nom') ?: 'Compte inconnu';
        $operation->compte_credit_nom = PlanComptable::where('numero', $operation->compte_credit)->value('nom') ?: 'Compte inconnu';
        
        // Enrichir les opérations automatiques
        foreach ($operation->operations_autos as $auto) {
            $auto->compte_debit_nom = PlanComptable::where('numero', $auto->compte_debit)->value('nom') ?: 'Compte inconnu';
            $auto->compte_credit_nom = PlanComptable::where('numero', $auto->compte_credit)->value('nom') ?: 'Compte inconnu';
        }
        
        return $operation;
    }

    /**
     * Créer une nouvelle opération
     */
    public function create(array $data): array
    {
        DB::beginTransaction();
        try {
            // Déterminer le compte TVA si applicable
            $compteTva = null;
            if ($data['has_tva'] ?? false) {
                $compteTva = ($data['sens_tva'] ?? '') === 'debit' ? '445' : '443';
            }
            
            // Valider les comptes
            $this->validateCompte($data['compte_debit'] ?? '', 'compte débit');
            $this->validateCompte($data['compte_credit'] ?? '', 'compte crédit');
            if ($compteTva) {
                $this->validateCompte($compteTva, 'compte TVA');
            }
            
            // Valider les comptes des opérations automatiques
            if (!empty($data['auto_operations']) && is_array($data['auto_operations'])) {
                $this->validateAutoComptes($data['auto_operations']);
            }
            
            // Générer le code opération
            $codeOperation = $this->generateOperationCode($data['operation_type_id']);
            
            if (!$codeOperation) {
                throw new \Exception('Impossible de générer le code opération');
            }
            
            // Créer l'opération
            $operation = OperationsJournal::create([
                'code_operation' => $codeOperation,
                'operation_type_id' => $data['operation_type_id'],
                'libelle' => $data['libelle'],
                'compte_debit' => $data['compte_debit'],
                'compte_credit' => $data['compte_credit'],
                'auto_operation_code' => $data['auto_operation_code'] ?? null,
                'has_tva' => $data['has_tva'] ?? false,
                'taux_tva' => $data['taux_tva'] ?? 16,
                'sens_tva' => $data['sens_tva'] ?? null,
                'is_active' => true
            ]);
            
            // Attacher les entités
            $operation->entites()->attach($data['entite_ids']);
            
            // Créer les opérations automatiques
            if (!empty($data['auto_operations']) && is_array($data['auto_operations'])) {
                $this->autoService->createForOperation($operation->id, $data['auto_operations']);
            }
            
            DB::commit();
            
            // Recharger avec les relations
            $operation->load(['operations_type', 'entites', 'operations_autos']);
            
            Log::info("✅ Opération créée", [
                'id' => $operation->id,
                'code' => $operation->code_operation,
                'libelle' => $operation->libelle
            ]);
            
            return [
                'success' => true,
                'message' => 'Opération créée avec succès',
                'operation' => $operation
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("❌ Erreur création opération: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Mettre à jour une opération existante
     */
    public function update(int $id, array $data): array
    {
        $operation = OperationsJournal::findOrFail($id);
        
        DB::beginTransaction();
        try {
            $compteTva = null;
            if ($data['has_tva'] ?? false) {
                $compteTva = ($data['sens_tva'] ?? '') === 'debit' ? '445' : '443';
            }
            
            $this->validateCompte($data['compte_debit'] ?? '', 'compte débit');
            $this->validateCompte($data['compte_credit'] ?? '', 'compte crédit');
            if ($compteTva) {
                $this->validateCompte($compteTva, 'compte TVA');
            }
            
            $operation->update([
                'operation_type_id' => $data['operation_type_id'],
                'libelle' => $data['libelle'],
                'compte_debit' => $data['compte_debit'],
                'compte_credit' => $data['compte_credit'],
                'has_tva' => $data['has_tva'] ?? $operation->has_tva,
                'taux_tva' => $data['taux_tva'] ?? $operation->taux_tva,
                'sens_tva' => $data['sens_tva'] ?? $operation->sens_tva
            ]);
            
            // Synchroniser les entités
            $operation->entites()->sync($data['entite_ids']);
            
            // Synchroniser les opérations automatiques
            if (isset($data['auto_operations']) && is_array($data['auto_operations'])) {
                $this->autoService->syncForOperation($id, $data['auto_operations']);
            }
            
            DB::commit();
            
            $operation->load(['operations_type', 'entites', 'operations_autos']);
            
            Log::info("✅ Opération modifiée", ['id' => $id, 'libelle' => $data['libelle']]);
            
            return [
                'success' => true,
                'message' => 'Opération modifiée avec succès',
                'operation' => $operation
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("❌ Erreur modification opération: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Supprimer une opération et ses opérations automatiques
     */
    public function delete(int $id): array
    {
        DB::beginTransaction();
        try {
            $operation = OperationsJournal::findOrFail($id);
            
            // Supprimer les relations many-to-many
            $operation->entites()->detach();
            
            // Supprimer les opérations automatiques liées
            $deletedAuto = $this->autoService->deleteForOperation($id);
            
            // Supprimer l'opération parent
            $operation->delete();
            
            DB::commit();
            
            Log::info("✅ Opération supprimée", [
                'id' => $id,
                'auto_supprimees' => $deletedAuto
            ]);
            
            return [
                'success' => true,
                'message' => "Opération et ses {$deletedAuto} opération(s) automatique(s) supprimées avec succès"
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("❌ Erreur suppression opération: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Vérifier l'existence d'un compte dans le plan comptable
     */
    public function checkCompte(string $compteNumero): array
    {
        if (empty($compteNumero)) {
            return [
                'exists' => false,
                'message' => 'Numéro de compte requis'
            ];
        }
        
        $existe = PlanComptable::where('numero', $compteNumero)->exists();
        
        return [
            'exists' => $existe,
            'message' => $existe ? 'Compte valide' : "Le compte '{$compteNumero}' n'existe pas"
        ];
    }

    /**
     * Récupérer les types d'opérations par entité
     */
    public function getOperationTypesByEntite(int $entiteId)
    {
        return OperationsJournal::where('entite_id', $entiteId)
            ->with('operations_type')
            ->select('operation_type_id')
            ->distinct()
            ->get();
    }

    /**
     * Valider un compte
     */
    private function validateCompte(string $compteNumero, string $fieldName): bool
    {
        if (empty($compteNumero)) {
            return true;
        }
        
        $existe = PlanComptable::where('numero', $compteNumero)->exists();
        
        if (!$existe) {
            throw new \Exception("Le compte '{$compteNumero}' n'existe pas dans le plan comptable. Vérifiez le champ '{$fieldName}'.");
        }
        
        return true;
    }

    /**
     * Valider les comptes des opérations automatiques
     */
    private function validateAutoComptes(array $autoOperations): bool
    {
        foreach ($autoOperations as $autoOp) {
            if (!empty($autoOp['compte_debit'])) {
                $this->validateCompte($autoOp['compte_debit'], 'compte débit (opération auto)');
            }
            if (!empty($autoOp['compte_credit'])) {
                $this->validateCompte($autoOp['compte_credit'], 'compte crédit (opération auto)');
            }
        }
        return true;
    }
}