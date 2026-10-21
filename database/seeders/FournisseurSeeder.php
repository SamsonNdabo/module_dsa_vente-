<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FournisseurSeeder extends Seeder
{
    public function run(): void
    {
        $fournisseurs = [
            ['nom' => 'Samsung Electronics', 'email' => 'contact@samsung.com', 'telephone' => '243812345678', 'ville' => json_encode(['Kinshasa', 'Lubumbashi']), 'produits' => json_encode(['Électronique', 'Téléphones']), 'code_fournisseur' => 'SAM001'],
            ['nom' => 'Apple Inc', 'email' => 'contact@apple.com', 'telephone' => '243812345679', 'ville' => json_encode(['Kinshasa']), 'produits' => json_encode(['Électronique', 'Accessoires']), 'code_fournisseur' => 'APP001'],
            ['nom' => 'Nestlé', 'email' => 'contact@nestle.com', 'telephone' => '243812345680', 'ville' => json_encode(['Kinshasa', 'Goma']), 'produits' => json_encode(['Alimentaire']), 'code_fournisseur' => 'NES001'],
            ['nom' => 'HP Inc', 'email' => 'contact@hp.com', 'telephone' => '243812345681', 'ville' => json_encode(['Kinshasa']), 'produits' => json_encode(['Informatique']), 'code_fournisseur' => 'HP001'],
            ['nom' => 'Dell Technologies', 'email' => 'contact@dell.com', 'telephone' => '243812345682', 'ville' => json_encode(['Kinshasa', 'Lubumbashi']), 'produits' => json_encode(['Informatique']), 'code_fournisseur' => 'DEL001'],
            ['nom' => 'Sony', 'email' => 'contact@sony.com', 'telephone' => '243812345683', 'ville' => json_encode(['Kinshasa']), 'produits' => json_encode(['Électronique']), 'code_fournisseur' => 'SON001'],
            ['nom' => 'LG Electronics', 'email' => 'contact@lg.com', 'telephone' => '243812345684', 'ville' => json_encode(['Kinshasa', 'Goma']), 'produits' => json_encode(['Électronique', 'Maison']), 'code_fournisseur' => 'LG001'],
            ['nom' => 'Adidas', 'email' => 'contact@adidas.com', 'telephone' => '243812345685', 'ville' => json_encode(['Kinshasa']), 'produits' => json_encode(['Sport', 'Vêtements']), 'code_fournisseur' => 'ADI001'],
            ['nom' => 'Nike', 'email' => 'contact@nike.com', 'telephone' => '243812345686', 'ville' => json_encode(['Kinshasa', 'Lubumbashi']), 'produits' => json_encode(['Sport']), 'code_fournisseur' => 'NIK001'],
            ['nom' => 'L\'Oréal', 'email' => 'contact@loreal.com', 'telephone' => '243812345687', 'ville' => json_encode(['Kinshasa']), 'produits' => json_encode(['Beauté']), 'code_fournisseur' => 'LOR001'],
        ];

        foreach ($fournisseurs as $f) {
            DB::table('fournisseur')->insert([
                'nom' => $f['nom'],
                'email' => $f['email'],
                'telephone' => $f['telephone'],
                'ville' => $f['ville'],
                'produits' => $f['produits'],
                'code_fournisseur' => $f['code_fournisseur'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}