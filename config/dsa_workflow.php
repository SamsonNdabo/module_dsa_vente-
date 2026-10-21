<?php

use App\Workflow\CompteHandler;
use App\Workflow\KycHandler;

/*
 * Moteur de workflow : graphe de transitions fermé (cahier §19) + permission requise par transition.
 * 'systeme' = transition réservée au moteur.
 * Les libellés / l'ordre / le caractère terminal par partenaire viennent de la table statuts_configurables
 * (MCD) ; ce fichier fournit le graphe et les valeurs par défaut.
 */
return [

    'KYC' => [
        'handler' => KycHandler::class,
        'initial' => 'BROUILLON',
        'terminaux' => ['VALIDE', 'REJETE'],
        'etats_soumission' => ['SOUMIS'],
        'etats_decision' => ['VALIDE', 'REJETE', 'A_CORRIGER'],
        'motif_obligatoire' => ['REJETE', 'A_CORRIGER'],
        'transitions' => [
            'BROUILLON' => ['SOUMIS' => 'dsa.kyc.soumettre'],
            'SOUMIS' => [
                'VALIDE' => 'dsa.kyc.valider',
                'REJETE' => 'dsa.kyc.valider',
                'A_CORRIGER' => 'dsa.kyc.valider',
            ],
            'A_CORRIGER' => ['SOUMIS' => 'dsa.kyc.soumettre'],
        ],
    ],

    'OUVERTURE_COMPTE' => [
        'handler' => CompteHandler::class,
        'initial' => 'PROSPECT',
        'terminaux' => ['ACTIF', 'REJETE'],
        'etats_soumission' => ['DOSSIER_SOUMIS'],
        'etats_decision' => ['COMPTE_OUVERT', 'REJETE'],
        'motif_obligatoire' => ['REJETE'],
        'transitions' => [
            'PROSPECT' => [
                'DOSSIER_SOUMIS' => 'dsa.comptes.soumettre',
                'REJETE' => 'dsa.comptes.ouvrir',
            ],
            'DOSSIER_SOUMIS' => [
                'KYC_VALIDE' => 'systeme',          // exigences KYC du partenaire satisfaites
                'REJETE' => 'dsa.comptes.ouvrir',
            ],
            'KYC_VALIDE' => [
                'COMPTE_OUVERT' => 'dsa.comptes.ouvrir',
                'REJETE' => 'dsa.comptes.ouvrir',
            ],
            'COMPTE_OUVERT' => [
                'ACTIF' => 'dsa.comptes.activer',
                'REJETE' => 'dsa.comptes.ouvrir',
            ],
        ],
    ],

    // Dossier KYC du client (indépendant du partenaire) : minimum pour pouvoir le soumettre.
    'kyc_dossier_minimum' => ['pieces' => 1, 'signature' => true],

    // Que faire si un partenaire/produit n'a AUCUNE règle KYC ? 'bloquer' (recommandé) ou 'defaut'.
    'kyc_sans_regle' => 'bloquer',

    // Utilisé seulement si kyc_sans_regle = 'defaut' ; IDENTITE = une pièce d'identité au choix.
    'kyc_defaut' => [
        'pieces_identite' => ['CARTE_IDENTITE', 'PASSEPORT', 'CARTE_ELECTEUR', 'PERMIS', 'AUTRE_PIECE'],
        'regles' => ['IDENTITE', 'PHOTO_CLIENT', 'SIGNATURE'],
    ],

    'libelles' => [
        'BROUILLON' => 'Brouillon', 'SOUMIS' => 'Soumis', 'VALIDE' => 'Validé', 'REJETE' => 'Rejeté',
        'A_CORRIGER' => 'À corriger', 'PROSPECT' => 'Prospect', 'DOSSIER_SOUMIS' => 'Dossier soumis',
        'KYC_VALIDE' => 'KYC validé', 'COMPTE_OUVERT' => 'Compte ouvert', 'ACTIF' => 'Actif',
    ],
];
