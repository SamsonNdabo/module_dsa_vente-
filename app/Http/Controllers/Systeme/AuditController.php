<?php


namespace App\Http\Controllers\Systeme;  // ← Changement ici

use App\Http\Controllers\Controller; 

use App\Models\AuditSysteme;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditController extends Controller
{
    public function __construct()
    {
        // $this->middleware('permission:view-audit-logs');
    }

    /**
     * Liste des logs d'audit
     */
    public function index(Request $request)
    {
        $query = AuditSysteme::with('utilisateur');

        // Filtres
        if ($request->has('table_affectee')) {
            $query->where('table_affectee', $request->table_affectee);
        }

        if ($request->has('type_action')) {
            $query->where('type_action', $request->type_action);
        }

        if ($request->has('id_utilisateur')) {
            $query->where('id_utilisateur', $request->id_utilisateur);
        }

        if ($request->has('date_debut')) {
            $query->whereDate('created_at', '>=', $request->date_debut);
        }

        if ($request->has('date_fin')) {
            $query->whereDate('created_at', '<=', $request->date_fin);
        }

        if ($request->has('niveau_importance')) {
            $query->where('niveau_importance', $request->niveau_importance);
        }

        if ($request->has('id_enregistrement')) {
            $query->where('id_enregistrement', $request->id_enregistrement);
        }

        if ($request->has('recherche')) {
            $query->where('description', 'like', "%{$request->recherche}%");
        }

        // Tri
        $orderBy = $request->get('order_by', 'created_at');
        $orderDir = $request->get('order_dir', 'desc');
        $query->orderBy($orderBy, $orderDir);

        $logs = $query->paginate($request->get('per_page', 50));

        // Statistiques
        $stats = [
            'total' => AuditSysteme::count(),
            'par_action' => AuditSysteme::select('type_action', DB::raw('count(*) as total'))
                ->groupBy('type_action')
                ->get(),
            'par_table' => AuditSysteme::select('table_affectee', DB::raw('count(*) as total'))
                ->groupBy('table_affectee')
                ->get(),
            'aujourd_hui' => AuditSysteme::whereDate('created_at', today())->count()
        ];

        return response()->json([
            'success' => true,
            'data' => $logs,
            'stats' => $stats
        ]);
    }

    /**
     * Afficher un log spécifique
     */
    public function show($id)
    {
        $log = AuditSysteme::with('utilisateur')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $log,
            'details' => $log->details
        ]);
    }

    /**
     * Obtenir les tables disponibles
     */
    public function tables()
    {
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
     * Obtenir les types d'actions
     */
    public function actions()
    {
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
     * Nettoyer les vieux logs
     */
    public function clean(Request $request)
    {
        $request->validate([
            'plus_vieux_que' => 'required|integer|min:30|max:365'
        ]);

        $date = now()->subDays($request->plus_vieux_que);
        
        $count = AuditSysteme::where('created_at', '<', $date)
            ->where('niveau_importance', '!=', 'CRITIQUE')
            ->delete();

        return response()->json([
            'success' => true,
            'message' => "{$count} logs supprimés"
        ]);
    }

    /**
     * Exporter les logs
     */
    public function export(Request $request)
    {
        $query = AuditSysteme::with('utilisateur');

        // Appliquer les filtres comme dans index
        if ($request->has('date_debut')) {
            $query->whereDate('created_at', '>=', $request->date_debut);
        }

        if ($request->has('date_fin')) {
            $query->whereDate('created_at', '<=', $request->date_fin);
        }

        $logs = $query->limit(10000)->get();

        // Générer CSV
        $output = fopen('php://temp', 'r+');
        
        fputcsv($output, [
            'Date',
            'Table',
            'Action',
            'ID Enregistrement',
            'Utilisateur',
            'Description',
            'IP',
            'Importance'
        ]);

        foreach ($logs as $log) {
            fputcsv($output, [
                $log->created_at->format('d/m/Y H:i:s'),
                $log->table_affectee,
                $log->type_action,
                $log->id_enregistrement,
                $log->utilisateur?->nom_complet,
                $log->description,
                $log->adresse_ip,
                $log->niveau_importance
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return response($csv)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', 'attachment; filename="audit_logs.csv"');
    }
}