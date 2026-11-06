<?php

namespace App\Http\Controllers\Zone;

use App\Http\Controllers\Controller;
use App\Models\Zone;
use App\Models\Utilisateur;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ZoneController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Zone::with([
                'superviseur' => function ($q) {
                    $q->withTrashed()->select('id', 'nom_complet', 'nom_utilisateur', 'email', 'telephone');
                }
            ]);

            if ($request->filled('recherche')) {
                $query->recherche($request->recherche);
            }
            if ($request->filled('region')) {
                $query->where('region', $request->region);
            }
            if ($request->filled('ville')) {
                $query->where('ville', $request->ville);
            }
            if ($request->filled('superviseur_id')) {
                $query->where('superviseur_id', $request->superviseur_id);
            }
            if ($request->has('actif')) {
                $query->where('actif', $request->boolean('actif'));
            }

            $query->orderBy($request->get('order_by', 'created_at'), $request->get('order_dir', 'desc'));
            $zones = $query->paginate($request->get('per_page', 15));

            $stats = [
                'total'            => Zone::count(),
                'actives'          => Zone::where('actif', true)->count(),
                'inactives'        => Zone::where('actif', false)->count(),
                'avec_superviseur' => Zone::whereNotNull('superviseur_id')->count(),
                'par_region'       => Zone::select('region', DB::raw('count(*) as total'))
                                        ->whereNotNull('region')
                                        ->groupBy('region')
                                        ->orderByDesc('total')
                                        ->get(),
            ];

            return response()->json([
                'success' => true,
                'data'    => $zones,
                'stats'   => $stats,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $request->validate([
            'code'               => 'required|string|max:50|unique:zones,code',
            'nom'                => 'required|string|max:150',
            'region'             => 'nullable|string|max:100',
            'ville'              => 'nullable|string|max:100',
            'superviseur_id'     => 'nullable|exists:utilisateurs,id',
            'coordonnees_centre' => 'nullable|array',
            'rayon_km'           => 'nullable|numeric|min:0',
            'actif'              => 'boolean',
        ]);

        try {
            $zone = Zone::create([
                'code'               => $request->code,
                'nom'                => $request->nom,
                'region'             => $request->region,
                'ville'              => $request->ville,
                'superviseur_id'     => $request->superviseur_id,
                'coordonnees_centre' => $request->coordonnees_centre,
                'rayon_km'           => $request->rayon_km,
                'actif'              => $request->boolean('actif', true),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Zone créée avec succès',
                'data'    => $zone->load([
                    'superviseur' => function ($q) {
                        $q->withTrashed()->select('id', 'nom_complet', 'nom_utilisateur', 'email', 'telephone');
                    }
                ]),
            ], 201);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        $zone = Zone::with([
            'superviseur' => function ($q) {
                $q->withTrashed()->select('id', 'nom_complet', 'nom_utilisateur', 'email', 'telephone');
            },
            'agentsDsa.utilisateur', 
            'campagnes'
        ])->findOrFail($id);
        
        return response()->json(['success' => true, 'data' => $zone]);
    }

    public function update(Request $request, $id)
    {
        $zone = Zone::findOrFail($id);

        $request->validate([
            'code'               => ['sometimes', 'string', 'max:50', Rule::unique('zones', 'code')->ignore($zone->id)],
            'nom'                => 'sometimes|string|max:150',
            'region'             => 'nullable|string|max:100',
            'ville'              => 'nullable|string|max:100',
            'superviseur_id'     => 'nullable|exists:utilisateurs,id',
            'coordonnees_centre' => 'nullable|array',
            'rayon_km'           => 'nullable|numeric|min:0',
            'actif'              => 'boolean',
        ]);

        try {
            $zone->update($request->only([
                'code', 'nom', 'region', 'ville', 'superviseur_id',
                'coordonnees_centre', 'rayon_km', 'actif',
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Zone mise à jour',
                'data'    => $zone->fresh([
                    'superviseur' => function ($q) {
                        $q->withTrashed()->select('id', 'nom_complet', 'nom_utilisateur', 'email', 'telephone');
                    }
                ]),
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        $zone = Zone::findOrFail($id);

        if ($zone->campagnes()->exists()) {
            return response()->json(['success' => false, 'message' => 'Zone utilisée par des campagnes'], 400);
        }
        if ($zone->agentsDsa()->exists()) {
            return response()->json(['success' => false, 'message' => 'Agents DSA rattachés à cette zone'], 400);
        }

        $zone->delete();
        
        return response()->json(['success' => true, 'message' => 'Zone supprimée']);
    }

    public function changerStatut(Request $request, $id)
    {
        $request->validate(['actif' => 'required|boolean']);
        
        $zone = Zone::findOrFail($id);
        $zone->update(['actif' => $request->actif]);

        return response()->json(['success' => true, 'message' => 'Statut mis à jour', 'data' => $zone]);
    }

    public function options()
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'regions' => Zone::select('region')->whereNotNull('region')->distinct()->pluck('region'),
                'villes'  => Zone::select('ville')->whereNotNull('ville')->distinct()->pluck('ville'),
                'superviseurs' => Utilisateur::where('statut', 'ACTIF')
                    ->select('id', 'nom_complet', 'nom_utilisateur', 'email')
                    ->orderBy('nom_complet')
                    ->get(),
            ],
        ]);
    }
}