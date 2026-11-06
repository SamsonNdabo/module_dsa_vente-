<?php

namespace App\Services\Sequence;

use App\Models\Sequence;
use Illuminate\Support\Facades\DB;

class SequenceService
{
    /**
     * Génère le prochain numéro séquentiel pour un code donné.
     *
     * Exemple :
     *   suivant('CLIENT', 'CLI')  → "CLI00001", "CLI00002", ...
     *   suivant('DEMANDE', 'DEM') → "DEM00001", "DEM00002", ...
     *
     * Le prefixe est optionnel : s'il est passé, il est utilisé pour créer
     * la séquence si elle n'existe pas encore.
     */
    public function suivant(string $code, ?string $prefixe = null): string
    {
        return DB::transaction(function () use ($code, $prefixe) {
            $sequence = Sequence::where('code', $code)
                ->lockForUpdate()
                ->first();

            if (! $sequence) {
                $sequence = Sequence::create([
                    'code'             => $code,
                    'prefixe'          => $prefixe ?: strtoupper(substr($code, 0, 3)),
                    'dernier_numero'   => 0,
                    'longueur_padding' => 5,
                ]);
            }

            $sequence->increment('dernier_numero');
            $sequence->refresh();

            $numero = str_pad(
                (string) $sequence->dernier_numero,
                $sequence->longueur_padding,
                '0',
                STR_PAD_LEFT
            );

            return $sequence->prefixe . $numero;
        });
    }
}