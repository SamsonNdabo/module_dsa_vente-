<?php
// database/seeders/SuccursaleSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Succursale;
use App\Models\Utilisateur;
use Illuminate\Support\Facades\DB;

// ============================================================
// 3. SUCCURSALES
// ============================================================
class SuccursaleSeeder extends Seeder
{
    public function run(): void
    {
        $succursales = [
            ['code' => 'SUC001', 'nom' => 'Dépôt Principal', 'type' => 'DEPOT', 'ville' => 'Kinshasa', 'est_active' => true],
            ['code' => 'SUC002', 'nom' => 'Agence Centre', 'type' => 'POINT_VENTE', 'ville' => 'Kinshasa', 'est_active' => true],
            ['code' => 'SUC003', 'nom' => 'Agence Limete', 'type' => 'POINT_VENTE', 'ville' => 'Kinshasa', 'est_active' => true],
            ['code' => 'SUC004', 'nom' => 'Agence Ngaliema', 'type' => 'POINT_VENTE', 'ville' => 'Kinshasa', 'est_active' => true],
            ['code' => 'SUC005', 'nom' => 'Dépôt Lubumbashi', 'type' => 'DEPOT', 'ville' => 'Lubumbashi', 'est_active' => true],
            ['code' => 'SUC006', 'nom' => 'Agence Lubumbashi', 'type' => 'POINT_VENTE', 'ville' => 'Lubumbashi', 'est_active' => true],
            ['code' => 'SUC007', 'nom' => 'Dépôt Goma', 'type' => 'DEPOT', 'ville' => 'Goma', 'est_active' => true],
            ['code' => 'SUC008', 'nom' => 'Agence Goma', 'type' => 'POINT_VENTE', 'ville' => 'Goma', 'est_active' => true],
            ['code' => 'SUC009', 'nom' => 'Dépôt Kisangani', 'type' => 'DEPOT', 'ville' => 'Kisangani', 'est_active' => true],
            ['code' => 'SUC010', 'nom' => 'Agence Kisangani', 'type' => 'POINT_VENTE', 'ville' => 'Kisangani', 'est_active' => true],
        ];

        foreach ($succursales as $s) {
            $id = DB::table('succursales')->insertGetId([
                'code' => $s['code'],
                'nom' => $s['nom'],
                'type' => $s['type'],
                'ville' => $s['ville'],
                'est_active' => $s['est_active'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}