<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Sanctum\HasApiTokens;

class ExternalSystem extends Model
{
    use SoftDeletes, HasApiTokens;

    protected $table = 'external_systems';
    protected $fillable = [
        'nom', 
        'slug', 
        'api_key', 
        'description',
        'permissions', 
        'actif', 
        'user_id', 
        'last_used_at'
    ];

    protected $casts = [
        'permissions' => 'array',
        'actif'       => 'boolean',
        'last_used_at'=> 'datetime',
    ];

    // Relation vers l'utilisateur lié
    public function utilisateur()
    {
        return $this->belongsTo(Utilisateur::class, 'user_id');
    }

    // Vérifier les permissions
    public function peut(string $action): bool
    {
        if (!$this->actif) return false;
        return \in_array($action, $this->permissions ?? []);
    }

}