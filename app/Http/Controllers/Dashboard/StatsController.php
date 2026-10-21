<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\OperationChange;
use App\Models\Devise;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StatsController extends Controller
{
    public function journalieres(Request $request)
    {
        $request->validate([
            'date'          => 'nullable|date',
            'succursale_id' => 'nullable|exists:succursale,id',
        ]);

        $date = $request->date ?? now()->toDateString();
        $succursaleId = $request->succursale_id;

        $query = OperationChange::query();
        if ($succursaleId) {
            $query->where('succursale_id', $succursaleId);
        }
        $query->whereDate('date_operation', $date);

        // 1. Total opérations (tous statuts confondus)
        $totalOperations = (clone $query)->count();

        // 2. Montant total échangé en USD (on prend le montant_source quand la devise source est USD, sinon on convertit ?)
        // Pour simplifier, on prend la somme des montants_source quand devise_source = USD
        $totalMontantUSD = (clone $query)
            ->whereHas('deviseSource', fn($q) => $q->where('code', 'USD'))
            ->sum('montant_source');

        // 3. Commission totale
        $totalCommission = (clone $query)->sum('commission');

        // 4. Volume par devise (montant total échangé par devise, toutes opérations)
        $devises = Devise::where('statut', 1)->get();
        $volumesSource = (clone $query)
            ->select('devise_source_id', DB::raw('SUM(montant_source) as total'))
            ->groupBy('devise_source_id')
            ->get()
            ->keyBy('devise_source_id');

        $volumesCible = (clone $query)
            ->select('devise_cible_id', DB::raw('SUM(montant_cible) as total'))
            ->groupBy('devise_cible_id')
            ->get()
            ->keyBy('devise_cible_id');

        $volumeParDevise = [];
        foreach ($devises as $devise) {
            $source = $volumesSource->get($devise->id);
            $cible  = $volumesCible->get($devise->id);
            $volumeParDevise[$devise->code] = (float) (($source->total ?? 0) + ($cible->total ?? 0));
        }

        // 5. Évolution horaire (par heure de la journée) – utiliser created_at
        $evolutionHoraire = (clone $query)
            ->select(DB::raw("HOUR(created_at) as heure"), DB::raw("COUNT(*) as nb"))
            ->groupBy('heure')
            ->orderBy('heure')
            ->get()
            ->map(fn($item) => [
                'heure' => str_pad($item->heure, 2, '0', STR_PAD_LEFT) . 'h',
                'nb'    => $item->nb,
            ])
            ->toArray();

        // 6. Répartition achat / vente (selon type_operation)
        $nbAchats = (clone $query)->whereIn('type_operation', ['ACHAT', 'ACHAT-T'])->count();
        $nbVentes = (clone $query)->whereIn('type_operation', ['VENTE', 'VENTE-T'])->count();

        // Comparaison achat/vente par devise
        $comparaisonAchatVente = [];
        foreach ($devises as $devise) {
            $comparaisonAchatVente[$devise->code] = [
                'achat' => (float) (clone $query)
                    ->whereIn('type_operation', ['ACHAT', 'ACHAT-T'])
                    ->where('devise_source_id', $devise->id)
                    ->sum('montant_source'),
                'vente' => (float) (clone $query)
                    ->whereIn('type_operation', ['VENTE', 'VENTE-T'])
                    ->where('devise_cible_id', $devise->id)
                    ->sum('montant_cible'),
            ];
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'total_operations'        => $totalOperations,
                'total_montant_usd'       => (float) $totalMontantUSD,
                'total_commission'        => (float) $totalCommission,
                'volume_par_devise'       => $volumeParDevise,
                'evolution_horaire'       => $evolutionHoraire,
                'nb_achats'               => $nbAchats,
                'nb_ventes'               => $nbVentes,
                'comparaison_achat_vente' => $comparaisonAchatVente,
            ],
        ]);
    }
}