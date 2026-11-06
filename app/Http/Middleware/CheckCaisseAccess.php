<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\Utilisateur;
use Symfony\Component\HttpFoundation\Response;

class CheckPosCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        // Récupérer le cookie pos_session (prioritaire) ou laravel_session
        $sessionId = $request->cookie('pos_session');

        Log::info('POS-AUTH: Cookies reçus', [
            'pos_session' => $request->cookie('pos_session') ? 'OK' : 'ABSENT',
            'laravel_session' => $request->cookie('laravel_session') ? 'OK' : 'ABSENT',
        ]);

        if (!$sessionId) {
            Log::warning('POS-AUTH: Cookie pos_session manquant');
            
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Session POS manquante'
                ], 401);
            }
            return redirect()->route('login');
        }

        // Si déjà connecté, on laisse passer
        if (Auth::check()) {
            Log::info('POS-AUTH: Déjà authentifié', [
                'user_id' => Auth::id(),
            ]);
            return $next($request);
        }

        try {
            // Décoder le cookie pos_session (JSON)
            $posData = json_decode($sessionId, true);

            if (!$posData || !isset($posData['id'])) {
                Log::error('POS-AUTH: Cookie pos_session invalide', [
                    'data' => $posData,
                ]);
                
                if ($request->expectsJson() || $request->is('api/*')) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cookie POS invalide'
                    ], 401);
                }
                return redirect()->route('login');
            }

            $userId = $posData['id'];

            // Chercher l'utilisateur dans la table utilisateurs
            $utilisateur = Utilisateur::find($userId);

            if (!$utilisateur) {
                Log::error('POS-AUTH: Utilisateur introuvable', [
                    'user_id' => $userId,
                ]);
                
                if ($request->expectsJson() || $request->is('api/*')) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Utilisateur introuvable'
                    ], 401);
                }
                return redirect()->route('login');
            }

            // Vérifier si le compte est actif
            if ($utilisateur->statut !== 'ACTIF' || $utilisateur->compte_verrouille) {
                Log::warning('POS-AUTH: Compte inactif ou verrouillé', [
                    'user_id' => $userId,
                    'statut' => $utilisateur->statut,
                    'verrouille' => $utilisateur->compte_verrouille,
                ]);
                
                if ($request->expectsJson() || $request->is('api/*')) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Compte inactif ou verrouillé'
                    ], 401);
                }
                return redirect()->route('login');
            }

            //  SUCCÈS - Connecter l'utilisateur
            Auth::setUser($utilisateur);

            // Ajouter les infos utilisateur dans la requête (comme SharedSessionAuth)
            $request->merge([
                'pos_user' => [
                    'id' => $utilisateur->id,
                    'nom_complet' => $utilisateur->nom_complet,
                    'nom_utilisateur' => $utilisateur->nom_utilisateur,
                    'email' => $utilisateur->email,
                    'matricule' => $utilisateur->matricule,
                    'role_id' => $utilisateur->role_id,
                    'succursale_id' => $utilisateur->succursale_id,
                    'statut' => $utilisateur->statut,
                ],
            ]);

            Log::info('POS-AUTH:  SUCCÈS', [
                'user_id' => $utilisateur->id,
                'nom' => $utilisateur->nom_complet,
                'email' => $utilisateur->email,
            ]);

            return $next($request);

        } catch (\Throwable $e) {
            Log::error('POS-AUTH: ERREUR', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur serveur authentification POS'
                ], 500);
            }

            return redirect()->route('login');
        }
    }
}