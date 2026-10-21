<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StatutConfigurable extends Model
{
    protected $table = 'statuts_configurables';

    protected $fillable = ['partenaire_id', 'entite', 'code', 'libelle', 'ordre', 'terminal'];

    protected $casts = ['terminal' => 'boolean', 'ordre' => 'integer'];
}
