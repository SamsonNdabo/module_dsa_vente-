<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;

class ChiffrerOperateurs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:chiffrer-operateurs';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $operateurs = \DB::table('operateurs_mobile_money')->get();

        foreach ($operateurs as $operateur) {
            // Déchiffrer les valeurs actuellement mal chiffrées
            // puis rechiffrer avec encryptString
            try {
                $merchantToken = Crypt::decryptString($operateur->merchant_token);
            } catch (\Exception $e) {
                // Si ça échoue c'est qu'elles sont chiffrées avec encrypt()
                // donc on décrypte avec decrypt() qui désérialise
                $merchantToken = decrypt($operateur->merchant_token);
            }

            try {
                $merchantCode = Crypt::decryptString($operateur->merchant_code);
            } catch (\Exception $e) {
                $merchantCode = decrypt($operateur->merchant_code);
            }

            try {
                $baseUrl = Crypt::decryptString($operateur->base_url);
            } catch (\Exception $e) {
                $baseUrl = decrypt($operateur->base_url);
            }

            try {
                $callbackUrl = Crypt::decryptString($operateur->callback_url);
            } catch (\Exception $e) {
                $callbackUrl = decrypt($operateur->callback_url);
            }

            \DB::table('operateurs_mobile_money')
                ->where('id', $operateur->id)
                ->update([
                    'merchant_token' => Crypt::encryptString($merchantToken),
                    'merchant_code'  => Crypt::encryptString($merchantCode),
                    'base_url'       => Crypt::encryptString($baseUrl),
                    'callback_url'   => Crypt::encryptString($callbackUrl),
                ]);

            $this->info("Opérateur {$operateur->code} rechiffré correctement.");
        }
    }
}
