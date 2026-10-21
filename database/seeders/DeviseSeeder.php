<?php
// database/seeders/DeviseSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DeviseSeeder extends Seeder
{
    public function run(): void
    {
        // Désactiver les contraintes de clés étrangères
        Schema::disableForeignKeyConstraints();
        
        // Vider la table avant de la remplir (optionnel)
        DB::table('devise')->truncate();
        
        // Réactiver les contraintes
        Schema::enableForeignKeyConstraints();

        $devises = [
            [
                'code' => 'CDF',
                'nom' => 'Franc Congolais',
                'symbole' => 'FC',
                'taux' => 1.0000,
                'devise_defaut' => true,
                'decimaux' => 0,
                'position_symbole' => 'apres',
                'statut' => 'ACTIF',        // ✅ CORRECT : 'statut' au lieu de 'actif'
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'USD',
                'nom' => 'Dollar Américain',
                'symbole' => '$',
                'taux' => 2800.0000,
                'devise_defaut' => false,
                'decimaux' => 2,
                'position_symbole' => 'avant',
                'statut' => 'ACTIF',        // ✅ CORRECT
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'EUR',
                'nom' => 'Euro',
                'symbole' => '€',
                'taux' => 3000.0000,
                'devise_defaut' => false,
                'decimaux' => 2,
                'position_symbole' => 'avant',
                'statut' => 'ACTIF',        // ✅ CORRECT
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'GBP',
                'nom' => 'Livre Sterling',
                'symbole' => '£',
                'taux' => 3500.0000,
                'devise_defaut' => false,
                'decimaux' => 2,
                'position_symbole' => 'avant',
                'statut' => 'INACTIF',       // ✅ CORRECT
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'KES',
                'nom' => 'Shilling Kenyan',
                'symbole' => 'KSh',
                'taux' => 21.0000,
                'devise_defaut' => false,
                'decimaux' => 2,
                'position_symbole' => 'avant',
                'statut' => 'ACTIF',        // ✅ CORRECT
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'CFA',
                'nom' => 'Franc CFA',
                'symbole' => 'FCFA',
                'taux' => 4.5000,
                'devise_defaut' => false,
                'decimaux' => 0,
                'position_symbole' => 'apres',
                'statut' => 'ACTIF',        // ✅ CORRECT
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'ZAR',
                'nom' => 'Rand Sud-Africain',
                'symbole' => 'R',
                'taux' => 150.0000,
                'devise_defaut' => false,
                'decimaux' => 2,
                'position_symbole' => 'avant',
                'statut' => 'ACTIF',        // ✅ CORRECT
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        DB::table('devise')->insert($devises);
        
        $this->command->info('✅ Devises insérées avec succès !');
    }
}