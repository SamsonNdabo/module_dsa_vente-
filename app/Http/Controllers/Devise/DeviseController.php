<?php

namespace App\Http\Controllers\Devise;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use App\Models\Devise;
use Illuminate\Http\Request;

class DeviseController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $devise = DB::table("devise")->paginate(20);
        return response()->json($devise, 200);
    }
    
    public function indexSansCdf()
    {
        $devise = DB::table("devise")->where('code', '!=', 'CDF')->get();
        return response()->json($devise, 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // Valider les données
        $validated = $request->validate([
            'code'             => 'required|string|max:15|unique:devise,code',
            'nom'              => 'required|string|max:50|unique:devise,nom',
            'symbole'          => 'nullable|string|max:5|unique:devise,symbole',
            'position_symbole' => 'nullable|in:AVANT,APRES',
            'statut'           => 'in:ACTIF,INACTIF',
        ]);

        // Insérer dans la base de données
        $id = DB::table('devise')->insertGetId([
            'code'             => strtoupper($validated['code']),
            'nom'              => ucfirst(trim($validated['nom'])),
            'symbole'          => $validated['symbole'] ?? null,
            'devise_defaut'    => 0,
            'position_symbole' => $validated['position_symbole'] ?? 'APRES',
            'statut'           => $validated['statut'] ?? 'ACTIF',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        // Récupérer la devise créée
        $devise = DB::table('devise')->find($id);

        return response()->json([
            'message' => 'Devise créée avec succès.',
            'data'    => $devise,
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        //
        $devise = DB::table('devise')->find($id);

        if (!$devise) {
            return response()->json([
                'message' => 'Devise introuvable'
            ], 404);
        }

        return response()->json($devise, 200);
    }

    /**
     * Update the specified resource in storage.
     */
public function update(Request $request, $id)
{
    if (!ctype_digit(\strval($id))) {
        return response()->json([
            'success' => false,
            'message' => 'ID invalide',
            'data'    => null
        ], 400);
    }

    $devise = DB::table('devise')->find($id);

    if (!$devise) {
        return response()->json([
            'success' => false,
            'message' => 'Devise introuvable',
            'data'    => null
        ], 404);
    }

    $validated = $request->validate([
        'code'             => 'sometimes|string|max:15|unique:devise,code,' . $id,
        'nom'              => 'sometimes|string|max:50|unique:devise,nom,' . $id,
        'symbole'          => 'nullable|string|max:5|unique:devise,symbole,' . $id,
        'position_symbole' => 'nullable|in:AVANT,APRES',
        'statut'           => 'nullable|in:ACTIF,INACTIF',
    ]);

    // Formater les champs si envoyés
    if (isset($validated['code'])) {
        $validated['code'] = strtoupper($validated['code']);
    }

    if (isset($validated['nom'])) {
        $validated['nom'] = ucfirst(trim($validated['nom']));
    }

    DB::table('devise')
        ->where('id', $id)
        ->update([
            ...$validated,
            'updated_at' => now(),
        ]);

    $deviseUpdated = DB::table('devise')->find($id);

    return response()->json([
        'success' => true,
        'message' => 'Devise mise à jour avec succès',
        'data'    => array_intersect_key((array) $deviseUpdated, $validated),
    ], 200);
}

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        if (!ctype_digit(\strval($id))) {
            return response()->json([
                'success' => false,
                'message' => 'ID invalide',
                'data'    => null
            ], 400);
        }

        $devise = DB::table('devise')->find($id);

        if (!$devise) {
            return response()->json([
                'success' => false,
                'message' => 'Devise introuvable',
                'data'    => null
            ], 404);
        }

        // Vérifier si la devise est la devise par défaut
        if ($devise->devise_defaut == 1) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer la devise par défaut',
                'data'    => null
            ], 422);
        }

        // Vérifier si la devise est utilisée dans taux_change
        $utiliseeDansTaux = DB::table('taux_change')
            ->where('devise_source', $id)
            ->orWhere('devise_cible', $id)
            ->exists();

        if ($utiliseeDansTaux) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer cette devise car elle est utilisée dans des taux de change',
                'data'    => null
            ], 422);
        }

        DB::table('devise')->where('id', $id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Devise supprimée avec succès',
            'data'    => [
                'id'   => $devise->id,
                'code' => $devise->code,
                'nom'  => $devise->nom,
            ]
        ], 200);
    }
}
