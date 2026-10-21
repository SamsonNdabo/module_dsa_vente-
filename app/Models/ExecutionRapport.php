<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExecutionRapport extends Model
{
    use HasFactory;

    protected $table = 'executions_rapports';
    protected $primaryKey = 'id';

    protected $fillable = [
        'rapport_id',
        'user_id',
        'parametres_utilisation_json',
        'date_execution',
        'duree_execution_ms',
        'statut_execution',
        'erreur_message',
        'resultat_json',
        'fichier_export'
    ];

    protected $casts = [
        'parametres_utilisation_json' => 'array',
        'date_execution' => 'datetime',
        'resultat_json' => 'array'
    ];

    public function rapport()
    {
        return $this->belongsTo(RapportPersonnalise::class, 'rapport_id');
    }

    public function utilisateur()
    {
        return $this->belongsTo(Utilisateur::class, 'user_id');
    }

    public function getTailleResultatAttribute()
    {
        return strlen(json_encode($this->resultat_json));
    }

    public function getEstSuccesAttribute()
    {
        return $this->statut_execution === 'SUCCES';
    }

    public function getNbLignesAttribute()
    {
        return $this->est_succes ? count($this->resultat_json ?? []) : 0;
    }

    public function getDureeFormateeAttribute()
    {
        if ($this->duree_execution_ms < 1000) {
            return round($this->duree_execution_ms, 2) . ' ms';
        }
        return round($this->duree_execution_ms / 1000, 2) . ' s';
    }
}