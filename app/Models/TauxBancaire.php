<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TauxBancaire extends Model
{
    protected $table = 'taux_bancaires';

    protected $fillable = ['devise_id', 'taux', 'date_taux', 'reference', 'notes'];

    protected $casts = [
        'taux' => 'decimal:5',
        'date_taux' => 'date',
    ];

    public function devise()
    {
        return $this->belongsTo(Devise::class);
    }

    public function achats()
    {
        return $this->hasMany(AchatDevise::class);
    }

    public function scopeDernier($query, int $deviseId)
    {
        return $query->where('devise_id', $deviseId)
            ->orderBy('date_taux', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit(1);
    }

    public static function moyenneTaux(): float
    {
        return (float) self::avg('taux') ?? 0.0;
    }
}