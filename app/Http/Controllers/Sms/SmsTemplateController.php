<?php

namespace App\Http\Controllers\Sms;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SmsTemplateController extends Controller
{
    /**
     * Affiche la liste paginée des templates SMS.
     */
    public function index()
    {
        $templates = DB::table('sms_templates')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($templates, 200);
    }

    /**
     * Enregistre un nouveau template SMS.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'contexte' => 'required|string|max:50|unique:sms_templates,contexte',
            'nom'      => 'required|string|max:100',
            'template' => 'required|string',
            'actif'    => 'boolean',
        ]);

        // Valeur par défaut pour 'actif' si non fourni (true selon la table)
        $validated['actif'] = $request->has('actif') ? $request->boolean('actif') : true;

        $id = DB::table('sms_templates')->insertGetId([
            'contexte'   => $validated['contexte'],
            'nom'        => $validated['nom'],
            'template'   => $validated['template'],
            'actif'      => $validated['actif'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $template = DB::table('sms_templates')->find($id);

        return response()->json([
            'message' => 'Template SMS créé avec succès.',
            'data'    => $template,
        ], 201);
    }

    /**
     * Affiche un template spécifique.
     */
    public function show($id)
    {
        if (!ctype_digit($id)) {
            return response()->json([
                'message' => 'ID invalide'
            ], 400);
        }

        $template = DB::table('sms_templates')->find($id);

        if (!$template) {
            return response()->json([
                'message' => 'Template introuvable'
            ], 404);
        }

        return response()->json($template, 200);
    }

    /**
     * Met à jour un template existant.
     */
    public function update(Request $request, $id)
    {
        if (!ctype_digit($id)) {
            return response()->json([
                'message' => 'ID invalide'
            ], 400);
        }

        $template = DB::table('sms_templates')->find($id);

        if (!$template) {
            return response()->json([
                'message' => 'Template introuvable'
            ], 404);
        }

        $validated = $request->validate([
            'contexte' => [
                'sometimes',
                'string',
                'max:50',
                Rule::unique('sms_templates', 'contexte')->ignore($id),
            ],
            'nom'      => 'sometimes|string|max:100',
            'template' => 'sometimes|string',
            'actif'    => 'sometimes|boolean',
        ]);

        if ($request->has('actif')) {
            $validated['actif'] = $request->boolean('actif');
        }

        DB::table('sms_templates')
            ->where('id', $id)
            ->update([
                ...$validated,
                'updated_at' => now(),
            ]);

        $updatedTemplate = DB::table('sms_templates')->find($id);

        return response()->json([
            'message' => 'Template mis à jour avec succès.',
            'data'    => $updatedTemplate,
        ], 200);
    }

    /**
     * Supprime un template.
     */
    public function destroy($id)
    {
        if (!ctype_digit($id)) {
            return response()->json([
                'message' => 'ID invalide'
            ], 400);
        }

        $template = DB::table('sms_templates')->find($id);

        if (!$template) {
            return response()->json([
                'message' => 'Template introuvable'
            ], 404);
        }

        DB::table('sms_templates')->where('id', $id)->delete();

        return response()->json([
            'message' => 'Template supprimé avec succès.',
            'data'    => ['id' => (int) $id],
        ], 200);
    }
}