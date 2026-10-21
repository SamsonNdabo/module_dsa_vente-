<?php
// app/Http/Controllers/Notification/NotificationStockController.php

namespace App\Http\Controllers\Notification;

use App\Http\Controllers\Controller;
use App\Models\NotificationStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Role;
use App\Models\Succursale;
use App\Models\Utilisateur;

class NotificationStockController extends Controller
{
    /**
     * Liste des notifications pour l'utilisateur connecté
     * Permissions: view-notifications
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        
        if (!$user->hasPermission('view-notifications')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour voir les notifications'
            ], 403);
        }

        $query = NotificationStock::pourUtilisateur($user->id);

        if ($request->boolean('non_lues')) {
            $query->where('est_lue', false);
        }

        $notifications = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        $nonLues = NotificationStock::pourUtilisateur($user->id)
            ->where('est_lue', false)
            ->count();

        return response()->json([
            'success' => true,
            'data' => $notifications,
            'non_lues' => $nonLues
        ]);
    }

    /**
     * Créer une notification manuelle (maintenance, info, alerte, etc.)
     * Permissions: manage-notifications
     */
    public function store(Request $request)
    {
        $user = auth()->user();
        
        if (!$user->hasPermission('manage-notifications')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour créer des notifications'
            ], 403);
        }
        
        // Validation de base
        $validator = Validator::make($request->all(), [
            'destinataire_type' => 'required|in:public,utilisateur,multiple,role,succursale',
            'type' => 'required|string|in:' . implode(',', array_keys(NotificationStock::TYPES)),
            'titre' => 'required|string|max:255',
            'message' => 'required|string',
            'donnees' => 'nullable|array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // Validation selon le type de destinataire
        switch ($request->destinataire_type) {
            case 'utilisateur':
                $validator = Validator::make($request->all(), [
                    'destinataire_id' => 'required|exists:utilisateurs,id'
                ]);
                break;
                
            case 'multiple':
                $validator = Validator::make($request->all(), [
                    'destinataire_id' => 'required|array',
                    'destinataire_id.*' => 'exists:utilisateurs,id'
                ]);
                break;
                
            case 'role':
                $validator = Validator::make($request->all(), [
                    'destinataire_id' => 'required|string|exists:roles,code'
                ]);
                break;
                
            case 'succursale':
                $validator = Validator::make($request->all(), [
                    'destinataire_id' => 'required|exists:succursales,id'
                ]);
                break;
                
            case 'public':
                // Pas de validation supplémentaire
                break;
        }

        if ($validator && $validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // Données communes
        $donnees = array_merge($request->donnees ?? [], [
            'cree_par' => auth()->user()->nom_complet,
            'cree_par_id' => auth()->id(),
            'date_creation' => now()->toDateTimeString()
        ]);

        // Ajouter des infos selon le type de notification
        switch ($request->type) {
            case 'MAINTENANCE':
                $donnees = array_merge($donnees, [
                    'debut' => $request->donnees['debut'] ?? now(),
                    'fin' => $request->donnees['fin'] ?? now()->addHours(2),
                    'impact' => $request->donnees['impact'] ?? 'Tous les services'
                ]);
                break;
                
            case 'FERMETURE_EXCEPTIONNELLE':
                $donnees = array_merge($donnees, [
                    'date_fermeture' => $request->donnees['date_fermeture'] ?? now()->addDays(1),
                    'motif' => $request->donnees['motif'] ?? 'Non spécifié'
                ]);
                break;
                
            case 'ALERTE_STOCK':
                $donnees = array_merge($donnees, [
                    'priorite' => $request->donnees['priorite'] ?? 'HAUTE',
                    'stock_id' => $request->donnees['stock_id'] ?? null,
                    'produit_id' => $request->donnees['produit_id'] ?? null
                ]);
                break;
        }

        try {
            // Créer la notification selon le type de destinataire
            switch ($request->destinataire_type) {
                case 'utilisateur':
                    $notification = NotificationStock::notifierUtilisateur(
                        $request->destinataire_id,
                        $request->type,
                        $request->titre,
                        $request->message,
                        $donnees
                    );
                    $message = "Notification envoyée à l'utilisateur";
                    break;
                    
                case 'multiple':
                    $notification = NotificationStock::notifierUtilisateurs(
                        $request->destinataire_id,
                        $request->type,
                        $request->titre,
                        $request->message,
                        $donnees
                    );
                    $message = "Notification envoyée à " . count($request->destinataire_id) . " utilisateurs";
                    break;
                    
                case 'role':
                    $notification = NotificationStock::notifierRole(
                        $request->destinataire_id,
                        $request->type,
                        $request->titre,
                        $request->message,
                        $donnees
                    );
                    $role = Role::where('code', $request->destinataire_id)->first();
                    $message = "Notification envoyée à tous les {$role->nom_role}s";
                    break;
                    
                case 'succursale':
                    $notification = NotificationStock::notifierSuccursale(
                        $request->destinataire_id,
                        $request->type,
                        $request->titre,
                        $request->message,
                        $donnees
                    );
                    $succursale = Succursale::find($request->destinataire_id);
                    $message = "Notification envoyée à toute la succursale {$succursale->nom}";
                    break;
                    
                case 'public':
                    $notification = NotificationStock::notifierPublic(
                        $request->type,
                        $request->titre,
                        $request->message,
                        $donnees
                    );
                    $message = "Notification publique envoyée à tous les utilisateurs";
                    break;
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'notification' => $notification,
                    'destinataire_type' => $request->destinataire_type,
                    'destinataire_info' => $this->getDestinataireInfo($request),
                    'type_info' => [
                        'code' => $request->type,
                        'libelle' => NotificationStock::TYPES[$request->type] ?? $request->type,
                        'couleur' => NotificationStock::COULEURS[$request->type] ?? 'gray'
                    ]
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de la notification',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtenir des infos sur le destinataire pour le retour JSON
     */
    private function getDestinataireInfo($request)
    {
        switch ($request->destinataire_type) {
            case 'utilisateur':
                $user = Utilisateur::find($request->destinataire_id);
                return [
                    'id' => $user->id,
                    'nom' => $user->nom_complet,
                    'email' => $user->email
                ];
                
            case 'multiple':
                $users = Utilisateur::whereIn('id', $request->destinataire_id)->get();
                return [
                    'count' => count($request->destinataire_id),
                    'liste' => $users->map(fn($u) => [
                        'id' => $u->id,
                        'nom' => $u->nom_complet
                    ])
                ];
                
            case 'role':
                $role = Role::where('code', $request->destinataire_id)->first();
                $count = Utilisateur::whereHas('role', fn($q) => $q->where('code', $request->destinataire_id))->count();
                return [
                    'code' => $role->code,
                    'nom' => $role->nom_role,
                    'nb_utilisateurs' => $count
                ];
                
            case 'succursale':
                $succursale = Succursale::find($request->destinataire_id);
                $count = Utilisateur::where('succursale_id', $request->destinataire_id)->count();
                return [
                    'id' => $succursale->id,
                    'nom' => $succursale->nom,
                    'nb_utilisateurs' => $count
                ];
                
            case 'public':
                $count = Utilisateur::where('statut', 'ACTIF')->count();
                return [
                    'type' => 'Tous les utilisateurs',
                    'nb_utilisateurs' => $count
                ];
        }
    }

    /**
     * Marquer une notification comme lue
     * Permissions: view-notifications
     */
    public function marquerLue($id)
    {
        $user = auth()->user();
        
        if (!$user->hasPermission('view-notifications')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour modifier les notifications'
            ], 403);
        }
        
        $notification = NotificationStock::pourUtilisateur($user->id)
            ->find($id);

        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notification non trouvée'
            ], 404);
        }

        $notification->est_lue = true;
        $notification->date_lecture = now();
        $notification->save();

        return response()->json([
            'success' => true,
            'message' => 'Notification marquée comme lue'
        ]);
    }

    /**
     * Marquer toutes les notifications comme lues
     * Permissions: view-notifications
     */
    public function marquerToutesLues()
    {
        $user = auth()->user();
        
        if (!$user->hasPermission('view-notifications')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour modifier les notifications'
            ], 403);
        }

        NotificationStock::pourUtilisateur($user->id)
            ->where('est_lue', false)
            ->update([
                'est_lue' => true,
                'date_lecture' => now()
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Toutes les notifications ont été marquées comme lues'
        ]);
    }

    /**
     * Supprimer une notification
     * Permissions: manage-notifications
     */
    public function destroy($id)
    {
        $user = auth()->user();
        
        if (!$user->hasPermission('manage-notifications')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour supprimer des notifications'
            ], 403);
        }
        
        $notification = NotificationStock::pourUtilisateur($user->id)
            ->find($id);

        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notification non trouvée'
            ], 404);
        }

        $notification->delete();

        return response()->json([
            'success' => true,
            'message' => 'Notification supprimée avec succès'
        ]);
    }

    /**
     * Obtenir le nombre de notifications non lues
     * Permissions: view-notifications
     */
    public function nonLuesCount()
    {
        $user = auth()->user();
        
        if (!$user->hasPermission('view-notifications')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour voir les notifications'
            ], 403);
        }

        $count = NotificationStock::pourUtilisateur($user->id)
            ->where('est_lue', false)
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'non_lues' => $count
            ]
        ]);
    }

    /**
     * Obtenir les types de notifications disponibles
     * Permissions: view-notifications
     */
    public function typesNotifications()
    {
        $user = auth()->user();
        
        if (!$user->hasPermission('view-notifications')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour voir les types de notifications'
            ], 403);
        }

        $types = [];
        foreach (NotificationStock::TYPES as $code => $libelle) {
            $types[] = [
                'code' => $code,
                'libelle' => $libelle,
                'couleur' => NotificationStock::COULEURS[$code] ?? 'gray',
                'icone' => NotificationStock::ICONES[$code] ?? 'bell'
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $types
        ]);
    }

    /**
     * Obtenir les options pour les filtres (destinataires possibles)
     * Permissions: manage-notifications
     */
    public function optionsDestinataires()
    {
        $user = auth()->user();
        
        if (!$user->hasPermission('manage-notifications')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour accéder à cette ressource'
            ], 403);
        }

        $roles = Role::select('code', 'nom_role')->orderBy('nom_role')->get();
        $succursales = Succursale::select('id', 'nom')->where('est_active', true)->orderBy('nom')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'roles' => $roles->map(fn($r) => [
                    'code' => $r->code,
                    'nom' => $r->nom_role
                ]),
                'succursales' => $succursales,
                'types_destinataires' => [
                    ['code' => 'public', 'label' => 'Tous les utilisateurs'],
                    ['code' => 'utilisateur', 'label' => 'Utilisateur spécifique'],
                    ['code' => 'multiple', 'label' => 'Plusieurs utilisateurs'],
                    ['code' => 'role', 'label' => 'Par rôle'],
                    ['code' => 'succursale', 'label' => 'Par succursale']
                ]
            ]
        ]);
    }
}