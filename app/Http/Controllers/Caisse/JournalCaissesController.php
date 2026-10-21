<?php

namespace App\Http\Controllers\Caisse;

use App\Http\Controllers\Controller;
use App\Models\Journal_Caisses;
use App\Models\Caisses;
use App\Models\Utilisateur;
use Illuminate\Http\Request;

class JournalCaissesController extends Controller
{
    /**
     * Afficher la liste des journaux de caisse
     * Permission: view-journal-caisses
     */
        public function index(Request $request)
    {
        $user = auth()->user();
    
        $query = Journal_Caisses::with([
            'caisse',
            'soldes.devise',
            'mouvements',
        ]);
    
        if ($user->isSuperAdmin() || $user->isChefOperation()||$user->isAuditeur()) {
            // Accès à toutes les caisses
        } elseif ($user->isChefAgence()) {
            $query->whereHas('caisse', function ($q) use ($user) {
                $q->where('succursale_id', $user->succursale_id);
            });
        } else {
            $query->where('user_id', $user->id);
        }
    
        // Filtres optionnels
        if ($request->has('caisse_id')) {
            $query->where('caisse_id', $request->caisse_id);
        }
    
        if ($request->has('statut')) {
            $query->where('statut', $request->statut);
        }
    
        if ($request->has('type_operation')) {
            $query->where('type_operation', $request->type_operation);
        }
    
        if ($request->has('date_debut')) {
            $query->whereDate('date_debut', '>=', $request->date_debut);
        }
    
        if ($request->has('date_fin')) {
            $query->whereDate('date_debut', '<=', $request->date_fin);
        }
    
        $journaux = $query->orderBy('created_at', 'desc')->paginate(10);
    
        // Extraction des IDs
        $userIds = $journaux->pluck('user_id')
                            ->filter()
                            ->unique()
                            ->toArray();
    
        $validateurIds = $journaux->pluck('valide_fermeture')
                                  ->filter()
                                  ->unique()
                                  ->toArray();
    
        // Chargement des utilisateurs
        $listeUtilisateurs = Utilisateur::getUtilisateursByIds($userIds);
        $listeValidateurs  = Utilisateur::getUtilisateursByIds($validateurIds);
    
        // Injection des utilisateurs
        $journaux->getCollection()->transform(function ($item) use ($listeUtilisateurs, $listeValidateurs) {
    
            $item->utilisateur = (
                is_numeric($item->user_id) && $item->user_id > 0
            ) ? $listeUtilisateurs->get((int) $item->user_id) : null;
    
            $item->validateur_fermeture = (
                is_numeric($item->valide_fermeture) && $item->valide_fermeture > 0
            ) ? $listeValidateurs->get((int) $item->valide_fermeture) : null;
    
            return $item;
        });
    
        return response()->json([
            'success' => true,
            'message' => 'Liste des journaux de caisse',
            'data'    => $journaux,
        ], 200);
    }

