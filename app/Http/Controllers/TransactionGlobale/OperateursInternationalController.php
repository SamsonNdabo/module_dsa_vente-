<?php

namespace App\Http\Controllers\TransactionGlobale;

use App\Http\Controllers\Controller;
use App\Models\OperateursInternational;
use Illuminate\Http\Request;

class OperateursInternationalController extends Controller
{
    public function index()
    {
        $operateurs = OperateursInternational::all();

        return response()->json([
            'success' => true,
            'message' => 'Liste des opérateurs internationaux',
            'data'    => $operateurs,
        ], 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code_operateur'       => 'required|string|max:20|unique:operateursinternational,code_operateur',
            'nom_operateur'        => 'required|string|max:50',
            'type_operateur'       => 'nullable|in:BANCAIRE,MOBILE',
            'commission_envoi'     => 'required|numeric|min:0',
            'commission_reception' => 'required|numeric|min:0',
            'taux_change'          => 'nullable|numeric|min:0',
            'pays_desservis'       => 'nullable|array',
            'pays_desservis.*'     => 'string|max:2',
            'api_endpoint'         => 'nullable|string|max:255',
            'api_key'              => 'nullable|string|max:255',
            'statut'               => 'required|in:ACTIF,INACTIF',
        ]);

        $operateur = OperateursInternational::create([
            'code_operateur'       => $validated['code_operateur'],
            'nom_operateur'        => $validated['nom_operateur'],
            'type_operateur'       => $validated['type_operateur'],
            'commission_envoi'     => $validated['commission_envoi'],
            'commission_reception' => $validated['commission_reception'],
            'taux_change'          => $validated['taux_change'] ?? null,
            'pays_desservis'       => $validated['pays_desservis'] ?? null,
            'api_endpoint'         => $validated['api_endpoint'] ?? null,
            'api_key'              => $validated['api_key'] ?? null,
            'statut'               => $validated['statut'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Opérateur international créé avec succès',
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

        $operateur = OperateursInternational::with('transferts')->find($id);

        if (!$operateur) {
            return response()->json([
                'success' => false,
                'message' => 'Opérateur introuvable',
                'data'    => null
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Détails de l\'opérateur',
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

        $operateur = OperateursInternational::find($id);

        if (!$operateur) {
            return response()->json([
                'success' => false,
                'message' => 'Opérateur introuvable',
                'data'    => null
            ], 404);
        }

        $validated = $request->validate([
            'nom_operateur'        => 'sometimes|string|max:50',
            'type_operateur'       => 'sometimes|in:WESTERN_UNION,MONEYGRAM,RIA,AUTRE',
            'commission_envoi'     => 'sometimes|numeric|min:0',
            'commission_reception' => 'sometimes|numeric|min:0',
            'taux_change'          => 'nullable|numeric|min:0',
            'pays_desservis'       => 'nullable|array',
            'pays_desservis.*'     => 'string|max:2',
            'api_endpoint'         => 'nullable|string|max:255',
            'api_key'              => 'nullable|string|max:255',
        ]);

        $operateur->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Opérateur mis à jour avec succès',
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

        $operateur = OperateursInternational::find($id);

        if (!$operateur) {
            return response()->json([
                'success' => false,
                'message' => 'Opérateur introuvable',
                'data'    => null
            ], 404);
        }

        // Bloquer si l'opérateur a des transferts
        if ($operateur->transferts()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer un opérateur ayant des transferts',
                'data'    => null
            ], 422);
        }

        $operateur->delete();

        return response()->json([
            'success' => true,
            'message' => 'Opérateur supprimé avec succès',
            'data'    => [
                'id'             => $operateur->id,
                'code_operateur' => $operateur->code_operateur,
                'nom_operateur'  => $operateur->nom_operateur,
            ]
        ], 200);
    }

    // Activer un opérateur
    public function activer($id)
    {
        if (!ctype_digit(\strval($id))) {
            return response()->json([
                'success' => false,
                'message' => 'ID invalide',
                'data'    => null
            ], 400);
        }

        $operateur = OperateursInternational::find($id);

        if (!$operateur) {
            return response()->json([
                'success' => false,
                'message' => 'Opérateur introuvable',
                'data'    => null
            ], 404);
        }

        if ($operateur->statut == 'ACTIF') {
            return response()->json([
                'success' => false,
                'message' => 'Opérateur déjà actif',
                'data'    => null
            ], 422);
        }

        $operateur->activer();

        return response()->json([
            'success' => true,
            'message' => 'Opérateur activé avec succès',
            'data'    => $operateur->fresh(),
        ], 200);
    }

    // Désactiver un opérateur
    public function desactiver($id)
    {
        if (!ctype_digit(\strval($id))) {
            return response()->json([
                'success' => false,
                'message' => 'ID invalide',
                'data'    => null
            ], 400);
        }

        $operateur = operateursinternational::find($id);

        if (!$operateur) {
            return response()->json([
                'success' => false,
                'message' => 'Opérateur introuvable',
                'data'    => null
            ], 404);
        }

        if ($operateur->statut == 'INACTIF') {
            return response()->json([
                'success' => false,
                'message' => 'Opérateur déjà inactif',
                'data'    => null
            ], 422);
        }

        if (!$operateur->desactiver()) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de désactiver un opérateur ayant des transferts en cours',
                'data'    => null
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Opérateur désactivé avec succès',
            'data'    => $operateur->fresh(),
        ], 200);
    }

    public function simulerTransfert(Request $request, $id)
    {
        if (!ctype_digit(\strval($id))) {
            return response()->json([
                'success' => false,
                'message' => 'ID invalide',
                'data'    => null
            ], 400);
        }

        $operateur = operateursinternational::find($id);

        if (!$operateur) {
            return response()->json([
                'success' => false,
                'message' => 'Opérateur introuvable',
                'data'    => null
            ], 404);
        }

        if ($operateur->statut == 'INACTIF') {
            return response()->json([
                'success' => false,
                'message' => 'Cet opérateur est inactif',
                'data'    => null
            ], 422);
        }

        $validated = $request->validate([
            'type_operation'    => 'required|in:ENVOI,RECEPTION',
            'montant'           => 'required|numeric|min:0',
            'devise_source_id'  => 'required|integer|exists:devise,id',
            'devise_dest_id'    => 'required|integer|exists:devise,id',
        ]);

        // Calculer les frais d'abord
        $frais = $validated['type_operation'] === 'ENVOI'
            ? $operateur->calculerFraisEnvoi((float) $validated['montant'])
            : $operateur->calculerFraisReception((float) $validated['montant']);

        $montantNet = $validated['type_operation'] === 'ENVOI'
            ? (float) $validated['montant'] - $frais   // ENVOI → on retire les frais avant conversion
            : (float) $validated['montant'] - $frais;  // RECEPTION → idem

        // Conversion via le model
        $conversion = $operateur->convertir(
            (int) $validated['devise_source_id'],
            (int) $validated['devise_dest_id'],
            $montantNet 
        );

        if ($conversion['sens'] === 'aucun_taux') {
            return response()->json([
                'success' => false,
                'message' => 'Aucun taux de change disponible pour ces devises',
                'data'    => null
            ], 422);
        }

        // Calculer les frais selon le type d'opération
        $frais = $validated['type_operation'] === 'ENVOI'
            ? $operateur->calculerFraisEnvoi((float) $validated['montant'])
            : $operateur->calculerFraisReception((float) $validated['montant']);

        $montantTotal = $validated['type_operation'] === 'ENVOI'
            ? (float) $validated['montant'] + $frais  // client paie montant brut + frais
            : (float) $validated['montant'] - $frais;  // client reçoit montant brut - frais

        return response()->json([
            'success' => true,
            'message' => 'Simulation de transfert',
            'data'    => [
                'operateur'         => $operateur->nom_operateur,
                'type_operation'    => $validated['type_operation'],
                'montant_brut'      => $validated['montant'],
                'frais_operateur'   => $frais,
                'montant_net'       => $montantNet,          
                'devise_source'     => $validated['devise_source_id'],
                'devise_destination'=> $validated['devise_dest_id'],
                'taux_utilise'      => $conversion['taux_utilise'],
                'source_taux'       => $conversion['sens'],
                'montant_converti'  => $conversion['montant_converti'], 
                'montant_total'     => round($montantTotal, 4),
            ]
        ], 200);
    }

}