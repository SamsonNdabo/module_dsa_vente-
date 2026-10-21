<?php

namespace App\Http\Controllers\TauxChange;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use Illuminate\Http\Request;

class ExchangeRateController extends Controller
{
    public function index(Request $request)
    {
        $query = ExchangeRate::query();

        if ($request->filled('devise_source')) {
            $query->where('devise_source', $request->devise_source);
        }
        if ($request->filled('devise_destination')) {
            $query->where('devise_destination', $request->devise_destination);
        }
        if ($request->has('actif')) {
            $query->where('actif', $request->boolean('actif'));
        }

        $rates = $query->orderBy('date_effet', 'desc')->paginate(20);
        return response()->json(['success' => true, 'data' => $rates]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'devise_source'      => 'required|string|max:10|exists:devise,code',
            'devise_destination' => 'required|string|max:10|exists:devise,code|different:devise_source',
            'taux_reference'     => 'required|numeric|min:0',
            'spread_achat'       => 'required|numeric|min:0',
            'spread_vente'       => 'required|numeric|min:0',
            'date_effet'         => 'required|date',
            'actif'              => 'sometimes|boolean',
            'motif'              => 'nullable|string|max:255',
        ]);

        // Récupérer l'ancien taux valide pour cette paire
        $ancien = ExchangeRate::where('devise_source', $validated['devise_source'])
            ->where('devise_destination', $validated['devise_destination'])
            ->where('statut', 'VALIDE')
            ->orderBy('date_effet', 'desc')
            ->first();

        // Si un taux est actif, on le désactive (sauf si le nouveau est en attente)
        if (($validated['actif'] ?? true) && !isset($validated['statut'])) {
            ExchangeRate::where('devise_source', $validated['devise_source'])
                ->where('devise_destination', $validated['devise_destination'])
                ->where('actif', true)
                ->update(['actif' => false]);
        }

        $data = [
            'devise_source'         => $validated['devise_source'],
            'devise_destination'    => $validated['devise_destination'],
            'taux_reference'        => $validated['taux_reference'],
            'spread_achat'          => $validated['spread_achat'],
            'spread_vente'          => $validated['spread_vente'],
            'date_effet'            => $validated['date_effet'],
            'actif'                 => $validated['actif'] ?? true,
            'modifie_par'           => auth()->id(),
            'ancien_taux_reference' => $ancien ? $ancien->taux_reference : null,
            'motif'                 => $validated['motif'] ?? null,
            'statut'                => 'VALIDE', // niveau global : validation automatique
            'valide_par'            => auth()->id(),
            'date_validation'       => now(),
        ];

        $taux = ExchangeRate::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Taux global créé avec succès.',
            'data'    => $taux->load(['modificateur', 'validateur']),
        ], 201);
    }
    public function show($id)
    {
        $rate = ExchangeRate::findOrFail($id);
        return response()->json(['success' => true, 'data' => $rate]);
    }

    public function update(Request $request, $id)
    {
        $rate = ExchangeRate::findOrFail($id);

        $validated = $request->validate([
            'taux_reference' => 'sometimes|numeric|min:0',
            'spread_achat'   => 'sometimes|numeric|min:0',
            'spread_vente'   => 'sometimes|numeric|min:0',
            'date_effet'     => 'sometimes|date',
            'actif'          => 'sometimes|boolean',
        ]);

        // Si on active ce taux, désactiver les autres pour la même paire
        if (($validated['actif'] ?? false) && !$rate->actif) {
            ExchangeRate::where('devise_source', $rate->devise_source)
                ->where('devise_destination', $rate->devise_destination)
                ->where('id', '!=', $rate->id)
                ->where('actif', true)
                ->update(['actif' => false]);
        }

        $rate->update($validated);

        return response()->json(['success' => true, 'message' => 'Taux de référence mis à jour.', 'data' => $rate]);
    }

    public function destroy($id)
    {
        $rate = ExchangeRate::findOrFail($id);
        $rate->delete();
        return response()->json(['success' => true, 'message' => 'Taux de référence supprimé.']);
    }

    public function activate($id)
    {
        $rate = ExchangeRate::findOrFail($id);
        // Désactiver les autres
        ExchangeRate::where('devise_source', $rate->devise_source)
            ->where('devise_destination', $rate->devise_destination)
            ->where('id', '!=', $rate->id)
            ->update(['actif' => false]);

        $rate->actif = true;
        $rate->save();

        return response()->json(['success' => true, 'message' => 'Taux de référence activé.', 'data' => $rate]);
    }

    public function getActif($source, $dest)
    {
        $rate = ExchangeRate::getTauxActif($source, $dest);
        if (!$rate) {
            return response()->json(['success' => false, 'message' => 'Aucun taux actif trouvé pour cette paire.'], 404);
        }
        return response()->json(['success' => true, 'data' => $rate]);
    }

}