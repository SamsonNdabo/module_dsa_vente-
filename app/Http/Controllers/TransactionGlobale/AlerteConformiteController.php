<?php

namespace App\Http\Controllers\TransactionGlobale;

use App\Http\Controllers\Controller;
use App\Models\AlerteConformite;
use Illuminate\Http\Request;

class AlerteConformiteController extends Controller
{
    public function index(Request $request)
    {
        $query = AlerteConformite::with(['assigneA', 'resolueParUtilisateur']);

        if ($request->has('type_alerte')) {
            $query->where('type_alerte', $request->type_alerte);
        }

        if ($request->has('niveau_alerte')) {
            $query->where('niveau_alerte', $request->niveau_alerte);
        }

        if ($request->has('statut_alerte')) {
            $query->where('statut_alerte', $request->statut_alerte);
        }

        if ($request->has('entite_type')) {
            $query->where('entite_type', $request->entite_type);
        }

        if ($request->has('non_resolues')) {
            $query->nonResolue();
        }

        if ($request->has('en_retard')) {
            $query->enRetard();
        }

        if ($request->has('critique')) {
            $query->critique();
        }

        $alertes = $query->orderBy('date_creation', 'desc')->paginate(20);

        return response()->json([
            'success' => true,
            'message' => 'Liste des alertes de conformité',
            'data'    => $alertes,
        ], 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'type_alerte'           => 'required|in:SEUIL_DEPASSE,DOCUMENT_MANQUANT,COMPORTEMENT_SUSPECT,SANCTION,AUTRE',
            'niveau_alerte'         => 'required|in:FAIBLE,MOYEN,ELEVE,CRITIQUE',
            'entite_type'           => 'required|in:CLIENT,TRANSACTION,UTILISATEUR',
            'entite_id'             => 'required|integer',
            'description'           => 'required|string',
            'donnees_contexte_json' => 'nullable|array',
            'action_requise'        => 'nullable|string',
            'assignee_a'            => 'nullable|integer|exists:utilisateur,id',
            'date_echeance'         => 'nullable|date',
        ]);

        $alerte = AlerteConformite::create([
            'type_alerte'           => $validated['type_alerte'],
            'niveau_alerte'         => $validated['niveau_alerte'],
            'entite_type'           => $validated['entite_type'],
            'entite_id'             => $validated['entite_id'],
            'description'           => $validated['description'],
            'donnees_contexte_json' => $validated['donnees_contexte_json'] ?? null,
            'action_requise'        => $validated['action_requise'] ?? null,
            'statut_alerte'         => 'NOUVELLE',
            'assignee_a'            => $validated['assignee_a'] ?? null,
            'date_echeance'         => $validated['date_echeance'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Alerte de conformité créée avec succès',
            'data'    => $alerte->load(['assigneA']),
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

        $alerte = AlerteConformite::with([
            'assigneA',
            'resolueParUtilisateur',
        ])->find($id);

        if (!$alerte) {
            return response()->json([
                'success' => false,
                'message' => 'Alerte introuvable',
                'data'    => null
            ], 404);
        }

        // Indiquer si l'alerte est en retard
        $alerte->en_retard = $alerte->estEnRetard();

        return response()->json([
            'success' => true,
            'message' => 'Détails de l\'alerte',
            'data'    => $alerte,
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

        $alerte = AlerteConformite::find($id);

        if (!$alerte) {
            return response()->json([
                'success' => false,
                'message' => 'Alerte introuvable',
                'data'    => null
            ], 404);
        }

        // Bloquer si déjà résolue ou ignorée
        if (\in_array($alerte->statut_alerte, ['RESOLUE', 'IGNOREE'])) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de modifier une alerte résolue ou ignorée',
                'data'    => null
            ], 422);
        }

        $validated = $request->validate([
            'action_requise'        => 'sometimes|string',
            'donnees_contexte_json' => 'nullable|array',
            'date_echeance'         => 'nullable|date',
        ]);

        $alerte->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Alerte mise à jour avec succès',
            'data'    => \array_intersect_key(
                $alerte->fresh()->toArray(),
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

        $alerte = AlerteConformite::find($id);

        if (!$alerte) {
            return response()->json([
                'success' => false,
                'message' => 'Alerte introuvable',
                'data'    => null
            ], 404);
        }

        // Bloquer la suppression si non résolue
        if (!\in_array($alerte->statut_alerte, ['RESOLUE', 'IGNOREE'])) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer une alerte non résolue',
                'data'    => null
            ], 422);
        }

        $alerte->delete();

