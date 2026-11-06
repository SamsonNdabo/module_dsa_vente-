<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

class CompteEpargne extends Model
{
    use Auditable;

    protected $table = 'comptes_epargne';
    protected $fillable = [
        'numero_compte','client_id','produit_epargne_id','agent_dsa_id','succursale_id','devise_id',
        'solde','solde_bloque','statut','date_ouverture','date_cloture','date_derniere_operation','created_by',
    ];
    protected $casts = [
        'solde'                    => 'decimal:4',
        'solde_bloque'             => 'decimal:4',
        'date_ouverture'           => 'date',
        'date_cloture'             => 'date',
        'date_derniere_operation'  => 'datetime',
    ];

    public function client()    { return $this->belongsTo(Client::class, 'client_id'); }
    public function produit()   { return $this->belongsTo(ProduitEpargne::class, 'produit_epargne_id'); }
    public function agentDsa()  { return $this->belongsTo(AgentDsa::class, 'agent_dsa_id'); }
    public function succursale(){ return $this->belongsTo(Succursale::class, 'succursale_id'); }
    public function devise()    { return $this->belongsTo(Devise::class, 'devise_id'); }
    public function transactions() { return $this->hasMany(TransactionEpargne::class, 'compte_epargne_id'); }
    public function partenaire()
    {
        return $this->belongsTo(Partenaire::class, 'partenaire_id');
    }
}