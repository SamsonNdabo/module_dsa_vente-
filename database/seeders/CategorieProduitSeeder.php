<?php
// database/seeders/CategorieProduitSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\CategorieProduit;
use Illuminate\Support\Facades\DB;

class CategorieProduitSeeder extends Seeder
{
    public function run(): void
    {
        // Désactiver les contraintes de clés étrangères
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        
        // Tronquer la table
        DB::table('categorie_produit')->truncate();
        
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $categories = [
            // Téléphones et Smartphones
            [
                'code' => 'CAT2026001',
                'nom' => 'TRANSPORT ET LOGISTIQUE',
                'description' => 'Transport professionnel,gestion de flotte et logistique integree',
                'statut' => true,
            ],
            [
                'code' => 'CAT2026002',
                'nom' => 'AGRO-INDUSTRIE',
                'description' => 'Production agricole strategique et transformation agroalimentaire',
                'statut' => true,
            ],
            [
                'code' => 'CAT2026003',
                'nom' => 'CONSULTING RH',
                'description' => 'Services specialises, deploiement terrain et support institutionnel',
                'statut' => true,
            ],
            [
                'code' => 'CAT2026004',
                'nom' => 'DISTRIBUTION ET FINANCE',
                'description' => 'Digitalisation des paiements et solutions FinTech',
                'statut' => true,
            ],
            [
                'code' => 'CAT2026005',
                'nom' => 'EVENEMENTIEL ET AMENAGEMENT',
                'description' => 'Organistion d\'evenements corporatifs et amenagement d\'espaces professionnels',
                'statut' => true,
            ],
            [
                'code' => 'CAT2026006',
                'nom' => 'SHOP MODERNE',
                'description' => 'Bras operationnel de Starehe Holding pour la finance de proximite',
                'statut' => true,
            ]
        ];

        $count = 0;
        
        foreach ($categories as $categorieData) {
            $categorie = CategorieProduit::firstOrCreate(
                ['code' => $categorieData['code']],
                $categorieData
            );
            
            if ($categorie->wasRecentlyCreated) {
                $count++;
                $this->command->info("   ✅ Catégorie créée: {$categorieData['code']} - {$categorieData['nom']}");
            }
        }

        $this->command->info("✅ {$count} catégories de produits créées avec succès !");
    }
}