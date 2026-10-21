<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatCaissePermission extends Model
{
    protected $table = 'cat_caisse_permissions';
    protected $fillable = [
        'code', 
        'libelle', 
        'description', 
        'actif'
    ];
}