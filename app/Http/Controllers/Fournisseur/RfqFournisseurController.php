<?php
// app/Http/Controllers/Fournisseur/RfqFournisseurController.php

namespace App\Http\Controllers\Fournisseur;

use App\Http\Controllers\Controller;
use App\Models\RfqFournisseur;
use App\Models\Fournisseur;
use App\Models\Produit;
use App\Models\RfqLigne;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Mail\RfqFournisseurMail;
use Illuminate\Support\Facades\Log;

class RfqFournisseurController extends Controller
{
    //  Constantes
    private const ROLES_FULL_ACCESS = ['SUPER_ADMIN','CHEF_OPERATIONS','AUDITEUR'];
    private const STATUTS_ANNULABLES = ['BROUILLON', 'ENVOYEE', 'EN_ATTENTE_REPONSE'];

    /**
     * Afficher la liste des RFQ - OPTIMISÉ
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-rfq')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour voir les demandes de devis'
            ], 403);
        }

        $query = RfqFournisseur::select([
            'id', 'reference', 'rfq_group_uuid', 'fournisseur_id', 'devise_id',
            'date_rfq', 'date_reponse_souhaitee', 'statut', 'cree_par_id',
            'envoye_par_id', 'date_envoi', 'created_at', 'updated_at'
        ])->with([
            'fournisseur' => function($q) {
                $q->select('id', 'nom', 'code_fournisseur', 'email', 'telephone');
            },
            'devise' => function($q) {
                $q->select('id', 'code', 'nom', 'symbole');
            },
            'creePar' => function($q) {
                $q->select('id', 'nom_complet', 'email');
            }
        ]);

        //  Filtrage par utilisateur
        if (!in_array($user->role?->code, self::ROLES_FULL_ACCESS) && !$user->hasPermission('manage-all-users')) {
            $query->where('cree_par_id', $user->id);
        }

        //  Filtres
        if ($request->filled('statut')) {
            $query->where('statut', $request->statut);
        }

        if ($request->filled('fournisseur_id')) {
            $query->where('fournisseur_id', $request->fournisseur_id);
        }

        if ($request->filled('devise_id')) {
            $query->where('devise_id', $request->devise_id);
        }

        if ($request->filled('rfq_group_uuid')) {
            $query->where('rfq_group_uuid', $request->rfq_group_uuid);
        }

        //  Recherche optimisée - Une seule requête
        if ($request->filled('recherche')) {
            $search = $request->recherche;
            $query->where(function($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                  ->orWhereHas('fournisseur', function($sq) use ($search) {
                      $sq->where('nom', 'like', "%{$search}%")
                         ->orWhere('code_fournisseur', 'like', "%{$search}%");
                  });
            });
        }

        $perPage = $request->get('per_page', 20);
        $rfqs = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json(['success' => true, 'data' => $rfqs]);
    }

    /**
     * Créer une RFQ pour plusieurs fournisseurs - OPTIMISÉ
     */
    public function store(Request $request)
    {
        $user = auth()->user();

        if (!$user->hasPermission('create-rfq')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour créer des demandes de devis'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'devise_id' => 'required|exists:devise,id',
            'fournisseurs' => 'required|array|min:1',
            'fournisseurs.*.id' => 'required|exists:fournisseur,id',
            'date_reponse_souhaitee' => 'required|date|after:today',
            'notes' => 'nullable|string',
            'lignes' => 'required|array|min:1',
            'lignes.*.produit_id' => 'required|exists:produit,id',
            'lignes.*.libelle' => 'required|string',
            'lignes.*.quantite' => 'required|integer|min:1',
            'lignes.*.date_livraison_souhaitee' => 'nullable|date'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            $rfqGroupUuid = (string) Str::uuid();
            $rfqs = [];
            $rfqIds = [];

            //  Préparer les données des lignes une seule fois
            $lignesData = $this->prepareLignesData($request->lignes);

            foreach ($request->fournisseurs as $fournisseurData) {
                $rfq = RfqFournisseur::create([
                    'rfq_group_uuid' => $rfqGroupUuid,
                    'fournisseur_id' => $fournisseurData['id'],
                    'devise_id' => $request->devise_id,
                    'date_reponse_souhaitee' => $request->date_reponse_souhaitee,
                    'notes' => $request->notes,
                    'cree_par_id' => auth()->id(),
                    'statut' => 'BROUILLON'
                ]);

                $rfqIds[] = $rfq->id;
                $rfqs[] = $rfq;
            }

            //  Insertion groupée des lignes pour tous les RFQ
            $allLignesData = [];
            foreach ($rfqIds as $rfqId) {
                foreach ($lignesData as $ligne) {
                    $allLignesData[] = array_merge($ligne, ['rfq_id' => $rfqId]);
                }
            }

            if (!empty($allLignesData)) {
                RfqLigne::insert($allLignesData);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => count($rfqs) . ' demande(s) de devis créée(s) en brouillon',
                'data' => [
                    'rfqs' => $rfqs,
                    'rfq_group_uuid' => $rfqGroupUuid
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur création RFQ', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            return response()->json(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Afficher une RFQ - OPTIMISÉ
     */
    public function show($id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-rfq')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour voir les demandes de devis'
            ], 403);
        }

        $rfq = RfqFournisseur::select([
            'id', 'reference', 'rfq_group_uuid', 'fournisseur_id', 'devise_id',
            'date_rfq', 'date_reponse_souhaitee', 'statut', 'notes',
            'documents', 'metadata', 'cree_par_id', 'envoye_par_id',
            'date_envoi', 'created_at', 'updated_at'
        ])->with([
            'fournisseur' => function($q) {
                $q->select('id', 'nom', 'code_fournisseur', 'email', 'telephone', 'adresse', 'ville');
            },
            'devise' => function($q) {
                $q->select('id', 'code', 'nom', 'symbole');
            },
            'lignes' => function($q) {
                $q->select('id', 'rfq_id', 'produit_id', 'libelle', 'quantite', 'unite', 'date_livraison_souhaitee', 'notes')
                  ->with(['produit' => function($pq) {
                      $pq->select('id', 'nom', 'code_barre', 'type');
                  }]);
            },
            'creePar' => function($q) {
                $q->select('id', 'nom_complet', 'email');
            },
            'devis' => function($q) {
                $q->select('id', 'reference', 'fournisseur_id', 'statut', 'date_devis')
                  ->with(['fournisseur' => function($fq) {
                      $fq->select('id', 'nom');
                  }]);
            }
        ])->findOrFail($id);

        //  Vérification d'accès
        if (!in_array($user->role?->code, self::ROLES_FULL_ACCESS) &&
            !$user->hasPermission('manage-all-users') &&
            $rfq->cree_par_id != $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas accès à cette RFQ'
            ], 403);
        }

