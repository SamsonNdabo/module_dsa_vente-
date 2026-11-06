<?php

namespace App\Http\Controllers\Comptabilite;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;

class BroadcastingAuthController extends Controller
{
    /**
     * Authentifier pour les canaux privés Pusher
     */
    public function authenticate(Request $request)
    {
        // Récupérer l'userId depuis les cookies (POS ou SAAS)
        $userId = $this->findUserId($request);

        if (!$userId) {
            Log::warning('Broadcasting: ❌ Utilisateur non trouvé');
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Récupérer les données de la requête Pusher
        $socketId = $request->input('socket_id');
        $channelName = $request->input('channel_name');

        if (!$socketId || !$channelName) {
            return response()->json(['message' => 'Paramètres manquants'], 400);
        }

        // Vérifier que l'utilisateur peut accéder à ce canal
        if (!$this->canAccessChannel($userId, $channelName)) {
            Log::warning('Broadcasting: ❌ Canal non autorisé', [
                'user_id' => $userId,
                'channel' => $channelName,
            ]);
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Générer la signature d'authentification Pusher
        $auth = $this->generatePusherAuth($socketId, $channelName);

        Log::info('Broadcasting:  Authentifié', [
            'user_id' => $userId,
            'channel' => $channelName,
        ]);

        return response()->json(json_decode($auth, true));
    }

    /**
     * Trouver l'userId dans les cookies de session
     */
    private function findUserId(Request $request): ?int
    {
        // Méthode 1 : Session POS
        $posSessionId = $request->cookie('pos_session');
        if ($posSessionId) {
            $sessionFile = storage_path('framework/sessions/' . $posSessionId);
            if (file_exists($sessionFile)) {
                $content = file_get_contents($sessionFile);
                $data = @unserialize($content);
                if (is_array($data)) {
                    foreach ($data as $key => $value) {
                        if (is_string($key) && str_starts_with($key, 'login_web_')) {
                            return (int) $value;
                        }
                    }
                }
            }
        }

        // Méthode 2 : Session SAAS
        $saasSessionId = $request->cookie('starehe_session') 
            ?? $request->cookie('laravel_session');
        
        if ($saasSessionId) {
            try {
                $session = \Illuminate\Support\Facades\DB::connection('mysql_saas')
                    ->table('sessions')
                    ->where('id', $saasSessionId)
                    ->first();
                
                if ($session && !empty($session->payload)) {
                    $payload = @unserialize(base64_decode($session->payload));
                    if (is_array($payload)) {
                        foreach ($payload as $key => $value) {
                            if (is_string($key) && str_starts_with($key, 'login_web_')) {
                                return (int) $value;
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::error('Broadcasting: Erreur session SAAS', ['error' => $e->getMessage()]);
            }
        }

        return null;
    }

    /**
     * Vérifier que l'utilisateur peut accéder au canal
     */
    private function canAccessChannel(int $userId, string $channelName): bool
    {
        // Canaux publics : toujours OK
        if (!str_starts_with($channelName, 'private-') && !str_starts_with($channelName, 'presence-')) {
            return true;
        }

        // Canaux privés : vérifier que le userId correspond
        if (str_starts_with($channelName, 'private-')) {
            // Extraire le dernier segment après le dernier point
            $parts = explode('.', $channelName);
            $lastPart = end($parts);
            
            // Si c'est un nombre, vérifier qu'il correspond au userId
            if (is_numeric($lastPart)) {
                return (int) $lastPart === (int) $userId;
            }
            
            // Sinon, canal sans userId spécifique (ex: private-comptables) → OK
            return true;
        }

        // Canaux presence : OK
        return true;
    }

    /**
     * Générer la signature Pusher
     */
    private function generatePusherAuth(string $socketId, string $channelName): string
    {
        $appKey = config('broadcasting.connections.pusher.key');
        $appSecret = config('broadcasting.connections.pusher.secret');

        // Pour les canaux de présence, on doit ajouter les données utilisateur
        if (str_starts_with($channelName, 'presence-')) {
            $userId = $this->findUserId(request()) ?? 0;
            $userData = json_encode([
                'user_id' => $userId,
                'user_info' => [
                    'id' => $userId,
                    'name' => 'Utilisateur ' . $userId,
                ]
            ]);

            $signature = $appKey . ':' . hash_hmac(
                'sha256',
                $socketId . ':' . $channelName . ':' . $userData,
                $appSecret
            );

            return json_encode([
                'auth' => $signature,
                'channel_data' => $userData,
            ]);
        }

        // Canaux privés simples
        $signature = $appKey . ':' . hash_hmac(
            'sha256',
            $socketId . ':' . $channelName,
            $appSecret
        );

        return json_encode([
            'auth' => $signature,
        ]);
    }
}