<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\Utilisateur;
use Symfony\Component\HttpFoundation\Response;

class CheckPosCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        // Récupérer pos_session
        $posSessionId = $request->cookie('pos_session');

        Log::info('CheckPosCookie: pos_session', [
            'pos_session' => $posSessionId ? 'OK (' . strlen($posSessionId) . ' chars)' : 'ABSENT',
            'all_cookies' => $request->cookies->keys(),
        ]);

        if (Auth::check()) {
            Log::info('CheckPosCookie: Déjà authentifié', ['user_id' => Auth::id()]);
            return $next($request);
        }

        if (!$posSessionId) {
            Log::warning('CheckPosCookie: pos_session ABSENT');
            return response()->json(['success' => false, 'message' => 'Session manquante'], 401);
        }

        // Chercher dans le fichier de session (MÊME LOGIQUE QUE /api/sso-user)
        $sessionFile = storage_path('framework/sessions/' . $posSessionId);

        Log::info('CheckPosCookie: Recherche fichier', [
            'file' => $sessionFile,
            'exists' => file_exists($sessionFile) ? 'OUI' : 'NON',
        ]);

        if (file_exists($sessionFile)) {
            $content = file_get_contents($sessionFile);
            $data = @unserialize($content);

            if (is_array($data)) {
                foreach ($data as $key => $value) {
                    if (is_string($key) && str_starts_with($key, 'login_web_')) {
                        $user = Utilisateur::find($value);

                        if ($user && $user->statut === 'ACTIF' && !$user->compte_verrouille) {
                            Auth::login($user, false);
                            Log::info('CheckPosCookie:  Connecté via fichier', [
                                'user_id' => $user->id,
                                'nom' => $user->nom_complet,
                            ]);
                            return $next($request);
                        }
                    }
                }
            }
        }

        // Chercher en base de données
        $session = DB::table('sessions')->where('id', $posSessionId)->first();
        if ($session) {
            $payload = @unserialize(base64_decode($session->payload));
            if (is_array($payload)) {
                foreach ($payload as $key => $value) {
                    if (is_string($key) && str_starts_with($key, 'login_web_')) {
                        $user = Utilisateur::find($value);
                        if ($user && $user->statut === 'ACTIF' && !$user->compte_verrouille) {
                            Auth::login($user, false);
                            Log::info('CheckPosCookie:  Connecté via DB', ['user_id' => $user->id]);
                            return $next($request);
                        }
                    }
                }
            }
        }

        Log::error('CheckPosCookie: Session introuvable');
        return response()->json(['success' => false, 'message' => 'Session invalide'], 401);
    }
}