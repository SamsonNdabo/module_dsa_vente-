<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Visite extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'visites';

    protected $fillable = [
        'reference',
        'client_id',
        'prospect_nom',
        'prospect_telephone',
        'agent_id',
        'date_visite',
        'type_visite',
        'statut',
        'objet',
        'compte_rendu',
        'latitude',
        'longitude',
        'adresse_gps',
    ];

    protected $casts = [
        'date_visite' => 'datetime',
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    // Relations
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function agent()
    {
        return $this->belongsTo(AgentDsa::class, 'agent_id');
    }
}