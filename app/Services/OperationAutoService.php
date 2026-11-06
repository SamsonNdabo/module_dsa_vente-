<?php

namespace App\Services;

use App\Models\OperationsAuto;
use App\Models\Entite;
use App\Models\Succursale;
use App\Models\PlanComptable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OperationAutoService
{
    /**
     *  Trouver une entité par code ou par ID
     * Priorité : code > entite_id
     */
    private function trouverEntite(?string $code = null, ?int $entiteId = null): ?Entite
    {
        // 1. Chercher par code d'abord (prioritaire)
        if (!empty($code)) {
            $code = strtoupper(trim($code));
            
            $entite = Entite::where('code', $code)->first();
            if ($entite) {
                Log::info(" Entité trouvée par code", ['code' => $code, 'id' => $entite->id]);
                return $entite;
            }
            
            // Chercher via succursale
            $succursale = Succursale::where('code', $code)->first();
            if ($succursale && $succursale->entite) {
                Log::info(" Entité trouvée via succursale", ['code_succ' => $code, 'entite_id' => $succursale->entite->id]);
                return $succursale->entite;
            }
            
            throw new \Exception("Aucune entité trouvée avec le code '{$code}'");
        }

        // 2. Fallback : chercher par ID
        if (!empty($entiteId)) {
            $entite = Entite::find($entiteId);
            if ($entite) {
                Log::info(" Entité trouvée par ID", ['id' => $entiteId, 'code' => $entite->code]);
                return $entite;
            }
            throw new \Exception("Aucune entité trouvée avec l'ID '{$entiteId}'");
        }

        return null;
    }

    /**
     * Générer un code pour OperationsAuto à partir du libellé
     * Format: [PREFIX][NUMERO] (ex: REMISE001)
     */
    public function generateAutoOperationCode(string $libelle, ?int $excludeId = null): string
    {
        if (empty($libelle)) {
            return 'AUTO_' . date('Ymd') . '_' . rand(100, 999);
        }
        
        $prefix = strtoupper($libelle);
        
        // Remplacer les accents
        $prefix = str_replace(
            ['À', 'Á', 'Â', 'Ã', 'Ä', 'Å', 'Ç', 'È', 'É', 'Ê', 'Ë', 'Ì', 'Í', 'Î', 'Ï', 'Ò', 'Ó', 'Ô', 'Õ', 'Ö', 'Ù', 'Ú', 'Û', 'Ü', 'Ý', 'à', 'á', 'â', 'ã', 'ä', 'å', 'ç', 'è', 'é', 'ê', 'ë', 'ì', 'í', 'î', 'ï', 'ò', 'ó', 'ô', 'õ', 'ö', 'ù', 'ú', 'û', 'ü', 'ý', 'ÿ'],
            ['A', 'A', 'A', 'A', 'A', 'A', 'C', 'E', 'E', 'E', 'E', 'I', 'I', 'I', 'I', 'O', 'O', 'O', 'O', 'O', 'U', 'U', 'U', 'U', 'Y', 'A', 'A', 'A', 'A', 'A', 'A', 'C', 'E', 'E', 'E', 'E', 'I', 'I', 'I', 'I', 'O', 'O', 'O', 'O', 'O', 'U', 'U', 'U', 'U', 'Y', 'Y'],
            $prefix
        );
        
        // Garder uniquement les lettres majuscules
        $prefix = preg_replace('/[^A-Z]/', '', $prefix);
        
        // Limiter à 6 caractères
        if (strlen($prefix) > 6) {
            $prefix = substr($prefix, 0, 6);
        }
        
        if (empty($prefix)) {
            $prefix = 'AUTO';
        }
        
        // Trouver le dernier numéro utilisé pour ce préfixe
        $query = OperationsAuto::where('code_operation', 'LIKE', $prefix . '%');
        
        // Exclure l'enregistrement courant en cas de mise à jour
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }
        
        // Récupérer le dernier code pour ce préfixe
        $lastCode = $query->orderBy('id', 'desc')->value('code_operation');
        
        // Extraire le numéro du dernier code ou commencer à 1
        if ($lastCode && preg_match('/(\d+)$/', $lastCode, $matches)) {
            $lastNumber = (int) $matches[1];
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }
        
        // Générer le nouveau code
        $newCode = $prefix . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
        
        // Vérification finale : s'assurer que le code est vraiment unique
        $counter = 0;
        while ($counter < 100) {
            $exists = OperationsAuto::where('code_operation', $newCode)
                ->when($excludeId, function($q) use ($excludeId) {
                    return $q->where('id', '!=', $excludeId);
                })
                ->exists();
            
            if (!$exists) {
                return $newCode;
            }
            
            $nextNumber++;
            $newCode = $prefix . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
            $counter++;
        }
        
        // Fallback de sécurité (ne devrait jamais arriver)
        return $prefix . '_' . date('His') . '_' . rand(100, 999);
    }

    /**
     * Construire les données d'une opération auto
     */
    public function buildAutoData(int $operationId, array $autoOp): array
    {
        $typeCalcul = $autoOp['type_calcul'] ?? 'meme_montant';
        $libelle = $autoOp['libelle'] ?? '';
        
        // Passer l'ID si existant pour exclure lors de l'update
        $excludeId = !empty($autoOp['id']) ? (int) $autoOp['id'] : null;
        
        return [
            'parent_operation_id' => $operationId,
            'libelle'             => $libelle,
            'compte_debit'        => $autoOp['compte_debit'] ?? '',
            'compte_credit'       => $autoOp['compte_credit'] ?? '',
            'type_calcul'         => $typeCalcul,
            'montant_fixe'        => ($typeCalcul === 'montant_fixe') ? ($autoOp['montant_fixe'] ?? null) : null,
            'is_percentage'       => ($typeCalcul === 'pourcentage'),
            'pourcentage'         => ($typeCalcul === 'pourcentage') ? ($autoOp['pourcentage'] ?? null) : null,
            'sens_operation'      => $autoOp['sens_operation'] ?? 'apres',
            'code_operation'      => $this->generateAutoOperationCode($libelle, $excludeId),
            'is_active'           => $autoOp['is_active'] ?? 1
        ];
    }

    /**
     * Récupérer toutes les opérations automatiques
     *  Supporte le filtrage par code entité ou entite_id
     */
    public function getAll(?string $code = null, ?int $entiteId = null)
    {
        $query = OperationsAuto::with(['parentOperation.operations_type', 'parentOperation.entites'])
            ->orderBy('created_at', 'desc');
        
        //  Filtrer par entité si code ou ID fourni
        if (!empty($code) || !empty($entiteId)) {
            try {
                $entite = $this->trouverEntite($code, $entiteId);
                if ($entite) {
                    $query->whereHas('parentOperation.entites', function($q) use ($entite) {
                        $q->where('entites.id', $entite->id);
                    });
                }
            } catch (\Exception $e) {
                Log::warning("Filtrage entité ignoré: " . $e->getMessage());
            }
        }
        
        $autoOperations = $query->get();
        
        // Enrichir avec les noms des comptes
        foreach ($autoOperations as $auto) {
            $auto->compte_debit_nom = PlanComptable::where('numero', $auto->compte_debit)->value('nom') ?: 'Compte inconnu';
            $auto->compte_credit_nom = PlanComptable::where('numero', $auto->compte_credit)->value('nom') ?: 'Compte inconnu';
        }
        
        return $autoOperations;
    }

    /**
     * Récupérer une opération automatique par son ID
     */
    public function getById(int $id)
    {
        $autoOperation = OperationsAuto::with(['parentOperation.operations_type'])->findOrFail($id);
        
        $autoOperation->compte_debit_nom = PlanComptable::where('numero', $autoOperation->compte_debit)->value('nom') ?: 'Compte inconnu';
        $autoOperation->compte_credit_nom = PlanComptable::where('numero', $autoOperation->compte_credit)->value('nom') ?: 'Compte inconnu';
        
        return $autoOperation;
    }

    /**
     * Récupérer l'opération parent d'une opération automatique
     */
    public function getParentOperation(int $id)
    {
        $autoOp = OperationsAuto::with(['parentOperation.operations_type'])->findOrFail($id);
        return $autoOp->parentOperation;
    }

    /**
     * Créer plusieurs opérations automatiques pour une opération parent
     */
    public function createForOperation(int $operationId, array $autoOperations): array
    {
        $created = [];
        
        foreach ($autoOperations as $autoOp) {
            $autoData = $this->buildAutoData($operationId, $autoOp);
            $created[] = OperationsAuto::create($autoData);
        }
        
        Log::info(" Opérations automatiques créées", [
            'operation_id' => $operationId,
            'count'        => count($created)
        ]);
        
        return $created;
    }

    /**
     * Synchroniser les opérations automatiques d'une opération parent
     * (Crée les nouvelles, met à jour les existantes, supprime les absentes)
     */
    public function syncForOperation(int $operationId, array $autoOperations): void
    {
        $idsRecus = [];
        
        foreach ($autoOperations as $autoOp) {
            $autoData = $this->buildAutoData($operationId, $autoOp);
            
            // Si l'opération auto a un ID, on la met à jour
            if (!empty($autoOp['id'])) {
                $autoExistante = OperationsAuto::where('id', $autoOp['id'])
                    ->where('parent_operation_id', $operationId)
                    ->first();
                
                if ($autoExistante) {
                    $autoExistante->update($autoData);
                    $idsRecus[] = $autoOp['id'];
                    continue;
                }
            }
            
            // Sinon on la crée
            $nouvelleAuto = OperationsAuto::create($autoData);
            $idsRecus[] = $nouvelleAuto->id;
        }
        
        // Supprimer les opérations automatiques qui ne sont plus dans la liste
        OperationsAuto::where('parent_operation_id', $operationId)
            ->whereNotIn('id', $idsRecus)
            ->delete();
        
        Log::info(" Opérations automatiques synchronisées", [
            'operation_id' => $operationId,
            'conservées'   => count($idsRecus)
        ]);
    }

    /**
     * Mettre à jour une opération automatique
     */
    public function update(int $id, array $data): array
    {
        $autoOp = OperationsAuto::findOrFail($id);
        
        DB::beginTransaction();
        try {
            // Valider les comptes
            $this->validateCompte($data['compte_debit'] ?? '', 'compte débit');
            $this->validateCompte($data['compte_credit'] ?? '', 'compte crédit');
            
            $updateData = [
                'libelle'        => $data['libelle'],
                'compte_debit'   => $data['compte_debit'],
                'compte_credit'  => $data['compte_credit'],
                'type_calcul'    => $data['type_calcul'],
                'sens_operation' => $data['sens_operation'],
                'is_percentage'  => $data['type_calcul'] === 'pourcentage',
            ];
            
            // Gérer les montants selon le type de calcul
            if ($data['type_calcul'] === 'montant_fixe') {
                $updateData['montant_fixe'] = $data['montant_fixe'] ?? null;
                $updateData['pourcentage'] = null;
            } elseif ($data['type_calcul'] === 'pourcentage') {
                $updateData['pourcentage'] = $data['pourcentage'] ?? null;
                $updateData['montant_fixe'] = null;
            } else {
                $updateData['montant_fixe'] = null;
                $updateData['pourcentage'] = null;
            }
            
            // Régénérer le code si le libellé a changé
            if ($autoOp->libelle !== $data['libelle']) {
                $newCode = $this->generateAutoOperationCode($data['libelle'], $id);
                $updateData['code_operation'] = $newCode;
            }
            
            $autoOp->update($updateData);
            
            DB::commit();
            
            $autoOp->load(['parentOperation.operations_type']);
            
            Log::info(" Opération automatique modifiée", ['id' => $id, 'libelle' => $data['libelle']]);
            
            return [
                'success'         => true,
                'message'         => 'Opération automatique modifiée avec succès',
                'auto_operation'  => $autoOp
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("❌ Erreur modification opération auto: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Supprimer une opération automatique
     */
    public function delete(int $id): array
    {
        try {
            $autoOp = OperationsAuto::findOrFail($id);
            $autoOp->delete();
            
            Log::info(" Opération automatique supprimée", ['id' => $id]);
            
            return [
                'success' => true,
                'message' => 'Opération automatique supprimée avec succès'
            ];
        } catch (\Exception $e) {
            Log::error("❌ Erreur suppression opération auto: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Supprimer toutes les opérations automatiques d'une opération parent
     */
    public function deleteForOperation(int $operationId): int
    {
        return OperationsAuto::where('parent_operation_id', $operationId)->delete();
    }

    /**
     * Valider l'existence d'un compte dans le plan comptable
     */
    public function validateCompte(string $compteNumero, string $fieldName): bool
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
}