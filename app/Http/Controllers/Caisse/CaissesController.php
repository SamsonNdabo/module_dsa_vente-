<?php

namespace App\Http\Controllers\Caisse;

use App\DTO\ApprovisionnementDTO;
use App\DTO\FermetureDTO;
use App\DTO\TransfertDTO;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Services\CaisseService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CaissesController extends Controller
{
    protected CaisseService $caisseService;

    public function __construct(CaisseService $caisseService)
    {
        $this->caisseService = $caisseService;
    }

    /**
     * Liste des caisses avec filtres et permissions
     */
    public function index(Request $request)
    {
        try {
            $user = auth()->user();
            
            // ============================================================
            // 🔐 GESTION DES PERMISSIONS DIRECTEMENT DANS LE CONTROLLER
            // ============================================================
            
            $filters = $request->only(['statut', 'succursale_id', 'type_caisse', 'inactif', 'categorie', 'parent_id', 'recherche', 'per_page', 'sort', 'direction']);
            
            //  Si l'utilisateur n'est pas SuperAdmin, ChefOperation ou Auditeur
            if (!$user->isSuperAdmin() && !$user->isChefOperation() && !$user->isAuditeur()) {
                
                //  ChefAgence → Uniquement sa succursale
                if ($user->isChefAgence()) {
                    if (!$user->succursale_id) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Aucune succursale associée à cet utilisateur'
                        ], 403);
                    }
                    $filters['succursale_id'] = $user->succursale_id;
                } 
                //  Autres utilisateurs → Uniquement leur succursale
                else {
                    if ($user->succursale_id) {
                        $filters['succursale_id'] = $user->succursale_id;
                    }
                }
            }
            
            //  Si l'utilisateur essaie de filtrer sur une autre succursale (tentative de contournement)
            if ($request->filled('succursale_id') && !$user->isSuperAdmin() && !$user->isChefOperation() && !$user->isAuditeur()) {
                if ($request->succursale_id != ($filters['succursale_id'] ?? null)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Vous ne pouvez pas filtrer sur cette succursale'
                    ], 403);
                }
            }
            
            $caisses = $this->caisseService->getCaisses($filters);
            
            return response()->json([
                'success' => true,
                'message' => 'Liste des caisses',
                'data'    => $caisses,
            ]);
            
        } catch (BusinessException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode());
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Liste des coffres avec filtres et permissions
     */
    public function indexCoffres(Request $request)
    {
        try {
            $user = auth()->user();
            
            // ============================================================
            // 🔐 GESTION DES PERMISSIONS DIRECTEMENT DANS LE CONTROLLER
            // ============================================================
            
            $filters = $request->only(['statut', 'succursale_id', 'type_caisse', 'inactif', 'recherche', 'per_page', 'sort', 'direction']);
            
            //  Si l'utilisateur n'est pas SuperAdmin, ChefOperation ou Auditeur
            if (!$user->isSuperAdmin() && !$user->isChefOperation() && !$user->isAuditeur()) {
                
                //  ChefAgence → Uniquement sa succursale
                if ($user->isChefAgence()) {
                    if (!$user->succursale_id) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Aucune succursale associée à cet utilisateur'
                        ], 403);
                    }
                    $filters['succursale_id'] = $user->succursale_id;
                } 
                //  Autres utilisateurs → Uniquement leur succursale
                else {
                    if ($user->succursale_id) {
                        $filters['succursale_id'] = $user->succursale_id;
                    }
                }
            }
            
            //  Si l'utilisateur essaie de filtrer sur une autre succursale (tentative de contournement)
            if ($request->filled('succursale_id') && !$user->isSuperAdmin() && !$user->isChefOperation() && !$user->isAuditeur()) {
                if ($request->succursale_id != ($filters['succursale_id'] ?? null)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Vous ne pouvez pas filtrer sur cette succursale'
                    ], 403);
                }
            }
            
            $caisses = $this->caisseService->getCoffres($filters);
            
            return response()->json([
                'success' => true,
                'message' => 'Liste des coffres',
                'data'    => $caisses,
            ]);
            
        } catch (BusinessException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode());
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $user = auth()->id();
        try {
            $rules = [
                'nom_caisse'              => 'required|string|max:100',
                'type_caisse'             => 'required|string|max:100',
                'categorie'               => 'required|in:COFFRE,PRINCIPALE,SECONDAIRE',
                'succursale_id'           => 'required|integer|exists:succursales,id',
                'caissier_attribue'       => 'nullable|integer|exists:utilisateurs,id',
                'parent_id'               => 'nullable|integer|exists:caisses,id',
                'service_mobile_money_id' => 'nullable|integer|exists:service_mobile_money,id',
                'code'                    => 'nullable|string|unique:caisses,code',
            ];

            if ($request->input('type_caisse') != 'PHYSIQUE') {
                $rules['service_mobile_money_id'] = 'nullable|integer|exists:service_mobile_money,id';
            }

            $validated = $request->validate($rules);
            $caisse = $this->caisseService->creerCaisse($validated, $user);

            return response()->json([
                'success' => true,
                'message' => 'Caisse créée avec succès',
                'data'    => $caisse,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Erreur de validation', 'errors' => $e->errors()], 422);
        } catch (BusinessException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode());
        }
    }

    public function show($id)
    {
        try {
            if (!ctype_digit((string) $id)) {
                throw new BusinessException('ID invalide', 400);
            }
            $caisse = \App\Models\Caisses::with(['succursale', 'devise', 'caissier', 'soldes.devise'])->find($id);
            if (!$caisse) {
                throw new BusinessException('Caisse introuvable', 404);
            }
            return response()->json([
                'success' => true,
                'message' => 'Détails de la caisse',
                'data'    => $caisse,
            ]);
        } catch (BusinessException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode());
        }
    }

    public function update(Request $request, $id)
    {
        try {
            if (!ctype_digit((string) $id)) {
                throw new BusinessException('ID invalide', 400);
            }
            $validated = $request->validate([
                'nom_caisse'               => 'sometimes|string|max:100',
                'type_caisse'              => 'sometimes|string|max:100',
                'categorie'                => 'sometimes|in:COFFRE,PRINCIPALE,SECONDAIRE',
                'caissier_attribue'        => 'nullable|integer|exists:utilisateurs,id',
                'parent_id'                => 'nullable|integer|exists:caisses,id|different:id',
                'soldes'                   => 'sometimes|array',
                'soldes.*.devise_id'       => 'required|integer|exists:devise,id',
                'soldes.*.solde_minimal'   => 'nullable|numeric|min:0',
                'soldes.*.solde_maximal'   => 'nullable|numeric|min:0',
                'devises_a_supprimer'      => 'sometimes|array',
                'devises_a_supprimer.*'    => 'integer|exists:devise,id',
            ]);

            $result = $this->caisseService->mettreAJour((int) $id, $validated);

            return response()->json([
                'success' => true,
                'message' => 'Caisse mise à jour avec succès',
                'data'    => $result,
            ]);
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Erreur de validation', 'errors' => $e->errors()], 422);
        } catch (BusinessException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode());
        }
    }

    public function ouvrir(Request $request, $id)
    {
        try {
            if (!ctype_digit((string) $id)) {
                throw new BusinessException('ID invalide', 400);
            }
            $validated = $request->validate([
                'utilisateur_id' => 'required|integer|exists:utilisateurs,id',
                'notes'          => 'nullable|string',
            ]);

            // Vérifier que la succursale est active
            $caisse = \App\Models\Caisses::find($id);
            if (!$caisse) {
                throw new BusinessException('Caisse introuvable', 404);
            }
            $succursale = \App\Models\Succursale::find($caisse->succursale_id);
            if (!$succursale || $succursale->statut != 'ACTIVE') {
                throw new BusinessException('La succursale n\'est pas ouverte. Impossible d\'ouvrir la caisse.', 403);
            }

            $result = $this->caisseService->ouvrirCaisse((int) $id, $validated['utilisateur_id'], $validated['notes'] ?? null);

            return response()->json([
                'success' => true,
                'message' => 'Caisse ouverte avec succès',
                'data'    => $result,
            ]);
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Erreur de validation', 'errors' => $e->errors()], 422);
        } catch (BusinessException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode());
        }
    }

    public function fermer(Request $request, $id)
    {
        try {
            if (!ctype_digit((string) $id)) {
                throw new BusinessException('ID invalide', 400);
            }

            $caisse = \App\Models\Caisses::find($id);
            if (!$caisse) {
                throw new BusinessException('Caisse introuvable', 404);
            }

            if (!$caisse->isPrincipale() && !$caisse->isCoffre()) {
                throw new BusinessException('Seules les caisses principales et les coffres peuvent être fermés.');
            }

            $rules = [
                'utilisateur_id' => 'required|integer|exists:utilisateurs,id',
                'notes'          => 'nullable|string',
            ];
            if ($caisse->isPrincipale()) {
                $rules['soldes_fermeture'] = 'nullable|array';
                $rules['soldes_fermeture.*.devise_id'] = 'required_with:soldes_fermeture|integer|exists:devise,id';
                $rules['soldes_fermeture.*.montant'] = 'required_with:soldes_fermeture|numeric|min:0';
            }

            $validated = $request->validate($rules);

            $dto = new FermetureDTO(
                caisseId: (int) $id,
                utilisateurId: $validated['utilisateur_id'],
                notes: $validated['notes'] ?? null,
                soldesFermeture: $validated['soldes_fermeture'] ?? null
            );

            $result = $this->caisseService->mettreEnAttente($dto);

            return response()->json([
                'success' => true,
                'message' => 'Caisse mise en attente avec succès',
                'data'    => $result,
            ]);
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Erreur de validation', 'errors' => $e->errors()], 422);
        } catch (BusinessException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode());
        }
    }

    public function validerFermeture(Request $request, $id)
    {
        try {
            if (!ctype_digit((string) $id)) {
                throw new BusinessException('ID invalide', 400);
            }

            $validated = $request->validate([
                'utilisateur_id' => 'required|integer|exists:utilisateurs,id',
                'notes'          => 'nullable|string',
            ]);

            $dto = new FermetureDTO(
                caisseId: (int) $id,
                utilisateurId: $validated['utilisateur_id'],
                notes: $validated['notes'] ?? null
            );

            $result = $this->caisseService->validerFermeture($dto);

            return response()->json([
                'success' => true,
                'message' => 'Fermeture validée avec succès',
                'data'    => $result,
            ]);
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Erreur de validation', 'errors' => $e->errors()], 422);
        } catch (BusinessException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode());
        }
    }

    public function detailFermeture($id)
    {
        try {
            if (!ctype_digit((string) $id)) {
                throw new BusinessException('ID invalide', 400);
            }
            $result = $this->caisseService->detailFermeture((int) $id);
            return response()->json([
                'success' => true,
                'message' => 'Détail de fermeture',
                'data'    => $result,
            ]);
        } catch (BusinessException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode());
        }
    }

    public function bloquer(Request $request, $id)
    {
        try {
            if (!ctype_digit((string) $id)) {
                throw new BusinessException('ID invalide', 400);
            }
            $validated = $request->validate(['notes' => 'required|string']);
            $result = $this->caisseService->bloquerCaisse((int) $id, $validated['notes']);
            return response()->json([
                'success' => true,
                'message' => 'Caisse bloquée avec succès',
                'data'    => $result,
            ]);
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Erreur de validation', 'errors' => $e->errors()], 422);
        } catch (BusinessException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode());
        }
    }

    public function debloquer($id)
    {
        try {
            if (!ctype_digit((string) $id)) {
                throw new BusinessException('ID invalide', 400);
            }
            $result = $this->caisseService->debloquerCaisse((int) $id);
            return response()->json([
                'success' => true,
                'message' => 'Caisse débloquée avec succès',
                'data'    => $result,
            ]);
        } catch (BusinessException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode());
        }
    }

    public function desactiver($id)
    {
        try {
            if (!ctype_digit((string) $id)) {
                throw new BusinessException('ID invalide', 400);
            }
            $result = $this->caisseService->desactiverCaisse((int) $id);
            return response()->json([
                'success' => true,
                'message' => 'Caisse désactivée',
                'data'    => $result,
            ]);
        } catch (BusinessException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode());
        }
    }

    public function reactiver($id)
    {
        try {
            if (!ctype_digit((string) $id)) {
                throw new BusinessException('ID invalide', 400);
            }
            $result = $this->caisseService->reactiverCaisse((int) $id);
            return response()->json([
                'success' => true,
                'message' => 'Caisse réactivée',
                'data'    => $result,
            ]);
        } catch (BusinessException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode());
        }
    }

    public function initialiserSoldes(Request $request, $id)
    {
        try {
            if (!ctype_digit((string) $id)) {
                throw new BusinessException('ID invalide', 400);
            }

            $caisse = \App\Models\Caisses::find($id);
            if (!$caisse) {
                throw new BusinessException('Caisse introuvable', 404);
            }

            $validated = [];
            if ($caisse->isCoffre()) {
                $validated = $request->validate([
                    'soldes'                    => 'required|array|min:1',
                    'soldes.*.devise_id'        => 'required|integer|exists:devise,id',
                    'soldes.*.montant'          => 'required|numeric|min:0.01',
                    'soldes.*.solde_minimal'    => 'nullable|numeric|min:0',
                    'soldes.*.solde_maximal'    => 'nullable|numeric|min:1',
                    'motif'                     => 'required|string|max:255',
                    'reference'                 => 'nullable|string|max:100',
                ]);
            } elseif ($caisse->isPrincipale()) {
                $validated = $request->validate([
                    'secondaires'                    => 'required|array|min:1',
                    'secondaires.*.caisse_id'       => 'required|integer|exists:caisses,id',
                    'secondaires.*.soldes'          => 'required|array|min:1',
                    'secondaires.*.soldes.*.devise_id' => 'required|integer|exists:devise,id',
                    'secondaires.*.soldes.*.montant'   => 'required|numeric|min:0.01',
                    'secondaires.*.soldes.*.solde_minimal' => 'nullable|numeric|min:0',
                    'secondaires.*.soldes.*.solde_maximal' => 'nullable|numeric|min:1',
                    'motif'                     => 'required|string|max:255',
                    'reference'                 => 'nullable|string|max:100',
                ]);
            } else {
                throw new BusinessException('Seules les caisses principales ou le coffre peuvent être approvisionnés.');
            }

            $operations = $caisse->isCoffre() ? $validated['soldes'] : $validated['secondaires'];
            $dto = new ApprovisionnementDTO(
                caisseId: (int) $id,
                motif: $validated['motif'],
                reference: $validated['reference'] ?? null,
                operations: $operations
            );

            $result = $this->caisseService->initialiserSoldes($dto);

            return response()->json([
                'success' => true,
                'message' => $caisse->isCoffre() ? 'Coffre approvisionné' : 'Approvisionnement effectué',
                'data'    => $result,
            ]);
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Erreur de validation', 'errors' => $e->errors()], 422);
        } catch (BusinessException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode());
        }
    }

    public function transfererFonds(Request $request)
    {
        try {
            $validated = $request->validate([
                'caisse_source_id'      => 'required|integer|exists:caisses,id',
                'devise_id'             => 'required|integer|exists:devise,id',
                'montant'               => 'required|numeric|min:0.01',
                'motif'                 => 'required|string|max:255',
                'caisse_destination_id' => 'required_without:destinations|integer|exists:caisses,id|different:caisse_source_id',
                'destinations'          => 'required_without:caisse_destination_id|array|min:1',
                'destinations.*.caisse_id' => 'required|integer|exists:caisses,id',
            ]);

            $dto = new TransfertDTO(
                sourceId: $validated['caisse_source_id'],
                deviseId: $validated['devise_id'],
                montant: (float) $validated['montant'],
                motif: $validated['motif'],
                destinationId: $validated['caisse_destination_id'] ?? null,
                destinations: $validated['destinations'] ?? null
            );

            $result = $this->caisseService->transfererFonds($dto);

            return response()->json([
                'success' => true,
                'message' => 'Transfert effectué avec succès',
                'data'    => $result,
            ]);
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Erreur de validation', 'errors' => $e->errors()], 422);
        } catch (BusinessException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode());
        }
    }

    public function agentCaisse(Request $request)
    {
        try {
            $validated = $request->validate(['utilisateur_id' => 'required|integer|exists:utilisateurs,id']);
            $caisse = $this->caisseService->caissePourAgent($validated['utilisateur_id']);
            if (!$caisse) {
                throw new BusinessException('Aucune caisse associée à cet agent', 404);
            }
            return response()->json([
                'success' => true,
                'message' => 'Caisse de l\'agent',
                'data'    => $caisse,
            ]);
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Erreur de validation', 'errors' => $e->errors()], 422);
        } catch (BusinessException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode());
        }
    }

    public function secondaires($parentId)
    {
        try {
            if (!ctype_digit((string) $parentId)) {
                throw new BusinessException('ID parent invalide', 400);
            }
            $caisses = $this->caisseService->getSecondaires((int) $parentId);
            return response()->json(['success' => true, 'data' => $caisses]);
        } catch (BusinessException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode());
        }
    }

    public function soldeConsolide($id)
    {
        try {
            if (!ctype_digit((string) $id)) {
                throw new BusinessException('ID invalide', 400);
            }
            $result = $this->caisseService->soldeConsolide((int) $id);
            return response()->json([
                'success' => true,
                'message' => 'Solde consolidé',
                'data'    => $result,
            ]);
        } catch (BusinessException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode());
        }
    }

    public function soldesSuccursales($id)
    {
        try {
            if (!ctype_digit((string) $id)) {
                throw new BusinessException('ID invalide', 400);
            }
            $result = $this->caisseService->soldesSuccursale((int) $id);
            return response()->json([
                'success' => true,
                'message' => 'Soldes par succursale',
                'data'    => $result,
            ]);
        } catch (BusinessException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode());
        }
    }
}