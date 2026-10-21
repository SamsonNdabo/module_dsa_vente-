<?php
// database/seeders/ProduitSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProduitSeeder extends Seeder
{
    public function run(): void
    {
        // Désactiver les contraintes de clés étrangères
        Schema::disableForeignKeyConstraints();
        
        // Vider la table produit
        DB::table('produit')->truncate();
        
        // Réactiver les contraintes
        Schema::enableForeignKeyConstraints();

        // ✅ Récupérer la devise par défaut (CDF)
        $devise = DB::table('devise')->where('devise_defaut', true)->first();
        
        if (!$devise) {
            $this->command->error('❌ Aucune devise par défaut trouvée !');
            return;
        }

        // ✅ Récupérer les catégories par code
        $categories = DB::table('categorie_produit')->get()->keyBy('code');

        $produits = [
            // ============================================================
            // TRANSPORT ET LOGISTIQUE - Véhicules
            // ============================================================
            [
                'code_barre' => 'TRANS001',
                'nom' => 'Land Cruiser TXL',
                'description' => 'Véhicule tout-terrain 7 places, climatisation, GPS',
                'marque' => 'Toyota',
                'modele' => 'Land Cruiser TXL',
                'type' => 'STANDARD',
                'prix_vente' => 85000,
                'stock_minimum' => 1,
                'est_perissable' => false,
                'categorie_code' => 'CAT2026001', // TRANSPORT ET LOGISTIQUE
                'actif' => true,
            ],
            [
                'code_barre' => 'TRANS002',
                'nom' => 'Fortuner',
                'description' => 'SUV 5 places, confortable pour voyages d\'affaires',
                'marque' => 'Toyota',
                'modele' => 'Fortuner',
                'type' => 'STANDARD',
                'prix_vente' => 65000,
                'stock_minimum' => 1,
                'est_perissable' => false,
                'categorie_code' => 'CAT2026001',
                'actif' => true,
            ],
            [
                'code_barre' => 'TRANS003',
                'nom' => 'Mini Bus 12 places',
                'description' => 'Transport de groupe, climatisation, confortable',
                'marque' => 'Toyota',
                'modele' => 'Hiace',
                'type' => 'STANDARD',
                'prix_vente' => 45000,
                'stock_minimum' => 1,
                'est_perissable' => false,
                'categorie_code' => 'CAT2026001',
                'actif' => true,
            ],
            [
                'code_barre' => 'TRANS004',
                'nom' => 'Camion 10 Tonnes',
                'description' => 'Transport de marchandises, capacité 10T',
                'marque' => 'Mercedes',
                'modele' => 'Actros',
                'type' => 'STANDARD',
                'prix_vente' => 120000,
                'stock_minimum' => 1,
                'est_perissable' => false,
                'categorie_code' => 'CAT2026001',
                'actif' => true,
            ],

            // ============================================================
            // AGRO-INDUSTRIE - Produits agricoles
            // ============================================================
            [
                'code_barre' => 'AGRO001',
                'nom' => 'Maïs Bio',
                'description' => 'Maïs biologique de qualité supérieure',
                'marque' => 'Starehe Agro',
                'modele' => 'Sachet 1kg',
                'type' => 'ALIMENTAIRE',
                'prix_vente' => 2500,
                'stock_minimum' => 100,
                'est_perissable' => true,
                'date_fabrication' => now()->subDays(10),
                'date_expiration' => now()->addMonths(6),
                'categorie_code' => 'CAT2026002', // AGRO-INDUSTRIE
                'actif' => true,
            ],
            [
                'code_barre' => 'AGRO002',
                'nom' => 'Riz Parfumé',
                'description' => 'Riz de qualité supérieure, sachet 5kg',
                'marque' => 'Starehe Agro',
                'modele' => 'Sachet 5kg',
                'type' => 'ALIMENTAIRE',
                'prix_vente' => 12500,
                'stock_minimum' => 50,
                'est_perissable' => true,
                'date_fabrication' => now()->subDays(15),
                'date_expiration' => now()->addMonths(12),
                'categorie_code' => 'CAT2026002',
                'actif' => true,
            ],
            [
                'code_barre' => 'AGRO003',
                'nom' => 'Huile de Palme',
                'description' => 'Huile de palme 100% naturelle, bidon 5L',
                'marque' => 'Starehe Agro',
                'modele' => 'Bidon 5L',
                'type' => 'ALIMENTAIRE',
                'prix_vente' => 15000,
                'stock_minimum' => 30,
                'est_perissable' => true,
                'date_fabrication' => now()->subDays(20),
                'date_expiration' => now()->addMonths(24),
                'categorie_code' => 'CAT2026002',
                'actif' => true,
            ],

            // ============================================================
            // CONSULTING RH - Services (pas de stock physique)
            // ============================================================
            [
                'code_barre' => 'RH001',
                'nom' => 'Formation Professionnelle',
                'description' => 'Formation en gestion des ressources humaines',
                'marque' => 'Starehe Consulting',
                'modele' => 'Session 5 jours',
                'type' => 'STANDARD',
                'prix_vente' => 250000,
                'stock_minimum' => 0,
                'est_perissable' => false,
                'categorie_code' => 'CAT2026003', // CONSULTING RH
                'actif' => true,
            ],
            [
                'code_barre' => 'RH002',
                'nom' => 'Audit RH',
                'description' => 'Audit complet des processus RH',
                'marque' => 'Starehe Consulting',
                'modele' => 'Forfait entreprise',
                'type' => 'STANDARD',
                'prix_vente' => 500000,
                'stock_minimum' => 0,
                'est_perissable' => false,
                'categorie_code' => 'CAT2026003',
                'actif' => true,
            ],

            // ============================================================
            // DISTRIBUTION ET FINANCE - Services financiers
            // ============================================================
            [
                'code_barre' => 'FIN001',
                'nom' => 'Paiement Mobile Money',
                'description' => 'Service de transfert d\'argent via mobile',
                'marque' => 'Starehe Finance',
                'modele' => 'Transaction',
                'type' => 'STANDARD',
                'prix_vente' => 500,
                'stock_minimum' => 0,
                'est_perissable' => false,
                'categorie_code' => 'CAT2026004', // DISTRIBUTION ET FINANCE
                'actif' => true,
            ],
            [
                'code_barre' => 'FIN002',
                'nom' => 'Change de Devises',
                'description' => 'Service de change de devises',
                'marque' => 'Starehe Finance',
                'modele' => 'Par transaction',
                'type' => 'STANDARD',
                'prix_vente' => 0,
                'stock_minimum' => 0,
                'est_perissable' => false,
                'categorie_code' => 'CAT2026004',
                'actif' => true,
            ],

            // ============================================================
            // EVENEMENTIEL ET AMENAGEMENT
            // ============================================================
            [
                'code_barre' => 'EVT001',
                'nom' => 'Location Salle de Conférence',
                'description' => 'Salle équipée pour séminaires et conférences',
                'marque' => 'Starehe Events',
                'modele' => 'Journée',
                'type' => 'STANDARD',
                'prix_vente' => 150000,
                'stock_minimum' => 1,
                'est_perissable' => false,
                'categorie_code' => 'CAT2026005', // EVENEMENTIEL ET AMENAGEMENT
                'actif' => true,
            ],
            [
                'code_barre' => 'EVT002',
                'nom' => 'Pack Événementiel Complet',
                'description' => 'Organisation complète d\'événement',
                'marque' => 'Starehe Events',
                'modele' => 'Forfait',
                'type' => 'STANDARD',
                'prix_vente' => 500000,
                'stock_minimum' => 0,
                'est_perissable' => false,
                'categorie_code' => 'CAT2026005',
                'actif' => true,
            ],

            // ============================================================
            // SHOP MODERNE - Produits de consommation
            // ============================================================
            [
                'code_barre' => 'SHOP001',
                'nom' => 'Carte SIM Vodacom',
                'description' => 'Carte SIM prépayée',
                'marque' => 'Vodacom',
                'modele' => 'Prépayée',
                'type' => 'ACCESSOIRE',
                'prix_vente' => 2000,
                'stock_minimum' => 50,
                'est_perissable' => false,
                'categorie_code' => 'CAT2026006', // SHOP MODERNE
                'actif' => true,
            ],
            [
                'code_barre' => 'SHOP002',
                'nom' => 'Recharge Mobile',
                'description' => 'Recharge de crédit téléphonique',
                'marque' => 'Vodacom',
                'modele' => 'Valeur variable',
                'type' => 'ACCESSOIRE',
                'prix_vente' => 0,
                'stock_minimum' => 0,
                'est_perissable' => false,
                'categorie_code' => 'CAT2026006',
                'actif' => true,
            ],
            [
                'code_barre' => 'SHOP003',
                'nom' => 'Câble USB-C',
                'description' => 'Câble de recharge universel',
                'marque' => 'Generic',
                'modele' => '1m',
                'type' => 'ACCESSOIRE',
                'prix_vente' => 5000,
                'stock_minimum' => 20,
                'est_perissable' => false,
                'categorie_code' => 'CAT2026006',
                'actif' => true,
            ],
            [
                'code_barre' => 'SHOP004',
                'nom' => 'Chargeur Rapide',
                'description' => 'Chargeur rapide 20W',
                'marque' => 'Generic',
                'modele' => '20W',
                'type' => 'ACCESSOIRE',
                'prix_vente' => 15000,
                'stock_minimum' => 15,
                'est_perissable' => false,
                'categorie_code' => 'CAT2026006',
                'actif' => true,
            ],
            [
                'code_barre' => 'SHOP005',
                'nom' => 'Écouteurs Bluetooth',
                'description' => 'Écouteurs sans fil',
                'marque' => 'Samsung',
                'modele' => 'Buds2',
                'type' => 'ELECTRONIQUE',
                'prix_vente' => 45000,
                'stock_minimum' => 10,
                'est_perissable' => false,
                'categorie_code' => 'CAT2026006',
                'actif' => true,
            ],
            [
                'code_barre' => 'SHOP006',
                'nom' => 'Power Bank 10000mAh',
                'description' => 'Batterie externe',
                'marque' => 'Xiaomi',
                'modele' => '10000mAh',
                'type' => 'ELECTRONIQUE',
                'prix_vente' => 25000,
                'stock_minimum' => 10,
                'est_perissable' => false,
                'categorie_code' => 'CAT2026006',
                'actif' => true,
            ],
            [
                'code_barre' => 'SHOP007',
                'nom' => 'Lait Concentré',
                'description' => 'Lait concentré sucré',
                'marque' => 'Nestlé',
                'modele' => 'Boîte 397g',
                'type' => 'ALIMENTAIRE',
                'prix_vente' => 3500,
                'stock_minimum' => 30,
                'est_perissable' => true,
                'date_fabrication' => now()->subDays(30),
                'date_expiration' => now()->addMonths(12),
                'categorie_code' => 'CAT2026006',
                'actif' => true,
            ],
            [
                'code_barre' => 'SHOP008',
                'nom' => 'Savon de Toilette',
                'description' => 'Savon parfumé',
                'marque' => 'Dove',
                'modele' => '100g',
                'type' => 'STANDARD',
                'prix_vente' => 1500,
                'stock_minimum' => 50,
                'est_perissable' => false,
                'categorie_code' => 'CAT2026006',
                'actif' => true,
            ],
        ];

        $count = 0;
        
        foreach ($produits as $produit) {
            // Vérifier que la catégorie existe
            if (!isset($categories[$produit['categorie_code']])) {
                $this->command->warn("⚠️ Catégorie non trouvée: {$produit['categorie_code']} pour le produit {$produit['nom']}");
                continue;
            }
            
            $categorie = $categories[$produit['categorie_code']];
            
            // Calculer le prix TTC (TVA 16%)
            $prixTTC = $produit['prix_vente'] * 1.16;
            
            DB::table('produit')->insert([
                'code_barre' => $produit['code_barre'],
                'nom' => $produit['nom'],
                'description' => $produit['description'] ?? null,
                'marque' => $produit['marque'] ?? null,
                'modele' => $produit['modele'] ?? null,
                'type' => $produit['type'],
                'prix_vente' => $produit['prix_vente'],
                'prix_vente_TTC' => $prixTTC,
                'stock_minimum' => $produit['stock_minimum'],
                'est_perissable' => $produit['est_perissable'],
                'date_fabrication' => $produit['date_fabrication'] ?? null,
                'date_expiration' => $produit['date_expiration'] ?? null,
                'categorie_id' => $categorie->id,
                'devise_id' => $devise->id,
                'actif' => $produit['actif'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            
            $count++;
            $this->command->info("   ✅ Produit créé: {$produit['nom']} ({$produit['code_barre']})");
        }

        $this->command->info("✅ {$count} produits créés avec succès !");
        
        // Afficher un résumé par catégorie
        $this->command->newLine();
        $this->command->info('📊 RÉSUMÉ PAR CATÉGORIE:');
        
        $stats = DB::table('produit')
            ->select('categorie_produit.nom as categorie', DB::raw('count(*) as total'))
            ->join('categorie_produit', 'produit.categorie_id', '=', 'categorie_produit.id')
            ->groupBy('categorie_produit.nom')
            ->get();
        
        foreach ($stats as $stat) {
            $this->command->line("   - {$stat->categorie}: {$stat->total} produits");
        }
    }
}