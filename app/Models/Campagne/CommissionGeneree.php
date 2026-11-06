<?php

namespace App\Models\Campagne;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Agent;
use App\Models\Partenaire\Partenaire;

class CommissionGeneree extends Model
{
    use HasFactory;

    protected $table = 'commissions_generees';

    protected $fillable = [
        'id_agent',
        'id_campagne',
        'id_partenaire',
        'id_operation_source',
        'id_regle_commission',
        'montant_calcule',
        'statut',
    ];

    protected $casts = [
        'montant_calcule' => 'decimal:2',
        'statut' => 'string',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'id_agent');
    }

    public function campagne(): BelongsTo
    {
        return $this->belongsTo(Campagne::class, 'id_campagne');
    }

    public function partenaire(): BelongsTo
    {
        return $this->belongsTo(Partenaire::class, 'id_partenaire');
    }

    public function regle(): BelongsTo
    {
        return $this->belongsTo(CampagneRegle::class, 'id_regle_commission');
    }

    public function historique(): HasMany
    {
        return $this->hasMany(CommissionHistorique::class, 'id_commission');
    }

    public function operationSource(): BelongsTo
    {
        return $this->belongsTo(CampagneOperation::class, 'id_operation_source');
    }
}