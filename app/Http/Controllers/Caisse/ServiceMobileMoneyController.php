<?php

namespace App\Http\Controllers\Caisse;

use App\Http\Controllers\Controller;
use App\Models\ServiceMobileMoney;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ServiceMobileMoneyController extends Controller
{
    public function index(Request $request)
    {
        $query = ServiceMobileMoney::orderBy('nom');
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('actif')) {
            $query->where('actif', $request->boolean('actif'));
        }

        $services = $query->get();

        return response()->json([
            'success' => true,
            'message' => 'Liste des services.',
            'data'    => $services,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nom'         => 'required|string|max:100|unique:service_mobile_money,nom',
            'description' => 'nullable|string',
            'type'        => 'required|string|in:MOBILE_MONEY,BANCAIRE',
            'actif'       => 'boolean',
        ]);

        // Génération automatique du code
        $prefix = $validated['type'] === 'MOBILE_MONEY' ? 'SRVC-MM-' : 'SRVC-BQ-';
        $code = $prefix . Str::upper(Str::slug($request->nom, '')) . '-' . Str::upper(Str::random(5));

        $service = ServiceMobileMoney::create([
            'nom'         => $validated['nom'],
            'code'        => $code,
            'description' => $validated['description'] ?? null,
            'actif'       => $validated['actif'] ?? true,
            'type'        => $validated['type'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Service créé avec succès.',
            'data'    => $service,
        ], 201);
    }

    public function show($id)
    {
        if (!ctype_digit(\strval($id))) {
            return response()->json(['success' => false, 'message' => 'ID invalide', 'data' => null], 400);
        }

        $service = ServiceMobileMoney::find($id);
        if (!$service) {
            return response()->json(['success' => false, 'message' => 'Service introuvable', 'data' => null], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Détails du service.',
            'data'    => $service,
        ]);
    }

    public function update(Request $request, $id)
    {
        if (!ctype_digit(\strval($id))) {
            return response()->json(['success' => false, 'message' => 'ID invalide', 'data' => null], 400);
        }

        $service = ServiceMobileMoney::find($id);
        if (!$service) {
            return response()->json(['success' => false, 'message' => 'Service introuvable', 'data' => null], 404);
        }

        $validated = $request->validate([
            'nom'         => 'sometimes|string|max:100|unique:service_mobile_money,nom,' . $service->id,
            'description' => 'nullable|string',
            'type'        => 'sometimes|string|in:MOBILE_MONEY,BANCAIRE',
            'actif'       => 'boolean',
        ]);

        $service->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Service mis à jour.',
            'data'    => $service->fresh(),
        ]);
    }

    public function destroy($id)
    {
        if (!ctype_digit(\strval($id))) {
            return response()->json(['success' => false, 'message' => 'ID invalide', 'data' => null], 400);
        }

        $service = ServiceMobileMoney::find($id);
        if (!$service) {
            return response()->json(['success' => false, 'message' => 'Service introuvable', 'data' => null], 404);
        }

        if ($service->caisses()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer ce service car il est utilisé par des caisses.',
                'data'    => null,
            ], 422);
        }

        $service->delete();

        return response()->json([
            'success' => true,
            'message' => 'Service supprimé avec succès.',
            'data'    => ['id' => $id],
        ]);
    }
}