    /**
     * Créer un nouveau journal de caisse
     * Permission: create-journal-caisse
     */
    public function store(Request $request)
    {
        $user = auth()->user();
        
        // Vérification de la permission
        if (!$user->hasPermission('create-journal-caisse')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour créer un journal de caisse'
            ], 403);
        }
        
        $validated = $request->validate([
            'caisse_id'       => 'required|integer|exists:caisses,id',
            'utilisateur_id'  => 'required|integer|exists:utilisateur,id',
            'type_operation'  => 'required|in:OUVERTURE,FERMETURE,RECONCILIATION,AJUSTEMENT',
            'solde_ouverture' => 'required|numeric|min:0',
            'montant_initial' => 'required|numeric|min:0',
            'date_debut'      => 'required|date',
            'notes'           => 'nullable|string',
        ]);

        // Vérifier que la caisse existe
        $caisse = Caisses::find($validated['caisse_id']);

        if (!$caisse) {
            return response()->json([
                'success' => false,
                'message' => 'Caisse introuvable',
                'data'    => null
            ], 404);
        }

        // Vérifier que l'utilisateur a accès à cette caisse
        $hasViewAllCaisses = $user->hasPermission('view-caisses') || $user->role?->code === 'SUPER_ADMIN';
        if (!$hasViewAllCaisses && $caisse->succursale_id != $user->succursale_id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous ne pouvez pas créer un journal pour cette caisse'
            ], 403);
        }

        // Bloquer si une opération est déjà EN_COURS pour cette caisse
        $operationEnCours = Journal_Caisses::where('caisse_id', $validated['caisse_id'])
            ->enCours()
            ->exists();

        if ($operationEnCours) {
            return response()->json([
                'success' => false,
                'message' => 'Une opération est déjà en cours pour cette caisse',
                'data'    => null
            ], 422);
        }

        $journal = Journal_Caisses::create([
            'caisse_id'       => $validated['caisse_id'],
            'utilisateur_id'  => $validated['utilisateur_id'],
            'type_operation'  => $validated['type_operation'],
            'solde_ouverture' => (string) $validated['solde_ouverture'],
            'solde_fermeture' => '0',
            'montant_initial' => (string) $validated['montant_initial'],
            'montant_final'   => '0',
            'date_debut'      => $validated['date_debut'],
            'statut'          => 'EN_COURS',
            'notes'           => $validated['notes'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Journal de caisse créé avec succès',
            'data'    => $journal->load(['caisse', 'utilisateur']),
        ], 201);
    }

    /**
     * Afficher les détails d'un journal de caisse
     * Permission: show-journal-caisse
     */
    public function show($id)
    {
        $user = auth()->user();
        
        // Vérification de la permission
        if (!$user->hasPermission('show-journal-caisse')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour voir les détails d\'un journal de caisse'
            ], 403);
        }
        
        if (!ctype_digit(\strval($id))) {
            return response()->json([
                'success' => false,
                'message' => 'ID invalide',
                'data'    => null
            ], 400);
        }

        $journal = Journal_Caisses::with(['caisse', 'utilisateur'])->find($id);

        if (!$journal) {
            return response()->json([
                'success' => false,
                'message' => 'Journal introuvable',
                'data'    => null
            ], 404);
        }

        // Vérifier que l'utilisateur a accès à ce journal
        $hasViewAllCaisses = $user->hasPermission('view-caisses') || $user->role?->code === 'SUPER_ADMIN';
        if (!$hasViewAllCaisses && $journal->caisse->succursale_id != $user->succursale_id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas accès à ce journal'
            ], 403);
        }

        return response()->json([
            'success' => true,
            'message' => 'Détails du journal',
            'data'    => $journal,
        ], 200);
    }

    /**
     * Mettre à jour un journal de caisse
     * Permission: update-journal-caisse
     */
    public function update(Request $request, $id)
    {
        $user = auth()->user();
        
        // Vérification de la permission
        if (!$user->hasPermission('update-journal-caisse')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour modifier un journal de caisse'
            ], 403);
        }
        
        if (!ctype_digit(\strval($id))) {
            return response()->json([
                'success' => false,
                'message' => 'ID invalide',
                'data'    => null
            ], 400);
        }

        $journal = Journal_Caisses::find($id);

        if (!$journal) {
            return response()->json([
                'success' => false,
                'message' => 'Journal introuvable',
                'data'    => null
            ], 404);
        }

        // Vérifier que l'utilisateur a accès à ce journal
        $hasViewAllCaisses = $user->hasPermission('view-caisses') || $user->role?->code === 'SUPER_ADMIN';
        if (!$hasViewAllCaisses && $journal->caisse->succursale_id != $user->succursale_id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas accès à ce journal'
            ], 403);
        }

        // Bloquer si le journal est déjà terminé ou en anomalie
        if (\in_array($journal->statut, ['TERMINEE', 'ANOMALIE'])) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de modifier un journal terminé ou en anomalie',
                'data'    => null
            ], 422);
        }

        $validated = $request->validate([
            'notes'               => 'sometimes|string',
            'justification_ecart' => 'sometimes|string',
            'document_upload'     => 'nullable|array',
        ]);

        $journal->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Journal mis à jour avec succès',
            'data'    => \array_intersect_key(
                $journal->fresh()->toArray(),
                $validated
            ),
        ], 200);
    }

    /**
     * Terminer une opération de caisse
     * Permission: terminer-journal-caisse
     */
    public function terminer(Request $request, $id)
    {
        $user = auth()->user();
        
        // Vérification de la permission
        if (!$user->hasPermission('terminer-journal-caisse')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour terminer un journal de caisse'
            ], 403);
        }
        
        if (!ctype_digit(\strval($id))) {
            return response()->json([
                'success' => false,
                'message' => 'ID invalide',
                'data'    => null
            ], 400);
        }

        $journal = Journal_Caisses::find($id);

        if (!$journal) {
            return response()->json([
                'success' => false,
                'message' => 'Journal introuvable',
                'data'    => null
            ], 404);
        }

        // Vérifier que l'utilisateur a accès à ce journal
        $hasViewAllCaisses = $user->hasPermission('view-caisses') || $user->role?->code === 'SUPER_ADMIN';
        if (!$hasViewAllCaisses && $journal->caisse->succursale_id != $user->succursale_id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas accès à ce journal'
            ], 403);
        }

        if ($journal->statut !== 'EN_COURS') {
            return response()->json([
                'success' => false,
                'message' => 'Seul un journal EN_COURS peut être terminé',
                'data'    => null
            ], 422);
        }

        $validated = $request->validate([
            'solde_fermeture'     => 'required|numeric|min:0',
            'justification_ecart' => 'nullable|string',
        ]);

        // Terminer via la méthode du model
        $journal->terminer((float) $validated['solde_fermeture']);

        if ($validated['justification_ecart'] ?? null) {
            $journal->justification_ecart = $validated['justification_ecart'];
            $journal->save();
        }

        return response()->json([
            'success' => true,
            'message' => $journal->statut === 'ANOMALIE'
                ? 'Journal terminé avec anomalie détectée'
                : 'Journal terminé avec succès',
            'data'    => $journal->fresh()->load(['caisse', 'utilisateur']),
        ], 200);
    }

    /**
     * Supprimer un journal de caisse
     * Permission: delete-journal-caisse
     */
    public function destroy($id)
    {
        $user = auth()->user();
        
        // Vérification de la permission
        if (!$user->hasPermission('delete-journal-caisse')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour supprimer un journal de caisse'
            ], 403);
        }
        
        if (!ctype_digit(\strval($id))) {
            return response()->json([
                'success' => false,
                'message' => 'ID invalide',
                'data'    => null
            ], 400);
        }

        $journal = Journal_Caisses::find($id);

        if (!$journal) {
            return response()->json([
                'success' => false,
                'message' => 'Journal introuvable',
                'data'    => null
            ], 404);
        }

        // Vérifier que l'utilisateur a accès à ce journal
        $hasViewAllCaisses = $user->hasPermission('view-caisses') || $user->role?->code === 'SUPER_ADMIN';
        if (!$hasViewAllCaisses && $journal->caisse->succursale_id != $user->succursale_id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas accès à ce journal'
            ], 403);
        }

        // Bloquer la suppression si le journal est terminé
        if (\in_array($journal->statut, ['TERMINEE', 'ANOMALIE'])) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer un journal terminé ou en anomalie',
                'data'    => null
            ], 422);
        }

        $journal->delete();

        return response()->json([
            'success' => true,
            'message' => 'Journal supprimé avec succès',
            'data'    => [
                'id'             => $journal->id,
                'caisse_id'      => $journal->caisse_id,
                'type_operation' => $journal->type_operation,
            ]
        ], 200);
    }
}