        return response()->json(['success' => true, 'data' => $rfq]);
    }

    /**
     * Mettre à jour une RFQ - OPTIMISÉ
     */
    public function update(Request $request, $id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('create-rfq')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour modifier des demandes de devis'
            ], 403);
        }

        $rfq = RfqFournisseur::findOrFail($id);

        if ($rfq->cree_par_id != $user->id && !$user->hasPermission('manage-all-users')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous ne pouvez modifier que vos propres RFQ'
            ], 403);
        }

        if ($rfq->statut !== 'BROUILLON') {
            return response()->json([
                'success' => false,
                'message' => 'Seules les RFQ en brouillon peuvent être modifiées'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'fournisseur_id' => 'sometimes|exists:fournisseur,id',
            'devise_id' => 'sometimes|exists:devise,id',
            'date_reponse_souhaitee' => 'nullable|date|after:today',
            'notes' => 'nullable|string',
            'lignes' => 'sometimes|array|min:1',
            'lignes.*.id' => 'nullable|exists:rfq_lignes,id',
            'lignes.*.produit_id' => 'required_with:lignes|exists:produit,id',
            'lignes.*.libelle' => 'required_with:lignes|string',
            'lignes.*.quantite' => 'required_with:lignes|integer|min:1',
            'lignes.*.date_livraison_souhaitee' => 'nullable|date',
            'lignes.*.notes' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            $rfq->update($request->only([
                'fournisseur_id',
                'devise_id',
                'date_reponse_souhaitee',
                'notes'
            ]));

            if ($request->has('lignes')) {
                $this->updateLignes($rfq, $request->lignes);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'RFQ mise à jour avec succès',
                'data' => $rfq->load([
                    'fournisseur' => function($q) {
                        $q->select('id', 'nom');
                    },
                    'devise' => function($q) {
                        $q->select('id', 'code');
                    },
                    'lignes' => function($q) {
                        $q->with(['produit' => function($pq) {
                            $pq->select('id', 'nom');
                        }]);
                    }
                ])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur mise à jour RFQ', [
                'rfq_id' => $id,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Supprimer une RFQ
     * Permission: create-rfq
     */
    public function destroy($id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('create-rfq')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour supprimer des demandes de devis'
            ], 403);
        }

        $rfq = RfqFournisseur::findOrFail($id);

        if ($rfq->cree_par_id != $user->id && !$user->hasPermission('manage-all-users')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous ne pouvez supprimer que vos propres RFQ'
            ], 403);
        }

        if ($rfq->statut !== 'BROUILLON') {
            return response()->json([
                'success' => false,
                'message' => 'Seules les RFQ en brouillon peuvent être supprimées'
            ], 422);
        }

        $rfq->delete();

        return response()->json([
            'success' => true,
            'message' => 'RFQ supprimée avec succès'
        ]);
    }

    /**
     * Envoyer une RFQ par email
     * Permission: send-rfq
     */
    public function envoyer($id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('send-rfq')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour envoyer des demandes de devis'
            ], 403);
        }

        $rfq = RfqFournisseur::findOrFail($id);

        if ($rfq->cree_par_id != $user->id && !$user->hasPermission('manage-all-users')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous ne pouvez envoyer que vos propres RFQ'
            ], 403);
        }

        if ($rfq->statut !== 'BROUILLON') {
            return response()->json(['success' => false, 'message' => 'Seule une RFQ en brouillon peut être envoyée'], 422);
        }

        if ($rfq->lignes()->count() === 0) {
            return response()->json(['success' => false, 'message' => 'La RFQ doit contenir au moins une ligne'], 422);
        }

        if ($rfq->envoyerEmail()) {
            return response()->json(['success' => true, 'message' => 'RFQ envoyée avec succès']);
        }

        return response()->json(['success' => false, 'message' => 'Erreur lors de l\'envoi'], 500);
    }

    /**
     * Renvoyer l'email de la RFQ
     * Permission: send-rfq
     */
    public function renvoyerEmail($id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('send-rfq')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour renvoyer des demandes de devis'
            ], 403);
        }

        $rfq = RfqFournisseur::findOrFail($id);

        if (!in_array($rfq->statut, ['ENVOYEE', 'EN_ATTENTE_REPONSE'])) {
            return response()->json([
                'success' => false,
                'message' => 'Cette RFQ ne peut pas être renvoyée'
            ], 422);
        }

        if ($rfq->envoyerEmail()) {
            return response()->json([
                'success' => true,
                'message' => 'Email renvoyé avec succès'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Erreur lors de l\'envoi'
        ], 500);
    }

    /**
     * Exporter la RFQ en PDF
     * Permission: view-rfq
     */
    public function exporterPdf($id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-rfq')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour exporter les demandes de devis'
            ], 403);
        }

        $rfq = RfqFournisseur::with([
            'fournisseur' => function($q) {
                $q->select('id', 'nom', 'code_fournisseur', 'email', 'telephone', 'adresse');
            },
            'devise' => function($q) {
                $q->select('id', 'code', 'nom');
            },
            'lignes' => function($q) {
                $q->with(['produit' => function($pq) {
                    $pq->select('id', 'nom', 'code_barre');
                }]);
            }
        ])->findOrFail($id);

        $pdf = PDF::loadView('pdf.rfq', ['rfq' => $rfq]);

        return $pdf->download("RFQ_{$rfq->reference}.pdf");
    }

    /**
     * Annuler une RFQ
     * Permission: create-rfq
     */
    public function annuler($id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('create-rfq')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour annuler des demandes de devis'
            ], 403);
        }

        $rfq = RfqFournisseur::findOrFail($id);

        if (!in_array($rfq->statut, self::STATUTS_ANNULABLES)) {
            return response()->json([
                'success' => false,
                'message' => 'Cette RFQ ne peut pas être annulée (statut: ' . $rfq->statut . ')'
            ], 422);
        }

        $rfq->statut = 'ANNULEE';
        $rfq->save();

        return response()->json(['success' => true, 'message' => 'RFQ annulée']);
    }

    /**
     * Obtenir les statistiques des RFQ
     * Permission: view-rfq
     */
    public function statistiques(Request $request)
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-rfq')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour voir les statistiques'
            ], 403);
        }

        $query = RfqFournisseur::query();

        if (!in_array($user->role?->code, self::ROLES_FULL_ACCESS) && !$user->hasPermission('manage-all-users')) {
            $query->where('cree_par_id', $user->id);
        }

        if ($request->filled('date_debut')) {
            $query->whereDate('created_at', '>=', $request->date_debut);
        }

        if ($request->filled('date_fin')) {
            $query->whereDate('created_at', '<=', $request->date_fin);
        }

        $stats = [
            'total' => $query->count(),
            'par_statut' => (clone $query)->select('statut', DB::raw('count(*) as total'))
                ->groupBy('statut')
                ->get()
                ->map(fn($item) => [
                    'statut' => $item->statut,
                    'libelle' => RfqFournisseur::STATUTS[$item->statut] ?? $item->statut,
                    'total' => $item->total
                ]),
            'par_fournisseur' => (clone $query)->select('fournisseur_id', DB::raw('count(*) as total'))
                ->with(['fournisseur' => function($q) {
                    $q->select('id', 'nom');
                }])
                ->groupBy('fournisseur_id')
                ->orderBy('total', 'desc')
                ->limit(10)
                ->get()
                ->map(fn($item) => [
                    'fournisseur' => $item->fournisseur?->nom ?? 'Inconnu',
                    'total' => $item->total
                ]),
            'evolution' => (clone $query)->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('count(*) as total')
            )
                ->where('created_at', '>=', now()->subDays(30))
                ->groupBy('date')
                ->orderBy('date')
                ->get()
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    // ============================================================
    // MÉTHODES PRIVÉES
    // ============================================================

    /**
     * Prépare les données des lignes pour insertion BULK
     */
    private function prepareLignesData($lignes): array
    {
        $result = [];

        foreach ($lignes as $ligne) {
            $result[] = [
                'produit_id' => $ligne['produit_id'],
                'libelle' => $ligne['libelle'],
                'quantite' => $ligne['quantite'],
                'date_livraison_souhaitee' => $ligne['date_livraison_souhaitee'] ?? null,
                'notes' => $ligne['notes'] ?? null,
                'created_at' => now(),
                'updated_at' => now()
            ];
        }

        return $result;
    }

    /**
     * Met à jour les lignes d'une RFQ
     */
    private function updateLignes($rfq, $lignes): void
    {
        $existingLigneIds = [];

        foreach ($lignes as $ligneData) {
            if (isset($ligneData['id'])) {
                $ligne = RfqLigne::where('rfq_id', $rfq->id)
                    ->where('id', $ligneData['id'])
                    ->first();

                if ($ligne) {
                    $ligne->update([
                        'produit_id' => $ligneData['produit_id'],
                        'libelle' => $ligneData['libelle'],
                        'quantite' => $ligneData['quantite'],
                        'date_livraison_souhaitee' => $ligneData['date_livraison_souhaitee'] ?? null,
                        'notes' => $ligneData['notes'] ?? null
                    ]);
                    $existingLigneIds[] = $ligne->id;
                }
            } else {
                $newLigne = $rfq->lignes()->create([
                    'produit_id' => $ligneData['produit_id'],
                    'libelle' => $ligneData['libelle'],
                    'quantite' => $ligneData['quantite'],
                    'date_livraison_souhaitee' => $ligneData['date_livraison_souhaitee'] ?? null,
                    'notes' => $ligneData['notes'] ?? null
                ]);
                $existingLigneIds[] = $newLigne->id;
            }
        }

        $rfq->lignes()->whereNotIn('id', $existingLigneIds)->delete();
    }
}