<?php
// app/Models/RfqFournisseur.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\Auditable;
use App\Mail\RfqFournisseurMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class RfqFournisseur extends Model
{
    use SoftDeletes, Auditable;

    protected $table = 'rfq_fournisseurs';

    const STATUTS = [
        'BROUILLON' => 'Brouillon',
        'ENVOYEE' => 'Envoyée',
        'EN_ATTENTE_REPONSE' => 'En attente de réponse',
        'REPONSE_RECUE' => 'Réponse reçue',
        'EXPIREE' => 'Expirée',
        'ANNULEE' => 'Annulée'
    ];

    protected $fillable = [
        'reference',
        'fournisseur_id',
        'devise_id', // <-- Ajout de la devise
        'rfq_group_uuid',
        'date_rfq',
        'date_reponse_souhaitee',
        'statut',
        'notes',
        'cree_par_id',
        'envoye_par_id',
        'date_envoi',
        'documents',
        'metadata'
    ];

    protected $casts = [
        'date_rfq' => 'datetime',
        'date_reponse_souhaitee' => 'date',
        'date_envoi' => 'datetime',
        'documents' => 'array',
        'metadata' => 'array'
    ];

    protected $appends = [
        'libelle_statut',
        'couleur_statut'
    ];

    // RELATIONS
    public function fournisseur()
    {
        return $this->belongsTo(Fournisseur::class, 'fournisseur_id');
    }

    // <-- Ajout de la relation avec la table Devise
    public function devise()
    {
        return $this->belongsTo(Devise::class, 'devise_id');
    }

    public function lignes()
    {
        return $this->hasMany(RfqLigne::class, 'rfq_id');
    }

    public function devis()
    {
        return $this->hasMany(DevisFournisseur::class, 'rfq_id');
    }

    public function creePar()
    {
        return $this->belongsTo(Utilisateur::class, 'cree_par_id');
    }

    public function envoyePar()
    {
        return $this->belongsTo(Utilisateur::class, 'envoye_par_id');
    }

    // ATTRIBUTS
    public function getLibelleStatutAttribute()
    {
        return self::STATUTS[$this->statut] ?? $this->statut;
    }

    public function scopeAvecDevisRecus($query)
    {
        return $query->whereHas('devis');
    }

    public function scopeEnAttenteReponse($query)
    {
        return $query->where('statut', 'ENVOYEE')
            ->whereDoesntHave('devis');
    }

    public function getCouleurStatutAttribute()
    {
        return match($this->statut) {
            'BROUILLON' => 'gray',
            'ENVOYEE' => 'blue',
            'EN_ATTENTE_REPONSE' => 'orange',
            'REPONSE_RECUE' => 'green',
            'EXPIREE' => 'red',
            'ANNULEE' => 'darkred',
            default => 'gray'
        };
    }

    public function rfqGroup()
    {
        return $this->hasMany(RfqFournisseur::class, 'rfq_group_uuid', 'rfq_group_uuid');
    }
    
    public function isMultiFournisseur()
    {
        return !is_null($this->rfq_group_uuid);
    }
    
    public function getAllDevisFromGroup()
    {
        if (!$this->rfq_group_uuid) {
            return $this->devis;
        }
        
        $rfqIds = RfqFournisseur::where('rfq_group_uuid', $this->rfq_group_uuid)
            ->pluck('id');
            
        return DevisFournisseur::whereIn('rfq_id', $rfqIds)
            ->with(['fournisseur', 'lignes.produit'])
            ->get();
    }

    public static function genererReference()
    {
        $prefix = 'RFQ';
        $date = now()->format('Ymd');
        $unique = strtoupper(uniqid());
        
        return "{$prefix}{$date}-{$unique}";
    }

    public function envoyerEmail()
    {
        if (!$this->fournisseur || !$this->fournisseur->email) {
            Log::warning('RFQ - Fournisseur sans email', ['rfq_id' => $this->id]);
            return false;
        }

        try {
            Mail::to($this->fournisseur->email)
                ->send(new RfqFournisseurMail($this));
            
            $this->statut = 'ENVOYEE';
            $this->date_envoi = now();
            $this->envoye_par_id = auth()->id();
            $this->save();

            Log::info('RFQ envoyée', [
                'rfq_id' => $this->id,
                'reference' => $this->reference,
                'email' => $this->fournisseur->email
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Erreur envoi RFQ', [
                'rfq_id' => $this->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function marquerReponseRecue()
    {
        $this->statut = 'REPONSE_RECUE';
        $this->save();
        
        $this->logAudit('RFQ_REPONSE_RECUE', null, [
            'rfq_id' => $this->id,
            'reference' => $this->reference
        ]);
        
        return $this;
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($rfq) {
            if (empty($rfq->reference)) {
                $rfq->reference = static::genererReference();
            }
            if (empty($rfq->date_rfq)) {
                $rfq->date_rfq = now();
            }
            if (empty($rfq->statut)) {
                $rfq->statut = 'BROUILLON';
            }
        });
    }
}