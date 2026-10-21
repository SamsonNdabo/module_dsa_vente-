<?php

namespace App\Http\Controllers\Commission;

use App\Http\Controllers\Controller;
use App\Models\Commission;
use Illuminate\Http\Request;

class CommissionController extends Controller
{
        public function index(Request $request)
    {
        $user = auth()->user();
    
        $query = Commission::with([
            'transaction',
            'devise',
            'services.config',
            'succursale'
        ]);
    
    
        if ($user->isSuperAdmin() || $user->isChefOperation()||$user->isAuditeur()) {

        } elseif ($user->isChefAgence()) {
    
            if (!$user->succursale_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune succursale associée'
                ], 403);
            }
    
            $query->where('succursale_id', $user->succursale_id);
        }
    
    
        /*
        ============================================================
        FILTRES
        ============================================================
        */
    
        if ($request->has('statut')) {
            $query->where('statut', $request->statut);
        }
    
        if ($request->has('id_transaction_global')) {
            $query->where('id_transaction_global', $request->id_transaction_global);
        }
    
    
        /*
        ============================================================
        FILTRE SUCCURSALE
        ============================================================
        SUPER_ADMIN et CHEF_OPERATIONS peuvent filtrer
        une autre succursale.
        ============================================================
        */
        if (
            $request->has('succursale_id') &&
            ($user->isSuperAdmin() || $user->isChefOperation())
        ) {
            $query->where('succursale_id', $request->succursale_id);
        }
    
    
        // Filtre par intervalle de dates
        if ($request->filled('date_min')) {
            $query->whereDate('created_at', '>=', $request->date_min);
        }
    
        if ($request->filled('date_max')) {
            $query->whereDate('created_at', '<=', $request->date_max);
        }
    
    
        $commissions = $query
            ->orderBy('created_at', 'desc')
            ->paginate(20);
    
    
        return response()->json([
            'success' => true,
            'message' => 'Liste des commissions',
            'data'    => $commissions,
        ], 200);
    }

    public function show($id)
    {
        if (!ctype_digit(\strval($id))) {
            return response()->json([
                'success' => false,
                'message' => 'ID invalide',
                'data'    => null
            ], 400);
        }

        $commission = Commission::with([
            'transaction',
            'devise',
            'services.config',
            'succursale',
        ])->find($id);

        if (!$commission) {
            return response()->json([
                'success' => false,
                'message' => 'Commission introuvable',
                'data'    => null
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Détails de la commission',
            'data'    => $commission,
        ], 200);
    }

    // ============================================================
    // Payer une commission
    // ============================================================

    public function payer($id)
    {
        if (!ctype_digit(\strval($id))) {
            return response()->json([
                'success' => false,
                'message' => 'ID invalide',
                'data'    => null
            ], 400);
        }

        $commission = Commission::find($id);

        if (!$commission) {
            return response()->json([
                'success' => false,
                'message' => 'Commission introuvable',
                'data'    => null
            ], 404);
        }

        if ($commission->statut != 'EN_ATTENTE') {
            return response()->json([
                'success' => false,
                'message' => 'Seule une commission EN_ATTENTE peut être payée',
                'data'    => null
            ], 422);
        }

        $commission->payer();

        return response()->json([
            'success' => true,
            'message' => 'Commission payée avec succès',
            'data'    => $commission->fresh()->load(['services']),
        ], 200);
    }

    // ============================================================
    // Annuler une commission
    // ============================================================

    public function annuler($id)
    {
        if (!ctype_digit(\strval($id))) {
            return response()->json([
                'success' => false,
                'message' => 'ID invalide',
                'data'    => null
            ], 400);
        }

        $commission = Commission::find($id);

        if (!$commission) {
            return response()->json([
                'success' => false,
                'message' => 'Commission introuvable',
                'data'    => null
            ], 404);
        }

        if ($commission->statut == 'PAYEE') {
            return response()->json([
                'success' => false,
                'message' => 'Impossible d\'annuler une commission déjà payée',
                'data'    => null
            ], 422);
        }

        $commission->annuler();

        return response()->json([
            'success' => true,
            'message' => 'Commission annulée avec succès',
            'data'    => $commission->fresh(),
        ], 200);
    }

    // ============================================================
    // Statistiques
    // ============================================================
    
    public function statistiques(Request $request)
    {
        $query = Commission::with('services');

        if ($request->has('statut')) {
            $query->where('statut', $request->statut);
        }

        $commissions = $query->get();

        return response()->json([
            'success' => true,
            'message' => 'Statistiques des commissions',
            'data'    => [
                'total'            => $commissions->count(),
                'montant_total'    => $commissions->sum('montant_total'),
                'par_statut'       => $commissions->groupBy('statut')->map(function ($group) {
                    return [
                        'nombre'  => $group->count(),
                        'montant' => $group->sum('montant_total'),
                    ];
                }),
                'par_service'      => $commissions->flatMap->services
                    ->groupBy('service')
                    ->map(function ($group) {
                        return [
                            'nombre'  => $group->count(),
                            'montant' => $group->sum('montant'),
                        ];
                    }),
            ],
        ], 200);
    }
}