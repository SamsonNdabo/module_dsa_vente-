<?php
// app/Models/ClientHistoriqueConnexion.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientHistoriqueConnexion extends Model
{
    use HasFactory;

    protected $table = 'client_historique_connexions';

    protected $fillable = [
        'client_id',
        'ip_address',
        'login_at',
        'success'
    ];

    protected $casts = [
        'login_at' => 'datetime',
        'success' => 'boolean'
    ];

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }
}