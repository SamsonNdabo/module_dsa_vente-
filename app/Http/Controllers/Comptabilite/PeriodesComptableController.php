<?php

namespace App\Http\Controllers\Comptabilite;

use App\Models\PeriodesComptable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Controller;

class PeriodesComptableController extends Controller
{
    // Afficher la liste des périodes
    public function index()
    {
        $periodes = PeriodesComptable::orderBy('date_debut', 'desc')->get();
        return response()->json(['success' => true, 'periodes' => $periodes]);
    }

    // Créer une période
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|max:50|unique:periodes_comptables,code',
            'libelle' => 'required|string|max:255',
            'date_debut' => 'required|date',
            'date_fin' => 'required|date|after_or_equal:date_debut',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        $periode = PeriodesComptable::create($request->only(['code', 'libelle', 'date_debut', 'date_fin']));

        return response()->json([
            'success' => true,
            'message' => ' Période créée avec succès !',
            'periode' => $periode
        ]);
    }

    // Mettre à jour une période
    public function update(Request $request, $id)
    {
        $periode = PeriodesComptable::findOrFail($id);

        if ($periode->is_cloture) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de modifier une période clôturée.'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'code' => 'sometimes|string|max:50|unique:periodes_comptables,code,' . $id,
            'libelle' => 'sometimes|string|max:255',
            'date_debut' => 'sometimes|date',
            'date_fin' => 'sometimes|date|after_or_equal:date_debut',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        $periode->update($request->only(['code', 'libelle', 'date_debut', 'date_fin']));

        return response()->json([
            'success' => true,
            'message' => ' Période mise à jour !',
            'periode' => $periode
        ]);
    }

    // Supprimer une période
    public function destroy($id)
    {
        $periode = PeriodesComptable::findOrFail($id);

        if ($periode->bilans()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer une période contenant des bilans.'
            ], 422);
        }

        $periode->delete();

        return response()->json([
            'success' => true,
            'message' => '🗑️ Période supprimée !'
        ]);
    }

    // Clôturer une période
    public function cloturer($id)
    {
        $periode = PeriodesComptable::findOrFail($id);

        if ($periode->is_cloture) {
            return response()->json([
                'success' => false,
                'message' => 'Cette période est déjà clôturée.'
            ], 422);
        }

        $periode->update([
            'is_cloture' => true,
            'date_cloture' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => '🔒 Période clôturée !'
        ]);
    }

    // Rouvrir une période
    public function rouvrir($id)
    {
        $periode = PeriodesComptable::findOrFail($id);

        if (!$periode->is_cloture) {
            return response()->json([
                'success' => false,
                'message' => 'Cette période est déjà ouverte.'
            ], 422);
        }

        $periode->update([
            'is_cloture' => false,
            'date_cloture' => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => '🔓 Période rouverte !'
        ]);
    }

    // Afficher une période
    public function show($id)
    {
        $periode = PeriodesComptable::with(['bilans', 'bilan_initial', 'bilan_fermeture'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'periode' => $periode
        ]);
    }
}