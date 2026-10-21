<?php
// app/Http/Controllers/Auth/AuthController.php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Utilisateur;
use App\Models\ParametreSysteme;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Mail;
use App\Mail\PasswordResetMail;
use App\Mail\TwoFactorCodeMail;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    /**
     * POST /login - Connexion avec session Laravel Auth
     */
        public function login(Request $request)
{
    $request->validate([
        'nom_utilisateur'  => 'required|string',
        'mot_de_passe'     => 'required|string',
        'two_factor_code'  => 'nullable|string|size:6',
        'request_email_code' => 'nullable|boolean',
        'remember'         => 'nullable|boolean',
    ]);

    $remember = $request->boolean('remember');

    // ─────────────────────────────────────────────
    // Tentative avec email
    // ─────────────────────────────────────────────
    $credentials = [
        'email'    => $request->nom_utilisateur,
        'password' => $request->mot_de_passe,
    ];

    if (!Auth::attempt($credentials, $remember)) {

        // Tentative avec nom d'utilisateur
        $credentials = [
            'nom_utilisateur' => $request->nom_utilisateur,
            'password'        => $request->mot_de_passe,
        ];

        if (!Auth::attempt($credentials, $remember)) {

            $user = Utilisateur::where('nom_utilisateur', $request->nom_utilisateur)
                ->orWhere('email', $request->nom_utilisateur)
                ->first();

            if ($user) {
                $user->incrementerTentatives();
            }

            return $this->reponseErreur('Identifiants incorrects', 401);
        }
    }

    /** @var Utilisateur $user */
    $user = Auth::user();

    // ─────────────────────────────────────────────
    // Vérifications de sécurité
    // ─────────────────────────────────────────────
    if ($user->compte_verrouille) {
        Auth::guard('web')->logout();
        return $this->reponseErreur('Compte verrouillé', 403);
    }

    if ($user->statut !== 'ACTIF') {
        Auth::guard('web')->logout();
        return $this->reponseErreur('Compte désactivé', 403);
    }

    // ─────────────────────────────────────────────
    // 2FA Email
    // ─────────────────────────────────────────────
    if ($user->two_factor_email_enabled) {

        // Demande d'envoi du code
        if ($request->boolean('request_email_code')) {
            $user->genererEtEnvoyerCode2FAEmail();

            return response()->json([
                'success'      => false,
                'requires_2fa' => true,
                'type'         => 'email',
                'message'      => 'Code envoyé par email',
                'user_id'      => $user->id,
            ], 200);
        }

        // Code non fourni
        if (!$request->filled('two_factor_code')) {
            return response()->json([
                'success'      => false,
                'requires_2fa' => true,
                'type'         => 'email',
                'message'      => 'Code requis',
                'user_id'      => $user->id,
            ], 200);
        }

        // Code invalide
        if (!$user->verifierCode2FAEmail($request->two_factor_code)) {
            Auth::guard('web')->logout();
            return $this->reponseErreur('Code invalide', 401);
        }
    }

    // ═════════════════════════════════════════════
    // ⚠️ POINT CRITIQUE : attacher l'utilisateur à
    // la session WEB + régénérer la session
    // ═════════════════════════════════════════════

    // 1) Force le guard 'web' à connaitre l'utilisateur
    Auth::guard('web')->login($user, $remember);

    // 2) Régénère l'ID de session (protection fixation)
    $request->session()->regenerate();

    // 3) Persiste la session tout de suite
    $request->session()->save();

    // ─────────────────────────────────────────────
    // Reset sécurité + log
    // ─────────────────────────────────────────────
    // ═════════════════════════════════════════════
// MÉTHODE TOKEN — pas de session web
// ═════════════════════════════════════════════

    $user->resetTentatives();
    $user->enregistrerConnexion($request);

    $user->load(['succursale', 'role']);
    $permissions = $this->getUserPermissions($user);

    // Crée un token Sanctum (durée : 8h par défaut)
    $token = $user->createToken(
        'auth_token_' . now()->timestamp,
        $permissions,
        now()->addHours(8)   // facultatif
    )->plainTextToken;

    return response()->json([
        'success'     => true,
        'message'     => 'Connexion réussie',
        'access_token'=> $token,
        'token_type'  => 'Bearer',
        'expires_in'  => 8 * 3600,
        'user'        => $this->formatUserResponse($user),
        'permissions' => $permissions,
    ]);
}
    /**
     * POST /logout - Déconnexion avec Auth
     */
        public function logout(Request $request)
    {
        $user = Auth::user();
    
        if ($user) {
    
            Log::info('Déconnexion', [
                'user_id' => $user->id,
                'ip' => $request->ip()
            ]);
    
            /*
            ============================================================
            SUPPRESSION TOKEN SANCTUM UNIQUEMENT SI TOKEN RÉEL
            ============================================================
            */
    
            $token = $user->currentAccessToken();
    
            if ($token && $token instanceof \Laravel\Sanctum\PersonalAccessToken) {
                $token->delete();
            }
        }
    
    
        /*
        ============================================================
        INVALIDATION SESSION
        ============================================================
        */
    
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    
    
        /*
        ============================================================
        NETTOYAGE COOKIES SSO
        ============================================================
        */
    
        return response()->json([
            'success' => true,
            'message' => 'Déconnexion réussie'
        ])
        ->withCookie(cookie()->forget('session_token'))
        ->withCookie(cookie()->forget('starehe_session'))
        ->withCookie(cookie()->forget('pos_session'))
        ->withCookie(cookie()->forget('laravel_session'));
    }

    /**
     * GET /user - Récupérer l'utilisateur connecté via Auth
     */
    public function user(Request $request)
    {
        return $this->me($request);
    }

    /**
     * POST /change-password - Changer mot de passe
     */
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed'
        ]);

        $user = Auth::user();

        if (!$user) {
            return $this->reponseErreur('Non authentifié', 401);
        }

        if (!Hash::check($request->current_password, $user->password_hash)) {
            return $this->reponseErreur('Mot de passe actuel incorrect', 400);
        }

        if (Hash::check($request->new_password, $user->password_hash)) {
            return $this->reponseErreur('Le nouveau mot de passe doit être différent', 400);
        }

        $user->setMotDePasse($request->new_password);

        Log::info('Mot de passe modifié', [
            'user_id' => $user->id,
            'ip' => $request->ip()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Mot de passe modifié avec succès'
        ]);
    }

    // ============================================================
    // MÉTHODES 2FA EMAILS
    // ============================================================

    public function enableEmail2fa(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return $this->reponseErreur('Non authentifié', 401);
        }

        if ($user->two_factor_email_enabled) {
            return $this->reponseErreur('2FA par email déjà activé', 400);
        }

        $user->activer2FAEmail();

        Log::info('2FA email activé', [
            'user_id' => $user->id,
            'ip' => $request->ip()
        ]);

        return response()->json([
            'success' => true,
            'message' => '2FA par email activé avec succès'
        ]);
    }

    public function disableEmail2fa(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        $user = Auth::user();

        if (!$user) {
            return $this->reponseErreur('Non authentifié', 401);
        }

        if (strtolower(trim($user->email)) !== strtolower(trim($request->email))) {
            Log::warning('Tentative de désactivation 2FA avec email incorrect', [
                'user_id' => $user->id,
                'email_soumis' => $request->email,
                'ip' => $request->ip()
            ]);
            return $this->reponseErreur('L\'adresse email ne correspond pas à votre compte', 400);
        }

        $user->desactiver2FAEmail();

        Log::info('2FA email désactivé', [
            'user_id' => $user->id,
            'ip' => $request->ip()
        ]);

        return response()->json([
            'success' => true,
            'message' => '2FA par email désactivé avec succès'
        ]);
    }

    public function requestEmail2faCode(Request $request)
    {
        $request->validate(['email' => 'required|email|exists:utilisateurs,email']);

        $user = Utilisateur::where('email', $request->email)->first();

        if (!$user->two_factor_email_enabled) {
            return $this->reponseErreur('2FA par email non activé pour ce compte', 400);
        }

        $sent = $user->genererEtEnvoyerCode2FAEmail();

        if (!$sent) {
            return $this->reponseErreur('Erreur lors de l\'envoi du code', 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Code de vérification envoyé à votre adresse email',
            'email_masque' => $this->masquerEmail($user->email)
        ]);
    }

    public function verifyEmail2faCode(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email',
                'code' => 'required|string|size:6',
                'remember' => 'nullable|boolean'
            ]);

            $user = Utilisateur::where('email', $request->email)->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non trouvé'
                ], 404);
            }

            if (!$user->two_factor_email_enabled) {
                return response()->json([
                    'success' => false,
                    'message' => 'La vérification par email n\'est pas activée pour cet utilisateur'
                ], 400);
            }

            if ($user->verifierCode2FAEmail($request->code)) {
                // Connexion avec Auth
                Auth::login($user, $request->remember ?? false);

                $user->resetTentatives();
                $user->enregistrerConnexion($request);

                $permissions = $this->getUserPermissions($user);

                Log::info('Connexion réussie via 2FA email', [
                    'user_id' => $user->id,
                    'ip' => $request->ip()
                ]);

                $user->load('succursale', 'role');

                // Cookie configuré comme dans le premier code
                $cookie = cookie(
                    'session_token',
                    session()->getId(),
                    ($request->remember ?? false) ? 60 * 24 * 7 : 480,
                    '/',
                    '.stareheholding.com',
                    true,
                    true,
                    false,
                    'None'
                );

                return response()->json([
                    'success' => true,
                    'message' => 'Code vérifié avec succès',
                    'user' => $this->formatUserResponse($user),
                    'permissions' => $permissions
                ])->withCookie($cookie);
            }

            Log::warning('Code 2FA email invalide', [
                'user_id' => $user->id,
                'email' => $user->email,
                'ip' => $request->ip()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Code de vérification invalide ou expiré'
            ], 401);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la vérification du code 2FA email', [
                'email' => $request->email,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la vérification: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getEmail2faStatus(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return $this->reponseErreur('Non authentifié', 401);
        }

        return response()->json([
            'success' => true,
            'enabled' => (bool) $user->two_factor_email_enabled,
            'email' => $this->masquerEmail($user->email),
            'email_verifie' => $user->email_verified_at !== null,
            'two_factor_email' => $user->two_factor_email_enabled
        ]);
    }
    

    // ============================================================
    // GESTION DES MOTS DE PASSE
    // ============================================================

    // public function forgotPassword(Request $request)
    // {
    //     $request->validate([
    //         'email' => 'required|email|exists:utilisateurs,email'
    //     ]);

    //     $user = Utilisateur::where('email', $request->email)->first();

    //     $token = $this->genererResetToken($user);

    //     try {
    //         Mail::to($user->email)->send(new PasswordResetMail($token, $user));
    //     } catch (\Exception $e) {
    //         Log::error('Erreur envoi email: ' . $e->getMessage());
    //         return $this->reponseErreur('Erreur lors de l\'envoi de l\'email', 500);
    //     }

    //     return response()->json([
    //         'success' => true,
    //         'message' => 'Email de réinitialisation envoyé',
    //         'debug_token' => app()->environment('local') ? $token : null
    //     ]);
    // }

    // public function resetPassword(Request $request)
    // {
    //     $request->validate([
    //         'email' => 'required|email|exists:utilisateurs,email',
    //         'token' => 'required|string',
    //         'password' => 'required|string|min:8|confirmed'
    //     ]);

    //     $user = Utilisateur::where('email', $request->email)->first();

    //     $cachedToken = Cache::get('password_reset_' . $user->id);

    //     if (!$cachedToken || $cachedToken !== $request->token) {
    //         return $this->reponseErreur('Token invalide ou expiré', 400);
    //     }

    //     $user->setMotDePasse($request->password);
    //     $user->compte_verrouille = false;
    //     $user->tentatives_echec = 0;
    //     $user->save();

    //     Cache::forget('password_reset_' . $user->id);

    //     Log::info('Mot de passe réinitialisé', [
    //         'user_id' => $user->id,
    //         'ip' => $request->ip()
    //     ]);

    //     return response()->json([
    //         'success' => true,
    //         'message' => 'Mot de passe réinitialisé avec succès'
    //     ]);
    // }

    public function verifyResetToken(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:utilisateurs,email',
            'token' => 'required|string'
        ]);

        $user = Utilisateur::where('email', $request->email)->first();

        $cachedToken = Cache::get('password_reset_' . $user->id);

        if (!$cachedToken || $cachedToken !== $request->token) {
            return response()->json(['valid' => false], 400);
        }

        return response()->json(['valid' => true]);
    }

    // ============================================================
    // SESSION
    // ============================================================

    public function me(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Non authentifié'
            ], 401);
        }

        $user->load(['role', 'succursale']);
        $permissions = $this->getUserPermissions($user);

        return response()->json([
            'success'     => true,
            'user'        => $this->formatUserResponse($user),  // ← pas $user direct
            'permissions' => $permissions,
            'roles'       => [$user->role?->code]
        ]);
    }

    public function refreshToken(Request $request)
    {
        $request->validate([
            'refresh_token' => 'required|string'
        ]);

        $user = Auth::user();

        if (!$user) {
            return $this->reponseErreur('Non authentifié', 401);
        }

        $cachedToken = Cache::get('refresh_token_' . $user->id);

        if (!$cachedToken || $cachedToken !== $request->refresh_token) {
            return $this->reponseErreur('Refresh token invalide', 401);
        }

        try {
            $currentToken = $user->currentAccessToken();
            if ($currentToken) {
                $user->tokens()->where('id', $currentToken->id)->delete();
            }
        } catch (\Exception $e) {
            // Ignorer les erreurs de token
        }

        $abilities = $this->getUserPermissions($user);
        $token = $user->createToken('auth_token', $abilities)->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => 480 * 60
        ]);
    }

    public function logoutAll(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Non authentifié'
            ], 401);
        }

        $user->deconnecterToutesSessions(false);
        $user->revokeAllTokens();
        Cache::forget('refresh_token_' . $user->id);

        return response()->json([
            'success' => true,
            'message' => 'Déconnexion de toutes les sessions réussie'
        ]);
    }

    // ============================================================
    // MÉTHODES PRIVÉES
    // ============================================================

    /**
     * Récupère les permissions d'un utilisateur de manière standardisée
     */
    private function getUserPermissions(Utilisateur $user): array
    {
        // Priorité 1: Méthode getAllPermissions (via trait HasRole)
        if (method_exists($user, 'getAllPermissions')) {
            return $user->getAllPermissions()->pluck('name')->toArray();
        }

        // Priorité 2: Attribut permissions_liste (via accessor)
        if (method_exists($user, 'getPermissionsListeAttribute')) {
            return $user->permissions_liste->toArray();
        }

        // Priorité 3: Via le rôle
        if ($user->role && method_exists($user->role, 'permissions')) {
            return $user->role->permissions->pluck('permission_code')->toArray();
        }

        // Priorité 4: Aucune permission trouvée
        return [];
    }

    /**
     * Génère un token de réinitialisation de mot de passe
     */
    private function genererResetToken(Utilisateur $user): string
    {
        $token = Str::random(60) . bin2hex(random_bytes(32));
        Cache::put('password_reset_' . $user->id, $token, now()->addHours(24));
        return $token;
    }

    /**
     * Masque un email pour l'affichage
     */
    private function masquerEmail(string $email): string
    {
        $parts = explode('@', $email);
        $name = $parts[0];
        $domain = $parts[1] ?? '';
        $nameMasque = substr($name, 0, 2) . str_repeat('*', max(0, strlen($name) - 2));
        return $nameMasque . '@' . $domain;
    }

    /**
     * Formate la réponse utilisateur pour les endpoints d'authentification
     */
    private function formatUserResponse($user): array
    {
        return [
            'id' => $user->id,
            'matricule' => $user->matricule,
            'nom_complet' => $user->nom_complet,
            'email' => $user->email,
            'telephone' => $user->telephone,
            'nom_utilisateur' => $user->nom_utilisateur,
            'statut' => $user->statut,
            'compte_verrouille' => $user->compte_verrouille,
            'tentatives_echec' => $user->tentatives_echec,
            'date_derniere_connexion' => $user->date_derniere_connexion?->toISOString(),
            'created_at' => $user->created_at?->toISOString(),
            'updated_at' => $user->updated_at?->toISOString(),
            'two_factor_authentification' => $user->two_factor_authentification,
            'two_factor_email_enabled' => $user->two_factor_email_enabled,
            'two_factor_confirmed_at' => $user->two_factor_confirmed_at?->toISOString(),
            'role' => $user->role ? [
                'id' => $user->role->id,
                'code' => $user->role->code,
                'nom_role' => $user->role->nom_role,
                'niveau_acces' => $user->role->niveau_acces ?? 0
            ] : null,
            'succursale' => $user->succursale ? [
                'id' => $user->succursale->id,
                'code' => $user->succursale->code,
                'nom' => $user->succursale->nom,
                'type' => $user->succursale->type,
                'ville' => $user->succursale->ville,
                'province' => $user->succursale->province,
                'telephone' => $user->succursale->telephone,
                'email' => $user->succursale->email,
                'adresse' => $user->succursale->adresse
            ] : null,
            'initiales' => $user->initiales,
            'est_actif' => $user->est_actif,
            'est_en_ligne' => $user->est_en_ligne,
            'derniere_activite' => $user->derniere_activite?->toISOString(),
            'photo_profil' => $user->photo_profil ? asset('storage/' . $user->photo_profil) : null,
            'preferences' => $user->preferences,
            'permissions' => $user->permissions_liste->toArray()
        ];
    }

    /**
     * Retourne une réponse d'erreur standardisée
     */
    private function reponseErreur(string $message, int $code = 400)
    {
        return response()->json([
            'success' => false,
            'error' => $message
        ], $code);
    }
}