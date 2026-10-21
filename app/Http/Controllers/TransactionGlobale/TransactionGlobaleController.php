<?php

namespace App\Http\Controllers\TransactionGlobale;

use App\Http\Controllers\Controller;
use App\Models\OperationChange;
use App\Models\Transaction_Globale;
use App\Models\Seuil_Transactionnels;
use App\Models\Caisses;
use App\Models\Commission;
use Illuminate\Http\Request;

class TransactionGlobaleController extends Controller
{
      public function index(Request $request)
    {
        $user = auth()->user();
    
        $query = Transaction_Globale::with([
            'devise',
            'succursale',
            'caisse',
            'seuil'
        ]);
    
    
        if ($user->isSuperAdmin() || $user->isChefOperation()) {
    
            // Accès complet
    
        } elseif ($user->isChefAgence()) {
    
            $query->where('id_succursale', $user->succursale_id);
    
        } else {
    
            $query->whereHas('caisse', function ($q) use ($user) {
                $q->where('caissier_attribue', $user->id);
            });
    
        }
    
    
        /*
        ============================================================
        FILTRES
        ============================================================
        */
    
        if ($request->has('type')) {
            $query->parType($request->input('type'));
        }
    
    
        if ($request->has('statut')) {
            $query->parStatut($request->input('statut'));
        }
    
    
        /*
        ============================================================
        FILTRE SUCCURSALE
        ============================================================
        Accessible uniquement par SUPER_ADMIN et CHEF_OPERATIONS
        ============================================================
        */
        if (
            $request->has('id_succursale') &&
            ($user->isSuperAdmin() || $user->isChefOperation())
        ) {
            $query->parSuccursale($request->input('id_succursale'));
        }
    
    
        if ($request->has('caisse_id')) {
            $query->where('caisse_id', $request->input('caisse_id'));
        }
    
    
        if ($request->has('date_debut') && $request->has('date_fin')) {
            $query->whereBetween('date_transaction', [
                $request->input('date_debut'),
                $request->input('date_fin')
            ]);
        }
    
    
        if ($request->has('aujourdhui')) {
            $query->aujourdhui();
        }
    
    
        $transactions = $query
            ->orderBy('created_at', 'desc')
            ->paginate(20);
    
    
        return response()->json([
            'success' => true,
            'message' => 'Liste des transactions',
            'data'    => $transactions,
        ], 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'type'                           => 'required|string|max:150|in:MOBILE_MONEY,TRANSFERT_INTERNATIONAL,VENTE',
            'montant_local'                  => 'required|numeric|min:0',
            'devise_id'                      => 'required|integer|exists:devise,id',
            'date_transaction'               => 'required|date',
            'statut'                         => 'required|string|max:150',
            'id_succursale'                  => 'required|integer|exists:succursale,id',
            'caisse_id'                      => 'nullable|integer|exists:caisses,id',
            'inclure_change'                 => 'nullable|boolean',
            'devise_change_id'               => 'required_if:inclure_change,true|integer|exists:devise,id',
            'reference_transfert'            => 'required|string|max:50',
            'transfert_international_id'     => 'nullable|integer|exists:transfert_international ,id',
        ]);

        // Vérifier que la caisse est ouverte
        if ($validated['caisse_id'] ?? null) {
            $caisse = Caisses::find($validated['caisse_id']);

            if (!$caisse) {
                return response()->json([
                    'success' => false,
                    'message' => 'Caisse introuvable',
                    'data'    => null
                ], 404);
            }

            if ($caisse->statut != 'OUVERTE') {
                return response()->json([
                    'success' => false,
                    'message' => 'La caisse doit être ouverte pour effectuer une transaction',
                    'data'    => null
                ], 422);
            }
        }

        // Détecter automatiquement le seuil
        $seuil = Seuil_Transactionnels::detecter(
            $validated['type'],
            (float) $validated['montant_local']
        );

