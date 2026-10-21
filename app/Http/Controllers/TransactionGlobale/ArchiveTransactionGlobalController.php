<?php

namespace App\Http\Controllers\TransactionGlobale;

use App\Http\Controllers\Controller;
use App\Models\Archive_Transaction_Global;
use App\Models\Transaction_Globale;
use Illuminate\Http\Request;

class ArchiveTransactionGlobalController extends Controller
{
    public function index(Request $request)
    {
        $query = Archive_Transaction_Global::with(['devise', 'succursale', 'caisse']);

        if ($request->has('type')) {
            $query->parType($request->type);
        }

        if ($request->has('statut')) {
            $query->where('statut', $request->statut);
        }

        if ($request->has('id_succursale')) {
            $query->where('id_succursale', $request->id_succursale);
        }

        if ($request->has('date_debut') && $request->has('date_fin')) {
            $query->parPeriode($request->date_debut, $request->date_fin);
        }

        $archives = $query->orderBy('date_transaction', 'desc')->paginate(20);

        return response()->json([
            'success' => true,
            'message' => 'Liste des transactions archivées',
            'data'    => $archives,
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

        $archive = Archive_Transaction_Global::with([
            'devise',
            'succursale',
            'caisse',
        ])->find($id);

        if (!$archive) {
            return response()->json([
                'success' => false,
                'message' => 'Archive introuvable',
                'data'    => null
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Détails de l\'archive',
            'data'    => $archive,
        ], 200);
    }

    // ============================================================
    // Archiver une transaction globale
    // ============================================================

    public function archiver(Request $request)
    {
        $validated = $request->validate([
            'transaction_id' => 'required|integer|exists:transaction_globale,id',
        ]);

        $transaction = Transaction_Globale::find($validated['transaction_id']);

        // Vérifier que la transaction est complète avant d'archiver
        if (!$transaction->estComplete()) {
            return response()->json([
                'success' => false,
                'message' => 'Seules les transactions complètes peuvent être archivées',
                'data'    => null
            ], 422);
        }

        // Vérifier si déjà archivée
        $dejaArchivee = Archive_Transaction_Global::where('reference', $transaction->reference)
            ->exists();

        if ($dejaArchivee) {
            return response()->json([
                'success' => false,
                'message' => 'Cette transaction est déjà archivée',
                'data'    => null
            ], 422);
        }

        // Archiver via le model
        $archive = Archive_Transaction_Global::archiver($transaction);

        // Supprimer la transaction originale après archivage
        $transaction->delete();

        return response()->json([
            'success' => true,
            'message' => 'Transaction archivée avec succès',
            'data'    => $archive->load(['devise', 'succursale', 'caisse']),
        ], 201);
    }

    // ============================================================
    // Archiver plusieurs transactions en masse
    // ============================================================

    public function archiverEnMasse(Request $request)
    {
        $validated = $request->validate([
            'transaction_ids'   => 'required|array|min:1',
            'transaction_ids.*' => 'integer|exists:transaction_globale,id',
        ]);

        $succes   = [];
        $echecs   = [];

        foreach ($validated['transaction_ids'] as $transactionId) {
            $transaction = Transaction_Globale::find($transactionId);

            // Vérifier que la transaction est complète
            if (!$transaction->estComplete()) {
                $echecs[] = [
                    'id'     => $transactionId,
                    'raison' => 'Transaction non complète',
                ];
                continue;
            }

            // Vérifier si déjà archivée
            $dejaArchivee = Archive_Transaction_Global::where('reference', $transaction->reference)
                ->exists();

            if ($dejaArchivee) {
                $echecs[] = [
                    'id'     => $transactionId,
                    'raison' => 'Déjà archivée',
                ];
                continue;
            }

            // Archiver
            $archive    = Archive_Transaction_Global::archiver($transaction);
            $succes[]   = [
                'id'        => $transactionId,
                'reference' => $archive->reference,
            ];

            // Supprimer la transaction originale
            $transaction->delete();
        }

        return response()->json([
            'success' => true,
            'message' => \count($succes) . ' transaction(s) archivée(s) avec succès',
            'data'    => [
                'succes' => $succes,
                'echecs' => $echecs,
                'total_succes' => \count($succes),
                'total_echecs' => \count($echecs),
            ],
        ], 200);
    }

    // ============================================================
    // Statistiques des archives
    // ============================================================
    public function statistiques(Request $request)
    {
        $query = Archive_Transaction_Global::query();

        if ($request->has('id_succursale')) {
            $query->where('id_succursale', $request->id_succursale);
        }

        if ($request->has('date_debut') && $request->has('date_fin')) {
            $query->parPeriode($request->date_debut, $request->date_fin);
        }

        $archives = $query->get();

        return response()->json([
            'success' => true,
            'message' => 'Statistiques des archives',
            'data'    => [
                'total'            => $archives->count(),
                'total_montant'    => $archives->sum('montant_local'),
                'total_commission' => $archives->sum('commission_starehe'),
                'par_type'         => $archives->groupBy('type')->map(function ($group) {
                    return [
                        'nombre'  => $group->count(),
                        'montant' => $group->sum('montant_local'),
                    ];
                }),
                'par_statut'       => $archives->groupBy('statut')->map(function ($group) {
                    return [
                        'nombre'  => $group->count(),
                        'montant' => $group->sum('montant_local'),
                    ];
                }),
            ],
        ], 200);
    }
}