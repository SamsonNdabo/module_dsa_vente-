<?php
// app/Http/Controllers/Fournisseur/FournisseurController.php

namespace App\Http\Controllers\Fournisseur;

use App\Http\Controllers\Controller;
use App\Models\Fournisseur;
use App\Models\CommandeFournisseur;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FournisseurController extends Controller
{
    // ✅ Constantes
    private const ROLES_FULL_ACCESS = ['SUPER_ADMIN', 'AUDITEUR', 'CHEF_OPERATIONS'];
    private const STATUTS_VALIDES = ['ACTIF', 'INACTIF', 'SUSPENDU'];

    /**
     * Display a listing of the resource - OPTIMISÉ
     * Permission: view-fournisseurs
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-fournisseurs')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour voir les fournisseurs'
            ], 403);
        }

        // ✅ Sélection des colonnes nécessaires
        $query = Fournisseur::select([
            'id', 'nom', 'email', 'telephone', 'adresse',
            'ville', 'produits', 'code_fournisseur', 'statut',
            'created_at', 'updated_at'
        ]);

        // ✅ Filtres
        if ($request->filled('statut')) {
            $query->where('statut', $request->statut);
        }

        if ($request->filled('ville')) {
            $query->whereJsonContains('ville', $request->ville);
        }

        if ($request->filled('produit')) {
            $query->whereJsonContains('produits', $request->produit);
        }

        if ($request->filled('recherche')) {
            $search = $request->recherche;
            $query->where(function ($q) use ($search) {
                $q->where('nom', 'like', "%{$search}%")
                    ->orWhere('code_fournisseur', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('telephone', 'like', "%{$search}%")
                    ->orWhere('adresse', 'like', "%{$search}%")
                    ->orWhereJsonContains('ville', $search);
            });
        }

        // ✅ Tri
        $orderBy = $request->get('order_by', 'nom');
        $orderDir = $request->get('order_dir', 'asc');
        $query->orderBy($orderBy, $orderDir);

        // ✅ Pagination
        $perPage = $request->get('per_page', 20);
        $fournisseurs = $query->paginate($perPage);

        // ✅ Statistiques - UNE SEULE REQUÊTE
        $stats = $this->getFournisseurStats();

        return response()->json([
            'success' => true,
            'data' => $fournisseurs,
            'statistiques' => $stats
        ]);
    }

    /**
     * Store a newly created resource in storage.
     * Permission: create-fournisseur
     */
    public function store(Request $request)
    {
        $user = auth()->user();

        if (!$user->hasPermission('create-fournisseur')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour créer des fournisseurs'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'nom' => 'required|string|max:150',
            'email' => 'nullable|email|max:150|unique:fournisseur,email',
            'telephone' => 'nullable|string|max:50',
            'adresse' => 'nullable|string',
            'ville' => 'nullable|array',
            'ville.*' => 'string|max:100',
            'produits' => 'nullable|array',
            'produits.*' => 'string|max:100',
            'code_fournisseur' => 'nullable|string|max:50|unique:fournisseur,code_fournisseur',
            'statut' => 'nullable|in:' . implode(',', self::STATUTS_VALIDES),
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $fournisseur = Fournisseur::create($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Fournisseur créé avec succès',
                'data' => $fournisseur
            ], 201);

        } catch (\Exception $e) {
            Log::error('Erreur création fournisseur', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource - OPTIMISÉ
     * Permission: view-fournisseurs
     */
    public function show($id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-fournisseurs')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour voir les fournisseurs'
            ], 403);
        }

        $fournisseur = Fournisseur::select([
            'id', 'nom', 'email', 'telephone', 'adresse',
            'ville', 'produits', 'code_fournisseur', 'statut',
            'created_at', 'updated_at'
        ])->find($id);

        if (!$fournisseur) {
            return response()->json([
                'success' => false,
                'message' => 'Fournisseur introuvable'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $fournisseur
        ]);
    }

    /**
     * Update the specified resource in storage.
     * Permission: edit-fournisseur
     */
    public function update(Request $request, $id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('edit-fournisseur')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour modifier des fournisseurs'
            ], 403);
        }

        $fournisseur = Fournisseur::find($id);

        if (!$fournisseur) {
            return response()->json([
                'success' => false,
                'message' => 'Fournisseur introuvable'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'nom' => 'sometimes|string|max:150',
            'email' => 'nullable|email|max:150|unique:fournisseur,email,' . $id,
            'telephone' => 'nullable|string|max:50',
            'adresse' => 'nullable|string',
            'ville' => 'nullable|array',
            'ville.*' => 'string|max:100',
            'produits' => 'nullable|array',
            'produits.*' => 'string|max:100',
            'code_fournisseur' => 'nullable|string|max:50|unique:fournisseur,code_fournisseur,' . $id,
            'statut' => 'nullable|in:' . implode(',', self::STATUTS_VALIDES)
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $fournisseur->update($request->only([
            'nom', 'email', 'telephone', 'adresse',
            'ville', 'produits', 'code_fournisseur', 'statut'
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Fournisseur mis à jour avec succès',
            'data' => $fournisseur
        ]);
    }

    /**
     * Remove the specified resource from storage.
     * Permission: delete-fournisseur
     */
    public function destroy($id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('delete-fournisseur')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour supprimer des fournisseurs'
            ], 403);
        }

        $fournisseur = Fournisseur::find($id);

        if (!$fournisseur) {
            return response()->json([
                'success' => false,
                'message' => 'Fournisseur introuvable'
            ], 404);
        }

        $hasCommandes = CommandeFournisseur::where('fournisseur_id', $id)->exists();
        if ($hasCommandes) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer ce fournisseur car il est lié à des commandes'
            ], 422);
        }

        $fournisseur->delete();

        return response()->json([
            'success' => true,
            'message' => 'Fournisseur supprimé avec succès'
        ]);
    }

    /**
     * Suspendre ou réactiver un fournisseur
     * Permission: suspend-fournisseur
     */
    public function suspendre($id, Request $request)
    {
        $user = auth()->user();

        if (!$user->hasPermission('suspend-fournisseur')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour suspendre un fournisseur'
            ], 403);
        }

        $fournisseur = Fournisseur::find($id);

        if (!$fournisseur) {
            return response()->json([
                'success' => false,
                'message' => 'Fournisseur introuvable'
            ], 404);
        }

        $action = $request->get('action');

        if (!in_array($action, ['suspendre', 'reactiver'])) {
            return response()->json([
                'success' => false,
                'message' => 'Action invalide'
            ], 422);
        }

        if ($action === 'suspendre') {
            $commandesEnCours = CommandeFournisseur::where('fournisseur_id', $id)
                ->whereNotIn('statut', ['RECUE', 'ANNULEE'])
                ->exists();

            if ($commandesEnCours) {
                return response()->json([
                    'success' => false,
                    'message' => 'Impossible de suspendre ce fournisseur : des commandes sont en cours'
                ], 422);
            }

            $fournisseur->statut = 'SUSPENDU';
            $message = 'Fournisseur suspendu avec succès';
        } else {
            $fournisseur->statut = 'ACTIF';
            $message = 'Fournisseur réactivé avec succès';
        }

        $fournisseur->save();

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                'id' => $fournisseur->id,
                'statut' => $fournisseur->statut,
                'statut_label' => $fournisseur->statut_label
            ]
        ]);
    }

    /**
     * Obtenir la liste des types de produits possibles
     * Permission: view-fournisseurs
     */
    public function typesProduits()
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-fournisseurs')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour accéder à cette ressource'
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => [
                ['code' => 'ELECTRONIQUE', 'label' => 'Électronique'],
                ['code' => 'ACCESSOIRE', 'label' => 'Accessoires'],
                ['code' => 'ALIMENTAIRE', 'label' => 'Alimentaire'],
                ['code' => 'VEHICULE', 'label' => 'Véhicules'],
                ['code' => 'SERVICE', 'label' => 'Services'],
                ['code' => 'MATERIEL', 'label' => 'Matériel'],
                ['code' => 'AUTRE', 'label' => 'Autre']
            ]
        ]);
    }

    /**
     * Obtenir le top des fournisseurs (les plus commandés) - OPTIMISÉ
     * Permission: view-fournisseurs
     */
    public function topFournisseurs(Request $request)
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-fournisseurs')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour voir les statistiques des fournisseurs'
            ], 403);
        }

        $limit = $request->get('limit', 10);
        $criteria = $request->get('criteria', 'nb_commandes');

        $topFournisseurs = Fournisseur::getTopFournisseurs($limit, $criteria);

        // ✅ Statistiques globales - UNE SEULE REQUÊTE
        $stats = $this->getGlobalStats();

        return response()->json([
            'success' => true,
            'data' => [
                'top_fournisseurs' => $topFournisseurs,
                'statistiques_globales' => $stats,
                'criteres' => [
                    'utilise' => $criteria,
                    'disponibles' => ['nb_commandes', 'montant']
                ]
            ]
        ]);
    }

    /**
     * Obtenir les statistiques détaillées d'un fournisseur spécifique - OPTIMISÉ
     * Permission: view-fournisseurs
     */
    public function statistiques($id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-fournisseurs')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour voir les statistiques des fournisseurs'
            ], 403);
        }

        $fournisseur = Fournisseur::select([
            'id', 'nom', 'email', 'telephone', 'code_fournisseur',
            'statut', 'adresse', 'ville', 'produits'
        ])->find($id);

        if (!$fournisseur) {
            return response()->json([
                'success' => false,
                'message' => 'Fournisseur introuvable'
            ], 404);
        }

        $stats = $fournisseur->statistiques_commandes;

        // ✅ Détails optimisés
        $details = $this->getFournisseurDetails($id);

        return response()->json([
            'success' => true,
            'data' => [
                'fournisseur' => [
                    'id' => $fournisseur->id,
                    'nom' => $fournisseur->nom,
                    'code' => $fournisseur->code_fournisseur,
                    'email' => $fournisseur->email,
                    'telephone' => $fournisseur->telephone,
                    'statut' => $fournisseur->statut,
                    'statut_label' => $fournisseur->statut_label ?? ($fournisseur->statut === 'ACTIF' ? 'Actif' : 'Inactif'),
                    'adresse' => $fournisseur->adresse,
                    'ville' => $fournisseur->ville,
                    'produits' => $fournisseur->produits
                ],
                'statistiques' => $stats,
                'details' => $details
            ]
        ]);
    }

    /**
     * Exporter la liste des fournisseurs avec leurs statistiques (CSV)
     * Permission: view-fournisseurs + export-comptable
     */
    public function exportStatistiques(Request $request)
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-fournisseurs') || !$user->hasPermission('export-comptable')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour exporter les statistiques des fournisseurs'
            ], 403);
        }

        $fournisseurs = Fournisseur::withCount('commandes')->get();

        $export = $fournisseurs->map(function ($fournisseur) {
            $stats = $fournisseur->statistiques_commandes ?? [];
            return [
                'Code' => $fournisseur->code_fournisseur,
                'Nom' => $fournisseur->nom,
                'Email' => $fournisseur->email,
                'Téléphone' => $fournisseur->telephone,
                'Statut' => $fournisseur->statut === 'ACTIF' ? 'Actif' : 'Inactif',
                'Nombre commandes' => $stats['total_commandes'] ?? 0,
                'Nombre commandes reçues' => $stats['total_commandes_recues'] ?? 0,
                'Total articles' => $stats['total_articles_commandes'] ?? 0,
                'Total articles reçus' => $stats['total_articles_recus'] ?? 0,
                'Taux réception (%)' => $stats['taux_reception'] ?? 0,
                'Montant total (estimé)' => $stats['montant_total_estime'] ?? 0,
                'Taux fidélité (%)' => $stats['taux_fidelite'] ?? 0,
                'Dernière commande' => $stats['derniere_commande'] ?? 'N/A',
                'Première commande' => $stats['premiere_commande'] ?? 'N/A',
                'Fréquence (jours)' => $stats['frequence_moyenne_jours'] ?? 'N/A'
            ];
        });

        $filename = 'fournisseurs_statistiques_' . date('Ymd_His') . '.csv';
        $handle = fopen('php://temp', 'w+');

        fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

        if ($export->isNotEmpty()) {
            fputcsv($handle, array_keys($export->first()), ';');
            foreach ($export as $row) {
                fputcsv($handle, $row, ';');
            }
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return response($content, 200)
            ->header('Content-Type', 'text/csv; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    // ============================================================
    // MÉTHODES PRIVÉES
    // ============================================================

    /**
     * Statistiques des fournisseurs - UNE SEULE REQUÊTE
     */
    private function getFournisseurStats(): array
    {
        $stats = Fournisseur::selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN statut = 'ACTIF' THEN 1 ELSE 0 END) as actif,
            SUM(CASE WHEN statut = 'INACTIF' THEN 1 ELSE 0 END) as inactif,
            SUM(CASE WHEN statut = 'SUSPENDU' THEN 1 ELSE 0 END) as suspendu
        ")->first();

        return [
            'par_statut' => [
                'ACTIF' => (int) $stats->actif,
                'INACTIF' => (int) $stats->inactif,
                'SUSPENDU' => (int) $stats->suspendu
            ],
            'total' => (int) $stats->total,
            'taux_actifs' => $stats->total > 0
                ? round(($stats->actif / $stats->total) * 100, 2)
                : 0
        ];
    }

    /**
     * Statistiques globales - UNE SEULE REQUÊTE
     */
    private function getGlobalStats(): array
    {
        $stats = CommandeFournisseur::selectRaw("
            COUNT(*) as total_commandes,
            SUM(total_ttc) as montant_total
        ")->first();

        return [
            'total_fournisseurs' => Fournisseur::count(),
            'total_fournisseurs_actifs' => Fournisseur::where('statut', 'ACTIF')->count(),
            'total_commandes_toutes' => (int) ($stats->total_commandes ?? 0),
            'montant_total_global' => (float) ($stats->montant_total ?? 0),
            'periode_analyse' => [
                'debut' => CommandeFournisseur::min('date_commande'),
                'fin' => CommandeFournisseur::max('date_commande')
            ]
        ];
    }

    /**
     * Détails d'un fournisseur - OPTIMISÉ
     */
    private function getFournisseurDetails($fournisseurId): array
    {
        $commandesParAnnee = CommandeFournisseur::where('fournisseur_id', $fournisseurId)
            ->selectRaw('YEAR(date_commande) as annee, COUNT(*) as total')
            ->groupBy('annee')
            ->orderBy('annee', 'desc')
            ->get();

        $commandesParStatut = CommandeFournisseur::where('fournisseur_id', $fournisseurId)
            ->selectRaw('statut, COUNT(*) as total')
            ->groupBy('statut')
            ->get()
            ->map(fn($s) => [
                'statut' => $s->statut,
                'libelle' => CommandeFournisseur::STATUTS[$s->statut] ?? $s->statut,
                'total' => $s->total
            ]);

        $evolutionMensuelle = CommandeFournisseur::where('fournisseur_id', $fournisseurId)
            ->where('date_commande', '>=', now()->subMonths(12))
            ->selectRaw('DATE_FORMAT(date_commande, "%Y-%m") as mois, COUNT(*) as total_commandes, SUM(total_ttc) as montant')
            ->groupBy('mois')
            ->orderBy('mois', 'asc')
            ->get();

        return [
            'commandes_par_annee' => $commandesParAnnee,
            'commandes_par_statut' => $commandesParStatut,
            'evolution_mensuelle' => $evolutionMensuelle
        ];
    }
}