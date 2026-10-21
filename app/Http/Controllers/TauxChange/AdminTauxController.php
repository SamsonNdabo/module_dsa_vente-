<?php

namespace App\Http\Controllers\TauxChange;

use App\Http\Controllers\Controller;
use App\Models\SuccursaleExchangeRate;
use App\Models\Devise;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminTauxController extends Controller
{
    public function vueEnsemble(Request $request)
    {
        $validated = $request->validate([
            'devise_source' => 'required|integer|exists:devise,id',
            'devise_cible'  => 'required|integer|exists:devise,id|different:devise_source',
        ]);

        $data = SuccursaleExchangeRate::vueEnsemble(
            $validated['devise_source'],
            $validated['devise_cible']
        );

        $deviseSource = Devise::find($validated['devise_source']);
        $deviseCible  = Devise::find($validated['devise_cible']);

        return response()->json([
            'success' => true,
            'message' => 'Vue d\'ensemble des taux par succursale',
            'data'    => array_merge($data, [
                'devise_source' => $deviseSource->code,
                'devise_cible'  => $deviseCible->code,
            ]),
        ], 200);
    }
    // Cette fonction permet à l'admin de définir un taux sans que cela attande une validation
    // Par conséquent différent de tauxchangesuccursalecontroller (store)
    public function definir(Request $request)
    {
        $validated = $request->validate([
            'succursale_id' => 'required|integer|exists:succursale,id',
            'devise_source' => 'required|integer|exists:devise,id',
            'devise_cible'  => 'required|integer|exists:devise,id|different:devise_source',
            'taux_achat'    => 'required|numeric|min:0',
            'taux_vente'    => 'required|numeric|min:0|gte:taux_achat',
            'date_taux'     => 'required|date',
            'modifie_par'   => 'required|integer|exists:utilisateurs,id',
            'motif'         => 'required|string|max:191',
        ]);

        $taux = SuccursaleExchangeRate::definirParAdmin($validated);

        return response()->json([
            'success' => true,
            'message' => 'Taux défini avec succès pour la succursale',
            'data'    => $taux->load(['succursale']),
        ], 201);
    }

    public function reinitialiser(Request $request)
    {
        $validated = $request->validate([
            'modifie_par'              => 'required|integer|exists:utilisateurs,id',
            'motif'                    => 'required|string|max:191',
            'taux'                     => 'required|array|min:1',
            'taux.*.devise_source'     => 'required|integer|exists:devise,id',
            'taux.*.devise_cible'      => 'required|integer|exists:devise,id',
            'taux.*.taux_achat'        => 'required|numeric|min:0',
            'taux.*.taux_vente'        => 'required|numeric|min:0|gte:taux.*.taux_achat',
        ]);

        // Vérifications (inchangées)
        foreach ($validated['taux'] as $index => $paire) {
            if ($paire['devise_source'] === $paire['devise_cible']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur paire #' . ($index + 1) . ' : devise_source et devise_cible identiques',
                    'data'    => null,
                ], 422);
            }
        }
        $cles = array_map(fn($p) => $p['devise_source'] . '-' . $p['devise_cible'], $validated['taux']);
        if (count($cles) !== count(array_unique($cles))) {
            return response()->json([
                'success' => false,
                'message' => 'Le tableau contient des paires de devises en doublon',
                'data'    => null,
            ], 422);
        }

        $rapport = DB::transaction(fn() =>
            SuccursaleExchangeRate::reinitialiserToutes(
                $validated['taux'],
                $validated['modifie_par'],
                $validated['motif']
            )
        );

        return response()->json([
            'success' => true,
            'message' => 'Réinitialisation effectuée — ' . count($rapport['paires']) . ' paire(s), ' . $rapport['nb_succursales'] . ' succursale(s) mises à jour',
            'data'    => [
                'modifie_par'              => $validated['modifie_par'],
                'motif'                    => $validated['motif'],
                'date'                     => now()->format('Y-m-d H:i:s'),
                'paires_traitees'          => count($rapport['paires']),
                'succursales_mises_a_jour' => $rapport['nb_succursales'],
                'rapport'                  => $rapport['paires'],
            ],
        ], 200);
    }
}