<?php
// database/seeders/ParametreSystemeSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ParametreSysteme;
use Illuminate\Support\Facades\DB;

class ParametreSystemeSeeder extends Seeder
{
    public function run(): void
    {
        // Désactiver les contraintes
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        ParametreSysteme::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->command->info('🚀 Création des paramètres système...');

        $parametres = [
            // ============================================================
            // SÉCURITÉ
            // ============================================================
            [
                'categorie' => 'SECURITE',
                'cle' => 'SESSION_TIMEOUT_MINUTES',
                'valeur' => '30',
                'type_valeur' => 'INTEGER',
                'description' => 'Durée d\'inactivité avant déconnexion automatique (en minutes)',
                'modifiable' => true,
                'visible' => true
            ],
            [
                'categorie' => 'SECURITE',
                'cle' => 'PASSWORD_MIN_LENGTH',
                'valeur' => '8',
                'type_valeur' => 'INTEGER',
                'description' => 'Longueur minimale du mot de passe',
                'modifiable' => true,
                'visible' => true
            ],
            [
                'categorie' => 'SECURITE',
                'cle' => 'PASSWORD_REQUIRE_UPPERCASE',
                'valeur' => 'true',
                'type_valeur' => 'BOOLEAN',
                'description' => 'Le mot de passe doit contenir une majuscule',
                'modifiable' => true,
                'visible' => true
            ],
            [
                'categorie' => 'SECURITE',
                'cle' => 'PASSWORD_REQUIRE_LOWERCASE',
                'valeur' => 'true',
                'type_valeur' => 'BOOLEAN',
                'description' => 'Le mot de passe doit contenir une minuscule',
                'modifiable' => true,
                'visible' => true
            ],
            [
                'categorie' => 'SECURITE',
                'cle' => 'PASSWORD_REQUIRE_NUMBER',
                'valeur' => 'true',
                'type_valeur' => 'BOOLEAN',
                'description' => 'Le mot de passe doit contenir un chiffre',
                'modifiable' => true,
                'visible' => true
            ],
            [
                'categorie' => 'SECURITE',
                'cle' => 'PASSWORD_REQUIRE_SPECIAL',
                'valeur' => 'true',
                'type_valeur' => 'BOOLEAN',
                'description' => 'Le mot de passe doit contenir un caractère spécial',
                'modifiable' => true,
                'visible' => true
            ],
            [
                'categorie' => 'SECURITE',
                'cle' => 'MAX_LOGIN_ATTEMPTS',
                'valeur' => '5',
                'type_valeur' => 'INTEGER',
                'description' => 'Nombre maximum de tentatives de connexion avant verrouillage',
                'modifiable' => true,
                'visible' => true
            ],
            [
                'categorie' => 'SECURITE',
                'cle' => 'ACCOUNT_LOCKOUT_MINUTES',
                'valeur' => '30',
                'type_valeur' => 'INTEGER',
                'description' => 'Durée de verrouillage du compte après échecs (en minutes)',
                'modifiable' => true,
                'visible' => true
            ],
            
            // ============================================================
            // GÉNÉRAL
            // ============================================================
            [
                'categorie' => 'GENERAL',
                'cle' => 'COMPANY_NAME',
                'valeur' => 'STAREHE HOLDING SAS',
                'type_valeur' => 'STRING',
                'description' => 'Nom de l\'entreprise',
                'modifiable' => true,
                'visible' => true
            ],
            [
                'categorie' => 'GENERAL',
                'cle' => 'COMPANY_ADDRESS',
                'valeur' => 'Kinshasa, RDC',
                'type_valeur' => 'STRING',
                'description' => 'Adresse de l\'entreprise',
                'modifiable' => true,
                'visible' => true
            ],
            [
                'categorie' => 'GENERAL',
                'cle' => 'COMPANY_PHONE',
                'valeur' => '+243 000 000 000',
                'type_valeur' => 'STRING',
                'description' => 'Téléphone de l\'entreprise',
                'modifiable' => true,
                'visible' => true
            ],
            [
                'categorie' => 'GENERAL',
                'cle' => 'COMPANY_EMAIL',
                'valeur' => 'contact@starehe.cd',
                'type_valeur' => 'STRING',
                'description' => 'Email de l\'entreprise',
                'modifiable' => true,
                'visible' => true
            ],
            [
                'categorie' => 'GENERAL',
                'cle' => 'DEFAULT_CURRENCY',
                'valeur' => 'CDF',
                'type_valeur' => 'STRING',
                'description' => 'Devise par défaut',
                'modifiable' => true,
                'visible' => true
            ],
            [
                'categorie' => 'GENERAL',
                'cle' => 'DATE_FORMAT',
                'valeur' => 'DD/MM/YYYY',
                'type_valeur' => 'STRING',
                'description' => 'Format de date',
                'modifiable' => true,
                'visible' => true
            ],
            
            // ============================================================
            // FINANCES
            // ============================================================
            [
                'categorie' => 'FINANCE',
                'cle' => 'TAX_RATE',
                'valeur' => '16.00',
                'type_valeur' => 'DECIMAL',
                'description' => 'Taux de TVA par défaut (%)',
                'modifiable' => true,
                'visible' => true
            ],
            [
                'categorie' => 'FINANCE',
                'cle' => 'COMMISSION_DEFAULT',
                'valeur' => '2.00',
                'type_valeur' => 'DECIMAL',
                'description' => 'Commission par défaut (%)',
                'modifiable' => true,
                'visible' => true
            ],
            [
                'categorie' => 'FINANCE',
                'cle' => 'PENALTY_RATE',
                'valeur' => '2.00',
                'type_valeur' => 'DECIMAL',
                'description' => 'Taux de pénalité de retard (%)',
                'modifiable' => true,
                'visible' => true
            ],
            
            // ============================================================
            // CAISSE
            // ============================================================
            [
                'categorie' => 'CAISSE',
                'cle' => 'CAISSE_OPEN_TIME',
                'valeur' => '08:00:00',
                'type_valeur' => 'STRING',
                'description' => 'Heure d\'ouverture des caisses',
                'modifiable' => true,
                'visible' => true
            ],
            [
                'categorie' => 'CAISSE',
                'cle' => 'CAISSE_CLOSE_TIME',
                'valeur' => '18:00:00',
                'type_valeur' => 'STRING',
                'description' => 'Heure de fermeture des caisses',
                'modifiable' => true,
                'visible' => true
            ],
            [
                'categorie' => 'CAISSE',
                'cle' => 'MAX_DAILY_CASH',
                'valeur' => '10000000.00',
                'type_valeur' => 'DECIMAL',
                'description' => 'Maximum d\'argent en caisse par jour',
                'modifiable' => true,
                'visible' => true
            ],
            [
                'categorie' => 'CAISSE',
                'cle' => 'TOLERANCE_ECART',
                'valeur' => '1000.00',
                'type_valeur' => 'DECIMAL',
                'description' => 'Tolérance d\'écart de caisse',
                'modifiable' => true,
                'visible' => true
            ],
            
            // ============================================================
            // STOCK
            // ============================================================
            [
                'categorie' => 'STOCK',
                'cle' => 'ALERTE_STOCK_JOURS',
                'valeur' => '7',
                'type_valeur' => 'INTEGER',
                'description' => 'Nombre de jours avant expiration pour alerte',
                'modifiable' => true,
                'visible' => true
            ],
            [
                'categorie' => 'STOCK',
                'cle' => 'INVENTAIRE_AUTO',
                'valeur' => 'false',
                'type_valeur' => 'BOOLEAN',
                'description' => 'Inventaire automatique à la fermeture',
                'modifiable' => true,
                'visible' => true
            ],
            
            // ============================================================
            // BACKUP
            // ============================================================
            [
                'categorie' => 'BACKUP',
                'cle' => 'BACKUP_DAILY',
                'valeur' => 'true',
                'type_valeur' => 'BOOLEAN',
                'description' => 'Sauvegarde quotidienne automatique',
                'modifiable' => true,
                'visible' => true
            ],
            [
                'categorie' => 'BACKUP',
                'cle' => 'BACKUP_RETENTION_DAYS',
                'valeur' => '30',
                'type_valeur' => 'INTEGER',
                'description' => 'Durée de rétention des sauvegardes (en jours)',
                'modifiable' => true,
                'visible' => true
            ],
            [
                'categorie' => 'BACKUP',
                'cle' => 'BACKUP_TIME',
                'valeur' => '02:00:00',
                'type_valeur' => 'STRING',
                'description' => 'Heure de sauvegarde automatique',
                'modifiable' => true,
                'visible' => true
            ],
            
            // ============================================================
            // NOTIFICATIONS
            // ============================================================
            [
                'categorie' => 'NOTIFICATION',
                'cle' => 'NOTIFY_STOCK_ALERT',
                'valeur' => 'true',
                'type_valeur' => 'BOOLEAN',
                'description' => 'Notifier les alertes de stock',
                'modifiable' => true,
                'visible' => true
            ],
            [
                'categorie' => 'NOTIFICATION',
                'cle' => 'NOTIFY_LOW_BALANCE',
                'valeur' => 'true',
                'type_valeur' => 'BOOLEAN',
                'description' => 'Notifier les soldes bas',
                'modifiable' => true,
                'visible' => true
            ],
            [
                'categorie' => 'NOTIFICATION',
                'cle' => 'NOTIFY_EXPIRY',
                'valeur' => 'true',
                'type_valeur' => 'BOOLEAN',
                'description' => 'Notifier les produits proches expiration',
                'modifiable' => true,
                'visible' => true
            ],
            [
                'categorie' => 'NOTIFICATION',
                'cle' => 'NOTIFY_EMAIL_RECIPIENTS',
                'valeur' => '["direction@starehe.cd", "superviseur@starehe.cd"]',
                'type_valeur' => 'JSON',
                'description' => 'Destinataires des notifications par email',
                'modifiable' => true,
                'visible' => true
            ],
            
            // ============================================================
            // 2FA
            // ============================================================
            [
                'categorie' => '2FA',
                'cle' => 'TWO_FACTOR_EMAIL_EXPIRY_MINUTES',
                'valeur' => '10',
                'type_valeur' => 'INTEGER',
                'description' => 'Durée de validité du code 2FA par email (en minutes)',
                'modifiable' => true,
                'visible' => true
            ],
            [
                'categorie' => '2FA',
                'cle' => 'TWO_FACTOR_REQUIRED_FOR_ADMIN',
                'valeur' => 'false',
                'type_valeur' => 'BOOLEAN',
                'description' => '2FA obligatoire pour les administrateurs',
                'modifiable' => true,
                'visible' => true
            ],
        ];

        $count = 0;
        foreach ($parametres as $data) {
            ParametreSysteme::create($data);
            $count++;
            $this->command->info("   ✅ Paramètre créé: {$data['cle']}");
        }

        $this->command->info("✅ {$count} paramètres système créés avec succès !");
    }
}