<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CaissePermission extends Model
{
    protected $table = 'caisse_permissions';
    protected $fillable = ['caisse_id', 'actions'];

    protected $casts = [
        'actions' => 'array',
    ];

    public function caisse()
    {
        return $this->belongsTo(Caisses::class, 'caisse_id');
    }

    // Vérifie si une action est autorisée pour la caisse
    public function allows(string $action): bool
    {
        return \in_array($action, $this->actions ?? []);
    }
}