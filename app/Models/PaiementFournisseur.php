<?php
// app/Models/PaiementFournisseur.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;
use Illuminate\Support\Facades\DB;

class PaiementFournisseur extends Model
{
    use Auditable;

    protected $table = 'paiements_fournisseurs';

    const MODES_PAIEMENT = [
        'VIREMENT' => 'Virement bancaire',
        'CHEQUE' => 'Chèque',
        'ESPECES' => 'Espèces',
        'MOBILE_MONEY' => 'Mobile money',
        'COMPENSATION' => 'Compensation'
    ];

    const STATUTS = [
        'EN_ATTENTE' => 'En attente',
        'PARTIEL' => 'Paiement partiel',
        'COMPLET' => 'Payé',
        'ANNULE' => 'Annulé',
        'EN_RETARD' => 'En retard'
    ];

    protected $fillable = [
        'reference',
        'commande_id',
        'montant',
        'mode_paiement',
        'reference_transaction',
        'date_paiement',
        'statut',
        'notes',
        'cree_par_id',
        'valide_par_id',
        'date_validation',
        'acompte',
        'solde',
        'date_echeance',
        'penalites',
        'metadata'
    ];

    protected $casts = [
        'montant' => 'decimal:2',
        'acompte' => 'decimal:2',
        'solde' => 'decimal:2',
        'penalites' => 'decimal:2',
        'date_paiement' => 'date',
        'date_echeance' => 'date',
        'date_validation' => 'datetime',
        'metadata' => 'array'
    ];

    protected $appends = [
        'mode_label',
        'statut_label',
        'couleur_statut'
    ];

    // RELATIONS
    public function commande()
    {
        return $this->belongsTo(CommandeFournisseur::class, 'commande_id');
    }

    public function creePar()
    {
        return $this->belongsTo(Utilisateur::class, 'cree_par_id');
    }

    public function validePar()
    {
        return $this->belongsTo(Utilisateur::class, 'valide_par_id');
    }

    // ATTRIBUTS
    public function getModeLabelAttribute()
    {
        return self::MODES_PAIEMENT[$this->mode_paiement] ?? $this->mode_paiement;
    }

    public function getStatutLabelAttribute()
    {
        return self::STATUTS[$this->statut] ?? $this->statut;
    }

    public function getCouleurStatutAttribute()
    {
        return match($this->statut) {
            'EN_ATTENTE' => 'orange',
            'PARTIEL' => 'blue',
            'COMPLET' => 'green',
            'ANNULE' => 'red',
            'EN_RETARD' => 'darkred',
            default => 'gray'
        };
    }

    // MÉTHODES
    public static function genererReference()
    {
        $prefix = 'PAY-FRS';
        $date = now()->format('Ymd');
        $count = static::whereDate('created_at', today())->count() + 1;
        return "{$prefix}-{$date}-" . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    public function valider($userId)
    {
        $this->statut = 'COMPLET';
        $this->valide_par_id = $userId;
        $this->date_validation = now();
        $this->save();

        if ($this->commande) {
            $this->commande->statut = 'PAYEE';
            $this->commande->save();
        }

        $this->logAudit('VALIDATION_PAIEMENT', null, [
            'commande_id' => $this->commande_id,
            'montant' => $this->montant,
            'mode' => $this->mode_paiement
        ]);

        return $this;
    }

    public static function enregistrerPaiementPartiel($commandeId, $montant, $mode, $userId)
    {
        DB::beginTransaction();
        
        try {
            $commande = CommandeFournisseur::findOrFail($commandeId);
            
            $paiementsExistants = self::where('commande_id', $commandeId)->sum('montant');
            $nouveauTotal = $paiementsExistants + $montant;
            $totalCommande = $commande->total_ttc ?? 0;
            
            $statut = $nouveauTotal >= $totalCommande ? 'COMPLET' : 'PARTIEL';
            
            $paiement = self::create([
                'reference' => self::genererReference(),
                'commande_id' => $commandeId,
                'montant' => $montant,
                'mode_paiement' => $mode,
                'date_paiement' => now(),
                'statut' => $statut,
                'cree_par_id' => $userId,
                'acompte' => $paiementsExistants + $montant,
                'solde' => max(0, $totalCommande - ($paiementsExistants + $montant))
            ]);
            
            if ($statut === 'COMPLET') {
                $paiement->valider($userId);
            }
            
            DB::commit();
            
            return $paiement;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public static function historiqueFournisseur($fournisseurId)
    {
        return self::whereHas('commande', function($q) use ($fournisseurId) {
            $q->where('fournisseur_id', $fournisseurId);
        })
        ->with(['commande', 'creePar'])
        ->orderBy('date_paiement', 'desc')
        ->get();
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($paiement) {
            if (empty($paiement->reference)) {
                $paiement->reference = self::genererReference();
            }
            if (empty($paiement->date_paiement)) {
                $paiement->date_paiement = now();
            }
            if (empty($paiement->statut)) {
                $paiement->statut = 'EN_ATTENTE';
            }
        });
    }
}