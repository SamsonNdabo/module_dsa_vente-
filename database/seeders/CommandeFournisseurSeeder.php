<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;


class CommandeFournisseurSeeder extends Seeder
{
    public function run(): void
    {
        $fournisseurs = DB::table('fournisseur')->get();
        $depots = DB::table('stock_succursale')->where('is_depot', true)->get();
        $produits = DB::table('produit')->get();
        
        for ($i = 0; $i < 10; $i++) {
            $fournisseur = $fournisseurs->random();
            $depot = $depots->random();
            $commandeId = DB::table('commandes_fournisseurs')->insertGetId([
                'reference' => 'CMD' . date('Ymd') . '-' . str_pad($i+1, 4, '0', STR_PAD_LEFT),
                'fournisseur_id' => $fournisseur->id,
                'depot_destination_id' => $depot->id,
                'date_commande' => now()->subDays(rand(1, 15)),
                'date_livraison_prevue' => now()->addDays(rand(3, 10)),
                'statut' => ['EN_ATTENTE', 'VALIDEE', 'ENVOYEE', 'CONFIRMEE', 'EXPEDIEE', 'RECUE'][rand(0, 5)],
                'transporteur' => ['Express Logistics', 'DHL', 'FedEx', 'UPS'][rand(0, 3)],
                'total_ht' => 0,
                'total_tva' => 0,
                'total_ttc' => 0,
                'cree_par_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            
            $totalHT = 0;
            $totalTVA = 0;
            
            // Ajouter 2-3 lignes par commande
            for ($j = 0; $j < rand(2, 4); $j++) {
                $produit = $produits->random();
                $quantite = rand(10, 100);
                $prix = rand(1000, 50000);
                $tva = 16;
                $totalLigneHT = $quantite * $prix;
                $totalLigneTVA = $totalLigneHT * $tva / 100;
                
                DB::table('lignes_commandes_fournisseurs')->insert([
                    'commande_id' => $commandeId,
                    'produit_id' => $produit->id,
                    'libelle' => $produit->nom,
                    'quantite' => $quantite,
                    'prix_unitaire_ht' => $prix,
                    'taux_tva' => $tva,
                    'total_ht' => $totalLigneHT,
                    'total_tva' => $totalLigneTVA,
                    'total_ttc' => $totalLigneHT + $totalLigneTVA,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                
                $totalHT += $totalLigneHT;
                $totalTVA += $totalLigneTVA;
            }
            
            DB::table('commandes_fournisseurs')
                ->where('id', $commandeId)
                ->update([
                    'total_ht' => $totalHT,
                    'total_tva' => $totalTVA,
                    'total_ttc' => $totalHT + $totalTVA,
                ]);
        }
    }
}
