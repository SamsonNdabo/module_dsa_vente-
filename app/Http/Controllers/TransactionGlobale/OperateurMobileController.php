<?php

namespace App\Http\Controllers\TransactionGlobale;

use App\Http\Controllers\Controller;
use App\Models\Operateur_Mobile;
use Illuminate\Http\Request;

class OperateurMobileController extends Controller
{
    public function index()
    {
        $operateurs = Operateur_Mobile::with('serviceMobileMoney')->get();

        return response()->json([
            'success' => true,
            'message' => 'Liste des opérateurs mobile',
            'data'    => $operateurs,
        ], 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code_operateur'          => 'required|string|max:50|unique:operateur_mobile,code_operateur',
            'nom_operateur'           => 'required|string|max:100',
            'commission_defaut'       => 'nullable|numeric|min:0|max:100',
            'service_mobile_money_id' => 'nullable|exists:service_mobile_money,id',
        ]);

        $operateur = Operateur_Mobile::create([
            'code_operateur'          => $validated['code_operateur'],
            'nom_operateur'           => $validated['nom_operateur'],
            'commission_defaut'       => $validated['commission_defaut'] ?? null,
            'service_mobile_money_id' => $validated['service_mobile_money_id'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Opérateur mobile créé avec succès',
            'data'    => $operateur,
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

        $operateur = Operateur_Mobile::with('transactions', 'serviceMobileMoney.nom')->find($id);

        if (!$operateur) {
            return response()->json([
                'success' => false,
                'message' => 'Opérateur mobile introuvable',
                'data'    => null
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Détails de l\'opérateur mobile',
            'data'    => $operateur,
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

        $operateur = Operateur_Mobile::find($id);

        if (!$operateur) {
            return response()->json([
                'success' => false,
                'message' => 'Opérateur mobile introuvable',
                'data'    => null
            ], 404);
        }

        $validated = $request->validate([
            'nom_operateur'     => 'sometimes|string|max:100',
            'commission_defaut' => 'nullable|numeric|min:0|max:100',
        ]);

        $operateur->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Opérateur mobile mis à jour avec succès',
            'data'    => \array_intersect_key(
                $operateur->fresh()->toArray(),
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

        $operateur = Operateur_Mobile::find($id);

        if (!$operateur) {
            return response()->json([
                'success' => false,
                'message' => 'Opérateur mobile introuvable',
                'data'    => null
            ], 404);
        }

        // Bloquer si l'opérateur a des transactions
        if ($operateur->transactions()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer un opérateur ayant des transactions',
                'data'    => null
            ], 422);
        }

        $operateur->delete();

        return response()->json([
            'success' => true,
            'message' => 'Opérateur mobile supprimé avec succès',
            'data'    => [
                'id'             => $operateur->id,
                'code_operateur' => $operateur->code_operateur,
                'nom_operateur'  => $operateur->nom_operateur,
            ]
        ], 200);
    }

    // ============================================================
    // Simuler une commission
    // ============================================================
    public function simulerCommission(Request $request, $id)
    {
        if (!ctype_digit(\strval($id))) {
            return response()->json([
                'success' => false,
                'message' => 'ID invalide',
                'data'    => null
            ], 400);
        }

        $operateur = Operateur_Mobile::find($id);

        if (!$operateur) {
            return response()->json([
                'success' => false,
                'message' => 'Opérateur mobile introuvable',
                'data'    => null
            ], 404);
        }

        if (!$operateur->commission_defaut) {
            return response()->json([
                'success' => false,
                'message' => 'Cet opérateur n\'a pas de commission défaut configurée',
                'data'    => null
            ], 422);
        }

        $validated = $request->validate([
            'montant' => 'required|numeric|min:0',
        ]);

        $commission = $operateur->calculerCommission((float) $validated['montant']);
        $montantNet = $operateur->calculerMontantNet((float) $validated['montant']);

        return response()->json([
            'success' => true,
            'message' => 'Simulation de commission',
            'data'    => [
                'operateur'         => $operateur->nom_operateur,
                'montant_brut'      => $validated['montant'],
                'taux_commission'   => $operateur->commission_defaut,
                'commission'        => $commission,
                'montant_net'       => $montantNet,
            ]
        ], 200);
    }
}