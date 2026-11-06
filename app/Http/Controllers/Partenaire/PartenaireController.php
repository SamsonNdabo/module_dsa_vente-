<?php

namespace App\Http\Controllers\Partenaire;

use App\Http\Controllers\Controller;
use App\Models\Partenaire\Partenaire;
use App\Services\Partenaire\PartenaireService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class PartenaireController extends Controller
{
    public function __construct(private PartenaireService $service) {}

    /**
     * Liste des partenaires (Web Admin).
     */
    public function index(Request $request)
    {
        $filtres = $request->only(['statut', 'type_partenaire', 'search']);
        $partenaires = $this->service->filtrerPartenaires($filtres)->paginate(20);

        if ($request->expectsJson()) {
            return response()->json($partenaires);
        }

        return view('partenaires.index', compact('partenaires', 'filtres'));
    }

    /**
     * Formulaire de création.
     */
    public function create()
    {
        return view('partenaires.create');
    }

    /**
     * Enregistrement d'un nouveau partenaire.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'code'            => 'required|string|max:30|unique:partenaires,code',
            'nom'             => 'required|string|max:150',
            'type_partenaire' => 'required|in:BANQUE,IMF,MOBILE_MONEY,ASSURANCE,COMMERCIAL,AUTRE',
            'email'           => 'required|email|max:150',
            'telephone'       => 'nullable|string|max:20',
        ]);

        try {
            $partenaire = $this->service->creerPartenaire($data, Auth::user());

            if ($request->expectsJson()) {
                return response()->json([
                    'message'    => 'Partenaire créé avec succès.',
                    'partenaire' => $partenaire,
                ], 201);
            }

            return redirect()->route('partenaires.index')
                ->with('success', 'Partenaire créé avec succès.');
        } catch (\Exception $e) {
            if ($request->expectsJson()) {
                return response()->json(['error' => $e->getMessage()], 422);
            }
            return back()->withErrors(['error' => $e->getMessage()])->withInput();
        }
    }

    /**
     * Affichage d'un partenaire.
     */
    public function show(Request $request, Partenaire $partenaire)
    {
        // 🔒 Sécurité : un partenaire ne peut voir que ses propres données
        if (!$this->service->peutAcceder(Auth::user(), $partenaire->id)) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Accès refusé.'], 403);
            }
            abort(403);
        }

        $dashboard = $this->service->dashboard($partenaire->id);

        if ($request->expectsJson()) {
            return response()->json($dashboard);
        }

        return view('partenaires.show', compact('partenaire', 'dashboard'));
    }

    /**
     * Formulaire de modification.
     */
    public function edit(Partenaire $partenaire)
    {
        return view('partenaires.edit', compact('partenaire'));
    }

    /**
     * Mise à jour d'un partenaire.
     */
    public function update(Request $request, Partenaire $partenaire)
    {
        $data = $request->validate([
            'nom'             => 'sometimes|string|max:150',
            'type_partenaire' => 'sometimes|in:BANQUE,IMF,MOBILE_MONEY,ASSURANCE,COMMERCIAL,AUTRE',
            'email'           => 'sometimes|email|max:150',
            'telephone'       => 'nullable|string|max:20',
        ]);

        try {
            $partenaire = $this->service->mettreAJourPartenaire($partenaire, $data);

            if ($request->expectsJson()) {
                return response()->json([
                    'message'    => 'Partenaire mis à jour.',
                    'partenaire' => $partenaire,
                ]);
            }

            return redirect()->route('partenaires.show', $partenaire)
                ->with('success', 'Partenaire mis à jour.');
        } catch (\Exception $e) {
            if ($request->expectsJson()) {
                return response()->json(['error' => $e->getMessage()], 422);
            }
            return back()->withErrors(['error' => $e->getMessage()])->withInput();
        }
    }

    /**
     * Changement de statut (Actif / Inactif / Suspendu).
     */
    public function changerStatut(Request $request, Partenaire $partenaire)
    {
        $request->validate([
            'statut' => 'required|in:ACTIF,INACTIF,SUSPENDU',
        ]);

        try {
            $partenaire = $this->service->changerStatut($partenaire, $request->statut);

            if ($request->expectsJson()) {
                return response()->json([
                    'message'    => "Statut changé en {$partenaire->statut}.",
                    'partenaire' => $partenaire,
                ]);
            }

            return back()->with('success', "Statut changé en {$partenaire->statut}.");
        } catch (\Exception $e) {
            if ($request->expectsJson()) {
                return response()->json(['error' => $e->getMessage()], 422);
            }
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    /**
     * Dashboard du partenaire (Web ou API Mobile).
     */
    public function dashboard(Request $request, Partenaire $partenaire)
    {
        if (!$this->service->peutAcceder(Auth::user(), $partenaire->id)) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Accès refusé.'], 403);
            }
            abort(403);
        }

        $stats = $this->service->dashboard($partenaire->id);

        if ($request->expectsJson()) {
            return response()->json($stats);
        }

        return view('partenaires.dashboard', compact('partenaire', 'stats'));
    }

    /**
     * Suivi des produits du partenaire.
     */
    public function suiviProduits(Request $request, Partenaire $partenaire)
    {
        if (!$this->service->peutAcceder(Auth::user(), $partenaire->id)) {
            return response()->json(['error' => 'Accès refusé.'], 403);
        }

        $produits = $this->service->suiviProduits($partenaire->id);

        if ($request->expectsJson()) {
            return response()->json($produits);
        }

        return view('partenaires.produits', compact('partenaire', 'produits'));
    }

    /**
     * Suivi des agents.
     */
    public function suiviAgents(Request $request, Partenaire $partenaire)
    {
        if (!$this->service->peutAcceder(Auth::user(), $partenaire->id)) {
            return response()->json(['error' => 'Accès refusé.'], 403);
        }

        $agents = $this->service->suiviAgents($partenaire->id);

        if ($request->expectsJson()) {
            return response()->json($agents);
        }

        return view('partenaires.agents', compact('partenaire', 'agents'));
    }

    /**
     * Export d'un rapport.
     */
    public function export(Request $request, Partenaire $partenaire)
    {
        if (!$this->service->peutAcceder(Auth::user(), $partenaire->id)) {
            return response()->json(['error' => 'Accès refusé.'], 403);
        }

        $request->validate([
            'date_debut' => 'nullable|date',
            'date_fin'   => 'nullable|date|after_or_equal:date_debut',
            'format'     => 'nullable|in:csv,pdf',
        ]);

        $rapport = $this->service->genererRapport(
            $partenaire->id,
            $request->date_debut,
            $request->date_fin
        );

        // Ici on retourne le rapport JSON. Pour un vrai export :
        // return Excel::download(new RapportExport($rapport), 'rapport.xlsx');
        return response()->json($rapport);
    }

    /**
     * Liste des rapprochements financiers.
     */
    public function rapprochements(Request $request, Partenaire $partenaire)
    {
        if (!$this->service->peutAcceder(Auth::user(), $partenaire->id)) {
            return response()->json(['error' => 'Accès refusé.'], 403);
        }

        $enEcart = $this->service->rapprochementsEnEcart($partenaire->id);

        if ($request->expectsJson()) {
            return response()->json($enEcart);
        }

        return view('partenaires.rapprochements', compact('partenaire', 'enEcart'));
    }

    /**
     * Créer un rapprochement.
     */
    public function creerRapprochement(Request $request, Partenaire $partenaire)
    {
        if (!$this->service->peutAcceder(Auth::user(), $partenaire->id)) {
            return response()->json(['error' => 'Accès refusé.'], 403);
        }

        $data = $request->validate([
            'periode_debut'    => 'required|date',
            'periode_fin'      => 'required|date|after_or_equal:periode_debut',
            'montant_confirme' => 'required|numeric|min:0',
            'commentaire'      => 'nullable|string',
        ]);

        try {
            $rapprochement = $this->service->creerRapprochement(
                $partenaire->id,
                $data['periode_debut'],
                $data['periode_fin'],
                (float) $data['montant_confirme'],
                $data['commentaire'] ?? null
            );

            if ($request->expectsJson()) {
                return response()->json([
                    'message'       => 'Rapprochement enregistré.',
                    'rapprochement' => $rapprochement,
                ], 201);
            }

            return back()->with('success', 'Rapprochement enregistré.');
        } catch (\Exception $e) {
            if ($request->expectsJson()) {
                return response()->json(['error' => $e->getMessage()], 422);
            }
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }
}