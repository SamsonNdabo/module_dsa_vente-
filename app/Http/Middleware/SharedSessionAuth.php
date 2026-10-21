<?php

namespace App\Http\Middleware;

use App\Models\Client;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class SharedSessionAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        // ============================================================
        // ✅ EXCEPTION PRIORITAIRE : Ignorer complètement broadcasting/auth
        // ============================================================
        if ($request->is('broadcasting/auth')) {
            return $next($request);
        }

        $loginUrl = 'https://pos.stareheholding.com/login-employe';
        
        // Récupérer tous les cookies de session disponibles
        $hasPosSession = $request->hasCookie('pos_session');
        $hasStareheSession = $request->hasCookie('starehe_session');
        $hasLaravelSession = $request->hasCookie('laravel_session');
        
        Log::info('SSO: Cookies détectés', [
            'pos_session' => $hasPosSession ? 'PRÉSENT' : 'ABSENT',
            'starehe_session' => $hasStareheSession ? 'PRÉSENT' : 'ABSENT',
            'laravel_session' => $hasLaravelSession ? 'PRÉSENT' : 'ABSENT',
            'url' => $request->fullUrl(),
        ]);
        
        // ============================================================
        // STRATÉGIE : ESSAYER LES DEUX SESSIONS, ARRÊTER À LA PREMIÈRE QUI FONCTIONNE
        // ============================================================
        
        $authenticated = false;
        
        // 1. Essayer d'abord la session SAAS (starehe_session ou laravel_session)
        if ($hasStareheSession || $hasLaravelSession) {
            $result = $this->authenticateWithSaasSession($request);
            if ($result) {
                Log::info('SSO: ✅ Authentifié via session SAAS');
                $authenticated = true;
            }
        }
        
        // 2. Si SAAS n'a pas fonctionné, essayer la session POS
        if (!$authenticated && $hasPosSession) {
            $result = $this->authenticateWithPosSession($request);
            if ($result) {
                Log::info('SSO: ✅ Authentifié via session POS');
                $authenticated = true;
            }
        }
        
        // 3. Si aucune session n'a fonctionné, rediriger
        if (!$authenticated) {
            Log::warning('SSO: ❌ Aucune session valide trouvée');
            return $this->redirectOrJson($request, 'Utilisateur non authentifié', $loginUrl);
        }
        
        return $next($request);
    }
    
    /**
     * Authentifier avec la session SAAS
     */
    private function authenticateWithSaasSession(Request $request): bool
    {
        $apiSessionId = $request->cookie('starehe_session') 
            ?? $request->cookie('laravel_session');
        
        if (!$apiSessionId) {
            return false;
        }
        
        try {
            $session = DB::connection('mysql_saas')->table('sessions')
                ->where('id', $apiSessionId)->first();
            
            if (!$session) {
                Log::warning('SSO: Session SAAS introuvable en DB');
                return false;
            }
            
            if ((time() - $session->last_activity) > 7200) {
                Log::warning('SSO: Session SAAS expirée');
                return false;
            }
            
            $userId = null;
            if (!empty($session->payload)) {
                $payload = @unserialize(base64_decode($session->payload));
                if (is_array($payload)) {
                    foreach ($payload as $key => $value) {
                        if (is_string($key) && str_starts_with($key, 'login_web_')) {
                            $userId = $value;
                            break;
                        }
                    }
                }
            }
            
            if (!$userId) {
                Log::warning('SSO: UserId non trouvé dans payload SAAS');
                return false;
            }
            
            $saasUser = DB::connection('mysql_saas')->table('user')
                ->where('id', $userId)->first();
            
            if (!$saasUser) {
                Log::warning('SSO: Utilisateur SAAS introuvable');
                return false;
            }
            
            $sharedUser = [
                'id' => $saasUser->id,
                'nom' => $saasUser->nom ?? '',
                'prenom' => $saasUser->prenom ?? '',
                'name' => trim(($saasUser->prenom ?? '') . ' ' . ($saasUser->nom ?? '')),
                'email' => $saasUser->email ?? '',
                'telephone' => $saasUser->telephone ?? '',
                'adresse' => $saasUser->adresse ?? null,
                'type_profil' => $saasUser->type_profil ?? '',
                'statut' => $saasUser->statut ?? 'Actif',
            ];
            
            $client = Client::syncFromSharedUser($sharedUser);
            $request->merge(['shared_user' => $sharedUser, 'client' => $client]);
            
            return true;
            
        } catch (\Throwable $e) {
            Log::error('SSO ERROR (SAAS)', ['message' => $e->getMessage()]);
            return false;
        }
    }
    
    /**
     * Authentifier avec la session POS
     */
    private function authenticateWithPosSession(Request $request): bool
    {
        $posSessionId = $request->cookie('pos_session');
        
        if (!$posSessionId) {
            return false;
        }
        
        $sessionFile = storage_path('framework/sessions/' . $posSessionId);
        
        if (!file_exists($sessionFile)) {
            Log::warning('SSO: Fichier session POS introuvable', ['file' => $sessionFile]);
            return false;
        }
        
        $content = file_get_contents($sessionFile);
        $data = @unserialize($content);
        
        if (!is_array($data)) {
            Log::warning('SSO: Données session POS invalides');
            return false;
        }
        
        foreach ($data as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'login_web_')) {
                $user = \App\Models\Utilisateur::find($value);
                if ($user && $user->statut === 'ACTIF' && !$user->compte_verrouille) {
                    \Illuminate\Support\Facades\Auth::setUser($user);
                    return true;
                }
            }
        }
        
        Log::warning('SSO: Utilisateur POS non trouvé dans la session');
        return false;
    }
    
    /**
     * Rediriger ou retourner JSON selon le type de requête
     */
    private function redirectOrJson(Request $request, string $message, string $loginUrl, int $code = 401): Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'success' => false,
                'message' => $message,
                'redirect' => $loginUrl
            ], $code);
        }
        
        return redirect($loginUrl);
    }
}