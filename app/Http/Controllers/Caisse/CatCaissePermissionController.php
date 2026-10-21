<?php

namespace App\Http\Controllers\Caisse;

use App\Http\Controllers\Controller;
use App\Models\CatCaissePermission;
use Illuminate\Http\Request;

class CatCaissePermissionController extends Controller
{

    public function index(Request $request)
    {
        $query = CatCaissePermission::orderBy('libelle');

        if ($request->has('actif')) {
            $query->where('actif', $request->boolean('actif'));
        }

        $permissions = $query->get();

        return response()->json([
            'success' => true,
            'message' => 'Liste des permissions de caisse.',
            'data'    => $permissions,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code'        => 'required|string|max:50|unique:cat_caisse_permissions,code',
            'libelle'     => 'required|string|max:100',
            'description' => 'nullable|string',
            'actif'       => 'boolean',
        ]);

        $permission = CatCaissePermission::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Permission ajoutée au catalogue.',
            'data'    => $permission,
        ], 201);
    }


    public function show($id)
    {
        if (!ctype_digit(\strval($id))) {
            return response()->json(['success' => false, 'message' => 'ID invalide', 'data' => null], 400);
        }

        $permission = CatCaissePermission::find($id);
        if (!$permission) {
            return response()->json(['success' => false, 'message' => 'Permission introuvable', 'data' => null], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Détails de la permission.',
            'data'    => $permission,
        ]);
    }

    public function update(Request $request, $id)
    {
        if (!ctype_digit(\strval($id))) {
            return response()->json(['success' => false, 'message' => 'ID invalide', 'data' => null], 400);
        }

        $permission = CatCaissePermission::find($id);
        if (!$permission) {
            return response()->json(['success' => false, 'message' => 'Permission introuvable', 'data' => null], 404);
        }

        $validated = $request->validate([
            'code'        => 'sometimes|string|max:50|unique:cat_caisse_permissions,code,' . $permission->id,
            'libelle'     => 'sometimes|string|max:100',
            'description' => 'nullable|string',
            'actif'       => 'boolean',
        ]);

        $permission->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Permission mise à jour.',
            'data'    => $permission->fresh(),
        ]);
    }

    public function destroy($id)
    {
        if (!ctype_digit(\strval($id))) {
            return response()->json(['success' => false, 'message' => 'ID invalide', 'data' => null], 400);
        }

        $permission = CatCaissePermission::find($id);
        if (!$permission) {
            return response()->json(['success' => false, 'message' => 'Permission introuvable', 'data' => null], 404);
        }

        // On pourrait vérifier ici si la permission est utilisée dans la table caisse_permissions (si relation)
        // Mais comme vous ne souhaitez pas de lien, on supprime directement.

        $permission->delete();

        return response()->json([
            'success' => true,
            'message' => 'Permission supprimée du catalogue.',
            'data'    => ['id' => $id],
        ]);
    }
}