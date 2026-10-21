<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArchiveJournalGeneral extends Model
{
    protected $table = 'archive_journal_general';
    //
    protected $fillable = [
        'ecriture_id',
        'numero_ecriture',
        'date_ecriture',
        'date_valeur',
        'type_operation',
        'reference_operation',
        'operation_id',
        'compte_debit_id',
        'compte_credit_id',
        'montant',
        'devise',
        'taux_change',
        'montant_devise',
        'description',
        'succursale_id',
        'caisse_id',
        'client_id',
        'piece_justificative',
        'numero_piece',
        'centre_cout_id',
        'projet_id',
        'valide_par',
        'date_validation',
        'statut',
        'date_creation',
    ];

    protected $casts = [
        'montant'        => 'decimal:4',
        'taux_change'    => 'decimal:4',
        'montant_devise' => 'decimal:4',
        'date_ecriture'  => 'date:Y-m-d',
        'date_valeur'    => 'date:Y-m-d',
        'date_validation'=> 'datetime',
        'date_creation'  => 'datetime',
    ];

    // ============================================================
    // RELATIONS
    // ============================================================

    public function compteDebit()
    {
        return $this->belongsTo(CompteComptable::class, 'compte_debit_id');
    }

    public function compteCredit()
    {
        return $this->belongsTo(CompteComptable::class, 'compte_credit_id');
    }

    public function caisse()
    {
        return $this->belongsTo(Caisses::class, 'caisse_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function validePar()
    {
        return $this->belongsTo(Utilisateur::class, 'valide_par');
    }
}
