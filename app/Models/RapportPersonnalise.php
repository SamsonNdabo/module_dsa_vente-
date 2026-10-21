<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class RapportPersonnalise extends Model
{
    use HasFactory;

    protected $table = 'rapports_personnalises';
    protected $primaryKey = 'id';

    protected $fillable = [
        'code_rapport',
        'nom_rapport',
        'description',
        'type_rapport',
        'public_cible',
        'requete_sql',
        'parametres_json',
        'periode_defaut',
        'format_sortie',
        'planification_cron',
        'actif'
    ];

    protected $casts = [
        'parametres_json' => 'array',
        'actif' => 'boolean'
    ];

    public function executions()
    {
        return $this->hasMany(ExecutionRapport::class, 'rapport_id');
    }

    public function getDerniereExecutionAttribute()
    {
        return $this->executions()->latest('date_execution')->first();
    }

    public function getNombreExecutionsAttribute()
    {
        return $this->executions()->count();
    }

    public function getTempsMoyenExecutionAttribute()
    {
        return $this->executions()
            ->where('statut_execution', 'SUCCES')
            ->avg('duree_execution_ms');
    }

    public function scopeActifs($query)
    {
        return $query->where('actif', true);
    }

    public function scopeParType($query, $type)
    {
        return $query->where('type_rapport', $type);
    }

    public function scopeParPublic($query, $public)
    {
        return $query->where('public_cible', $public);
    }

    public function executer($parametres = [], $utilisateurId = null)
    {
        $debut = microtime(true);

        try {
            // Fusionner les paramètres par défaut
            $params = array_merge($this->parametres_json ?? [], $parametres);

            // Exécuter la requête SQL
            $resultats = DB::select($this->requete_sql, $params);

            $duree = (microtime(true) - $debut) * 1000;

            $execution = $this->executions()->create([
                'user_id' => $utilisateurId ?? auth()->id(),
                'parametres_utilisation_json' => $params,
                'date_execution' => now(),
                'duree_execution_ms' => $duree,
                'statut_execution' => 'SUCCES',
                'resultat_json' => $resultats
            ]);

            return $execution;

        } catch (\Exception $e) {
            $duree = (microtime(true) - $debut) * 1000;

            $execution = $this->executions()->create([
                'user_id' => $utilisateurId ?? auth()->id(),
                'parametres_utilisation_json' => $parametres,
                'date_execution' => now(),
                'duree_execution_ms' => $duree,
                'statut_execution' => 'ECHEC',
                'erreur_message' => $e->getMessage()
            ]);

            return $execution;
        }
    }

    public function exporter($format, $executionId = null)
    {
        $execution = $executionId ? ExecutionRapport::find($executionId) : $this->derniere_execution;

        if (!$execution || !$execution->resultat_json) {
            throw new \Exception('Aucune donnée à exporter');
        }

        switch ($format) {
            case 'csv':
                return $this->genererCSV($execution);
            case 'excel':
                return $this->genererExcel($execution);
            case 'pdf':
                return $this->genererPDF($execution);
            default:
                return $execution->resultat_json;
        }
    }

    private function genererCSV($execution)
    {
        $data = $execution->resultat_json;
        if (empty($data)) {
            throw new \Exception('Aucune donnée à exporter');
        }

        $output = fopen('php://temp', 'r+');
        
        // Entêtes
        fputcsv($output, array_keys((array) $data[0]));

        // Données
        foreach ($data as $row) {
            fputcsv($output, (array) $row);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    private function genererExcel($execution)
    {
        // À implémenter avec Laravel Excel
        throw new \Exception('Export Excel à implémenter');
    }

    private function genererPDF($execution)
    {
        // À implémenter avec DomPDF
        throw new \Exception('Export PDF à implémenter');
    }
}