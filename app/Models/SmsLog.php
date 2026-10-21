<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsLog extends Model
{
    protected $table    = 'sms_logs';
    protected $fillable = [
        'destinataire',
        'nom_client',
        'contexte',
        'message',
        'message_id',
        'statut',
        'reference',
        'id_succursale',
        'erreur',
    ];
}