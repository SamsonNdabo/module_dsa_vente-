<?php
// app/Models/ParametreSysteme.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ParametreSysteme extends Model
{
    use HasFactory;

    protected $table = 'parametres_systeme';
    protected $primaryKey = 'id';

    protected $fillable = [
        'categorie',
        'cle',
        'valeur',
        'type_valeur',
        'description',
        'modifiable',
        'visible'
    ];

    protected $casts = [
        'modifiable' => 'boolean',
        'visible' => 'boolean'
    ];

    /**
     * Récupérer la valeur d'un paramètre
     * 
     * @param string $cle
     * @param mixed $default
     * @return mixed
     */
    public static function getValue($cle, $default = null)
    {
        $parametre = self::where('cle', $cle)->first();
        
        if (!$parametre) {
            return $default;
        }
        
        return $parametre->valeur;
    }

    /**
     * Récupérer la valeur typée d'un paramètre
     * 
     * @param string $cle
     * @param mixed $default
     * @return mixed
     */
    public static function getTypedValue($cle, $default = null)
    {
        $parametre = self::where('cle', $cle)->first();
        
        if (!$parametre) {
            return $default;
        }
        
        switch ($parametre->type_valeur) {
            case 'INTEGER':
                return (int) $parametre->valeur;
            case 'DECIMAL':
                return (float) $parametre->valeur;
            case 'BOOLEAN':
                return filter_var($parametre->valeur, FILTER_VALIDATE_BOOLEAN);
            case 'JSON':
                return json_decode($parametre->valeur, true);
            default:
                return $parametre->valeur;
        }
    }

    /**
     * Mettre à jour un paramètre
     * 
     * @param string $cle
     * @param mixed $valeur
     * @return bool
     */
    public static function setValue($cle, $valeur)
    {
        $parametre = self::where('cle', $cle)->first();
        
        if (!$parametre) {
            return false;
        }
        
        $parametre->valeur = (string) $valeur;
        return $parametre->save();
    }
}