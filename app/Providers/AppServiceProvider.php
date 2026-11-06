<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Gate;

// Import des modèles et observers
use App\Models\Campagne\CampagneOperation;
use App\Observers\CampagneOperationObserver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Enregistrement des services de l'application.
     */
    public function register(): void
    {
        //
    }

    /**
     * Démarrage des services de l'application.
     */
    public function boot(): void
    {
        // Sécurisation de l'accès à la documentation Scramble via Gate
        Gate::define('viewApiDocs', function ($user = null) {
            // En production, on vérifie l'adresse IP de la requête en cours
            if (app()->isProduction()) {
                return in_array(request()->ip(), ['196.250.113.208']);
            }
            
            return true;
        });

        Schema::defaultStringLength(191);
        
        // ============================================================
        // FORCE HTTPS EN PRODUCTION + CONFIG COOKIE CROSS-DOMAIN
        // ============================================================
        if (app()->isProduction()) {
            URL::forceScheme('https');
            
            // Configuration des cookies pour le partage cross-domain
            // NOTE: session.connection reste 'mysql' (base POS) pour les sessions locales
            config([
                'session.secure' => true,
                'session.same_site' => 'none',
                'session.http_only' => true,
                'session.domain' => '.stareheholding.com',
            ]);
        }
        
        // ============================================================
        // CONFIGURATION DES RATE LIMITERS
        // ============================================================
        
        // Rate limiter pour l'API (utilisé par 'throttle:api')
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Rate limiter pour les tentatives de connexion
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by($request->input('email') ?: $request->ip());
        });

        // Rate limiter pour les routes sensibles
        RateLimiter::for('sensitive', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        // Rate limiter pour le contrôle des expirations de lots FIFO
        RateLimiter::for('expiration', function (Request $request) {
            return Limit::perMinute(1)->by('check-expiring-lots-job');
        });

        // ============================================================
        // ENREGISTREMENT DES OBSERVERS
        // ============================================================
        
        // Déclenche la mise à jour automatique des stocks d'agents
        // à chaque création, modification ou suppression d'une opération.
        CampagneOperation::observe(CampagneOperationObserver::class);
    }
}