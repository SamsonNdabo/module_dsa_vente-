<?php
// database/seeders/StockSuccursaleSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\StockSuccursale;
use App\Models\Succursale;
use App\Models\Produit;
use Illuminate\Support\Facades\DB;

class StockSuccursaleSeeder extends Seeder
{
    public function run(): void
    {
        $succursales = DB::table('succursales')->get();
        $produits = DB::table('produit')->get();
        
        // Dépôts (is_depot = true)
        $depots = $succursales->where('type', 'DEPOT');
        $pointsVente = $succursales->where('type', 'POINT_VENTE');
        
        $stockEntries = [];
        
        // Créer les stocks pour les dépôts
        foreach ($depots as $depot) {
            foreach ($produits->take(5) as $produit) {
                $stockEntries[] = [
                    'succursale_id' => $depot->id,
                    'produit_id' => $produit->id,
                    'is_depot' => true,
                    'quantite_disponible' => rand(50, 200),
                    'quantite_reserve' => rand(0, 20),
                    'quantite_minimale' => rand(10, 30),
                    'emplacement' => 'Rayon A - Étagère ' . rand(1, 5),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }
        
        // Créer les stocks pour les points de vente
        foreach ($pointsVente as $pv) {
            foreach ($produits->take(8) as $produit) {
                // Trouver un dépôt source aléatoire
                $depotSource = $depots->random();
                $depotStock = DB::table('stock_succursale')
                    ->where('succursale_id', $depotSource->id)
                    ->where('produit_id', $produit->id)
                    ->first();
                
                $stockEntries[] = [
                    'succursale_id' => $pv->id,
                    'produit_id' => $produit->id,
                    'is_depot' => false,
                    'depot_source_id' => $depotStock->id ?? null,
                    'quantite_disponible' => rand(5, 50),
                    'quantite_reserve' => rand(0, 5),
                    'quantite_minimale' => rand(3, 10),
                    'emplacement' => 'Rayon B - Étagère ' . rand(1, 3),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }
        
        // Insérer uniquement si le produit n'existe pas déjà
        foreach ($stockEntries as $entry) {
            $exists = DB::table('stock_succursale')
                ->where('succursale_id', $entry['succursale_id'])
                ->where('produit_id', $entry['produit_id'])
                ->exists();
            
            if (!$exists) {
                DB::table('stock_succursale')->insert($entry);
            }
        }
    }
}