        return response()->json([
            'success' => true,
            'message' => 'Alerte supprimée avec succès',
            'data'    => [
                'id'          => $alerte->id,
                'type_alerte' => $alerte->type_alerte,
                'entite_type' => $alerte->entite_type,
                'entite_id'   => $alerte->entite_id,
            ]
        ], 200);
    }

    // ============================================================
    // Assigner une alerte à un utilisateur
    // ============================================================
    public function assigner(Request $request, $id)
    {
        if (!ctype_digit(\strval($id))) {
            return response()->json([
                'success' => false,
                'message' => 'ID invalide',
                'data'    => null
            ], 400);
        }

        $alerte = AlerteConformite::find($id);

        if (!$alerte) {
            return response()->json([
                'success' => false,
                'message' => 'Alerte introuvable',
                'data'    => null
            ], 404);
        }

        if (\in_array($alerte->statut_alerte, ['RESOLUE', 'IGNOREE'])) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible d\'assigner une alerte résolue ou ignorée',
                'data'    => null
            ], 422);
        }

        $validated = $request->validate([
            'utilisateur_id' => 'required|integer|exists:utilisateur,id',
        ]);

        $alerte->assigner($validated['utilisateur_id']);

        return response()->json([
            'success' => true,
            'message' => 'Alerte assignée avec succès',
            'data'    => $alerte->fresh()->load(['assigneA']),
        ], 200);
    }

    // ============================================================
    // Résoudre une alerte
    // ============================================================
    public function resoudre(Request $request, $id)
    {
        if (!ctype_digit(\strval($id))) {
            return response()->json([
                'success' => false,
                'message' => 'ID invalide',
                'data'    => null
            ], 400);
        }

        $alerte = AlerteConformite::find($id);

        if (!$alerte) {
            return response()->json([
                'success' => false,
                'message' => 'Alerte introuvable',
                'data'    => null
            ], 404);
        }

        if (\in_array($alerte->statut_alerte, ['RESOLUE', 'IGNOREE'])) {
            return response()->json([
                'success' => false,
                'message' => 'Cette alerte est déjà résolue ou ignorée',
                'data'    => null
            ], 422);
        }

        $validated = $request->validate([
            'utilisateur_id'   => 'required|integer|exists:utilisateur,id',
            'notes_resolution' => 'required|string',
        ]);

        $alerte->resoudre(
            $validated['utilisateur_id'],
            $validated['notes_resolution']
        );

        return response()->json([
            'success' => true,
            'message' => 'Alerte résolue avec succès',
            'data'    => $alerte->fresh()->load(['resolueParUtilisateur']),
        ], 200);
    }

    // ============================================================
    // Ignorer une alerte
    // ============================================================
    public function ignorer(Request $request, $id)
    {
        if (!ctype_digit(\strval($id))) {
            return response()->json([
                'success' => false,
                'message' => 'ID invalide',
                'data'    => null
            ], 400);
        }

        $alerte = AlerteConformite::find($id);

        if (!$alerte) {
            return response()->json([
                'success' => false,
                'message' => 'Alerte introuvable',
                'data'    => null
            ], 404);
        }

        if (\in_array($alerte->statut_alerte, ['RESOLUE', 'IGNOREE'])) {
            return response()->json([
                'success' => false,
                'message' => 'Cette alerte est déjà résolue ou ignorée',
                'data'    => null
            ], 422);
        }

        $validated = $request->validate([
            'utilisateur_id'   => 'required|integer|exists:utilisateur,id',
            'notes_resolution' => 'required|string',
        ]);

        $alerte->ignorer(
            $validated['utilisateur_id'],
            $validated['notes_resolution']
        );

        return response()->json([
            'success' => true,
            'message' => 'Alerte ignorée',
            'data'    => $alerte->fresh()->load(['resolueParUtilisateur']),
        ], 200);
    }

    // ============================================================
    // Statistiques des alertes
    // ============================================================
    public function statistiques()
    {
        $alertes = AlerteConformite::all();

        return response()->json([
            'success' => true,
            'message' => 'Statistiques des alertes',
            'data'    => [
                'total'          => $alertes->count(),
                'par_statut'     => $alertes->groupBy('statut_alerte')->map->count(),
                'par_niveau'     => $alertes->groupBy('niveau_alerte')->map->count(),
                'par_type'       => $alertes->groupBy('type_alerte')->map->count(),
                'non_resolues'   => $alertes->whereNotIn('statut_alerte', ['RESOLUE', 'IGNOREE'])->count(),
                'critiques'      => $alertes->where('niveau_alerte', 'CRITIQUE')->count(),
                'en_retard'      => $alertes->filter(fn($a) => $a->estEnRetard())->count(),
            ],
        ], 200);
    }
}