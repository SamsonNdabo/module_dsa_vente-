<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConfigTransfertCoffre extends Model
{
    protected $table = 'config_transfert_coffre';

    protected $fillable = [
        'nom', 
        'coffre_id', 
        'caisses_ids', 
        'motif', 
        'actif'
    ];

    protected $casts = [
        'caisses_ids' => 'array',
        'actif'       => 'boolean',
    ];

    public function coffre()
    {
        return $this->belongsTo(Caisses::class, 'coffre_id');
    }

    public function devises()
    {
        return $this->hasMany(ConfigTransfertCoffreDevise::class, 'config_id');
    }

    public function validerCaisses(): bool
    {
        if (empty($this->caisses_ids)) return false;
        return Caisses::whereIn('id', $this->caisses_ids)
            ->where('categorie', 'PRINCIPALE')
            ->count() === \count($this->caisses_ids);
    }
}