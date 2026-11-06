<?php

namespace App\Models\Agent;

use Illuminate\Database\Eloquent\Model;

class AgentStatutHistorique extends Model
{
    public $timestamps = false; // created_at géré par la base, l'historique est immuable

    protected $table = 'agent_statut_historiques';

    protected $fillable = ['agent_dsa_id', 'ancien_statut', 'nouveau_statut', 'motif', 'effectue_par'];

    protected $casts = ['created_at' => 'datetime'];
}
