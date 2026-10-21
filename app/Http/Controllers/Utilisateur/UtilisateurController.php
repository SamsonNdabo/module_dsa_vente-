<?php
// app/Http/Controllers/Utilisateur/UtilisateurController.php

namespace App\Http\Controllers\Utilisateur;

use App\Http\Controllers\Controller;
use App\Models\Utilisateur;
use App\Models\Role;
use App\Models\Succursale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class UtilisateurController extends Controller
{
    // ✅ Constantes pour les rôles avec accès total
    private const ROLES_FULL_ACCESS = ['SUPER_ADMIN','AUDITEUR'];

    /**
     * Liste des utilisateurs avec filtres - OPTIMISÉ
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if (!$user->role) {
            Log::warning('Utilisateur sans rôle', ['user_id' => $user->id]);
            return $this->reponseVide();
        }

        // ✅ Construction de la requête avec SELECT limité
        $query = Utilisateur::select([
            'id', 'matricule', 'nom_complet', 'email', 'telephone',
            'nom_utilisateur', 'role_id', 'succursale_id', 'statut',
            'compte_verrouille', 'date_derniere_connexion', 'created_at', 'two_factor_email_enabled'
        ])->with([
            'role' => function($q) {
                $q->select('id', 'code', 'nom_role', 'niveau_acces');
            },
            'succursale' => function($q) {
                $q->select('id', 'nom', 'code');
            }
        ]);

        // ✅ Application des restrictions de permissions
        $this->applyUserRestrictions($query, $user);

        // ✅ Filtres
        if ($request->filled('statut')) {
            $query->where('statut', $request->statut);
        }

        if ($request->filled('role_id')) {
            $query->where('role_id', $request->role_id);
        }

        if ($request->filled('succursale_id')) {
            $query->where('succursale_id', $request->succursale_id);
        }

        if ($request->filled('recherche')) {
            $search = $request->recherche;
            $query->where(function($q) use ($search) {
                $q->where('nom_complet', 'like', "%{$search}%")
                  ->orWhere('nom_utilisateur', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('telephone', 'like', "%{$search}%")
                  ->orWhere('matricule', 'like', "%{$search}%");
            });
        }

        // ✅ Tri optimisé
        $query->orderBy('created_at', 'desc');

        // ✅ Pagination
        $perPage = $request->get('per_page', 15);
        $utilisateurs = $query->paginate($perPage);

        // ✅ Statistiques - UNE SEULE REQUÊTE
        $stats = $this->getFilteredStats($user, $request);

        return response()->json([
            'success' => true,
            'data' => $utilisateurs,
            'stats' => $stats
        ]);
    }

    /**
     * Afficher un utilisateur spécifique - OPTIMISÉ
     */
    public function show($id)
    {
        $utilisateur = Utilisateur::select([
            'id', 'matricule', 'nom_complet', 'email', 'telephone',
            'nom_utilisateur', 'role_id', 'succursale_id', 'statut',
            'compte_verrouille', 'tentatives_echec', 'photo_profil',
            'preferences', 'date_derniere_connexion', 'date_expiration_mdp',
            'two_factor_authentification', 'two_factor_email_enabled',
            'created_at', 'updated_at'
        ])->with([
            'role' => function($q) {
                $q->select('id', 'code', 'nom_role', 'niveau_acces');
            },
            'succursale' => function($q) {
                $q->select('id', 'nom', 'code');
            },
            'audits' => function($q) {
                $q->select('id', 'id_utilisateur', 'type_action', 'table_type', 'created_at')
                  ->latest()
                  ->limit(10);
            }
        ])->findOrFail($id);

        $user = auth()->user();

        // ✅ Vérification d'accès
        if (!$this->canViewUser($user, $utilisateur)) {
            return response()->json(['error' => 'Non autorisé à voir cet utilisateur'], 403);
        }

        // ✅ Statistiques - UNE SEULE REQUÊTE
        $stats = [
            'nombre_connexions' => $utilisateur->audits()
                ->where('type_action', 'LOGIN')
                ->count(),
            'derniere_connexion' => $utilisateur->date_derniere_connexion,
            'en_ligne' => Cache::has('user-is-online-' . $utilisateur->id),
            'est_systeme' => $utilisateur->est_systeme ?? false
        ];

        return response()->json([
            'success' => true,
            'data' => $utilisateur,
            'stats' => $stats
        ]);
    }

    /**
     * Créer un nouvel utilisateur
     */
    public function store(Request $request)
    {
        $user = auth()->user();

        if (!$user->hasPermission('create-users')) {
            return response()->json([
                'success' => false,
                'error' => 'Vous n\'avez pas les droits pour créer des utilisateurs'
            ], 403);
        }

        // ✅ Récupération du rôle (PRIORITÉ AU CODE)
        if ($request->filled('role_code')) {
            $roleDemande = Role::where('code', $request->role_code)->first();
        } else {
            $roleDemande = Role::find($request->role_id);
        }

        if (!$roleDemande) {
            return response()->json([
                'success' => false,
                'error' => 'Rôle invalide'
            ], 400);
        }

        // ✅ Vérification des restrictions
        $restriction = $this->checkRoleRestrictions($user, $roleDemande, $request);
        if ($restriction !== true) {
            return $restriction;
        }

        // ✅ Validation
        $validator = $this->validateStore($request);
        if ($validator !== true) {
            return $validator;
        }

        DB::beginTransaction();

        try {
            $utilisateur = $this->createUser($request, $roleDemande, $user);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Utilisateur créé avec succès',
                'data' => $utilisateur->load([
                    'role' => function($q) {
                        $q->select('id', 'code', 'nom_role');
                    },
                    'succursale' => function($q) {
                        $q->select('id', 'nom', 'code');
                    }
                ])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur création utilisateur', [
                'error' => $e->getMessage(),
                'user_id' => $user->id
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Erreur lors de la création: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mettre à jour un utilisateur - OPTIMISÉ
     */
    public function update(Request $request, $id)
    {
        $utilisateur = Utilisateur::findOrFail($id);

        $currentUser = auth()->user();

        // ✅ Vérification des droits
        if (!$currentUser->hasPermission('manage-all-users') && $currentUser->id != $utilisateur->id) {
            return response()->json(['error' => 'Non autorisé'], 403);
        }

        // ✅ Validation
        $validator = $this->validateUpdate($request, $utilisateur);
        if ($validator !== true) {
            return $validator;
        }

        // ✅ Vérification des permissions sur le rôle
        if ($request->has('role_id') && $request->role_id != $utilisateur->role_id) {
            if (!$currentUser->hasPermission('change-users-role')) {
                return response()->json(['error' => 'Non autorisé à modifier le rôle'], 403);
            }
        }

        DB::beginTransaction();

        try {
            // ✅ Gestion du changement de rôle
            if ($request->has('role_id') && $request->role_id != $utilisateur->role_id) {
                $this->handleRoleChange($utilisateur, $request);
            }

            // ✅ Mise à jour des champs
            $utilisateur->fill($request->only([
                'nom_complet', 'email', 'telephone', 'role_id',
                'succursale_id', 'preferences'
            ]));

            // ✅ Gestion de la photo
            if ($request->hasFile('photo_profil')) {
                $this->handlePhotoUpload($utilisateur, $request);
            }

            $utilisateur->save();

            // ✅ Gestion du changement de succursale pour chef d'agence
            if ($request->has('succursale_id') && $request->succursale_id != $utilisateur->succursale_id) {
                $this->handleSuccursaleChange($utilisateur, $request);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Utilisateur mis à jour',
                'data' => $utilisateur->fresh([
                    'role' => function($q) {
                        $q->select('id', 'code', 'nom_role');
                    },
                    'succursale' => function($q) {
                        $q->select('id', 'nom', 'code');
                    }
                ])
            ]);

        }catch (\Throwable $e) {

    Log::error('Erreur update utilisateur', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
    ]);

    return response()->json([
        'message' => $e->getMessage(),
    ], 500);
}
    }

    /**
     * Supprimer un utilisateur
     */
    public function destroy($id)
    {
        $utilisateur = Utilisateur::findOrFail($id);

        if ($utilisateur->est_systeme ?? false) {
            return response()->json(['error' => 'Impossible de supprimer un utilisateur système'], 400);
        }

        if ($utilisateur->id === auth()->id()) {
            return response()->json(['error' => 'Vous ne pouvez pas vous supprimer vous-même'], 400);
        }

        if (!auth()->user()->hasPermission('manage-all-users')) {
            return response()->json(['error' => 'Non autorisé à supprimer des utilisateurs'], 403);
        }

        if ($utilisateur->caisses()->where('statut', 'OUVERTE')->exists()) {
            return response()->json([
                'error' => 'Cet utilisateur a des caisses ouvertes. Fermez-les d\'abord.'
            ], 400);
        }

        $utilisateur->delete();

        return response()->json([
            'success' => true,
            'message' => 'Utilisateur supprimé'
        ]);
    }

    /**
     * Suppression multiple
     */
    public function bulkDestroy(Request $request)
    {
        if (!auth()->user()->hasPermission('manage-all-users')) {
            return response()->json(['error' => 'Non autorisé'], 403);
        }

        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:utilisateurs,id'
        ]);

        $count = 0;
        $errors = [];

        foreach ($request->ids as $id) {
            try {
                $utilisateur = Utilisateur::find($id);
                if ($utilisateur && !($utilisateur->est_systeme ?? false) && $utilisateur->id !== auth()->id()) {
                    $utilisateur->delete();
                    $count++;
                } else {
                    $errors[] = "Impossible de supprimer l'utilisateur ID: {$id}";
                }
            } catch (\Exception $e) {
                $errors[] = "Erreur sur ID {$id}: " . $e->getMessage();
            }
        }

        return response()->json([
            'success' => true,
            'message' => "{$count} utilisateur(s) supprimé(s)",
            'errors' => $errors
        ]);
    }

    // ============================================================
    // MÉTHODES D'ACTIVATION/DÉSACTIVATION
    // ============================================================

    public function activate($id)
    {
        if (!auth()->user()->hasPermission('block-users')) {
            return response()->json(['error' => 'Non autorisé à activer des utilisateurs'], 403);
        }

        $utilisateur = Utilisateur::findOrFail($id);
        $utilisateur->update([
            'statut' => 'ACTIF',
            'compte_verrouille' => false,
            'tentatives_echec' => 0
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Utilisateur activé'
        ]);
    }

    public function deactivate($id)
    {
        if (!auth()->user()->hasPermission('block-users')) {
            return response()->json(['error' => 'Non autorisé à désactiver des utilisateurs'], 403);
        }

        $utilisateur = Utilisateur::findOrFail($id);

        if ($utilisateur->id === auth()->id()) {
            return response()->json(['error' => 'Vous ne pouvez pas vous désactiver vous-même'], 400);
        }

        $utilisateur->update(['statut' => 'INACTIF']);
        $utilisateur->deconnecterToutesSessions(false);

        return response()->json([
            'success' => true,
            'message' => 'Utilisateur désactivé'
        ]);
    }

    // ============================================================
    // MÉTHODES DE GESTION DES MOTS DE PASSE ET RÔLES
    // ============================================================

    public function resetPassword(Request $request, $id)
    {
        if (!auth()->user()->hasPermission('reset-user-password')) {
            return response()->json(['error' => 'Non autorisé à réinitialiser les mots de passe'], 403);
        }

        $request->validate([
            'nouveau_mot_de_passe' => 'required|string|min:8|confirmed'
        ]);

        $utilisateur = Utilisateur::findOrFail($id);
        $utilisateur->setMotDePasse($request->nouveau_mot_de_passe);
        $utilisateur->deconnecterToutesSessions(false);

        return response()->json([
            'success' => true,
            'message' => 'Mot de passe réinitialisé'
        ]);
    }

    public function assignRole(Request $request, $id)
    {
        if (!auth()->user()->hasPermission('manage-user-roles')) {
            return response()->json(['error' => 'Non autorisé à gérer les rôles'], 403);
        }

        $request->validate([
            'role_id' => 'required|exists:roles,id'
        ]);

        $utilisateur = Utilisateur::findOrFail($id);

        // Vérifier si le nouveau rôle est valide pour cette succursale
        $this->validateRoleAssignment($utilisateur, $request->role_id);

        $utilisateur->role_id = $request->role_id;
        $utilisateur->save();

        return response()->json([
            'success' => true,
            'message' => 'Rôle assigné',
            'data' => $utilisateur->fresh(['role' => function($q) {
                $q->select('id', 'code', 'nom_role');
            }])
        ]);
    }

    public function removeRole($id)
    {
        if (!auth()->user()->hasPermission('manage-user-roles')) {
            return response()->json(['error' => 'Non autorisé à gérer les rôles'], 403);
        }

        $utilisateur = Utilisateur::findOrFail($id);

        if ($utilisateur->est_systeme ?? false) {
            return response()->json(['error' => 'Impossible de modifier le rôle d\'un utilisateur système'], 400);
        }

        if (!$utilisateur->role_id) {
            return response()->json(['error' => 'Cet utilisateur n\'a pas de rôle à retirer'], 400);
        }

        $ancienRole = $utilisateur->role->nom_role ?? 'Inconnu';

        // Si chef d'agence, retirer de la succursale
        if ($utilisateur->role->code === 'CHEF_AGENCE') {
            Succursale::where('responsable_id', $utilisateur->id)->update(['responsable_id' => null]);
        }

        $utilisateur->role_id = null;
        $utilisateur->save();

        Log::info('Rôle retiré', [
            'user_id' => $utilisateur->id,
            'ancien_role' => $ancienRole,
            'par' => auth()->user()->id
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Rôle retiré avec succès',
            'data' => $utilisateur->fresh()
        ]);
    }

    // ============================================================
    // MÉTHODES D'AUDIT ET STATISTIQUES
    // ============================================================

    public function audit($id, Request $request)
    {
        $utilisateur = Utilisateur::findOrFail($id);

        $user = auth()->user();

        if (!$this->canViewUser($user, $utilisateur)) {
            return response()->json(['error' => 'Non autorisé'], 403);
        }

        $audits = $utilisateur->audits()
            ->select(['id', 'id_utilisateur', 'type_action', 'table_type', 'table_id', 'old_values', 'new_values', 'created_at'])
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $audits
        ]);
    }

    public function statistiques(Request $request)
    {
        $user = auth()->user();
        $query = Utilisateur::query();

        $this->applyUserRestrictions($query, $user);

        if ($request->has('succursale_id')) {
            $query->where('succursale_id', $request->succursale_id);
        }

        $stats = [
            'total' => $query->count(),
            'par_role' => (clone $query)->select('role_id', DB::raw('count(*) as total'))
                ->with('role:id,nom_role,code')
                ->groupBy('role_id')
                ->get()
                ->map(fn($item) => [
                    'role' => $item->role?->nom_role,
                    'code' => $item->role?->code,
                    'total' => $item->total
                ]),
            'par_succursale' => (clone $query)->select('succursale_id', DB::raw('count(*) as total'))
                ->with('succursale:id,nom,code')
                ->groupBy('succursale_id')
                ->get()
                ->map(fn($item) => [
                    'succursale' => $item->succursale?->nom,
                    'total' => $item->total
                ]),
            'evolution' => (clone $query)->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('count(*) as total')
            )
                ->where('created_at', '>=', now()->subDays(30))
                ->groupBy('date')
                ->orderBy('date')
                ->get()
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    // ============================================================
    // MÉTHODES D'EXPORT
    // ============================================================

    public function export(Request $request)
    {
        $user = auth()->user();
        $query = Utilisateur::with(['role:id,nom_role,code', 'succursale:id,nom,code']);

        $this->applyUserRestrictions($query, $user);

        if ($request->has('role_id')) {
            $query->where('role_id', $request->role_id);
        }

        if ($request->has('succursale_id')) {
            $query->where('succursale_id', $request->succursale_id);
        }

        if ($request->has('statut')) {
            $query->where('statut', $request->statut);
        }

        $utilisateurs = $query->get();
        $csv = $this->genererCSV($utilisateurs);

        return response($csv)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', 'attachment; filename="utilisateurs_' . now()->format('Y-m-d_His') . '.csv"');
    }

    // ============================================================
    // MÉTHODES PRIVÉES
    // ============================================================

     /**
     * Applique les restrictions de permissions sur une requête
     */
    private function applyUserRestrictions($query, Utilisateur $user): void
    {
        // SUPER ADMIN : accès total
        if ($user->isSuperAdmin()) {
            return;
        }
    
        // Si l'utilisateur n'est rattaché à aucune succursale
        if (empty($user->succursale_id)) {
            $query->where('id', $user->id);
            return;
        }
    
        // Chef d'agence
        if ($user->isChefAgence()) {
            $query->where('succursale_id', $user->succursale_id);
            return;
        }
    
        // Permission de consultation des utilisateurs
        if ($user->hasPermission('view-users')) {
            $query->where('succursale_id', $user->succursale_id);
            return;
        }
    
        // Aucun droit : uniquement lui-même
        $query->where('id', $user->id);
    }

    /**
     * Vérifie si un utilisateur peut voir un autre utilisateur
     */
    private function canViewUser($user, $target): bool
    {
        if (in_array($user->role?->code, self::ROLES_FULL_ACCESS) ||
            $user->hasPermission('manage-all-users') ||
            $user->hasPermission('view-all-users')) {
            return true;
        }

        if ($user->hasPermission('view-users')) {
            return $target->succursale_id == $user->succursale_id;
        }

        return $user->id == $target->id;
    }

    /**
     * Récupère les statistiques filtrées - OPTIMISÉ
     */
    private function getFilteredStats($user, $request): array
    {
        $query = Utilisateur::query();

        $this->applyUserRestrictions($query, $user);

        // ✅ Appliquer les mêmes filtres que la liste
        if ($request->filled('statut')) {
            $query->where('statut', $request->statut);
        }

        if ($request->filled('role_id')) {
            $query->where('role_id', $request->role_id);
        }

        if ($request->filled('succursale_id')) {
            $query->where('succursale_id', $request->succursale_id);
        }

        if ($request->filled('recherche')) {
            $search = $request->recherche;
            $query->where(function($q) use ($search) {
                $q->where('nom_complet', 'like', "%{$search}%")
                  ->orWhere('nom_utilisateur', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // ✅ Statistiques en UNE SEULE REQUÊTE groupée
        $stats = $query->selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN statut = 'ACTIF' THEN 1 ELSE 0 END) as actifs,
            SUM(CASE WHEN statut = 'INACTIF' THEN 1 ELSE 0 END) as inactifs,
            SUM(CASE WHEN compte_verrouille = 1 THEN 1 ELSE 0 END) as verrouilles
        ")->first();

        // ✅ Utilisateurs en ligne - requête optimisée
        $onlineCount = Cache::remember('users_online_count_' . md5(json_encode($request->all())), 60, function() use ($query) {
            $users = $query->select('id')->get();
            $count = 0;
            foreach ($users as $u) {
                if (Cache::has('user-is-online-' . $u->id)) {
                    $count++;
                }
            }
            return $count;
        });

        return [
            'total' => (int) $stats->total,
            'actifs' => (int) $stats->actifs,
            'inactifs' => (int) $stats->inactifs,
            'verrouilles' => (int) $stats->verrouilles,
            'en_ligne' => $onlineCount
        ];
    }

    /**
     * Valide la création d'un utilisateur
     */
    private function validateStore($request)
    {
        $validator = validator($request->all(), [
            'nom_complet' => 'required|string|max:100',
            'email' => 'required|email|unique:utilisateurs,email',
            'telephone' => 'required|string|max:20',
            'succursale_id' => 'required|exists:succursales,id',
            'nom_utilisateur' => 'required|string|unique:utilisateurs,nom_utilisateur',
            'mot_de_passe' => 'required|string|min:8|confirmed',
            'photo_profil' => 'nullable|image|max:2048',
            'date_expiration_mdp' => 'nullable|date'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        return true;
    }

    /**
     * Valide la mise à jour d'un utilisateur
     */
    private function validateUpdate($request, $utilisateur)
    {
        $validator = validator($request->all(), [
            'nom_complet' => 'sometimes|string|max:100',
            'email' => ['sometimes', 'email', Rule::unique('utilisateurs')->ignore($utilisateur->id)],
            'telephone' => 'sometimes|string|max:20',
            'role_id' => 'sometimes|exists:roles,id',
            'succursale_id' => 'sometimes|exists:succursales,id',
            'photo_profil' => 'nullable|image|max:2048',
            'preferences' => 'nullable|array'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        return true;
    }

    /**
     * Vérifie les restrictions sur le rôle
     */
    private function checkRoleRestrictions($user, $role, $request)
    {
        $userRoleCode = $user->role?->code;

        // SUPER ADMIN restriction
        if ($role->code === 'SUPER_ADMIN' && $userRoleCode !== 'SUPER_ADMIN') {
            return response()->json([
                'success' => false,
                'error' => 'Seul un SUPER_ADMIN peut créer un autre SUPER_ADMIN'
            ], 403);
        }

        // CHEF AGENCE restrictions
        if ($userRoleCode === 'CHEF_AGENCE') {
            $rolesAutorises = [
                'UTILISATEUR', 'MAGASINIER', 'CAISSIER',
                'LIVREUR', 'GESTIONNAIRE_DEPOT', 'GESTIONNAIRE_STOCK'
            ];

            if (!in_array($role->code, $rolesAutorises)) {
                return response()->json([
                    'success' => false,
                    'error' => 'En tant que CHEF_AGENCE, vous ne pouvez créer que : ' . implode(', ', $rolesAutorises)
                ], 403);
            }

            if ($request->succursale_id != $user->succursale_id) {
                return response()->json([
                    'success' => false,
                    'error' => 'Vous ne pouvez créer que dans votre succursale'
                ], 403);
            }
        }

        return true;
    }

    /**
     * Crée un utilisateur
     */
    private function createUser($request, $role, $user)
    {
        $utilisateur = new Utilisateur();
        $utilisateur->nom_complet = $request->nom_complet;
        $utilisateur->email = $request->email;
        $utilisateur->telephone = $request->telephone;
        $utilisateur->role_id = $role->id;
        $utilisateur->succursale_id = $request->succursale_id;
        $utilisateur->nom_utilisateur = $request->nom_utilisateur;
        $utilisateur->setMotDePasse($request->mot_de_passe);

        if ($request->filled('date_expiration_mdp')) {
            $utilisateur->date_expiration_mdp = $request->date_expiration_mdp;
        }

        if ($request->hasFile('photo_profil')) {
            $path = $request->file('photo_profil')->store('photos_profil', 'public');
            $utilisateur->photo_profil = $path;
        }

        $utilisateur->save();

        // Assignation automatique chef agence
        if ($role->code === 'CHEF_AGENCE') {
            $succursale = Succursale::find($request->succursale_id);
            if ($succursale) {
                $succursale->responsable_id = $utilisateur->id;
                $succursale->save();

                Log::info('Chef agence assigné', [
                    'user_id' => $utilisateur->id,
                    'succursale_id' => $succursale->id,
                    'par' => $user->id
                ]);
            }
        }

        return $utilisateur;
    }

    private function handleRoleChange($utilisateur, $request)
    {
        $nouveauRole = Role::find($request->role_id);
        $ancienRole = Role::find($utilisateur->role_id);

        // Si nouveau rôle = CHEF_AGENCE
        if ($nouveauRole && $nouveauRole->code === 'CHEF_AGENCE') {
            $succursaleId = $request->succursale_id ?? $utilisateur->succursale_id;
            $succursale = Succursale::find($succursaleId);

            if ($succursale && $succursale->responsable_id && $succursale->responsable_id != $utilisateur->id) {
                throw new \Exception('Cette succursale a déjà un chef d\'agence.');
            }
        }

        // Si ancien rôle = CHEF_AGENCE et nouveau ≠ CHEF_AGENCE
        if ($ancienRole && $ancienRole->code === 'CHEF_AGENCE' &&
            $nouveauRole && $nouveauRole->code !== 'CHEF_AGENCE') {
            Succursale::where('responsable_id', $utilisateur->id)->update(['responsable_id' => null]);

            Log::info('Chef d\'agence retiré automatiquement', [
                'utilisateur_id' => $utilisateur->id,
                'par' => auth()->user()->id
            ]);
        }
    }
    
        public function enableEmail2fa(Request $request, $userId)
{
    $admin = auth()->user();

    if (!$admin || !$admin->hasRole('SUPER_ADMIN')) {
        return response()->json([
            'success' => false,
            'error' => 'Accès refusé'
        ], 403);
    }

    $user = Utilisateur::findOrFail($userId);

    if ($user->two_factor_email_enabled) {
        return response()->json([
            'success' => false,
            'error' => '2FA déjà activé'
        ], 400);
    }

    $user->activer2FAEmail();

    return response()->json([
        'success' => true,
        'message' => '2FA activé avec succès pour l’utilisateur'
    ]);
}

public function disableEmail2fa(Request $request, $userId)
{
    $admin = auth()->user();

    if (!$admin || !$admin->hasRole('SUPER_ADMIN')) {
        return response()->json([
            'success' => false,
            'error' => 'Accès refusé'
        ], 403);
    }

    $user = Utilisateur::findOrFail($userId);
    $user->desactiver2FAEmail();

    return response()->json([
        'success' => true,
        'message' => '2FA désactivé avec succès pour l’utilisateur'
    ]);
}

public function agents(Request $request)
{
    $agents = Utilisateur::select([
        'id', 'nom_complet', 'email', 'telephone',
        'role_id', 'succursale_id', 'statut', 'photo_profil'
    ])
    ->with([
        'role:id,code,nom_role,niveau_acces',
        'succursale:id,nom,code',
    ])
    ->where('statut', 'ACTIF')
    ->where('compte_verrouille', false)
    ->whereNotNull('role_id')
    ->whereHas('role', function ($q) {
        // Exclure les rôles purement client
        $q->whereNotIn('code', ['CLIENT', 'INVITE']);
    })
    ->orderBy('nom_complet')
    ->get();

    return response()->json([
        'success' => true,
        'data' => $agents,
    ]);
}

    /**
     * Gère le téléchargement de la photo de profil
     */
    private function handlePhotoUpload($utilisateur, $request): void
    {
        if ($utilisateur->photo_profil) {
            Storage::disk('public')->delete($utilisateur->photo_profil);
        }

        $path = $request->file('photo_profil')->store('photos_profil', 'public');
        $utilisateur->photo_profil = $path;
    }

    /**
     * Gère le changement de succursale pour un chef d'agence
     */
    private function handleSuccursaleChange($utilisateur, $request): void
    {
        $role = Role::find($utilisateur->role_id);
        if ($role && $role->code === 'CHEF_AGENCE') {
            // Retirer de l'ancienne succursale
            Succursale::where('responsable_id', $utilisateur->id)->update(['responsable_id' => null]);

            // Assigner à la nouvelle succursale
            $nouvelleSuccursale = Succursale::find($request->succursale_id);
            if ($nouvelleSuccursale && !$nouvelleSuccursale->responsable_id) {
                $nouvelleSuccursale->responsable_id = $utilisateur->id;
                $nouvelleSuccursale->save();

                Log::info('Chef d\'agence réassigné', [
                    'utilisateur_id' => $utilisateur->id,
                    'nouvelle_succursale' => $request->succursale_id,
                    'par' => auth()->user()->id
                ]);
            }
        }
    }

    /**
     * Valide l'assignation d'un rôle
     */
    private function validateRoleAssignment($utilisateur, $roleId): void
    {
        $role = Role::find($roleId);
        if ($role && $role->code === 'CHEF_AGENCE') {
            $succursale = Succursale::find($utilisateur->succursale_id);
            if ($succursale && $succursale->responsable_id && $succursale->responsable_id != $utilisateur->id) {
                throw new \Exception('Cette succursale a déjà un chef d\'agence.');
            }
        }
    }

    /**
     * Réponse vide pour les utilisateurs sans rôle
     */
    private function reponseVide()
    {
        return response()->json([
            'success' => true,
            'data' => ['data' => [], 'total' => 0],
            'stats' => [
                'total' => 0,
                'actifs' => 0,
                'inactifs' => 0,
                'verrouilles' => 0,
                'en_ligne' => 0
            ]
        ]);
    }

    /**
     * Générer CSV
     */
    private function genererCSV($utilisateurs)
    {
        $output = fopen('php://temp', 'r+');

        fputcsv($output, [
            'Matricule', 'Nom complet', 'Email', 'Téléphone',
            'Rôle', 'Succursale', 'Statut', 'Date création'
        ]);

        foreach ($utilisateurs as $user) {
            fputcsv($output, [
                $user->matricule,
                $user->nom_complet,
                $user->email,
                $user->telephone,
                $user->role?->nom_role,
                $user->succursale?->nom,
                $user->statut,
                $user->created_at->format('d/m/Y H:i')
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }
}