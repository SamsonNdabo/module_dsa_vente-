<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    //  AJOUTER 'broadcasting/auth' pour permettre l'authentification Pusher/Reverb
    'paths' => [
        'api/*',                    
        'api/auth/*',              
        'api/caisse/*',
        'api/caisse',  
        'auth/*', 
        'api/approvisionnements-stock',
        'api/approvisionnements-stock/*',
        // j'ai ajouté une virgule sur la ligne ci-dessous ça renvoyait une erreur...
        'api/commandes/*',
        'login',
        'logout',
        'register',
        'sanctum/csrf-cookie',
        'broadcasting/auth',           // ← AJOUTÉ pour l'authentification WebSocket
        'request-email-code',
        'verify-email-code',
        'enable-email-2fa',        
        'disable-email-2fa',       
        '2fa-email-status',        
        'fournisseurs/*',          
        'rfq/*',                   
        'devis/*',                 
        'commandes-fournisseurs/*',
        'receptions-fournisseurs/*',
        'paiements-fournisseurs/*',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'https://stareheholding.com',
        'https://www.stareheholding.com',
        'https://api.stareheholding.com',      // ← AJOUTÉ (modèle)
        'https://pos-beta.stareheholding.com',
        'https://shop.stareheholding.com',
        'https://www.pos-beta.stareheholding.com',
        'https://apipos-beta.stareheholding.com', 
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://localhost:3000',
    ],

    'allowed_origins_patterns' => [
        '/^https:\/\/.*\.stareheholding\.com$/'
    ],

    'allowed_headers' => ['*'],  // ← MODIFIÉ (comme le modèle, plus simple et plus permissif)

    'exposed_headers' => [        // ← MODIFIÉ (comme le modèle)
        'Authorization',
        'X-CSRF-TOKEN',      
        'X-XSRF-TOKEN',        // Pour les cookies de session
    ],

    'max_age' => 86400,

    'supports_credentials' => true,

];