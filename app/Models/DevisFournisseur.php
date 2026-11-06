<?php
// app/Models/DevisFournisseur.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\Auditable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DevisFournisseur extends Model
{
    use SoftDeletes, Auditable;

    protected $table = 'devis_fournisseurs';

    const STATUTS = [
        'RECU' => 'Reçu',
        'EN_NEGOCIATION' => 'En négociation',
        'VALIDE' => 'Validé',
        'REFUSE' => 'Refusé',
        'EXPIRE' => 'Expiré'
    ];


protected $fillable = [
    'reference',
    'rfq_id',
    'fournisseur_id',
    'date_devis',
    'date_validite',
    'statut',
    'frais_livraison',
    'remise_globale',
    'conditions_paiement',
    'delai_livraison',
    'notes',
    'notes_internes',
    'fichier_pdf',  //  Champ pour le document
    'valide_par_id',
    'date_validation',
    'metadata'
];

// Accesseur pour l'URL du fichier PDF
public function getFichierPdfUrlAttribute()
{
    if ($this->fichier_pdf && Storage::disk('public')->exists($this->fichier_pdf)) {
        return Storage::url($this->fichier_pdf);
    }
    return null;
}

// Accesseur pour savoir si un fichier existe
public function getHasFichierPdfAttribute()
{
    return $this->fichier_pdf && Storage::disk('public')->exists($this->fichier_pdf);
}


    protected $casts = [
        'date_devis' => 'date',
        'date_validite' => 'date',
        'date_validation' => 'datetime',
        'frais_livraison' => 'decimal:2',
        'remise_globale' => 'decimal:2',
        'metadata' => 'array'
    ];

    protected $appends = [
        'libelle_statut',
        'couleur_statut',
        'total_ht',
        'total_ttc'
    ];

    // RELATIONS
    public function rfq()
    {
        return $this->belongsTo(RfqFournisseur::class, 'rfq_id');
    }

    public function fournisseur()
    {
        return $this->belongsTo(Fournisseur::class, 'fournisseur_id');
    }

    public function lignes()
    {
        return $this->hasMany(DevisLigne::class, 'devis_id');
    }

    public function validePar()
    {
        return $this->belongsTo(Utilisateur::class, 'valide_par_id');
    }

    public function commande()
    {
        return $this->hasOne(CommandeFournisseur::class, 'devis_id');
    }

    // ATTRIBUTS
    public function getLibelleStatutAttribute()
    {
        return self::STATUTS[$this->statut] ?? $this->statut;
    }

    public function getCouleurStatutAttribute()
    {
        return match($this->statut) {
            'RECU' => 'blue',
            'EN_NEGOCIATION' => 'orange',
            'VALIDE' => 'green',
            'REFUSE' => 'red',
            'EXPIRE' => 'gray',
            default => 'gray'
        };
    }

    public function getTotalHtAttribute()
    {
        $total = $this->lignes()->sum(DB::raw('quantite * prix_unitaire_ht'));
        return $total - ($total * ($this->remise_globale / 100));
    }

    public function getTotalTtcAttribute()
    {
        return $this->total_ht * 1.16 + ($this->frais_livraison ?? 0);
    }

    // MÉTHODES
    public static function genererReference()
    {
        $prefix = 'DEV';
        $date = now()->format('Ymd');
        $count = static::whereDate('created_at', today())->count() + 1;
        return "{$prefix}{$date}-" . str_pad($count, 3, '0', STR_PAD_LEFT);
    }

    public function valider($userId)
    {
        if ($this->statut === 'VALIDE') {
            throw new \Exception('Ce devis est déjà validé');
        }

        $this->statut = 'VALIDE';
        $this->valide_par_id = $userId;
        $this->date_validation = now();
        $this->save();

        $this->logAudit('VALIDATION_DEVIS', null, [
            'devis_id' => $this->id,
            'reference' => $this->reference,
            'fournisseur_id' => $this->fournisseur_id
        ]);

        return $this;
    }

    public function refuser($motif)
    {
        $this->statut = 'REFUSE';
        $this->notes_internes = $motif;
        $this->save();

        $this->logAudit('REFUS_DEVIS', null, [
            'devis_id' => $this->id,
            'motif' => $motif
        ]);

        return $this;
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($devis) {
            if (empty($devis->reference)) {
                $devis->reference = static::genererReference();
            }
            if (empty($devis->statut)) {
                $devis->statut = 'RECU';
            }
        });
    }
}