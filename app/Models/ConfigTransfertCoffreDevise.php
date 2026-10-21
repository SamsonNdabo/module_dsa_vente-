<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConfigTransfertCoffreDevise extends Model
{
    protected $table = 'config_transfert_coffre_devise';
    protected $fillable = [
        'config_id', 
        'devise_id', 
        'montant'
    ];

    public function configuration()
    {
        return $this->belongsTo(ConfigTransfertCoffre::class, 'config_id');
    }

    public function devise()
    {
        return $this->belongsTo(Devise::class, 'devise_id');
    }
}