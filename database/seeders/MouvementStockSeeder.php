<?php
// database/seeders/MouvementStockSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\MouvementStock;
use App\Models\StockSuccursale;
use App\Models\Utilisateur;
use Illuminate\Support\Facades\DB;

class MouvementStockSeeder extends Seeder
{
    public function run(): void
    {
        $stocks = DB::table('stock_succursale')->where('is_depot', true)->get();
        $produits = DB::table('produit')->get();
        
        for ($i = 0; $i < 10; $i++) {
            $stock = $stocks->random();
            $produit = $produits->firstWhere('id', $stock->produit_id);
            $quantite = rand(10, 50);
            
            DB::table('mouvements_stock')->insert([
                'reference_mouvement' => 'MVT' . date('Ymd') . '-' . str_pad($i+1, 4, '0', STR_PAD_LEFT),
                'succursale_id' => $stock->succursale_id,
                'produit_id' => $produit->id,
                'stock_destination_id' => $stock->id,
                'type_mouvement' => 'ENTREE',
                'statut' => 'VALIDE',
                'quantite' => $quantite,
                'quantite_avant' => $stock->quantite_disponible,
                'quantite_apres' => $stock->quantite_disponible + $quantite,
                'cout_unitaire' => rand(5000, 50000),
                'prix_achat_lot' => rand(5000, 50000),
                'quantite_restante_lot' => $quantite,
                'est_lot_fifo' => true,
                'date_lot' => now()->subDays(rand(1, 30)),
                'date_expiration_lot' => $produit->est_perissable ? now()->addDays(rand(7, 90)) : null,
                'raison' => 'Entrée initiale',
                'responsable_id' => 1,
                'date_mouvement' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            
            // Mettre à jour le stock
            DB::table('stock_succursale')
                ->where('id', $stock->id)
                ->update([
                    'quantite_disponible' => $stock->quantite_disponible + $quantite,
                    'date_dernier_entree' => now(),
                    'updated_at' => now(),
                ]);
        }
    }
}