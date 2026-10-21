<?php

use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    */
    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        Sanctum::currentApplicationUrlWithPort()
    ))),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    */
    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    */
    'expiration' => env('SANCTUM_TOKEN_EXPIRATION', 480),

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    */
    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware — LAISSER PAR DÉFAUT
    |--------------------------------------------------------------------------
    | ⚠️ Ne pas mettre null. Sanctum n'ajoute ces middlewares QUE pour les
    | requêtes stateful (via EnsureFrontendRequestsAreStateful), donc pas
    | de double exécution. Les mettre à null désactive CSRF + cookies
    | chiffrés + invalidation session → faille de sécurité.
    |--------------------------------------------------------------------------
    */
    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies'      => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token'  => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | ⚠️ SUPPRIMÉ : abilities, default_abilities, ability_groups,
    | first_party_abilities — ces clés ne sont PAS lues par Sanctum.
    |--------------------------------------------------------------------------
    | Si tu utilises ces listes dans ton code, déplace-les dans
    | un fichier dédié : config/permissions.php
    */
];