<?php
// database/seeders/InventaireLigneSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Inventaire;
use App\Models\InventaireLigne;
use App\Models\StockSuccursale;
use Illuminate\Support\Facades\DB;

class InventaireLigneSeeder extends Seeder
{
    public function run(): void
    {
        // Désactiver temporairement les contraintes de clés étrangères
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        
        // Optionnel: vider la table si vous voulez repartir de zéro
        // InventaireLigne::truncate();
        
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $inventaires = Inventaire::whereIn('statut', ['EN_COURS', 'TERMINE'])->get();

        if ($inventaires->isEmpty()) {
            $this->command->warn('⚠️ Aucun inventaire en cours trouvé.');
            return;
        }

        $compteur = 0;
        $lignes = [];

        foreach ($inventaires as $inventaire) {
            $stocks = StockSuccursale::where('succursale_id', $inventaire->succursale_id)
                ->with('produit')
                ->inRandomOrder()
                ->limit(rand(8, 20))
                ->get();

            foreach ($stocks as $stock) {
                $quantiteTheorique = $stock->quantite_disponible;
                $variation = rand(-3, 3);
                $quantiteReelle = max(0, $quantiteTheorique + $variation);
                $ecart = $quantiteReelle - $quantiteTheorique;
                $coutUnitaire = $stock->produit->prix_achat ?? rand(5, 50);

                $lignes[] = [
                    'inventaire_id' => $inventaire->id,
                    'produit_id' => $stock->produit_id,
                    'code_barre' => $stock->produit->code_barre ?? 'N/A',
                    'nom_produit' => $stock->produit->nom,
                    'quantite_theorique' => $quantiteTheorique,
                    'quantite_comptee' => $quantiteReelle,
                    'cout_unitaire' => $coutUnitaire,
                    'cout_ecart' => abs($ecart) * $coutUnitaire,
                    'ecart' => $ecart,
                    'notes' => $variation != 0 ? 'Écart constaté' : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            // Recalculer les totaux
            $inventaire->recalculerTotaux();
        }

        // Insérer en ignorant les doublons
        foreach (array_chunk($lignes, 50) as $chunk) {
            try {
                DB::table('inventaire_ligne')->insertOrIgnore($chunk);
                $compteur += count($chunk);
            } catch (\Exception $e) {
                $this->command->error("❌ Erreur batch: " . $e->getMessage());
            }
        }

        $this->command->info('✅ ' . $compteur . ' lignes d\'inventaire traitées (avec ignoration des doublons) !');
    }
}