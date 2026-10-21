<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditSysteme;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AuditSystemeController extends Controller
{
    /**
     * Liste des audits avec filtres, recherche et pagination
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        // Vérification des permissions
        if (!$user->hasPermission('view-audit') && !$user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas la permission de consulter les audits'
            ], 403);
        }

        $query = AuditSysteme::with('utilisateur');

        // ============================================================
        // FILTRES
        // ============================================================

        // Filtre par table
        if ($request->filled('table')) {
            $query->parTable($request->table);
        }

        // Filtre par type d'action
        if ($request->filled('type_action')) {
            $query->where('type_action', $request->type_action);
        }

        // Filtre par utilisateur
        if ($request->filled('utilisateur_id')) {
            $query->parUtilisateur($request->utilisateur_id);
        }

        // Filtre par niveau d'importance
        if ($request->filled('niveau_importance')) {
            $query->parImportance($request->niveau_importance);
        }

        // Filtre par période
        if ($request->filled('date_debut') && $request->filled('date_fin')) {
            $query->parPeriode($request->date_debut, $request->date_fin);
        }

        // ============================================================
        // RECHERCHE
        // ============================================================

        if ($request->filled('recherche')) {
            $query->recherche($request->recherche);
        }

        // ============================================================
        // TRI
        // ============================================================

        $allowedSorts = ['created_at', 'table_affectee', 'type_action', 'niveau_importance'];
        $sortField = $request->input('sort', 'created_at');
        $sortDirection = $request->input('direction', 'desc');

        if (in_array($sortField, $allowedSorts)) {
            $sortDirection = in_array(strtolower($sortDirection), ['asc', 'desc']) ? $sortDirection : 'desc';
            $query->orderBy($sortField, $sortDirection);
        }

        // ============================================================
        // PAGINATION
        // ============================================================

        $perPage = $request->input('per_page', 20);
        $perPage = min(max((int) $perPage, 1), 100);

        $audits = $query->paginate($perPage);

        // ============================================================
        // STATISTIQUES
        // ============================================================

        $stats = [
            'total' => AuditSysteme::count(),
            'par_table' => AuditSysteme::select('table_affectee', DB::raw('count(*) as total'))
                ->groupBy('table_affectee')
                ->get(),
            'par_type_action' => AuditSysteme::select('type_action', DB::raw('count(*) as total'))
                ->groupBy('type_action')
                ->get(),
            'par_niveau' => AuditSysteme::select('niveau_importance', DB::raw('count(*) as total'))
                ->groupBy('niveau_importance')
                ->get(),
            'par_jour' => AuditSysteme::select(DB::raw('DATE(created_at) as date'), DB::raw('count(*) as total'))
                ->groupBy('date')
                ->orderBy('date', 'desc')
                ->limit(7)
                ->get(),
        ];

        return response()->json([
            'success' => true,
            'data' => $audits,
            'stats' => $stats
        ]);
    }

    /**
     * Afficher les détails d'un audit spécifique
     * 
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-audit') && !$user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas la permission de consulter les audits'
            ], 403);
        }

        try {
            $audit = AuditSysteme::with('utilisateur')->findOrFail($id);

            // Décoder les valeurs JSON si nécessaire
            $ancienneValeur = $audit->ancienne_valeur;
            $nouvelleValeur = $audit->nouvelle_valeur;

            if (is_string($ancienneValeur)) {
                $ancienneValeur = json_decode($ancienneValeur, true);
            }
            if (is_string($nouvelleValeur)) {
                $nouvelleValeur = json_decode($nouvelleValeur, true);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'audit' => $audit,
                    'details' => [
                        'ancienne_valeur' => $ancienneValeur,
                        'nouvelle_valeur' => $nouvelleValeur,
                        'differences' => $audit->details['differences'] ?? [],
                    ],
                    'utilisateur' => $audit->utilisateur
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Audit non trouvé'
            ], 404);
        }
    }

 

    /**
     * Supprimer un audit spécifique
     * 
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $user = auth()->user();

        if (!$user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Seul un Super Admin peut supprimer des audits'
            ], 403);
        }

        try {
            $audit = AuditSysteme::findOrFail($id);
            $audit->delete();

            Log::info('Audit supprimé', [
                'audit_id' => $id,
                'utilisateur_id' => $user->id,
                'table_affectee' => $audit->table_affectee,
                'type_action' => $audit->type_action
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Audit supprimé avec succès'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Audit non trouvé'
            ], 404);
        }
    }

    /**
     * Supprimer plusieurs audits
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function bulkDestroy(Request $request)
    {
        $user = auth()->user();

        if (!$user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Seul un Super Admin peut supprimer des audits'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'ids' => 'required|array',
            'ids.*' => 'required|integer|exists:audit_systeme,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $count = AuditSysteme::whereIn('id', $request->ids)->delete();

            Log::info('Audits supprimés en masse', [
                'nb_supprimes' => $count,
                'utilisateur_id' => $user->id,
                'ids' => $request->ids
            ]);

            return response()->json([
                'success' => true,
                'message' => $count . ' audit(s) supprimé(s) avec succès'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Vider la table des audits (truncate)
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function truncate()
    {
        $user = auth()->user();

        if (!$user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Seul un Super Admin peut vider la table des audits'
            ], 403);
        }

        try {
            // Compter avant suppression
            $count = AuditSysteme::count();

            // Vider la table
            AuditSysteme::truncate();

            Log::warning('Table audit_systeme vidée', [
                'utilisateur_id' => $user->id,
                'nb_enregistrements' => $count
            ]);

            return response()->json([
                'success' => true,
                'message' => $count . ' enregistrement(s) d\'audit supprimé(s) définitivement'
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur vidage table audit', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupérer les tables disponibles pour le filtrage
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function tables()
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-audit') && !$user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Accès refusé'
            ], 403);
        }

        $tables = AuditSysteme::select('table_affectee')
            ->distinct()
            ->orderBy('table_affectee')
            ->pluck('table_affectee');

        return response()->json([
            'success' => true,
            'data' => $tables
        ]);
    }

    /**
     * Récupérer les types d'actions disponibles pour le filtrage
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function actions()
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-audit') && !$user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Accès refusé'
            ], 403);
        }

        $actions = AuditSysteme::select('type_action')
            ->distinct()
            ->orderBy('type_action')
            ->pluck('type_action');

        return response()->json([
            'success' => true,
            'data' => $actions
        ]);
    }

    /**
     * Récupérer les niveaux d'importance disponibles
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function niveaux()
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-audit') && !$user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Accès refusé'
            ], 403);
        }

        $niveaux = AuditSysteme::select('niveau_importance')
            ->distinct()
            ->orderBy('niveau_importance')
            ->pluck('niveau_importance');

        // Ajouter les niveaux par défaut même s'ils ne sont pas dans la table
        $defaultNiveaux = ['INFO', 'NORMALE', 'HAUTE', 'CRITIQUE', 'URGENTE'];
        $allNiveaux = array_unique(array_merge($defaultNiveaux, $niveaux->toArray()));
        sort($allNiveaux);

        return response()->json([
            'success' => true,
            'data' => $allNiveaux
        ]);
    }

    /**
     * Exporter les audits (format CSV)
     * 
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function export(Request $request)
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-audit') && !$user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Accès refusé'
            ], 403);
        }

        $query = AuditSysteme::with('utilisateur');

        // Appliquer les mêmes filtres que l'index
        if ($request->filled('table')) {
            $query->parTable($request->table);
        }
        if ($request->filled('type_action')) {
            $query->where('type_action', $request->type_action);
        }
        if ($request->filled('utilisateur_id')) {
            $query->parUtilisateur($request->utilisateur_id);
        }
        if ($request->filled('niveau_importance')) {
            $query->parImportance($request->niveau_importance);
        }
        if ($request->filled('date_debut') && $request->filled('date_fin')) {
            $query->parPeriode($request->date_debut, $request->date_fin);
        }
        if ($request->filled('recherche')) {
            $query->recherche($request->recherche);
        }

        $audits = $query->orderBy('created_at', 'desc')->get();

        // Générer le CSV
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="audits_' . date('Y-m-d') . '.csv"',
        ];

        $callback = function() use ($audits) {
            $handle = fopen('php://output', 'w');
            
            // En-têtes
            fputcsv($handle, [
                'ID',
                'Table',
                'Action',
                'ID Enregistrement',
                'Description',
                'Niveau',
                'Utilisateur',
                'IP',
                'Date',
                'Ancienne Valeur',
                'Nouvelle Valeur'
            ]);

            // Données
            foreach ($audits as $audit) {
                fputcsv($handle, [
                    $audit->id,
                    $audit->table_affectee,
                    $audit->type_action,
                    $audit->id_enregistrement,
                    $audit->description,
                    $audit->niveau_importance,
                    $audit->utilisateur?->nom_complet ?? $audit->id_utilisateur,
                    $audit->adresse_ip,
                    $audit->created_at?->format('Y-m-d H:i:s'),
                    json_encode($audit->ancienne_valeur),
                    json_encode($audit->nouvelle_valeur)
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }
}