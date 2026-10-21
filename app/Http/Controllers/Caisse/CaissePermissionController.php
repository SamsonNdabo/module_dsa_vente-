<?php

namespace App\Http\Controllers\Caisse;

use App\Http\Controllers\Controller;
use App\Models\Caisses;
use App\Models\CaissePermission;
use Illuminate\Http\Request;

class CaissePermissionController extends Controller
{
            public function index(Request $request)
    {
        $user = auth()->user();
    
        $query = Caisses::with([
            'succursale',
            'devise',
            'soldes.devise',
            'permissions'
        ]);
    
    
        if ($user->isSuperAdmin() || $user->isChefOperation()) {
            // Accès complet
        } elseif ($user->isChefAgence()) {
            $query->where('succursale_id', $user->succursale_id);
        }
    
        // Filtres existants
        if ($request->has('statut')) {
            $query->where('statut', $request->statut);
        }
    
        /*
        ============================================================
        FILTRE SUCCURSALE
        ============================================================
        Seuls le SUPER_ADMIN et le CHEF_OPERATIONS
        peuvent filtrer une succursale.
        ============================================================
        */
        if (
            $request->has('succursale_id') &&
            ($user->isSuperAdmin() || $user->isChefOperation())
        ) {
            $query->where('succursale_id', $request->succursale_id);
        }
    
        if ($request->has('type_caisse')) {
            $query->where('type_caisse', $request->type_caisse);
        }
    
        if ($request->has('inactif')) {
            $query->where('inactif', $request->inactif);
        }
    
        /*
        ============================================================
        FILTRE SUR LES PERMISSIONS
        ============================================================
        */
        if ($request->has('permet_action')) {
            $action = $request->permet_action;
    
            $query->where(function ($q) use ($action) {
                $q->where('categorie', '!=', 'PRINCIPALE')
                  ->orWhereHas('permissions', function ($sub) use ($action) {
                      $sub->whereJsonContains('actions', $action);
                  });
            });
        }
    
        $caisses = $query->get();
    
        // Ajout des permissions calculées
        $caisses->transform(function ($caisse) {
            $principale = $caisse->caissePourPermissions();
    
            $caisse->setAttribute(
                'permissions_actions',
                $principale && $principale->permissions
                    ? $principale->permissions->actions
                    : []
            );
    
            return $caisse;
        });
    
        return response()->json([
            'success' => true,
            'message' => 'Liste des caisses',
            'data'    => $caisses,
        ], 200);
    }
    public function store(Request $request, $id)
    {
        $caisse = Caisses::findOrFail($id);
        if (!$caisse->isPrincipale()) {
            return response()->json(['success' => false, 'message' => 'Seules les caisses principales peuvent recevoir des permissions.'], 422);
        }

        $validated = $request->validate([
            'actions'   => 'required|array|min:1',
            'actions.*' => 'string|in:CHANGE,VENTE,OUVRIR,FERMER,TRANSFERER,APPROVISIONNER,BLOQUER,DEBLOQUER,DESACTIVER,REACTIVER,MODIFIER',
        ]);

        // Une seule ligne par caisse
        $perm = CaissePermission::updateOrCreate(
            ['caisse_id' => $caisse->id],
            ['actions' => $validated['actions']]
        );

        return response()->json([
            'success' => true,
            'message' => 'Permissions enregistrées.',
            'data'    => $perm->actions,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $caisse = Caisses::findOrFail($id);
        if (!$caisse->isPrincipale()) {
            return response()->json(['success' => false, 'message' => 'Seules les caisses principales ont des permissions.'], 422);
        }

        $validated = $request->validate([
            'actions'   => 'required|array',
            'actions.*' => 'string|in:CHANGE,VENTE,OUVRIR,FERMER,TRANSFERER,APPROVISIONNER,BLOQUER,DEBLOQUER,DESACTIVER,REACTIVER,MODIFIER',
        ]);

        $perm = CaissePermission::updateOrCreate(
            ['caisse_id' => $caisse->id],
            ['actions' => $validated['actions']]
        );

        return response()->json([
            'success' => true,
            'message' => 'Permissions mises à jour.',
            'data'    => $perm->actions,
        ]);
    }

    public function show($id)
    {
        $caisse = Caisses::findOrFail($id);
        if (!$caisse->isPrincipale()) {
            return response()->json(['success' => false, 'message' => 'Seules les caisses principales ont des permissions.'], 422);
        }

        $perm = CaissePermission::where('caisse_id', $caisse->id)->first();
        $actions = $perm ? $perm->actions : [];

        return response()->json([
            'success'    => true,
            'data'       => $actions,
            'caisse_nom' => $caisse->nom_caisse,
        ]);
    }

}