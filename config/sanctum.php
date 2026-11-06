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



    'guard' => ['web'],  // ✅ Décommenté et unique



    /*

    |-------------------------------------------------------------------------

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

    | Sanctum Middleware

    |--------------------------------------------------------------------------

    | ⚠️ LAISSER VIDE car ces middlewares sont déjà dans bootstrap/app.php

    | Les définir ici cause une DOUBLE EXÉCUTION qui détruit la session !

    */



    'middleware' => [

        'authenticate_session' => null,  // ✅ Désactivé (déjà dans app.php)

        'encrypt_cookies' => null,        // ✅ Désactivé (déjà dans app.php)

        'validate_csrf_token' => null,    // ✅ Désactivé (déjà dans app.php)

    ],



    /*

    |--------------------------------------------------------------------------

    | Token Abilities (Permissions)

    |--------------------------------------------------------------------------

    */



    'abilities' => [

        '*',

        'create-agences',

        'view-all-agences',

        'edit-agences',

        'block-agences',

        'create-superviseurs',

        'manage-superviseurs',

        'manage-commissions',

        'view-commissions',

        'manage-produits-financiers',

        'manage-limites-transactionnelles',

        'view-rapports-globaux',

        'export-rapports-globaux',

        'view-users',

        'view-all-users',

        'create-users',

        'edit-users',

        'delete-users',

        'block-users',

        'manage-all-users',

        'manage-user-roles',

        'activate-users',

        'deactivate-users',

        'reset-user-password',

        'export-users',

        'import-users',

        'manage-partenaires',

        'view-partenaires',

        'create-guichetiers',

        'manage-guichetiers',

        'validate-transactions-importantes',

        'view-all-transactions-agence',

        'cloture-journaliere',

        'manage-ecarts-caisse',

        'view-ecarts-caisse',

        'view-rapports-journaliers',

        'export-rapports-journaliers',

        'manage-kyc',

        'validate-kyc',

        'view-kyc',

        'manage-stock-agence',

        'view-stock-agence',

        'view-alertes-fraude',

        'manage-alertes-fraude',

        'encaissement',

        'decaissement',

        'consultation-solde-caisse',

        'mobile-money-depot',

        'mobile-money-retrait',

        'mobile-money-transfert',

        'change-achat',

        'change-vente',

        'paiement-facture',

        'vente-accessoires',

        'impression-recus',

        'lecture-seule',

        'consultation-historique-transactions',

        'export-rapports-audit',

        'verification-logs',

        'suivi-anomalies',

        'consult-transactions-partenaires',

        'view-rapports-dedies',

        'api-integration',

        'reconciliation',

        'view-flux-financiers',

        'export-comptable',

        'reconciliation-multi-agences',

        'suivi-commissions',

        'gestion-tva-taxes',

        'view-dashboard',

        'view-analytics',

        'view-roles',

        'create-roles',

        'edit-roles',

        'delete-roles',

        'view-permissions',

        'manage-permissions',

        'view-clients',

        'create-clients',

        'edit-clients',

        'delete-clients',

        'view-produits',

        'create-produits',

        'edit-produits',

        'delete-produits',

        'view-stocks',

        'manage-stock',

        'view-factures',

        'create-factures',

        'edit-factures',

        'delete-factures',

        'view-paiements',

        'process-paiements',

        'view-rapports',

        'export-rapports',

        'view-audit-logs',

        'export-audit-logs',

        'view-backups',

        'create-backups',

        'restore-backups',

    ],



    'default_abilities' => [

        'view-dashboard',

        'consultation-solde-caisse',

    ],



    'ability_groups' => [

        'super_admin' => ['*'],

        

        'auditeur' => [

            'lecture-seule',

            'consultation-historique-transactions',

            'export-rapports-audit',

            'verification-logs',

            'suivi-anomalies',

            'view-all-agences',

            'view-commissions',

            'view-rapports-globaux',

            'view-flux-financiers',

            'view-alertes-fraude',

            'view-kyc',

            'view-stock-agence',

            'view-ecarts-caisse',

            'view-rapports-journaliers',

            'view-dashboard',

            'view-analytics',

            'view-users',

            'view-all-users',

        ],

        

        'comptable_holding' => [

            'view-flux-financiers',

            'export-comptable',

            'reconciliation-multi-agences',

            'suivi-commissions',

            'gestion-tva-taxes',

            'view-rapports-globaux',

            'export-rapports-globaux',

            'view-commissions',

            'view-dashboard',

            'view-analytics',

        ],

        

        'chef_agence' => [

            'create-guichetiers',

            'manage-guichetiers',

            'validate-transactions-importantes',

            'view-all-transactions-agence',

            'cloture-journaliere',

            'manage-ecarts-caisse',

            'view-ecarts-caisse',

            'view-rapports-journaliers',

            'export-rapports-journaliers',

            'manage-kyc',

            'validate-kyc',

            'view-kyc',

            'manage-stock-agence',

            'view-stock-agence',

            'view-alertes-fraude',

            'manage-alertes-fraude',

            'encaissement',

            'decaissement',

            'consultation-solde-caisse',

            'mobile-money-depot',

            'mobile-money-retrait',

            'mobile-money-transfert',

            'change-achat',

            'change-vente',

            'paiement-facture',

            'vente-accessoires',

            'impression-recus',

            'view-dashboard',

            'view-users',

        ],

        

        'guichetier' => [

            'encaissement',

            'decaissement',

            'consultation-solde-caisse',

            'mobile-money-depot',

            'mobile-money-retrait',

            'mobile-money-transfert',

            'change-achat',

            'change-vente',

            'paiement-facture',

            'vente-accessoires',

            'impression-recus',

            'view-dashboard',

        ],

        

        'partenaire' => [

            'consult-transactions-partenaires',

            'view-rapports-dedies',

            'api-integration',

            'reconciliation',

        ],

    ],



    'first_party_abilities' => [

        '*',

    ],

];