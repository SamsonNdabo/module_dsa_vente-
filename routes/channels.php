<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| CANAL UTILISATEUR (Défaut Laravel)
|--------------------------------------------------------------------------
*/
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/*
|--------------------------------------------------------------------------
| CANAL TRANSACTIONS EXTERNES
|--------------------------------------------------------------------------
*/
Broadcast::channel('transactions-externes', function ($user) {
    return $user !== null;
});

/*
|--------------------------------------------------------------------------
| ÉCRITURES COMPTABLES CHANNELS
| (Balance, Journal, Grand Livre)
|--------------------------------------------------------------------------
*/

// Canal général pour toutes les écritures
Broadcast::channel('ecritures-comptables', function ($user) {
    return $user !== null;
});

// Canal privé pour les écritures d'un utilisateur spécifique
Broadcast::channel('ecritures.{utilisateurId}', function ($user, $utilisateurId) {
    return $user !== null && (int) $user->id === (int) $utilisateurId;
});

/*
|--------------------------------------------------------------------------
| PLAN COMPTABLE CHANNEL
|--------------------------------------------------------------------------
*/
Broadcast::channel('plan-comptable', function ($user) {
    return $user !== null;
});

/*
|--------------------------------------------------------------------------
| BILAN CHANNEL
|--------------------------------------------------------------------------
*/
Broadcast::channel('bilan-updates', function ($user) {
    return $user !== null;
});

/*
|--------------------------------------------------------------------------
| 🆕 NOTIFICATIONS COMPTABLES CHANNELS
|--------------------------------------------------------------------------
*/

// Canal privé pour les notifications d'un utilisateur spécifique
Broadcast::channel('notifications.{utilisateurId}', function ($user, $utilisateurId) {
    return $user !== null && (int) $user->id === (int) $utilisateurId;
});

/*
|--------------------------------------------------------------------------
| 🆕 SYSTEM LOGS CHANNELS
|--------------------------------------------------------------------------
*/

// Canal privé pour les logs système d'un utilisateur
Broadcast::channel('system-logs.{utilisateurId}', function ($user, $utilisateurId) {
    return $user !== null && (int) $user->id === (int) $utilisateurId;
});

/*
|--------------------------------------------------------------------------
| 🆕 BUDGET ALERTS CHANNELS
|--------------------------------------------------------------------------
*/

// Canal privé pour les alertes budgétaires d'un utilisateur
Broadcast::channel('budget-alerts.{utilisateurId}', function ($user, $utilisateurId) {
    return $user !== null && (int) $user->id === (int) $utilisateurId;
});

/*
|--------------------------------------------------------------------------
| 🆕 CANAL COMPTABLES (Rôles spécifiques)
|--------------------------------------------------------------------------
*/

// Canal pour tous les comptables et auditeurs
Broadcast::channel('comptables', function ($user) {
    if (!$user) return false;
    
    // Vérifier si l'utilisateur a un rôle comptable
    $rolesAutorises = ['SUPER_ADMIN', 'COMPTABLE_HOLDING', 'AUDITEUR', 'CHEF_AGENCE', 'CHEF_OPERATIONS'];
    
    return $user->role && in_array($user->role->code, $rolesAutorises);
});

/*
|--------------------------------------------------------------------------
| 🆕 SUIVI BUDGÉTAIRE CHANNELS
|--------------------------------------------------------------------------
*/

// Canal pour les mises à jour du suivi budgétaire
Broadcast::channel('suivi-budgetaire.{utilisateurId}', function ($user, $utilisateurId) {
    return $user !== null && (int) $user->id === (int) $utilisateurId;
});

/*
|--------------------------------------------------------------------------
| 🆕 PÉRIODES COMPTABLES CHANNELS
|--------------------------------------------------------------------------
*/

// Canal pour les changements de périodes comptables
Broadcast::channel('periodes-comptables', function ($user) {
    if (!$user) return false;
    
    $rolesAutorises = ['SUPER_ADMIN', 'COMPTABLE_HOLDING', 'AUDITEUR'];
    
    return $user->role && in_array($user->role->code, $rolesAutorises);
});

/*
|--------------------------------------------------------------------------
| 🆕 IMMOBILISATIONS CHANNELS
|--------------------------------------------------------------------------
*/

// Canal pour les notifications d'immobilisations
Broadcast::channel('immobilisations.{utilisateurId}', function ($user, $utilisateurId) {
    return $user !== null && (int) $user->id === (int) $utilisateurId;
});

/*
|--------------------------------------------------------------------------
| 🆕 TVA CHANNELS
|--------------------------------------------------------------------------
*/

// Canal pour les déclarations TVA
Broadcast::channel('tva.{utilisateurId}', function ($user, $utilisateurId) {
    return $user !== null && (int) $user->id === (int) $utilisateurId;
});

/*
|--------------------------------------------------------------------------
| 🆕 RAPPROCHEMENTS BANCAIRES CHANNELS
|--------------------------------------------------------------------------
*/

// Canal pour les rapprochements bancaires
Broadcast::channel('rapprochements.{utilisateurId}', function ($user, $utilisateurId) {
    return $user !== null && (int) $user->id === (int) $utilisateurId;
});

/*
|--------------------------------------------------------------------------
| 🆕 JOURNAL & GRAND LIVRE CHANNELS
|--------------------------------------------------------------------------
*/

// Canal pour le journal comptable
Broadcast::channel('journal.{utilisateurId}', function ($user, $utilisateurId) {
    return $user !== null && (int) $user->id === (int) $utilisateurId;
});

// Canal pour le grand livre
Broadcast::channel('grand-livre.{utilisateurId}', function ($user, $utilisateurId) {
    return $user !== null && (int) $user->id === (int) $utilisateurId;
});

/*
|--------------------------------------------------------------------------
| 🆕 BALANCE CHANNELS
|--------------------------------------------------------------------------
*/

// Canal pour la balance comptable
Broadcast::channel('balance.{utilisateurId}', function ($user, $utilisateurId) {
    return $user !== null && (int) $user->id === (int) $utilisateurId;
});

/*
|--------------------------------------------------------------------------
| 🆕 RÉSULTAT CHANNELS
|--------------------------------------------------------------------------
*/

// Canal pour le compte de résultat
Broadcast::channel('resultat.{utilisateurId}', function ($user, $utilisateurId) {
    return $user !== null && (int) $user->id === (int) $utilisateurId;
});

/*
|--------------------------------------------------------------------------
| 🆕 AMORTISSEMENT CHANNELS
|--------------------------------------------------------------------------
*/

// Canal pour les calculs d'amortissement
Broadcast::channel('amortissement.{utilisateurId}', function ($user, $utilisateurId) {
    return $user !== null && (int) $user->id === (int) $utilisateurId;
});

/*
|--------------------------------------------------------------------------
| 🆕 CANAL GLOBAL (Présence utilisateur)
|--------------------------------------------------------------------------
*/

// Canal de présence pour savoir qui est en ligne
Broadcast::channel('presence', function ($user) {
    if (!$user) return false;
    
    return [
        'id' => $user->id,
        'nom' => $user->nom_complet ?? $user->nom_utilisateur,
        'matricule' => $user->matricule ?? '',
        'role' => $user->role ? $user->role->libelle : 'Inconnu',
        'succursale_id' => $user->succursale_id,
    ];
});