        if (!$seuil) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun seuil configuré pour ce type de transaction',
                'data'    => null
            ], 422);
        }

        // Générer la référence unique
        $reference = Transaction_Globale::genererReference();

        $transaction = Transaction_Globale::create([
            'reference'                   => $reference,
            'type'                        => $validated['type'],
            'montant_local'               => $validated['montant_local'],
            'devise_id'                   => $validated['devise_id'],
            'date_transaction'            => $validated['date_transaction'],
            'statut'                      => $validated['statut'],
            'commission_starehe'          => 0,
            'id_succursale'               => $validated['id_succursale'],
            'id_creator'                  => auth()->id(),
            'caisse_id'                   => $validated['caisse_id'] ?? null,
            'id_seuil_transaction'        => $seuil->id,
            'reference_transfert'         => $validated['reference_transfert'] ?? null,
            'transfert_international_id'  => $validated['transfert_international_id'] ?? null,
        ]);
        
        // Change inclus si demandé
        if ($validated['inclure_change'] ?? false) {
            $change = OperationChange::effectuer(
                $validated['id_succursale'],
                $validated['devise_id'],
                $validated['devise_change_id'],
                (float) $validated['montant_local'],
                $validated['caisse_id'] ?? null,
                null
            );

            if (!$change) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun taux de change disponible pour effectuer le change',
                    'data'    => null,
                ], 422);
            }

            $transaction->operation_change_id  = $change['operation']->id;
            $transaction->montant_devise_pivot = $change['operation']->montant_cible;
            $transaction->save();
        }


        // Vérifier si une alerte est nécessaire
        $user = auth()->user();
        if (Seuil_Transactionnels::necessiteAlerte($validated['type'], (float) $validated['montant_source'], $validated['devise_id'], $user->role_id)) {
            // TODO:Notifier (log, email, etc.)
        }

        $commission = Commission::creerPourTransaction($transaction);

        return response()->json([
            'success' => true,
            'message' => 'Transaction créée avec succès',
            'data'    => [
                'transaction'      => $transaction->load(['devise', 'succursale', 'caisse', 'seuil', 'operationChange']),
                'commission'       => $commission,
                'niveau_alerte'    => $seuil->niveau_alerte,
            ],
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

        $transaction = Transaction_Globale::with([
            'devise',
            'succursale',
            'caisse',
            'seuil',
            'transactionMobile.operateur',
        ])->find($id);

        if (!$transaction) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction introuvable',
                'data'    => null
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Détails de la transaction',
            'data'    => $transaction,
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

        $transaction = Transaction_Globale::find($id);

        if (!$transaction) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction introuvable',
                'data'    => null
            ], 404);
        }

        // Bloquer si la transaction est déjà complète
        if ($transaction->estComplete()) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de modifier une transaction complète',
                'data'    => null
            ], 422);
        }

        $validated = $request->validate([
            'statut'             => 'sometimes|string|max:150',
            'commission_starehe' => 'sometimes|numeric|min:0',
        ]);

        $transaction->update($validated);

        // Si statut change → mettre à jour la commission
        if (isset($validated['statut'])) {
            $transaction->mettreAJourCommission($validated['statut']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Transaction mise à jour avec succès',
            'data'    => \array_intersect_key(
                $transaction->fresh()->toArray(),
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

        $transaction = Transaction_Globale::find($id);

        if (!$transaction) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction introuvable',
                'data'    => null
            ], 404);
        }

        // Bloquer si la transaction est complète
        if ($transaction->estComplete()) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer une transaction complète',
                'data'    => null
            ], 422);
        }

        // Bloquer si la transaction a des enfants
        if ($transaction->transactionMobile()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer une transaction ayant des transactions mobile associées',
                'data'    => null
            ], 422);
        }

        $transaction->delete();

        return response()->json([
            'success' => true,
            'message' => 'Transaction supprimée avec succès',
            'data'    => [
                'id'        => $transaction->id,
                'reference' => $transaction->reference,
                'type'      => $transaction->type,
            ]
        ], 200);
    }

    // ============================================================
    // Statistiques du jour
    // ============================================================

    public function statistiques(Request $request)
    {
        $query = Transaction_Globale::aujourdhui();

        if ($request->has('id_succursale')) {
            $query->parSuccursale($request->id_succursale);
        }

        $transactions = $query->get();

        $stats = [
            'total_transactions' => $transactions->count(),
            'total_montant'      => $transactions->sum('montant_local'),
            'total_commission'   => $transactions->sum('commission_starehe'),
            'par_type'           => $transactions->groupBy('type')->map(function ($group) {
                return [
                    'nombre'  => $group->count(),
                    'montant' => $group->sum('montant_local'),
                ];
            }),
            'par_statut' => $transactions->groupBy('statut')->map(function ($group) {
                return [
                    'nombre'  => $group->count(),
                    'montant' => $group->sum('montant_local'),
                ];
            }),
        ];

        return response()->json([
            'success' => true,
            'message' => 'Statistiques des transactions du jour',
            'data'    => $stats,
        ], 200);
    }

}