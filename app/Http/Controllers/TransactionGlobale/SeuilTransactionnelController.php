<?php

namespace App\Http\Controllers\TransactionGlobale;

use App\Http\Controllers\Controller;
use App\Models\Seuil_Transactionnels;
use Illuminate\Http\Request;

class SeuilTransactionnelController extends Controller
{
    public function index(Request $request)
    {
        $query = Seuil_Transactionnels::with(['role', 'devise']);

        if ($request->has('type_transaction')) {
            $query->parType($request->type_transaction);
        }

        if ($request->has('niveau_alerte')) {
            $query->where('niveau_alerte', $request->niveau_alerte);
        }

        if ($request->has('role_id')) {
            $query->where('role_id', $request->role_id);
        }

        if ($request->has('devise_id')) {
            $query->where('devise_id', $request->devise_id);
        }

        $seuils = $query->orderBy('type_transaction')
                        ->orderBy('role_id')
                        ->orderBy('devise_id')
                        ->orderBy('montant_min')
                        ->get();

        return response()->json([
            'success' => true,
            'message' => 'Liste des seuils transactionnels',
            'data'    => $seuils,
        ], 200);
    }

    public function store(Request $request)
    {
        // Validation avec règles conditionnelles
        $rules = [
            'type_transaction' => 'required|string|max:150|in:MOBILE_MONEY,TRANSFERT_INTERNATIONAL,CHANGE,VENTE,ACHAT',
            'montant_min'      => 'nullable|numeric|min:0',
            'montant_max'      => 'nullable|numeric|min:0',
            'niveau_alerte'    => 'required|string|in:FAIBLE,MOYEN,ELEVE,CRITIQUE',
            'role_id'          => 'nullable|exists:roles,id',
            'devise_id'        => 'nullable|exists:devise,id',
        ];

        // Si les deux champs sont fournis, montant_max > montant_min
        if (!is_null($request->montant_min) && !is_null($request->montant_max)) {
            $rules['montant_max'] .= '|gt:montant_min';
        }

        $validated = $request->validate($rules);

        // Construction de la requête de chevauchement avec contexte
        $query = Seuil_Transactionnels::where('type_transaction', $validated['type_transaction'])
            ->where(function ($q) use ($validated) {
                $q->where(function ($sub) use ($validated) {
                    if (isset($validated['role_id']) && !is_null($validated['role_id'])) {
                        $sub->where('role_id', $validated['role_id']);
                    } else {
                        $sub->whereNull('role_id');
                    }
                });
                $q->where(function ($sub) use ($validated) {
                    if (isset($validated['devise_id']) && !is_null($validated['devise_id'])) {
                        $sub->where('devise_id', $validated['devise_id']);
                    } else {
                        $sub->whereNull('devise_id');
                    }
                });
            });

        $newMin = $validated['montant_min'] ?? null;
        $newMax = $validated['montant_max'] ?? null;

        // Condition de chevauchement avec gestion des NULL
        $query->where(function ($q) use ($newMin, $newMax) {
            // existant.min <= newMax (ou newMax null => toujours vrai)
            $q->when(!is_null($newMax), function ($q) use ($newMax) {
                $q->where(function ($sub) use ($newMax) {
                    $sub->whereNull('montant_min')
                        ->orWhere('montant_min', '<=', $newMax);
                });
            }, function ($q) {
                $q->whereRaw('1=1');
            });

            // existant.max >= newMin (ou newMin null => toujours vrai)
            $q->when(!is_null($newMin), function ($q) use ($newMin) {
                $q->where(function ($sub) use ($newMin) {
                    $sub->whereNull('montant_max')
                        ->orWhere('montant_max', '>=', $newMin);
                });
            }, function ($q) {
                $q->whereRaw('1=1');
            });
        });

        if ($query->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Ce seuil chevauche une tranche existante pour ce type, ce rôle et cette devise.',
                'data'    => null
            ], 422);
        }

        $seuil = Seuil_Transactionnels::create([
            'type_transaction' => $validated['type_transaction'],
            'montant_min'      => $validated['montant_min'] ?? null,
            'montant_max'      => $validated['montant_max'] ?? null,
            'niveau_alerte'    => $validated['niveau_alerte'],
            'role_id'          => $validated['role_id'] ?? null,
            'devise_id'        => $validated['devise_id'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Seuil créé avec succès',
            'data'    => $seuil->load(['role', 'devise']),
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

        $seuil = Seuil_Transactionnels::with(['role', 'devise'])->find($id);

        if (!$seuil) {
            return response()->json([
                'success' => false,
                'message' => 'Seuil introuvable',
                'data'    => null
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Détails du seuil',
            'data'    => $seuil,
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

        $seuil = Seuil_Transactionnels::find($id);

        if (!$seuil) {
            return response()->json([
                'success' => false,
                'message' => 'Seuil introuvable',
                'data'    => null
            ], 404);
        }

        $rules = [
            'montant_min'   => 'sometimes|nullable|numeric|min:0',
            'montant_max'   => 'sometimes|nullable|numeric|min:0',
            'niveau_alerte' => 'sometimes|string|in:FAIBLE,MOYEN,ELEVE,CRITIQUE',
        ];

        // On ajoute la règle gt uniquement si les deux sont fournis
        if ($request->has('montant_min') && $request->has('montant_max')) {
            $rules['montant_max'] .= '|gt:montant_min';
        }

        $validated = $request->validate($rules);

        $seuil->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Seuil mis à jour avec succès',
            'data'    => \array_intersect_key(
                $seuil->fresh()->toArray(),
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

        $seuil = Seuil_Transactionnels::find($id);

        if (!$seuil) {
            return response()->json([
                'success' => false,
                'message' => 'Seuil introuvable',
                'data'    => null
            ], 404);
        }

        // Bloquer si des transactions utilisent ce seuil
        if ($seuil->transactions()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer un seuil utilisé par des transactions',
                'data'    => null
            ], 422);
        }

        $seuil->delete();

        return response()->json([
            'success' => true,
            'message' => 'Seuil supprimé avec succès',
            'data'    => [
                'id'               => $seuil->id,
                'type_transaction' => $seuil->type_transaction,
                'niveau_alerte'    => $seuil->niveau_alerte,
            ]
        ], 200);
    }

    // ============================================================
    // Détecter le seuil pour un montant donné
    // ============================================================
    
    public function detecter(Request $request)
    {
        $validated = $request->validate([
            'type_transaction' => 'required|string|in:MOBILE_MONEY,TRANSFERT_INTERNATIONAL,CHANGE,VENTE,ACHAT',
            'montant'          => 'required|numeric|min:0',
            'devise_id'        => 'nullable|integer|exists:devise,id', // Devise concernée (source ou cible)
            // role_id est automatiquement déduit de l'utilisateur connecté
        ]);

        $user = auth()->user();
        $roleId = $user ? $user->role_id : null;

        $seuil = Seuil_Transactionnels::detecter(
            $validated['type_transaction'],
            (float) $validated['montant'],
            $validated['devise_id'] ?? null,
            $roleId
        );

        if (!$seuil) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun seuil trouvé pour ce montant, ce type, cette devise et ce rôle.',
                'data'    => null,
            ], 404);
        }

        $necessiteAlerte = in_array($seuil->niveau_alerte, ['MOYEN', 'ELEVE', 'CRITIQUE']);
        $estCritique = $seuil->niveau_alerte === 'CRITIQUE';

        return response()->json([
            'data' => [
                'seuil'            => $seuil,
                'necessite_alerte' => $necessiteAlerte,
                'est_critique'     => $estCritique,
            ], 
        ],200);
    }
}