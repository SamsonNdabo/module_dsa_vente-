<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->command->info('🚀 Début du seeding STAREHE...');
        
        $this->call([
            RolesAndPermissionsSeeder::class, 

        CategorieProduitSeeder::class,
            DeviseSeeder::class,
            SuccursaleSeeder::class,
            RolesAndPermissionsSeeder::class, 
            UsersSeeder::class,
            FournisseurSeeder::class,
            ProduitSeeder::class,
            // StockSuccursaleSeeder::class,
            // MouvementStockSeeder::class,
            // CommandeFournisseurSeeder::class,
            // ReceptionFournisseurSeeder::class,
            // ApprovisionnementSeeder::class,
            // NotificationSeeder::class,
            // ============================================================
            // NIVEAU 1 : Tables de base (aucune dépendance)
            // ============================================================
                 // Rôles et permissions
            // UsersSeeder::class,                      // Utilisateurs (dépend des rôles)
            // SuccursaleSeeder::class,                 // Succursales (indépendant)
            
            // ============================================================
            // NIVEAU 2 : Tables de référence (dépendent des précédentes)
            // ============================================================
            // FournisseurSeeder::class,                // Fournisseurs (indépendant)
            // CategorieProduitSeeder::class,           // Catégories produits (indépendant)
            // DeviseSeeder::class,                      // Devises (indépendant)
            Taux_ChangeSeeder::class,                 // Taux de change (dépend des devises)
            ParametreSystemeSeeder::class,            // Paramètres système (indépendant)
            // CaisseSeeder::class,                       // Caisses (dépend de succursales)
            
            // ============================================================
            // NIVEAU 3 : Produits (dépend de fournisseurs, catégories, devises)
            // ============================================================
            // ProduitSeeder::class,
            
            // ============================================================
            // NIVEAU 4 : Stocks (dépend de succursales et produits)
            // ============================================================
            // StockSuccursaleSeeder::class,
            
            // ============================================================
            // NIVEAU 5 : Mouvements (dépend de stocks)
            // ============================================================
            // MouvementStockSeeder::class,
            
            // ============================================================
            // NIVEAU 6 : Inventaires (dépend de succursales, utilisateurs, produits, stocks)
            // ============================================================
            // InventaireSeeder::class,
            // InventaireLigneSeeder::class,            // Dépend des inventaires et produits
        ]);
        
        $this->command->info('✅ Seeding STAREHE terminé avec succès !');
    }
}