<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CategorieDepense extends Model
{
    protected $table = 'categorie_depense';
    protected $fillable = ['code', 'nom', 'description', 'montant_usd', 'actif'];
    protected $casts = ['actif' => 'boolean'];

    public function depenses()
    {
        return $this->hasMany(Depense::class);
    }
    

    public  function idByCodeDepense($code)
    {
        return $this->where('code', $code)->value('id');
    }
    
    public static function codeById (int $id)
    {
        return self::where('id', $id)->firstOrFail()->code;
    }
}