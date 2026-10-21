<?php

namespace App\Http\Controllers\TransactionGlobale;

use App\Http\Controllers\Controller;
use App\Models\Registre_Transactions_Sensibles;
use Illuminate\Http\Request;

class RegistreTransactionsSensiblesController extends Controller
{
    public function index(Request $request)
    {
        $query = Registre_Transactions_Sensibles::with([
            'succursale',
            'caissier',
            'superviseur',
        ]);

        if ($request->has('transaction_type')) {
            $query->parType($request->transaction_type);
        }

        if ($request->has('raison_sensibilite')) {
            $query->parRaison($request->raison_sensibilite);
        }

        if ($request->has('succursale_id')) {
            $query->where('succursale_id', $request->succursale_id);
        }

        if ($request->has('non_notifie')) {
            $query->nonNotifie();
        }

        if ($request->has('date_debut') && $request->has('date_fin')) {
            $query->whereBetween('date_transaction', [
                $request->date_debut,
                $request->date_fin,
            ]);
        }

        $registres = $query->orderBy('date_creation', 'desc')->paginate(20);

        return response()->json([
            'success' => true,
            'message' => 'Liste des transactions sensibles',
            'data'    => $registres,
        ], 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'transaction_type'    => 'required|in:MOBILE_MONEY,TRANSFERT_INTERNATIONAL,CHANGE,VENTE',
            'transaction_id'      => 'required|integer',
            'client_id'           => 'nullable|integer',
            'montant'             => 'required|numeric|min:0',
            'devise'              => 'required|string|max:30',
            'raison_sensibilite'  => 'required|in:MONTANT_ELEVE,CLIENT_RISQUE,COMPORTEMENT_SUSPECT,SANCTION,AUTRE',
            'details_sensibilite' => 'required|string',
            'actions_appliquees'  => 'nullable|array',
            'succursale_id'       => 'required|integer|exists:succursale,id',
            'caissier_id'         => 'required|integer|exists:utilisateur,id',
            'superviseur_notifie' => 'nullable|integer|exists:utilisateur,id',
        ]);

        $registre = Registre_Transactions_Sensibles::create([
            'transaction_type'    => $validated['transaction_type'],
            'transaction_id'      => $validated['transaction_id'],
            'client_id'           => $validated['client_id'] ?? null,
            'montant'             => $validated['montant'],
            'devise'              => $validated['devise'],
            'raison_sensibilite'  => $validated['raison_sensibilite'],
            'details_sensibilite' => $validated['details_sensibilite'],
            'actions_appliquees'  => $validated['actions_appliquees'] ?? null,
            'date_transaction'    => now()->format('Y-m-d H:i:s'),
            'succursale_id'       => $validated['succursale_id'],
            'caissier_id'         => $validated['caissier_id'],
            'superviseur_notifie' => $validated['superviseur_notifie'] ?? null,
            'date_notification'   => isset($validated['superviseur_notifie']) ? now()->format('Y-m-d H:i:s') : null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Transaction sensible enregistrée avec succès',
            'data'    => $registre->load(['succursale', 'caissier', 'superviseur']),
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

        $registre = Registre_Transactions_Sensibles::with([
            'succursale',
            'caissier',
            'superviseur',
        ])->find($id);

        if (!$registre) {
            return response()->json([
                'success' => false,
                'message' => 'Registre introuvable',
                'data'    => null
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Détails du registre',
            'data'    => $registre,
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

        $registre = Registre_Transactions_Sensibles::find($id);

        if (!$registre) {
            return response()->json([
                'success' => false,
                'message' => 'Registre introuvable',
                'data'    => null
            ], 404);
        }

        // Un registre de transaction sensible ne peut jamais être supprimé
        return response()->json([
            'success' => false,
            'message' => 'Les registres de transactions sensibles ne peuvent pas être supprimés',
            'data'    => null
        ], 403);
    }

    // ============================================================
    // Notifier le superviseur
    // ============================================================
    public function notifier(Request $request, $id)
    {
        if (!ctype_digit(\strval($id))) {
            return response()->json([
                'success' => false,
                'message' => 'ID invalide',
                'data'    => null
            ], 400);
        }

        $registre = Registre_Transactions_Sensibles::find($id);

        if (!$registre) {
            return response()->json([
                'success' => false,
                'message' => 'Registre introuvable',
                'data'    => null
            ], 404);
        }

        if ($registre->superviseur_notifie) {
            return response()->json([
                'success' => false,
                'message' => 'Le superviseur a déjà été notifié le ' . $registre->date_notification,
                'data'    => null
            ], 422);
        }

        $validated = $request->validate([
            'superviseur_id' => 'required|integer|exists:utilisateur,id',
        ]);

        $registre->notifierSuperviseur($validated['superviseur_id']);

        return response()->json([
            'success' => true,
            'message' => 'Superviseur notifié avec succès',
            'data'    => $registre->fresh()->load(['superviseur']),
        ], 200);
    }

    // ============================================================
    // Statistiques
    // ============================================================
    public function statistiques(Request $request)
    {
        $query = Registre_Transactions_Sensibles::query();

        if ($request->has('succursale_id')) {
            $query->where('succursale_id', $request->succursale_id);
        }

        $registres = $query->get();

        return response()->json([
            'success' => true,
            'message' => 'Statistiques des transactions sensibles',
            'data'    => [
                'total'           => $registres->count(),
                'par_type'        => $registres->groupBy('transaction_type')->map->count(),
                'par_raison'      => $registres->groupBy('raison_sensibilite')->map->count(),
                'total_montant'   => $registres->sum('montant'),
                'non_notifies'    => $registres->whereNull('superviseur_notifie')->count(),
                'notifies'        => $registres->whereNotNull('superviseur_notifie')->count(),
            ],
        ], 200);
    }
}