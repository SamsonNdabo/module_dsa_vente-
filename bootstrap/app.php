<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))

    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )

    ->withMiddleware(function (Middleware $middleware) {

        /*
        |--------------------------------------------------------------------------
        | Alias Middleware
        |--------------------------------------------------------------------------
        */

        $middleware->alias([
            'auth' => \Illuminate\Auth\Middleware\Authenticate::class,
            'auth.basic' => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
            'auth.session' => \Illuminate\Session\Middleware\AuthenticateSession::class,
            'cache.headers' => \Illuminate\Http\Middleware\SetCacheHeaders::class,
            'can' => \Illuminate\Auth\Middleware\Authorize::class,

            // Middlewares personnalisés
            'permission' => \App\Http\Middleware\PermissionMiddleware::class,
            'succursale' => \App\Http\Middleware\SuccursaleMiddleware::class,
            'caisse.access' => \App\Http\Middleware\CheckCaisseAccess::class,
            '2fa' => \App\Http\Middleware\TwoFactorMiddleware::class,
            'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class,
            'password.confirm' => \Illuminate\Auth\Middleware\RequirePassword::class,
            'signed' => \Illuminate\Routing\Middleware\ValidateSignature::class,
            'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
            'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,

            // Sanctum
            'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            'ability' => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,

            // Tes middlewares
            'shared.session' => \App\Http\Middleware\SharedSessionAuth::class,
            'api.key' => \App\Http\Middleware\ApiKeyMiddleware::class,
            'comptable.holding' => \App\Http\Middleware\ComptableHoldingMiddleware::class,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Groupe WEB
        |--------------------------------------------------------------------------
        */

        $middleware->group('web', [
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,

            // ✅ AJOUT INERTIA
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Groupe API Laravel 12 + Sanctum
        |--------------------------------------------------------------------------
        */

        $middleware->group('api', [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class.':api',
        ]);

        /*
        |--------------------------------------------------------------------------
        | CORS doit passer avant tout
        |--------------------------------------------------------------------------
        */

        $middleware->prepend(
            \Illuminate\Http\Middleware\HandleCors::class
        );

    })

    ->withExceptions(function (Exceptions $exceptions): void {

        /*
        |--------------------------------------------------------------------------
        | Retour JSON pour API au lieu de redirection login
        |--------------------------------------------------------------------------
        */

        $exceptions->renderable(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Non authentifié',
                    'error' => 'AuthenticationException',
                ], 401, [
                    'Content-Type' => 'application/json; charset=UTF-8'
                ], JSON_UNESCAPED_UNICODE);
            }
        });

        /*
        |--------------------------------------------------------------------------
        | ✅ GESTION DES ERREURS GÉNÉRALES AVEC NETTOYAGE UTF-8
        |--------------------------------------------------------------------------
        */

        $exceptions->renderable(function (\Throwable $e, Request $request) {
            
            // Vérifier si c'est une requête API ou JSON
            if (
                $request->is('api/*') ||
                $request->expectsJson() ||
                $request->ajax() ||
                $request->header('X-Requested-With') === 'XMLHttpRequest'
            ) {
                
                // ✅ Nettoyer le message d'erreur
                $message = $e->getMessage();
                
                // Vérifier si le message est valide en UTF-8
                if (!mb_check_encoding($message, 'UTF-8')) {
                    $detectedEncoding = mb_detect_encoding($message, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);
                    
                    if ($detectedEncoding && $detectedEncoding !== 'UTF-8') {
                        $message = mb_convert_encoding($message, 'UTF-8', $detectedEncoding);
                    } else {
                        $message = mb_convert_encoding($message, 'UTF-8', 'ISO-8859-1');
                    }
                }
                
                // Supprimer les caractères UTF-8 invalides
                $message = iconv('UTF-8', 'UTF-8//IGNORE', $message);
                
                // Supprimer les caractères de contrôle problématiques
                $message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $message);
                
                // ✅ Déterminer le code HTTP approprié
                $statusCode = 500;
                
                if (method_exists($e, 'getStatusCode')) {
                    $statusCode = $e->getStatusCode();
                } elseif ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                    $statusCode = 404;
                } elseif ($e instanceof \Illuminate\Validation\ValidationException) {
                    $statusCode = 422;
                } elseif ($e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
                    $statusCode = 404;
                } elseif ($e instanceof \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException) {
                    $statusCode = 405;
                } elseif ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
                    $statusCode = $e->getStatusCode();
                } elseif ($e instanceof \Illuminate\Auth\Access\AuthorizationException) {
                    $statusCode = 403;
                } elseif ($e instanceof \Illuminate\Database\QueryException) {
                    $statusCode = 500;
                    // Ne pas exposer les détails SQL en production
                    if (!config('app.debug')) {
                        $message = 'Erreur de base de données';
                    }
                }
                
                // ✅ Journaliser l'erreur
                \Illuminate\Support\Facades\Log::error('Exception traitée', [
                    'original_message' => $e->getMessage(),
                    'cleaned_message' => $message,
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'url' => $request->fullUrl(),
                    'method' => $request->method(),
                    'ip' => $request->ip(),
                ]);
                
                // ✅ Ajouter les erreurs de validation si présentes
                $errors = null;
                if ($e instanceof \Illuminate\Validation\ValidationException) {
                    $errors = $e->errors();
                }
                
                // ✅ Construire la réponse JSON
                $responseData = [
                    'success' => false,
                    'message' => $message,
                    'error' => class_basename($e),
                ];
                
                if ($errors) {
                    $responseData['errors'] = $errors;
                }
                
                // En mode debug, ajouter les détails
                if (config('app.debug')) {
                    $responseData['file'] = $e->getFile();
                    $responseData['line'] = $e->getLine();
                    $responseData['trace'] = $e->getTraceAsString();
                }
                
                // ✅ Retourner la réponse JSON propre
                return response()->json(
                    $responseData,
                    $statusCode,
                    ['Content-Type' => 'application/json; charset=UTF-8'],
                    JSON_UNESCAPED_UNICODE
                );
            }
            
            // Pour les requêtes non-API, laisser le comportement par défaut
            return null;
        });

        /*
        |--------------------------------------------------------------------------
        | ✅ IGNORER LES ERREURS DE WEBSOCKET/BFCACHE
        |--------------------------------------------------------------------------
        | Ces erreurs sont bénignes et ne doivent pas être journalisées
        |--------------------------------------------------------------------------
        */

        $exceptions->reportable(function (\Throwable $e) {
            // Ignorer les erreurs liées au WebSocket/BFCache
            $message = $e->getMessage();
            
            if (
                str_contains($message, 'Back-Forward Cache') ||
                str_contains($message, 'WebSocket connection') ||
                str_contains($message, 'Pusher') ||
                str_contains($message, 'Connection closed') ||
                str_contains($message, 'Connection refused')
            ) {
                return false; // Ne pas journaliser
            }
            
            return true;
        });

    })

    ->create();