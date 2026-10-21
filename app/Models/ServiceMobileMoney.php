<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceMobileMoney extends Model
{
    protected $table = 'service_mobile_money';
    protected $fillable = [
        'nom', 
        'code', 
        'description', 
        'actif',
        'type'
    ];


    // Scope pratique
    public function scopeMobile($query) { 
        return $query->where('type', 'MOBILE_MONEY'); 
    }
    public function scopeBancaire($query) { 
        return $query->where('type', 'BANCAIRE'); 
    }

    public function caisses()
    {
        return $this->hasMany(Caisses::class, 'service_mobile_money_id');
    }
    
}