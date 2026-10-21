<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Gate;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
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
        // âœ… FORCE HTTPS EN PRODUCTION + CONFIG COOKIE CROSS-DOMAIN
        // ============================================================
        if (app()->isProduction()) {
            URL::forceScheme('https');
            
            // ðŸ”¥ Configuration des cookies pour le partage cross-domain
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
        
        // Rate limiter pour l'API (utilisÃ© par 'throttle:api')
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
    }
}