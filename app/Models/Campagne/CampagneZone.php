<?php

namespace App\Models\Campagne;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Zone;
use App\Models\Utilisateur;

class CampagneZone extends Model
{
    use HasFactory;

    protected $table = 'campagne_zones';

    protected $fillable = [
        'id_campagne',
        'id_zone',
        'id_superviseur',
    ];

    public function campagne(): BelongsTo
    {
        return $this->belongsTo(Campagne::class, 'id_campagne');
    }


    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'id_zone');
    }

   
    public function superviseur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'id_superviseur');
    }
}