<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuditSysteme extends Model
{
    use HasFactory;

    protected $table = 'audit_systeme';

    protected $fillable = [
        'table_affectee',
        'type_action',
        'id_enregistrement',
        'ancienne_valeur',
        'nouvelle_valeur',
        'id_utilisateur',
        'adresse_ip',
        'user_agent',
        'description',
        'niveau_importance'
    ];

    protected $casts = [
        'ancienne_valeur' => 'array',
        'nouvelle_valeur' => 'array',
        'created_at' => 'datetime'
    ];

    public function utilisateur()
    {
        return $this->belongsTo(Utilisateur::class, 'id_utilisateur');
    }

    public function scopeParTable($query, $table)
    {
        return $query->where('table_affectee', $table);
    }

    public function scopeParUtilisateur($query, $userId)
    {
        return $query->where('id_utilisateur', $userId);
    }

    public function scopeParPeriode($query, $debut, $fin)
    {
        return $query->whereBetween('created_at', [$debut, $fin]);
    }

    public function scopeParImportance($query, $importance)
    {
        return $query->where('niveau_importance', $importance);
    }

    public function scopeRecherche($query, $term)
    {
        return $query->where('description', 'like', "%{$term}%")
            ->orWhere('table_affectee', 'like', "%{$term}%");
    }

    public function getDetailsAttribute()
    {
        return [
            'ancien' => $this->ancienne_valeur,
            'nouveau' => $this->nouvelle_valeur,
            'differences' => $this->calculerDifferences()
        ];
    }

    protected function calculerDifferences()
    {
        if (!$this->ancienne_valeur || !$this->nouvelle_valeur) {
            return [];
        }
        
        $differences = [];
        foreach ($this->nouvelle_valeur as $key => $value) {
            if (!isset($this->ancienne_valeur[$key]) || $this->ancienne_valeur[$key] != $value) {
                $differences[$key] = [
                    'ancien' => $this->ancienne_valeur[$key] ?? null,
                    'nouveau' => $value
                ];
            }
        }
        
        return $differences;
    }
}