<?php

use Illuminate\Support\Str;

return [

    'driver' => env('SESSION_DRIVER', 'database'),

    'lifetime' => (int) env('SESSION_LIFETIME', 120),
    'expire_on_close' => false,

    'encrypt' => false,

    'files' => storage_path('framework/sessions'),

    'connection' => env('SESSION_CONNECTION'),
    'table' => env('SESSION_TABLE', 'sessions'),

    'store' => env('SESSION_STORE'),

    'lottery' => [2, 100],

    /*
    🔥 FIX IMPORTANT :
    DOIT être IDENTIQUE sur POS + API + SITE PRINCIPAL
    */
    'cookie' => env('SESSION_COOKIE', 'pos_session'),

    'path' => '/',

    /*
    🔥 CRITIQUE :
    permet partage entre sous-domaines
    */
    'domain' => env('SESSION_DOMAIN', '.stareheholding.com'),

    /*
    🔥 HTTPS obligatoire en production
    */
    'secure' => env('SESSION_SECURE_COOKIE', true),

    'http_only' => true,

    /*
    🔥 IMPORTANT POUR API + SPA
    */
    'same_site' => 'none',  //  Doit être 'none', pas 'lax'

    'partitioned' => false,
];