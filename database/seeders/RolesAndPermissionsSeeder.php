<?php
// database/seeders/RolesAndPermissionsSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Désactiver les contraintes de clés étrangères
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        
        // Nettoyer les tables
        DB::table('role_has_permissions')->truncate();
        DB::table('permissions')->truncate();
        DB::table('roles')->truncate();
        
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->command->info('🚀 Création des rôles et permissions selon la structure STAREHE...');

        // ============================================================
        // 1. CRÉATION DES PERMISSIONS
        // ============================================================
        
        $this->command->info('📋 Création des permissions...');

        $permissions = [
            // ============================================================
            // PERMISSIONS SUPER ADMIN (HOLDING LEVEL)
            // ============================================================
            ['code' => 'view-agences', 'module' => 'agences', 'action' => 'view', 'description' => 'Voir les agences de sa zone', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'AUDITEUR', 'COMPTABLE_HOLDING']],
            ['code' => 'create-agences', 'module' => 'agences', 'action' => 'create', 'description' => 'Création des agences', 'role' => ['SUPER_ADMIN']],
            ['code' => 'view-all-agences', 'module' => 'agences', 'action' => 'view', 'description' => 'Voir toutes les agences', 'role' => ['SUPER_ADMIN', 'AUDITEUR', 'COMPTABLE_HOLDING']],
            ['code' => 'edit-agences', 'module' => 'agences', 'action' => 'edit', 'description' => 'Modifier les agences', 'role' => ['SUPER_ADMIN']],
            ['code' => 'delete-agences', 'module' => 'agences', 'action' => 'delete', 'description' => 'Supprimer les agences', 'role' => ['SUPER_ADMIN']],
            ['code' => 'block-agences', 'module' => 'agences', 'action' => 'block', 'description' => 'Bloquer une agence', 'role' => ['SUPER_ADMIN']],
            ['code' => 'export-agences', 'module' => 'agences', 'action' => 'export', 'description' => 'Exporter les agences', 'role' => ['SUPER_ADMIN', 'COMPTABLE_HOLDING']],
            
            // Gestion des superviseurs
            ['code' => 'create-superviseurs', 'module' => 'superviseurs', 'action' => 'create', 'description' => 'Création des superviseurs', 'role' => ['SUPER_ADMIN']],
            ['code' => 'manage-superviseurs', 'module' => 'superviseurs', 'action' => 'manage', 'description' => 'Gérer les superviseurs', 'role' => ['SUPER_ADMIN']],
            
            // Paramétrage des commissions
            ['code' => 'manage-commissions', 'module' => 'parametrage', 'action' => 'manage', 'description' => 'Gérer les commissions', 'role' => ['SUPER_ADMIN', 'COMPTABLE_HOLDING']],
            ['code' => 'view-commissions', 'module' => 'parametrage', 'action' => 'view', 'description' => 'Voir les commissions', 'role' => ['SUPER_ADMIN', 'COMPTABLE_HOLDING', 'AUDITEUR']],
            
            // Rapports globaux
            ['code' => 'view-rapports-globaux', 'module' => 'rapports', 'action' => 'view', 'description' => 'Voir les rapports globaux multi-villes', 'role' => ['SUPER_ADMIN', 'COMPTABLE_HOLDING', 'AUDITEUR']],
            ['code' => 'export-rapports-globaux', 'module' => 'rapports', 'action' => 'export', 'description' => 'Exporter les rapports globaux', 'role' => ['SUPER_ADMIN', 'COMPTABLE_HOLDING']],
            
            // ============================================================
            // GESTION DES UTILISATEURS
            // ============================================================
            ['code' => 'view-users', 'module' => 'utilisateurs', 'action' => 'view', 'description' => 'Voir les utilisateurs de sa succursale', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'AUDITEUR', 'COMPTABLE_HOLDING']],
            ['code' => 'view-all-users', 'module' => 'utilisateurs', 'action' => 'view', 'description' => 'Voir tous les utilisateurs', 'role' => ['SUPER_ADMIN', 'AUDITEUR', 'COMPTABLE_HOLDING']],
            ['code' => 'create-users', 'module' => 'utilisateurs', 'action' => 'create', 'description' => 'Créer des utilisateurs', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE']],
            ['code' => 'edit-users', 'module' => 'utilisateurs', 'action' => 'edit', 'description' => 'Modifier des utilisateurs', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE']],
            ['code' => 'delete-users', 'module' => 'utilisateurs', 'action' => 'delete', 'description' => 'Supprimer des utilisateurs', 'role' => ['SUPER_ADMIN']],
            ['code' => 'block-users', 'module' => 'utilisateurs', 'action' => 'block', 'description' => 'Bloquer des utilisateurs', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE']],
            ['code' => 'activate-users', 'module' => 'utilisateurs', 'action' => 'activate', 'description' => 'Activer des utilisateurs', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE']],
            ['code' => 'deactivate-users', 'module' => 'utilisateurs', 'action' => 'deactivate', 'description' => 'Désactiver des utilisateurs', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE']],
            ['code' => 'reset-user-password', 'module' => 'utilisateurs', 'action' => 'reset', 'description' => 'Réinitialiser le mot de passe', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE']],
            ['code' => 'export-users', 'module' => 'utilisateurs', 'action' => 'export', 'description' => 'Exporter les utilisateurs', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'COMPTABLE_HOLDING']],
            
            ['code' => 'manage-all-users', 'module' => 'utilisateurs', 'action' => 'manage_all', 'description' => 'Gérer les utilisateurs de toutes les succursales', 'role' => ['SUPER_ADMIN']],
            
            // ============================================================
            // GESTION DES RÔLES ET PERMISSIONS
            // ============================================================
            ['code' => 'view-roles', 'module' => 'roles', 'action' => 'view', 'description' => 'Voir les rôles', 'role' => ['SUPER_ADMIN', 'AUDITEUR']],
            ['code' => 'manage-roles', 'module' => 'roles', 'action' => 'manage', 'description' => 'Gérer les rôles', 'role' => ['SUPER_ADMIN']],
            ['code' => 'create-roles', 'module' => 'roles', 'action' => 'create', 'description' => 'Créer des rôles', 'role' => ['SUPER_ADMIN']],
            ['code' => 'edit-roles', 'module' => 'roles', 'action' => 'edit', 'description' => 'Modifier des rôles', 'role' => ['SUPER_ADMIN']],
            ['code' => 'delete-roles', 'module' => 'roles', 'action' => 'delete', 'description' => 'Supprimer des rôles', 'role' => ['SUPER_ADMIN']],
            ['code' => 'view-permissions', 'module' => 'permissions', 'action' => 'view', 'description' => 'Voir les permissions', 'role' => ['SUPER_ADMIN', 'AUDITEUR']],
            ['code' => 'manage-permissions', 'module' => 'permissions', 'action' => 'manage', 'description' => 'Gérer les permissions', 'role' => ['SUPER_ADMIN']],
            
            // ============================================================
            // GESTION DES STOCKS
            // ============================================================
            ['code' => 'view-stock-agence', 'module' => 'stock', 'action' => 'view', 'description' => 'Voir le stock de son agence', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GUICHETIER', 'GESTIONNAIRE_STOCK', 'AUDITEUR']],
            ['code' => 'view-all-stock', 'module' => 'stock', 'action' => 'view', 'description' => 'Voir le stock de toutes les agences', 'role' => ['SUPER_ADMIN', 'GESTIONNAIRE_STOCK', 'AUDITEUR']],
            ['code' => 'manage-stock-agence', 'module' => 'stock', 'action' => 'manage', 'description' => 'Gérer le stock', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GESTIONNAIRE_STOCK']],
            ['code' => 'create-stock', 'module' => 'stock', 'action' => 'create', 'description' => 'Créer une entrée de stock', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GESTIONNAIRE_STOCK']],
            ['code' => 'edit-stock', 'module' => 'stock', 'action' => 'edit', 'description' => 'Modifier une entrée de stock', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GESTIONNAIRE_STOCK']],
            ['code' => 'delete-stock', 'module' => 'stock', 'action' => 'delete', 'description' => 'Supprimer une entrée de stock', 'role' => ['SUPER_ADMIN']],
            ['code' => 'ajouter-stock', 'module' => 'stock', 'action' => 'ajouter', 'description' => 'Ajouter du stock', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GESTIONNAIRE_STOCK']],
            ['code' => 'retirer-stock', 'module' => 'stock', 'action' => 'retirer', 'description' => 'Retirer du stock', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GESTIONNAIRE_STOCK']],
            ['code' => 'transferer-stock', 'module' => 'stock', 'action' => 'transferer', 'description' => 'Transférer du stock', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GESTIONNAIRE_STOCK']],
            ['code' => 'reserver-stock', 'module' => 'stock', 'action' => 'reserver', 'description' => 'Réserver du stock', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GESTIONNAIRE_STOCK']],
            ['code' => 'liberer-reservation', 'module' => 'stock', 'action' => 'liberer', 'description' => 'Libérer une réservation', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GESTIONNAIRE_STOCK']],
            
            // Mouvements de stock
            ['code' => 'view-mouvements-stock', 'module' => 'stock', 'action' => 'view', 'description' => 'Voir les mouvements de stock', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GESTIONNAIRE_STOCK', 'AUDITEUR']],
            ['code' => 'create-mouvement-stock', 'module' => 'stock', 'action' => 'create', 'description' => 'Créer un mouvement de stock', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GESTIONNAIRE_STOCK']],
            ['code' => 'annuler-mouvement-stock', 'module' => 'stock', 'action' => 'annuler', 'description' => 'Annuler un mouvement de stock', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GESTIONNAIRE_STOCK']],
            
            // ============================================================
            // GESTION DES DÉPÔTS
            // ============================================================
            ['code' => 'view-depots', 'module' => 'depot', 'action' => 'view', 'description' => 'Voir les dépôts', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GESTIONNAIRE_DEPOT', 'GESTIONNAIRE_STOCK', 'AUDITEUR']],
            ['code' => 'manage-depots', 'module' => 'depot', 'action' => 'manage', 'description' => 'Gérer les dépôts', 'role' => ['SUPER_ADMIN', 'GESTIONNAIRE_DEPOT']],
            ['code' => 'create-depot', 'module' => 'depot', 'action' => 'create', 'description' => 'Créer un dépôt', 'role' => ['SUPER_ADMIN', 'GESTIONNAIRE_DEPOT']],
            ['code' => 'edit-depot', 'module' => 'depot', 'action' => 'edit', 'description' => 'Modifier un dépôt', 'role' => ['SUPER_ADMIN', 'GESTIONNAIRE_DEPOT']],
            ['code' => 'delete-depot', 'module' => 'depot', 'action' => 'delete', 'description' => 'Supprimer un dépôt', 'role' => ['SUPER_ADMIN']],
            ['code' => 'view-depot-stock', 'module' => 'depot', 'action' => 'view', 'description' => 'Voir le stock des dépôts', 'role' => ['SUPER_ADMIN', 'GESTIONNAIRE_DEPOT', 'CHEF_AGENCE', 'GESTIONNAIRE_STOCK']],
            ['code' => 'transfer-depot', 'module' => 'depot', 'action' => 'transfer', 'description' => 'Transférer entre dépôts', 'role' => ['SUPER_ADMIN', 'GESTIONNAIRE_DEPOT']],
            
            // ============================================================
            // APPROVISIONNEMENTS
            // ============================================================
            ['code' => 'view-approvisionnements', 'module' => 'stock', 'action' => 'view', 'description' => 'Voir les approvisionnements', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GESTIONNAIRE_STOCK', 'GESTIONNAIRE_DEPOT', 'RECEPTEUR', 'AUDITEUR']],
            ['code' => 'create-approvisionnement', 'module' => 'stock', 'action' => 'create', 'description' => 'Demander un approvisionnement', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GESTIONNAIRE_STOCK']],
            ['code' => 'validate-approvisionnement', 'module' => 'stock', 'action' => 'validate', 'description' => 'Valider un approvisionnement', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GESTIONNAIRE_DEPOT']],
            ['code' => 'expedier-approvisionnement', 'module' => 'stock', 'action' => 'expedier', 'description' => 'Expédier un approvisionnement', 'role' => ['SUPER_ADMIN', 'GESTIONNAIRE_DEPOT']],
            ['code' => 'receptionner-approvisionnement', 'module' => 'stock', 'action' => 'receptionner', 'description' => 'Réceptionner un approvisionnement', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'RECEPTEUR']],
            ['code' => 'annuler-approvisionnement', 'module' => 'stock', 'action' => 'annuler', 'description' => 'Annuler un approvisionnement', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE']],
            ['code' => 'arriver-intermediaire', 'module' => 'stock', 'action' => 'arriver', 'description' => 'Enregistrer l\'arrivée au point intermédiaire', 'role' => ['SUPER_ADMIN', 'GESTIONNAIRE_DEPOT', 'CHEF_AGENCE']],
            ['code' => 'depart-intermediaire', 'module' => 'stock', 'action' => 'depart', 'description' => 'Enregistrer le départ du point intermédiaire', 'role' => ['SUPER_ADMIN', 'GESTIONNAIRE_DEPOT', 'CHEF_AGENCE']],
            ['code' => 'prendre-en-charge-aveugle', 'module' => 'stock', 'action' => 'prendre_en_charge', 'description' => 'Prendre en charge une demande à l\'aveugle', 'role' => ['SUPER_ADMIN', 'GESTIONNAIRE_DEPOT', 'CHEF_AGENCE']],
            ['code' => 'preparer-approvisionnement', 'module' => 'stock', 'action' => 'preparer', 'description' => 'preparer un approvisionnement', 'role' => ['SUPER_ADMIN', 'GESTIONNAIRE_DEPOT', 'CHEF_AGENCE']],

            
            // ============================================================
            // RÉCEPTIONS MARCHANDISES
            // ============================================================
            ['code' => 'receive-goods', 'module' => 'reception', 'action' => 'receive', 'description' => 'Réceptionner les marchandises', 'role' => ['SUPER_ADMIN', 'RECEPTEUR', 'CHEF_AGENCE', 'GESTIONNAIRE_DEPOT']],
            ['code' => 'quality-control', 'module' => 'reception', 'action' => 'control', 'description' => 'Contrôle qualité des réceptions', 'role' => ['SUPER_ADMIN', 'RECEPTEUR']],
            ['code' => 'report-defects', 'module' => 'reception', 'action' => 'report', 'description' => 'Signaler des défauts', 'role' => ['SUPER_ADMIN', 'RECEPTEUR', 'CHEF_AGENCE']],
            
            // ============================================================
            // GESTION DES INVENTAIRES
            // ============================================================
            ['code' => 'view-inventaires', 'module' => 'inventaires', 'action' => 'view', 'description' => 'Voir les inventaires', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GESTIONNAIRE_STOCK', 'AUDITEUR']],
            ['code' => 'create-inventaire', 'module' => 'inventaires', 'action' => 'create', 'description' => 'Créer un inventaire', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GESTIONNAIRE_STOCK']],
            ['code' => 'validate-inventaire', 'module' => 'inventaires', 'action' => 'validate', 'description' => 'Valider un inventaire', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GESTIONNAIRE_STOCK']],
            ['code' => 'delete-inventaire', 'module' => 'inventaires', 'action' => 'delete', 'description' => 'Supprimer un inventaire', 'role' => ['SUPER_ADMIN']],
            
            // ============================================================
            // GESTION DES FOURNISSEURS
            // ============================================================
            ['code' => 'view-fournisseurs', 'module' => 'fournisseurs', 'action' => 'view', 'description' => 'Voir les fournisseurs', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GESTIONNAIRE_STOCK', 'GESTIONNAIRE_DEPOT', 'AUDITEUR']],
            ['code' => 'create-fournisseur', 'module' => 'fournisseurs', 'action' => 'create', 'description' => 'Créer un fournisseur', 'role' => ['SUPER_ADMIN']],
            ['code' => 'edit-fournisseur', 'module' => 'fournisseurs', 'action' => 'edit', 'description' => 'Modifier un fournisseur', 'role' => ['SUPER_ADMIN']],
            ['code' => 'delete-fournisseur', 'module' => 'fournisseurs', 'action' => 'delete', 'description' => 'Supprimer un fournisseur', 'role' => ['SUPER_ADMIN']],
            
            // ============================================================
            // ALERTES ET NOTIFICATIONS
            // ============================================================
            ['code' => 'view-alertes-stock', 'module' => 'stock', 'action' => 'view', 'description' => 'Voir les alertes de stock', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GESTIONNAIRE_STOCK', 'GESTIONNAIRE_DEPOT', 'AUDITEUR']],
            ['code' => 'view-notifications', 'module' => 'notifications', 'action' => 'view', 'description' => 'Voir les notifications', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GUICHETIER', 'GESTIONNAIRE_STOCK', 'GESTIONNAIRE_DEPOT', 'RECEPTEUR', 'AUDITEUR']],
            ['code' => 'manage-notifications', 'module' => 'notifications', 'action' => 'manage', 'description' => 'Gérer les notifications', 'role' => ['SUPER_ADMIN']],
            
            // ============================================================
            // PERMISSIONS GUICHETIER
            // ============================================================
            ['code' => 'create', 'module' => 'caisse', 'action' => 'creer', 'description' => 'creer une caisse', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE']],
            ['code' => 'update', 'module' => 'caisse', 'action' => 'modifier', 'description' => 'Modifier une caisse', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE']],
            ['code' => 'delete', 'module' => 'caisse', 'action' => 'supprimer', 'description' => 'Supprimer une caisse', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE']],
            ['code' => 'show', 'module' => 'caisse', 'action' => 'voir', 'description' => 'Voir les détails d\'une caisse', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GUICHETIER']],
            ['code' => 'encaissement', 'module' => 'caisse', 'action' => 'encaisser', 'description' => 'Effectuer des encaissements', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GUICHETIER']],
            ['code' => 'decaissement', 'module' => 'caisse', 'action' => 'decaisser', 'description' => 'Effectuer des décaissements', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GUICHETIER']],
            ['code' => 'consultation-solde-caisse', 'module' => 'caisse', 'action' => 'consult', 'description' => 'Consulter le solde de la caisse', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GUICHETIER', 'AUDITEUR']],
            ['code' => 'view-caisses', 'module' => 'caisse', 'action' => 'view', 'description' => 'Voir les caisses', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'AUDITEUR']],
            ['code' => 'open-caisse', 'module' => 'caisse', 'action' => 'open', 'description' => 'Ouvrir une caisse', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE']],
            ['code' => 'close-caisse', 'module' => 'caisse', 'action' => 'close', 'description' => 'Fermer une caisse', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE']],
            ['code' => 'cloture-journaliere', 'module' => 'caisse', 'action' => 'cloture', 'description' => 'Effectuer la clôture journalière', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE']],
            
            // ============================================================
            // PERMISSIONS AUDIT
            // ============================================================
            ['code' => 'lecture-seule', 'module' => 'audit', 'action' => 'read', 'description' => 'Accès en lecture seule', 'role' => ['SUPER_ADMIN', 'AUDITEUR']],
            ['code' => 'view-audit-logs', 'module' => 'audit', 'action' => 'view', 'description' => 'Voir les logs d\'audit', 'role' => ['SUPER_ADMIN', 'AUDITEUR']],
            ['code' => 'export-audit-logs', 'module' => 'audit', 'action' => 'export', 'description' => 'Exporter les logs d\'audit', 'role' => ['SUPER_ADMIN', 'AUDITEUR']],
            ['code' => 'view-system-logs', 'module' => 'audit', 'action' => 'view', 'description' => 'Voir les logs système', 'role' => ['SUPER_ADMIN', 'AUDITEUR']],
            ['code' => 'verification-logs', 'module' => 'audit', 'action' => 'verify', 'description' => 'Vérifier les logs système', 'role' => ['SUPER_ADMIN', 'AUDITEUR']],
            
            // ============================================================
            // PERMISSIONS COMPTABLE HOLDING
            // ============================================================
            ['code' => 'view-flux-financiers', 'module' => 'finance', 'action' => 'view', 'description' => 'Voir les flux financiers', 'role' => ['SUPER_ADMIN', 'COMPTABLE_HOLDING', 'AUDITEUR']],
            ['code' => 'export-comptable', 'module' => 'finance', 'action' => 'export', 'description' => 'Export comptable', 'role' => ['SUPER_ADMIN', 'COMPTABLE_HOLDING']],
            ['code' => 'reconciliation-multi-agences', 'module' => 'finance', 'action' => 'reconcile', 'description' => 'Réconciliation multi-agences', 'role' => ['SUPER_ADMIN', 'COMPTABLE_HOLDING']],
            ['code' => 'suivi-commissions', 'module' => 'finance', 'action' => 'follow', 'description' => 'Suivi des commissions', 'role' => ['SUPER_ADMIN', 'COMPTABLE_HOLDING']],
            ['code' => 'gestion-tva-taxes', 'module' => 'finance', 'action' => 'manage', 'description' => 'Gestion TVA et taxes', 'role' => ['SUPER_ADMIN', 'COMPTABLE_HOLDING']],
            
            // ============================================================
            // PERMISSIONS PARTENAIRE
            // ============================================================
            ['code' => 'consult-transactions-partenaires', 'module' => 'partenaires', 'action' => 'consult', 'description' => 'Consulter les transactions liées', 'role' => ['SUPER_ADMIN', 'PARTENAIRE']],
            ['code' => 'view-rapports-dedies', 'module' => 'partenaires', 'action' => 'view', 'description' => 'Voir les rapports dédiés', 'role' => ['SUPER_ADMIN', 'PARTENAIRE']],
            ['code' => 'api-integration', 'module' => 'partenaires', 'action' => 'api', 'description' => 'Intégration API', 'role' => ['SUPER_ADMIN', 'PARTENAIRE']],
            ['code' => 'reconciliation', 'module' => 'partenaires', 'action' => 'reconcile', 'description' => 'Effectuer la réconciliation', 'role' => ['SUPER_ADMIN', 'PARTENAIRE']],
            
            // ============================================================
            // GESTION DES CATÉGORIES DE PRODUITS
            // ============================================================
            ['code' => 'view-categories-produits', 'module' => 'categories', 'action' => 'view', 'description' => 'Voir les catégories de produits', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GESTIONNAIRE_STOCK']],
            ['code' => 'create-categorie-produit', 'module' => 'categories', 'action' => 'create', 'description' => 'Créer une catégorie', 'role' => ['SUPER_ADMIN']],
            ['code' => 'edit-categorie-produit', 'module' => 'categories', 'action' => 'edit', 'description' => 'Modifier une catégorie', 'role' => ['SUPER_ADMIN']],
            ['code' => 'delete-categorie-produit', 'module' => 'categories', 'action' => 'delete', 'description' => 'Supprimer une catégorie', 'role' => ['SUPER_ADMIN']],
            
            // ============================================================
            // CODES-BARRES ET QR CODES
            // ============================================================
            ['code' => 'generate-barcode', 'module' => 'codes-barres', 'action' => 'generate', 'description' => 'Générer des codes-barres', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GESTIONNAIRE_STOCK']],
            ['code' => 'scan-barcode', 'module' => 'codes-barres', 'action' => 'scan', 'description' => 'Scanner des codes-barres', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GUICHETIER', 'GESTIONNAIRE_STOCK']],
            ['code' => 'print-barcodes', 'module' => 'codes-barres', 'action' => 'print', 'description' => 'Imprimer des codes-barres', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GESTIONNAIRE_STOCK']],
            
            // ============================================================
            // IMEI (TÉLÉPHONES)
            // ============================================================
            ['code' => 'view-imei', 'module' => 'imei', 'action' => 'view', 'description' => 'Voir les numéros IMEI', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GESTIONNAIRE_STOCK']],
            ['code' => 'create-imei', 'module' => 'imei', 'action' => 'create', 'description' => 'Ajouter des IMEI', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GESTIONNAIRE_STOCK']],
            ['code' => 'transferer-imei', 'module' => 'imei', 'action' => 'transferer', 'description' => 'Transférer des IMEI', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GESTIONNAIRE_STOCK']],
            ['code' => 'activer-garantie-imei', 'module' => 'imei', 'action' => 'activer', 'description' => 'Activer la garantie', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GESTIONNAIRE_STOCK']],
            
            // ============================================================
            // GESTION DES CLIENTS
            // ============================================================
            ['code' => 'view-clients', 'module' => 'clients', 'action' => 'view', 'description' => 'Voir la liste des clients', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'AUDITEUR', 'COMPTABLE_HOLDING', 'GUICHETIER']],
            ['code' => 'view-all-clients', 'module' => 'clients', 'action' => 'view', 'description' => 'Voir tous les clients (toutes agences)', 'role' => ['SUPER_ADMIN', 'AUDITEUR', 'COMPTABLE_HOLDING']],
            ['code' => 'create-client', 'module' => 'clients', 'action' => 'create', 'description' => 'Créer un client', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GUICHETIER']],
            ['code' => 'edit-client', 'module' => 'clients', 'action' => 'edit', 'description' => 'Modifier un client', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE']],
            ['code' => 'delete-client', 'module' => 'clients', 'action' => 'delete', 'description' => 'Supprimer un client', 'role' => ['SUPER_ADMIN']],
            ['code' => 'block-client', 'module' => 'clients', 'action' => 'block', 'description' => 'Bloquer un client', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE']],
            ['code' => 'activate-client', 'module' => 'clients', 'action' => 'activate', 'description' => 'Activer un client', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE']],
            ['code' => 'deactivate-client', 'module' => 'clients', 'action' => 'deactivate', 'description' => 'Désactiver un client', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE']],
            ['code' => 'export-clients', 'module' => 'clients', 'action' => 'export', 'description' => 'Exporter les clients', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'COMPTABLE_HOLDING']],
            ['code' => 'client-access', 'module' => 'clients', 'action' => 'access', 'description' => 'Accès client à son propre compte', 'role' => ['CLIENT']],
            
            // ============================================================
            // KYC (KNOW YOUR CUSTOMER)
            // ============================================================
            ['code' => 'view-kyc', 'module' => 'kyc', 'action' => 'view', 'description' => 'Voir les documents KYC', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'AUDITEUR', 'COMPTABLE_HOLDING', 'CLIENT']],
            ['code' => 'upload-kyc', 'module' => 'kyc', 'action' => 'upload', 'description' => 'Uploader des documents KYC', 'role' => ['SUPER_ADMIN', 'CLIENT']],
            ['code' => 'verify-kyc', 'module' => 'kyc', 'action' => 'verify', 'description' => 'Vérifier les documents KYC', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE']],
            ['code' => 'reject-kyc', 'module' => 'kyc', 'action' => 'reject', 'description' => 'Rejeter des documents KYC', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE']],
            
            // ============================================================
            // REMISES CLIENTS
            // ============================================================
            ['code' => 'view-remises', 'module' => 'remises', 'action' => 'view', 'description' => 'Voir les règles de remise', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'AUDITEUR']],
            ['code' => 'create-remise', 'module' => 'remises', 'action' => 'create', 'description' => 'Créer une règle de remise', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE']],
            ['code' => 'edit-remise', 'module' => 'remises', 'action' => 'edit', 'description' => 'Modifier une règle de remise', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE']],
            ['code' => 'delete-remise', 'module' => 'remises', 'action' => 'delete', 'description' => 'Supprimer une règle de remise', 'role' => ['SUPER_ADMIN']],
            ['code' => 'toggle-remise', 'module' => 'remises', 'action' => 'toggle', 'description' => 'Activer/Désactiver une remise', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE']],
            
            // ============================================================
            // COMMANDES CLIENTS
            // ============================================================
            ['code' => 'view-commandes-client', 'module' => 'commandes_client', 'action' => 'view', 'description' => 'Voir les commandes clients', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GESTIONNAIRE_STOCK', 'AUDITEUR', 'CLIENT']],
            ['code' => 'view-all-commandes-client', 'module' => 'commandes_client', 'action' => 'view', 'description' => 'Voir toutes les commandes clients', 'role' => ['SUPER_ADMIN', 'AUDITEUR']],
            ['code' => 'create-commande-client', 'module' => 'commandes_client', 'action' => 'create', 'description' => 'Passer une commande', 'role' => ['SUPER_ADMIN', 'CLIENT']],
            ['code' => 'cancel-commande-client', 'module' => 'commandes_client', 'action' => 'cancel', 'description' => 'Annuler une commande', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'CLIENT']],
            ['code' => 'validate-commande-client', 'module' => 'commandes_client', 'action' => 'validate', 'description' => 'Valider une commande', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE']],
            ['code' => 'process-commande-client', 'module' => 'commandes_client', 'action' => 'process', 'description' => 'Traiter une commande (préparation, expédition)', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GESTIONNAIRE_STOCK']],
            
            // ============================================================
            // LIVRAISONS
            // ============================================================
            ['code' => 'view-livraisons', 'module' => 'livraisons', 'action' => 'view', 'description' => 'Voir les livraisons', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GESTIONNAIRE_STOCK', 'AUDITEUR', 'CLIENT']],
            ['code' => 'view-all-livraisons', 'module' => 'livraisons', 'action' => 'view', 'description' => 'Voir toutes les livraisons', 'role' => ['SUPER_ADMIN', 'AUDITEUR']],
            ['code' => 'create-livraison', 'module' => 'livraisons', 'action' => 'create', 'description' => 'Créer une livraison', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE']],
            ['code' => 'update-livraison', 'module' => 'livraisons', 'action' => 'update', 'description' => 'Mettre à jour une livraison', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE']],
            ['code' => 'assign-livreur', 'module' => 'livraisons', 'action' => 'assign', 'description' => 'Assigner un livreur', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE']],
            ['code' => 'mark-livree', 'module' => 'livraisons', 'action' => 'mark', 'description' => 'Marquer une livraison comme livrée', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'LIVREUR']],
            ['code' => 'mark-echec', 'module' => 'livraisons', 'action' => 'mark', 'description' => 'Marquer une livraison comme échouée', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'LIVREUR']],
            ['code' => 'delete-livraison', 'module' => 'livraisons', 'action' => 'delete', 'description' => 'Supprimer une livraison', 'role' => ['SUPER_ADMIN']],
            
            // ============================================================
            // PAIEMENTS CLIENTS
            // ============================================================
            ['code' => 'view-paiements-client', 'module' => 'paiements_client', 'action' => 'view', 'description' => 'Voir les paiements clients', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'COMPTABLE_HOLDING', 'AUDITEUR', 'CLIENT']],
            ['code' => 'create-paiement-client', 'module' => 'paiements_client', 'action' => 'create', 'description' => 'Enregistrer un paiement', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GUICHETIER']],
            ['code' => 'refund-paiement-client', 'module' => 'paiements_client', 'action' => 'refund', 'description' => 'Rembourser un paiement', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE']],
            
            // ============================================================
            // PERMISSIONS MODULE APPROVISIONNEMENT (CAHIER DES CHARGES)
            // ============================================================
            // COMMANDES FOURNISSEURS
            ['code' => 'view-commandes', 'module' => 'commandes_fournisseurs', 'action' => 'view', 'description' => 'Voir les commandes fournisseurs', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GESTIONNAIRE_STOCK', 'GESTIONNAIRE_DEPOT', 'COMPTABLE_HOLDING', 'AUDITEUR']],
            ['code' => 'create-commandes', 'module' => 'commandes_fournisseurs', 'action' => 'create', 'description' => 'Créer des commandes fournisseurs', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GESTIONNAIRE_STOCK']],
            ['code' => 'edit-commandes', 'module' => 'commandes_fournisseurs', 'action' => 'edit', 'description' => 'Modifier des commandes fournisseurs', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE']],
            ['code' => 'validate-commandes', 'module' => 'commandes_fournisseurs', 'action' => 'validate', 'description' => 'Valider des commandes fournisseurs', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GESTIONNAIRE_DEPOT']],
            ['code' => 'cancel-commandes', 'module' => 'commandes_fournisseurs', 'action' => 'cancel', 'description' => 'Annuler des commandes fournisseurs', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE']],
            ['code' => 'delete-commandes', 'module' => 'commandes_fournisseurs', 'action' => 'delete', 'description' => 'Supprimer des commandes fournisseurs', 'role' => ['SUPER_ADMIN']],

            // RÉCEPTIONS FOURNISSEURS
            ['code' => 'view-receptions', 'module' => 'receptions_fournisseurs', 'action' => 'view', 'description' => 'Voir les réceptions fournisseurs', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GESTIONNAIRE_STOCK', 'GESTIONNAIRE_DEPOT', 'RECEPTEUR', 'COMPTABLE_HOLDING', 'AUDITEUR']],
            ['code' => 'create-receptions', 'module' => 'receptions_fournisseurs', 'action' => 'create', 'description' => 'Créer des réceptions (magasinier)', 'role' => ['SUPER_ADMIN', 'RECEPTEUR', 'CHEF_AGENCE', 'GESTIONNAIRE_DEPOT']],
            ['code' => 'validate-receptions', 'module' => 'receptions_fournisseurs', 'action' => 'validate', 'description' => 'Valider des réceptions (débloque paiement)', 'role' => ['SUPER_ADMIN', 'RECEPTEUR', 'CHEF_AGENCE']],
            ['code' => 'cancel-receptions', 'module' => 'receptions_fournisseurs', 'action' => 'cancel', 'description' => 'Annuler des réceptions', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE']],

            // RFQ (Demandes de devis)
            ['code' => 'view-rfq', 'module' => 'rfq', 'action' => 'view', 'description' => 'Voir les demandes de devis', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GESTIONNAIRE_STOCK', 'AUDITEUR']],
            ['code' => 'create-rfq', 'module' => 'rfq', 'action' => 'create', 'description' => 'Créer des demandes de devis', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GESTIONNAIRE_STOCK']],
            ['code' => 'send-rfq', 'module' => 'rfq', 'action' => 'send', 'description' => 'Envoyer des demandes de devis', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE']],

            // DEVIS (Quotations)
            ['code' => 'view-devis', 'module' => 'devis', 'action' => 'view', 'description' => 'Voir les devis fournisseurs', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'GESTIONNAIRE_STOCK', 'AUDITEUR']],
            ['code' => 'create-devis', 'module' => 'devis', 'action' => 'create', 'description' => 'Créer des devis', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE']],
            ['code' => 'validate-devis', 'module' => 'devis', 'action' => 'validate', 'description' => 'Valider des devis (Responsable achats)', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE']],
            ['code' => 'refuse-devis', 'module' => 'devis', 'action' => 'refuse', 'description' => 'Refuser des devis', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE']],
            ['code' => 'compare-devis', 'module' => 'devis', 'action' => 'compare', 'description' => 'Comparer des devis', 'role' => ['SUPER_ADMIN', 'CHEF_AGENCE', 'AUDITEUR']],

            // PAIEMENTS FOURNISSEURS
            ['code' => 'view-paiements-fournisseurs', 'module' => 'paiements_fournisseurs', 'action' => 'view', 'description' => 'Voir les paiements fournisseurs', 'role' => ['SUPER_ADMIN', 'COMPTABLE_HOLDING', 'CHEF_AGENCE', 'AUDITEUR']],
            ['code' => 'create-paiements-fournisseurs', 'module' => 'paiements_fournisseurs', 'action' => 'create', 'description' => 'Créer des paiements fournisseurs', 'role' => ['SUPER_ADMIN', 'COMPTABLE_HOLDING']],
            ['code' => 'validate-paiements-fournisseurs', 'module' => 'paiements_fournisseurs', 'action' => 'validate', 'description' => 'Valider des paiements fournisseurs', 'role' => ['SUPER_ADMIN', 'COMPTABLE_HOLDING']],
        ];

        $permissionCount = 0;
        foreach ($permissions as $perm) {
            $exists = DB::table('permissions')
                ->where('name', $perm['code'])
                ->where('guard_name', 'api')
                ->exists();
            
            if (!$exists) {
                DB::table('permissions')->insert([
                    'name' => $perm['code'],
                    'guard_name' => 'api',
                    'permission_code' => $perm['code'],
                    'module' => $perm['module'],
                    'action' => $perm['action'],
                    'description' => $perm['description'],
                    'categorie' => $this->getCategoryFromModule($perm['module']),
                    'est_systeme' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $permissionCount++;
            }
        }

        $this->command->info("✅ {$permissionCount} permissions créées");

        // ============================================================
        // 2. CRÉATION DES RÔLES
        // ============================================================
        
        $this->command->info('📋 Création des rôles...');

        $roles = [
            ['code' => 'SUPER_ADMIN', 'nom' => 'Super Admin (Holding)', 'description' => 'Direction STAREHE HOLDING / IT central - Accès total', 'niveau' => 10, 'defaut' => 0, 'systeme' => 1],
            ['code' => 'CHEF_AGENCE', 'nom' => 'Chef d\'Agence (Superviseur)', 'description' => 'Responsable SHOP MODERNE local - Accès agence spécifique', 'niveau' => 7, 'defaut' => 0, 'systeme' => 0],
            ['code' => 'GUICHETIER', 'nom' => 'Guichetier', 'description' => 'Opérateur transactionnel', 'niveau' => 4, 'defaut' => 1, 'systeme' => 0],
            ['code' => 'AUDITEUR', 'nom' => 'Audit / Contrôle Interne', 'description' => 'Inspection interne STAREHE - Accès lecture seule', 'niveau' => 8, 'defaut' => 0, 'systeme' => 1],
            ['code' => 'PARTENAIRE', 'nom' => 'Partenaire Financier', 'description' => 'ADVANS, EQUITY, opérateurs mobile, WESTERN UNION, RIA, MONEYGRAM', 'niveau' => 5, 'defaut' => 0, 'systeme' => 0],
            ['code' => 'COMPTABLE_HOLDING', 'nom' => 'Comptabilité / Finance Holding', 'description' => 'Gestion financière et comptable du holding', 'niveau' => 8, 'defaut' => 0, 'systeme' => 0],
            ['code' => 'GESTIONNAIRE_STOCK', 'nom' => 'Gestionnaire de Stock', 'description' => 'Gestion des stocks, inventaires et mouvements', 'niveau' => 6, 'defaut' => 0, 'systeme' => 0],
            ['code' => 'GESTIONNAIRE_DEPOT', 'nom' => 'Gestionnaire de Dépôt', 'description' => 'Gestion des dépôts et approvisionnements', 'niveau' => 6, 'defaut' => 0, 'systeme' => 0],
            ['code' => 'RECEPTEUR', 'nom' => 'Récepteur', 'description' => 'Réception des marchandises et contrôles qualité', 'niveau' => 5, 'defaut' => 0, 'systeme' => 0],
            ['code' => 'CLIENT', 'nom' => 'Client', 'description' => 'Client de l\'application - Accès front office', 'niveau' => 1, 'defaut' => 0, 'systeme' => 0],
            ['code' => 'LIVREUR', 'nom' => 'Livreur', 'description' => 'Livreur pour les commandes en ligne', 'niveau' => 3, 'defaut' => 0, 'systeme' => 0],
        ];

        $roleCount = 0;
        foreach ($roles as $r) {
            $exists = DB::table('roles')->where('code', $r['code'])->exists();
            
            if (!$exists) {
                DB::table('roles')->insert([
                    'name' => strtolower($r['code']),
                    'guard_name' => 'api',
                    'code' => $r['code'],
                    'nom_role' => $r['nom'],
                    'description' => $r['description'],
                    'niveau_acces' => $r['niveau'],
                    'est_defaut' => $r['defaut'],
                    'est_systeme' => $r['systeme'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $roleCount++;
                $this->command->info("   ✅ Rôle créé: {$r['code']} - {$r['nom']}");
            }
        }

        $this->command->info("✅ {$roleCount} rôles créés");

        // ============================================================
        // 3. ASSIGNATION DES PERMISSIONS AUX RÔLES
        // ============================================================
        
        $this->command->info('📋 Assignation des permissions aux rôles...');

        $rolesDb = DB::table('roles')->get()->keyBy('code');
        $permissionsDb = DB::table('permissions')->get()->keyBy('permission_code');

        foreach ($permissions as $perm) {
            $permission = $permissionsDb[$perm['code']] ?? null;
            if (!$permission) continue;
            
            foreach ($perm['role'] as $roleCode) {
                if (isset($rolesDb[$roleCode])) {
                    $exists = DB::table('role_has_permissions')
                        ->where('role_id', $rolesDb[$roleCode]->id)
                        ->where('permission_id', $permission->id)
                        ->exists();
                    
                    if (!$exists) {
                        DB::table('role_has_permissions')->insert([
                            'permission_id' => $permission->id,
                            'role_id' => $rolesDb[$roleCode]->id,
                            'date_attribution' => now(),
                        ]);
                    }
                }
            }
        }
        
        $this->command->info("✅ Permissions assignées aux rôles");

        // ============================================================
        // 4. CRÉATION DES UTILISATEURS PAR DÉFAUT
        // ============================================================
        
        $this->command->info('📋 Création des utilisateurs par défaut...');

        // Récupérer la première succursale (dépôt principal)
        $succursale = DB::table('succursales')->where('code', 'SUC001')->first();
        if (!$succursale) {
            $succursale = DB::table('succursales')->first();
        }

        $defaultUsers = [
            [
                'matricule' => 'SA001',
                'nom_complet' => 'Super Administrateur Holding',
                'email' => 'superadmin@starehe.cd',
                'telephone' => '+243000000001',
                'role_code' => 'SUPER_ADMIN',
                'username' => 'superadmin',
                'password' => 'SuperAdmin@2024'
            ],
            [
                'matricule' => 'CA001',
                'nom_complet' => 'Chef d\'Agence Principal',
                'email' => 'chefagence@starehe.cd',
                'telephone' => '+243000000002',
                'role_code' => 'CHEF_AGENCE',
                'username' => 'chefagence',
                'password' => 'ChefAgence@2024'
            ],
            [
                'matricule' => 'GS001',
                'nom_complet' => 'Gestionnaire de Stock Principal',
                'email' => 'gestionnaire.stock@starehe.cd',
                'telephone' => '+243000000003',
                'role_code' => 'GESTIONNAIRE_STOCK',
                'username' => 'gestionnaire.stock',
                'password' => 'Gestionnaire@2024'
            ],
            [
                'matricule' => 'GD001',
                'nom_complet' => 'Gestionnaire de Dépôt Principal',
                'email' => 'gestionnaire.depot@starehe.cd',
                'telephone' => '+243000000004',
                'role_code' => 'GESTIONNAIRE_DEPOT',
                'username' => 'gestionnaire.depot',
                'password' => 'GestionnaireDepot@2024'
            ],
            [
                'matricule' => 'REC001',
                'nom_complet' => 'Récepteur Principal',
                'email' => 'recepteur@starehe.cd',
                'telephone' => '+243000000005',
                'role_code' => 'RECEPTEUR',
                'username' => 'recepteur',
                'password' => 'Recepteur@2024'
            ],
            [
                'matricule' => 'GUI001',
                'nom_complet' => 'Guichetier Principal',
                'email' => 'guichetier@starehe.cd',
                'telephone' => '+243000000006',
                'role_code' => 'GUICHETIER',
                'username' => 'guichetier',
                'password' => 'Guichetier@2024'
            ],
            [
                'matricule' => 'AUD001',
                'nom_complet' => 'Auditeur Principal',
                'email' => 'auditeur@starehe.cd',
                'telephone' => '+243000000007',
                'role_code' => 'AUDITEUR',
                'username' => 'auditeur',
                'password' => 'Auditeur@2024'
            ],
            [
                'matricule' => 'COM001',
                'nom_complet' => 'Comptable Holding',
                'email' => 'comptable@starehe.cd',
                'telephone' => '+243000000008',
                'role_code' => 'COMPTABLE_HOLDING',
                'username' => 'comptable',
                'password' => 'Comptable@2024'
            ],
            [
                'matricule' => 'PAR001',
                'nom_complet' => 'Partenaire Principal',
                'email' => 'partenaire@starehe.cd',
                'telephone' => '+243000000009',
                'role_code' => 'PARTENAIRE',
                'username' => 'partenaire',
                'password' => 'Partenaire@2024'
            ],
            [
                'matricule' => 'LIV001',
                'nom_complet' => 'Livreur Principal',
                'email' => 'livreur@starehe.cd',
                'telephone' => '+243000000010',
                'role_code' => 'LIVREUR',
                'username' => 'livreur',
                'password' => 'Livreur@2024'
            ],
        ];

        $userCount = 0;
        foreach ($defaultUsers as $user) {
            $role = $rolesDb[$user['role_code']] ?? null;
            if (!$role) {
                $this->command->warn("⚠️ Rôle non trouvé: {$user['role_code']}");
                continue;
            }
            
            $exists = DB::table('utilisateurs')
                ->where('email', $user['email'])
                ->orWhere('matricule', $user['matricule'])
                ->exists();
            
            if (!$exists) {
                DB::table('utilisateurs')->insert([
                    'matricule' => $user['matricule'],
                    'nom_complet' => $user['nom_complet'],
                    'email' => $user['email'],
                    'telephone' => $user['telephone'],
                    'nom_utilisateur' => $user['username'],
                    'password_hash' => Hash::make($user['password']),
                    'role_id' => $role->id,
                    'succursale_id' => $succursale->id ?? null,
                    'statut' => 'ACTIF',
                    'email_verified_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $userCount++;
                $this->command->info("   ✅ Utilisateur créé: {$user['nom_complet']} ({$user['role_code']}) - Mot de passe: {$user['password']}");
            }
        }

        $this->command->info("✅ {$userCount} utilisateurs créés");

        // ============================================================
        // 5. VÉRIFICATION FINALE
        // ============================================================
        
        $this->command->info('📊 VÉRIFICATION FINALE:');
        $this->command->info('   - Permissions: ' . DB::table('permissions')->count());
        $this->command->info('   - Rôles: ' . DB::table('roles')->count());
        $this->command->info('   - Relations rôle_permissions: ' . DB::table('role_has_permissions')->count());
        $this->command->info('   - Utilisateurs: ' . DB::table('utilisateurs')->count());
        
        $this->command->info('🎉 Seeder terminé avec succès !');
        $this->command->info('');
        $this->command->info('📝 Identifiants de connexion:');
        $this->command->info('   Super Admin: superadmin@starehe.cd / SuperAdmin@2024');
        $this->command->info('   Chef Agence: chefagence@starehe.cd / ChefAgence@2024');
        $this->command->info('   Gestionnaire Stock: gestionnaire.stock@starehe.cd / Gestionnaire@2024');
        $this->command->info('   Gestionnaire Dépôt: gestionnaire.depot@starehe.cd / GestionnaireDepot@2024');
        $this->command->info('   Récepteur: recepteur@starehe.cd / Recepteur@2024');
        $this->command->info('   Guichetier: guichetier@starehe.cd / Guichetier@2024');
        $this->command->info('   Auditeur: auditeur@starehe.cd / Auditeur@2024');
        $this->command->info('   Comptable: comptable@starehe.cd / Comptable@2024');
        $this->command->info('   Partenaire: partenaire@starehe.cd / Partenaire@2024');
        $this->command->info('   Livreur: livreur@starehe.cd / Livreur@2024');
    }

    /**
     * Obtenir la catégorie à partir du module
     */
    private function getCategoryFromModule($module)
    {
        $categories = [
            'agences' => 'Agences',
            'superviseurs' => 'Superviseurs',
            'parametrage' => 'Paramétrage',
            'rapports' => 'Rapports',
            'utilisateurs' => 'Utilisateurs',
            'partenaires' => 'Partenaires',
            'transactions' => 'Transactions',
            'caisse' => 'Caisse',
            'kyc' => 'KYC',
            'stock' => 'Stock',
            'depot' => 'Dépôts',
            'reception' => 'Réceptions',
            'inventaires' => 'Inventaires',
            'fournisseurs' => 'Fournisseurs',
            'categories' => 'Catégories',
            'codes-barres' => 'Codes-barres',
            'imei' => 'IMEI',
            'notifications' => 'Notifications',
            'audit' => 'Audit',
            'finance' => 'Finance',
            'roles' => 'Rôles',
            'permissions' => 'Permissions',
            'clients' => 'Clients',
            'remises' => 'Remises',
            'commandes_client' => 'Commandes Client',
            'livraisons' => 'Livraisons',
            'paiements_client' => 'Paiements Client',
            'commandes_fournisseurs' => 'Commandes Fournisseurs',
            'receptions_fournisseurs' => 'Réceptions Fournisseurs',
            'rfq' => 'Demandes de devis',
            'devis' => 'Devis',
            'paiements_fournisseurs' => 'Paiements Fournisseurs',
        ];
        
        return $categories[$module] ?? 'Général';
    }
}
