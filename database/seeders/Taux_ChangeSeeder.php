<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class Taux_ChangeSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('taux_change')->insert([
            // CDF → USD
            [
                'devise_source' => 1,
                'devise_cible'  => 2,
                'taux'          => 2800.0000,
                'ancien_taux'   => null,
                'date_taux'     => now()->toDateString(),
                'modifie_par'   => 1,
                'motif'         => 'Taux initial',
                'created_at'    => now(),
                'updated_at'    => now(),
            ],
            // CDF → EUR
            [
                'devise_source' => 1,
                'devise_cible'  => 3,
                'taux'          => 3000.0000,
                'ancien_taux'   => null,
                'date_taux'     => now()->toDateString(),
                'modifie_par'   => 1,
                'motif'         => 'Taux initial',
                'created_at'    => now(),
                'updated_at'    => now(),
            ],
            // CDF → GBP
            [
                'devise_source' => 1,
                'devise_cible'  => 4,
                'taux'          => 3500.0000,
                'ancien_taux'   => null,
                'date_taux'     => now()->toDateString(),
                'modifie_par'   => 1,
                'motif'         => 'Taux initial',
                'created_at'    => now(),
                'updated_at'    => now(),
            ],
            // CDF → KES
            [
                'devise_source' => 1,
                'devise_cible'  => 5,
                'taux'          => 21.0000,
                'ancien_taux'   => null,
                'date_taux'     => now()->toDateString(),
                'modifie_par'   => 1,
                'motif'         => 'Taux initial',
                'created_at'    => now(),
                'updated_at'    => now(),
            ],
            // CDF → CFA
            [
                'devise_source' => 1,
                'devise_cible'  => 6,
                'taux'          => 4.5000,
                'ancien_taux'   => null,
                'date_taux'     => now()->toDateString(),
                'modifie_par'   => 1,
                'motif'         => 'Taux initial',
                'created_at'    => now(),
                'updated_at'    => now(),
            ],
            // USD → EUR
            [
                'devise_source' => 2,
                'devise_cible'  => 3,
                'taux'          => 0.9200,
                'ancien_taux'   => null,
                'date_taux'     => now()->toDateString(),
                'modifie_par'   => 1,
                'motif'         => 'Taux initial',
                'created_at'    => now(),
                'updated_at'    => now(),
            ],
            // USD → KES
            [
                'devise_source' => 2,
                'devise_cible'  => 5,
                'taux'          => 0.0075,
                'ancien_taux'   => null,
                'date_taux'     => now()->toDateString(),
                'modifie_par'   => 1,
                'motif'         => 'Taux initial',
                'created_at'    => now(),
                'updated_at'    => now(),
            ],
        ]);
    }
}