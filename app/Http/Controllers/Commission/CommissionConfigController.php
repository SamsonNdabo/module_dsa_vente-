<?php

namespace App\Http\Controllers\Commission;

use App\Http\Controllers\Controller;
use App\Models\CommissionConfig;
use Illuminate\Http\Request;

class CommissionConfigController extends Controller
{
    public function index(Request $request)
    {
        $query = CommissionConfig::query();

        if ($request->has('type_transaction')) {
            $query->parTypeTransaction($request->type_transaction);
        }

        if ($request->has('etat')) {
            $query->where('etat', $request->etat);
        }

        $configs = $query->orderBy('type_transaction')
                         ->orderBy('service')
                         ->get();

        return response()->json([
            'success' => true,
            'message' => 'Liste des configurations de commission',
            'data'    => $configs,
        ], 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'service'          => 'required|string|max:150',
            'type_calcul'      => 'required|in:POURCENTAGE,MONTANT_FIXE',
            'valeur'           => 'required|numeric|min:0',
            'type_transaction' => 'required|in:MOBILE_MONEY,MOBILE_MONEY_RETRAIT,TRANSFERT_INTERNATIONAL,TRANSFERT_INTERNATIONAL_RECEPTION,CHANGE,VENTE',
            'etat'             => 'required|in:ACTIF,INACTIF',
        ]);

        // Vérifier doublon service + type_transaction
        $existe = CommissionConfig::where('service', $validated['service'])
            ->where('type_transaction', $validated['type_transaction'])
            ->exists();

        if ($existe) {
            return response()->json([
                'success' => false,
                'message' => 'Une configuration existe déjà pour ce service et ce type de transaction',
                'data'    => null
            ], 422);
        }

        $config = CommissionConfig::create([
            'service'          => $validated['service'],
            'type_calcul'      => $validated['type_calcul'],
            'valeur'           => $validated['valeur'],
            'type_transaction' => $validated['type_transaction'],
            'etat'             => $validated['etat'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Configuration créée avec succès',
            'data'    => $config,
        ], 201);
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

        $config = CommissionConfig::find($id);

        if (!$config) {
            return response()->json([
                'success' => false,
                'message' => 'Configuration introuvable',
                'data'    => null
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Détails de la configuration',
            'data'    => $config,
        ], 200);
    }

    public function update(Request $request, $id)
    {
        if (!ctype_digit(\strval($id))) {
            return response()->json([
                'success' => false,
                'message' => 'ID invalide',
                'data'    => null
            ], 400);
        }

        $config = CommissionConfig::find($id);

        if (!$config) {
            return response()->json([
                'success' => false,
                'message' => 'Configuration introuvable',
                'data'    => null
            ], 404);
        }

        $validated = $request->validate([
            'type_calcul' => 'sometimes|in:POURCENTAGE,MONTANT_FIXE',
            'valeur'      => 'sometimes|numeric|min:0',
            'etat'        => 'sometimes|in:ACTIF,INACTIF',
        ]);

        $config->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Configuration mise à jour avec succès',
            'data'    => \array_intersect_key(
                $config->fresh()->toArray(),
                $validated
            ),
        ], 200);
    }

    public function destroy($id)
    {
        if (!ctype_digit(\strval($id))) {
            return response()->json([
                'success' => false,
                'message' => 'ID invalide',
                'data'    => null
            ], 400);
        }

        $config = CommissionConfig::find($id);

        if (!$config) {
            return response()->json([
                'success' => false,
                'message' => 'Configuration introuvable',
                'data'    => null
            ], 404);
        }

        // Bloquer si des commissions utilisent cette config
        if ($config->commissionServices()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer une configuration utilisée par des commissions',
                'data'    => null
            ], 422);
        }

        $config->delete();

        return response()->json([
            'success' => true,
            'message' => 'Configuration supprimée avec succès',
            'data'    => [
                'id'               => $config->id,
                'service'          => $config->service,
                'type_transaction' => $config->type_transaction,
            ]
        ], 200);
    }

    // ============================================================
    // Simuler les commissions pour un montant
    // ============================================================
    
    public function simuler(Request $request)
    {
        $validated = $request->validate([
            'type_transaction' => 'required|in:MOBILE_MONEY,TRANSFERT_INTERNATIONAL,CHANGE,VENTE',
            'montant'          => 'required|numeric|min:0',
        ]);

        $configs = CommissionConfig::getConfigsActives($validated['type_transaction']);

        if ($configs->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Aucune configuration de commission active pour ce type',
                'data'    => null
            ], 404);
        }

        $services     = [];
        $montantTotal = 0;

        foreach ($configs as $config) {
            $calcul        = $config->calculer((float) $validated['montant']);
            $montantTotal += $calcul['montant'];
            $services[]    = $calcul;
        }

        return response()->json([
            'success' => true,
            'message' => 'Simulation des commissions',
            'data'    => [
                'type_transaction' => $validated['type_transaction'],
                'montant'          => $validated['montant'],
                'montant_total'    => $montantTotal,
                'services'         => $services,
            ],
        ], 200);
    }
}