<?php

namespace App\Models\Sequence;

use Illuminate\Database\Eloquent\Model;

class Sequence extends Model
{
    protected $table = 'sequences';

    protected $fillable = [
        'code', 'prefixe', 'periode', 'dernier_numero', 'longueur_padding',
    ];

    protected $casts = [
        'dernier_numero'    => 'integer',
        'longueur_padding'  => 'integer',
    ];
}