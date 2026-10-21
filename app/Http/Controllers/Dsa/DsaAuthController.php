<?php

namespace App\Http\Controllers\Dsa;

use App\Enums\AgentStatut;
use App\Http\Controllers\Controller;
use App\Http\Resources\AgentDsaResource;
use App\Models\Utilisateur;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Login mobile des agents : sans session, avec jeton Sanctum.
 * Le jeton n'est émis qu'une fois mot de passe ET 2FA validés dans la même requête.
 */
class DsaAuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'nom_utilisateur' => 'required|string|max:255',
            'mot_de_passe' => 'required|string',
            'device_name' => 'required|string|max:100',
            'two_factor_code' => 'nullable|string|size:6',
        ]);

        $cle = Str::lower($data['nom_utilisateur']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($cle, 5)) {
            return $this->erreur('Trop de tentatives. Réessayez dans '.RateLimiter::availableIn($cle).' secondes.', 429);
        }

        $user = Utilisateur::where(
            fn ($q) => $q->where('nom_utilisateur', $data['nom_utilisateur'])->orWhere('email', $data['nom_utilisateur'])
        )->first();

        if (! $user || ! $user->verifierMotDePasse($data['mot_de_passe'])) {
            RateLimiter::hit($cle, 300);
            $user?->incrementerTentatives();

            return $this->erreur('Identifiants incorrects', 401);
        }

        if ($user->compte_verrouille) {
            return $this->erreur('Compte verrouillé', 403);
        }

        $agent = $user->agentDsa;

        if ($user->statut !== 'ACTIF'
            || ! $user->hasRole('AGENT_DSA')
            || ! $agent
            || $agent->statut !== AgentStatut::ACTIF) {
            return $this->erreur('Accès DSA non autorisé pour ce compte', 403);
        }

        if ($user->two_factor_email_enabled) {
            if (blank($data['two_factor_code'] ?? null)) {
                $user->genererEtEnvoyerCode2FAEmail();

                return response()->json([
                    'success' => false,
                    'requires_2fa' => true,
                    'type' => 'email',
                    'message' => 'Code envoyé par email',
                ], 202);
            }

            if (! $user->verifierCode2FAEmail($data['two_factor_code'])) {
                RateLimiter::hit($cle, 300);

                return $this->erreur('Code invalide ou expiré', 401);
            }
        }

        RateLimiter::clear($cle);
        $user->enregistrerConnexion($request);

        $nomJeton = 'dsa-mobile:'.$data['device_name'];
        $user->tokens()->where('name', $nomJeton)->delete(); // un seul jeton par appareil
        $jeton = $user->createToken($nomJeton, ['dsa:agent'], now()->addHours(12));

        return response()->json([
            'success' => true,
            'token' => $jeton->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $jeton->accessToken->expires_at?->toISOString(),
            'agent' => new AgentDsaResource($agent->load(['utilisateur', 'zone'])),
            'permissions' => $user->permissions_liste,
        ]);
    }

    public function me(Request $request)
    {
        $agent = $request->user()->agentDsa()->with(['utilisateur', 'zone'])->firstOrFail();

        return response()->json([
            'success' => true,
            'agent' => new AgentDsaResource($agent),
            'permissions' => $request->user()->permissions_liste,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        $request->user()->deconnecter($request);

        return response()->json(['success' => true, 'message' => 'Déconnexion réussie']);
    }

    private function erreur(string $message, int $code)
    {
        return response()->json(['success' => false, 'error' => $message], $code);
    }
}
