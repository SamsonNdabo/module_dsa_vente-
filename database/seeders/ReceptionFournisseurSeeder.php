<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ReceptionFournisseurSeeder extends Seeder
{
    public function run(): void
    {
        $commandes = DB::table('commandes_fournisseurs')
            ->whereIn('statut', ['EXPEDIEE', 'RECUE'])
            ->get();
        
        $users = DB::table('utilisateurs')->get();
        
        foreach ($commandes as $commande) {
            $lignes = DB::table('lignes_commandes_fournisseurs')
                ->where('commande_id', $commande->id)
                ->get();
            
            if ($lignes->isEmpty()) continue;
            
            DB::table('receptions_fournisseurs')->insert([
                'reference' => 'REC' . date('Ymd') . '-' . str_pad($commande->id, 4, '0', STR_PAD_LEFT),
                'commande_id' => $commande->id,
                'fournisseur_id' => $commande->fournisseur_id,
                'succursale_id' => $commande->depot_destination_id,
                'date_reception' => now()->subDays(rand(0, 5)),
                'num_bon_livraison' => 'BL-' . rand(1000, 9999),
                'num_facture_fournisseur' => 'FAC-' . rand(1000, 9999),
                'transporteur' => $commande->transporteur,
                'statut' => 'VALIDE',
                'cree_par_id' => $users->random()->id,
                'date_validation' => now(),
                'valide_par_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}