<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsTemplate extends Model
{
    protected $table    = 'sms_templates';
    protected $fillable = ['contexte', 'nom', 'template', 'actif'];
    protected $casts    = ['actif' => 'boolean'];

    public function scopeActif($query)
    {
        return $query->where('actif', true);
    }

    // Remplacer les variables {xxx} par les valeurs fournies
    public function rendu(array $variables): string
    {
        $message = $this->template;

        foreach ($variables as $cle => $valeur) {
            $message = str_replace('{' . $cle . '}', $valeur ?? '', $message);
        }

        return $message;
    }
}