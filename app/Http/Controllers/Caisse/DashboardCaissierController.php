<?php

namespace App\Http\Controllers\Caisse;

use App\Http\Controllers\Controller;
use App\Models\Caisses;
use App\Models\OperationChange;
use App\Models\Transaction_Mobile;
use App\Models\TransfertInternational;
use App\Models\Devise;
use App\Models\Commission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DashboardCaissierController extends Controller
{
    /**
     * Récupère les statistiques du caissier connecté.
     * - Nombre d'opérations (change, mobile, transfert)
     * - Montants totaux par devise
     * - Commissions générées
     * - Soldes de la caisse du caissier
     */
    public function stats(Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Non authentifié.'], 401);
        }
Log::info('ON A DEJA PU RECUPERER L\'UTILISATEUR');
        // Récupérer la caisse du caissier
        $caisse = Caisses::where('caissier_attribue', $user->id)
            ->where('categorie', 'PRINCIPALE')
            ->first();

        if (!$caisse) {
            return response()->json(['success' => false, 'message' => 'Aucune caisse principale associée à ce caissier.'], 404);
        }
Log::info('ON A DEJA PU RECUPERER LA CAISSE');
        // Période (par défaut : aujourd'hui)
        $dateDebut = $request->input('date_debut', now()->startOfDay());
        $dateFin = $request->input('date_fin', now()->endOfDay());
Log::info('ON A DEJA PU FILTRER LES JOURS');
        // ---- Opérations de change ----
        $operationsChange = OperationChange::where('id_creator', $user->id)
            ->whereBetween('date_operation', [$dateDebut, $dateFin])
            ->get();
Log::info('ON A DEJA PU FILTRER LES TRANSACTION PAR RAPPORT AU USER');
        $nbChange = $operationsChange->count();
        $montantChange = $operationsChange->sum('montant_source');
        $commissionChange = $operationsChange->sum('commission');

        // ---- Transactions Mobile Money ----
        $operationsMobile = Transaction_Mobile::where('id_creator', $user->id) // Supposons que Transaction_Mobile ait operateur_id (ou caissier_id)
            ->whereBetween('created_at', [$dateDebut, $dateFin])
            ->get();

        // Si le modèle n'a pas operateur_id, adapter avec la caisse
        $nbMobile = $operationsMobile->count();
        $montantMobile = $operationsMobile->sum('montant');

        // ---- Transferts internationaux ----
        $operationsTransfert = TransfertInternational::where('caissier_id', $user->id)
            ->whereBetween('date_creation', [$dateDebut, $dateFin])
            ->get();

        $nbTransfert = $operationsTransfert->count();
        $montantTransfert = $operationsTransfert->sum('montant_principal');

        // ---- Soldes de la caisse ----
        $soldes = $caisse->soldes()->with('devise')->get()->map(function ($solde) {
            return [
                'devise' => $solde->devise->code,
                'solde' => (float) $solde->solde,
                'minimal' => (float) $solde->solde_minimal,
                'maximal' => $solde->solde_maximal ? (float) $solde->solde_maximal : null,
            ];
        });

        // ---- Totaux par devise (change) ----
        $totauxParDevise = OperationChange::where('id_creator', $user->id)
            ->whereBetween('date_operation', [$dateDebut, $dateFin])
            ->select('devise_source_id', DB::raw('SUM(montant_source) as total'))
            ->groupBy('devise_source_id')
            ->with('deviseSource')
            ->get()
            ->map(function ($item) {
                return [
                    'devise' => $item->deviseSource->code,
                    'total' => (float) $item->total,
                ];
            });

        // ---- Commissions totales ----
        $caisse = Caisses::where('caissier_attribue', $user->id)->first();
        
        if ($caisse) {
            // Option 1 : Filtrer par caisse
            $commissionTotale = Commission::whereBetween('created_at', [$dateDebut, $dateFin])
                ->whereHas('transactionGlobale', function ($q) use ($caisse) {
                    $q->where('caisse_id', $caisse->id);
                })
                ->sum('montant_total');
        } else {
            $commissionTotale = 0;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'periode' => [
                    'debut' => $dateDebut->toDateString(),
                    'fin' => $dateFin->toDateString(),
                ],
                'caissier' => [
                    'id' => $user->id,
                    'nom' => $user->nom_complet,
                ],
                'caisse' => [
                    'id' => $caisse->id,
                    'nom' => $caisse->nom_caisse,
                    'soldes' => $soldes,
                ],
                'operations' => [
                    'change' => [
                        'nombre' => $nbChange,
                        'montant_total' => round($montantChange, 4),
                        'commission' => round($commissionChange, 4),
                    ],
                    'mobile_money' => [
                        'nombre' => $nbMobile,
                        'montant_total' => round($montantMobile, 4),
                    ],
                    'transferts_internationaux' => [
                        'nombre' => $nbTransfert,
                        'montant_total' => round($montantTransfert, 4),
                    ],
                ],
                'totaux_par_devise' => $totauxParDevise,
                'commission_totale' => round($commissionTotale, 4),
            ],
        ]);
    }

    /**
     * Évolution des transactions (change) par jour pour le caissier.
     */
    public function evolution(Request $request)
    {
        $user = auth()->user();
        $period = $request->input('period', 'day'); // day, week, month

        $query = OperationChange::where('id_creator', $user->id);

        switch ($period) {
            case 'week':
                $groupBy = DB::raw('YEARWEEK(date_operation) as periode');
                $label = 'Semaine';
                break;
            case 'month':
                $groupBy = DB::raw('DATE_FORMAT(date_operation, "%Y-%m") as periode');
                $label = 'Mois';
                break;
            default: // day
                $groupBy = DB::raw('DATE(date_operation) as periode');
                $label = 'Jour';
        }

        $data = $query->select($groupBy, DB::raw('SUM(montant_source) as total'))
            ->groupBy('periode')
            ->orderBy('periode', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'period' => $period,
                'label' => $label,
                'series' => $data->map(function ($item) {
                    return [
                        'periode' => $item->periode,
                        'montant' => (float) $item->total,
                    ];
                }),
            ],
        ]);
    }

    /**
     * Top 5 des clients du caissier.
     */
    public function topClients(Request $request)
    {
        $user = auth()->user();
        $limit = $request->input('limit', 5);

        $topClients = OperationChange::where('id_creator', $user->id)
            ->whereNotNull('client_id')
            ->select('client_id', DB::raw('COUNT(*) as total_operations'))
            ->with('client:id,nom_complet')
            ->groupBy('client_id')
            ->orderByDesc('total_operations')
            ->limit($limit)
            ->get()
            ->map(function ($item) {
                return [
                    'client_id' => $item->client_id,
                    'nom' => $item->client?->nom_complet ?? 'Client inconnu',
                    'total_operations' => $item->total_operations,
                ];
            });

        return response()->json(['success' => true, 'data' => $topClients]);
    }

    /**
     * Détails des dernières transactions (change) du caissier.
     */
    public function dernieresTransactions(Request $request)
    {
        $user = auth()->user();
        $limit = $request->input('limit', 10);

        $transactions = OperationChange::where('id_creator', $user->id)
            ->with(['deviseSource', 'deviseCible', 'client'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($op) {
                return [
                    'reference' => $op->reference,
                    'date' => $op->date_operation,
                    'type' => $op->type_operation,
                    'montant_source' => (float) $op->montant_source,
                    'devise_source' => $op->deviseSource->code,
                    'montant_cible' => (float) $op->montant_cible,
                    'devise_cible' => $op->deviseCible->code,
                    'commission' => (float) $op->commission,
                    'client' => $op->client?->nom_complet ?? 'N/A',
                    'statut' => $op->statut,
                ];
            });

        return response()->json(['success' => true, 'data' => $transactions]);
    }
}