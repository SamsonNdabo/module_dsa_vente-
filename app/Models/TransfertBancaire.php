<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransfertBancaire extends Model
{
    use HasFactory;

    // Nom de la table associée dans MySQL
    protected $table = 'transfert_bancaire';

    // Clé primaire
    protected $primaryKey = 'id';

    // Liste des champs assignables en masse (Mass Assignment)
    protected $fillable = [
        'id_transaction_globale',
        'id_operateur_bancaire',
        'reference_transfert',
        'reference_operateur',
        'id_creator',
        'caisse_id',
        'succursale_id',
        'type_operation',
        'montant',
        'devise_id',
        'statut',
        'description',
    ];

    // Typage automatique des colonnes (Casting)
    protected $casts = [
        'montant'                => 'decimal:4',
        'id_transaction_globale' => 'integer',
        'id_operateur_bancaire'  => 'integer',
        'id_creator'             => 'integer',
        'caisse_id'              => 'integer',
        'succursale_id'          => 'integer',
        'devise_id'              => 'integer',
        'created_at'             => 'datetime',
        'updated_at'             => 'datetime',
    ];

    // ── Relations (Eloquent Relationships) ───────────────────────────────

    /**
     * Obtient la transaction globale liée à ce transfert bancaire.
     */
    public function transactionGlobale()
    {
        return $this->belongsTo(Transaction_Globale::class, 'id_transaction_globale');
    }

    /**
     * Obtient l'opérateur de transfert international associé.
     */
    public function operateurBancaire()
    {
        return $this->belongsTo(OperateursInternational::class, 'id_operateur_bancaire');
    }

    /**
     * Obtient l'utilisateur (caissier/agent) ayant créé ce transfert.
     */
    public function creator()
    {
        return $this->belongsTo(Utilisateur::class, 'id_creator');
    }

    /**
     * Obtient la succursale d'où le transfert a été effectué.
     */
    public function succursale()
    {
        return $this->belongsTo(Succursale::class, 'succursale_id');
    }

    /**
     * Obtient la caisse physique associée au transfert.
     */
    public function caisse()
    {
        // Ajustez le nom du modèle (Caisse ou Caisses) selon votre projet
        return $this->belongsTo(Caisses::class, 'caisse_id');
    }

    /**
     * Obtient la devise monétaire du montant.
     */
    public function devise()
    {
        // Ajustez le nom du modèle de devise (Devise ou Devises) selon votre projet
        return $this->belongsTo(Devise::class, 'devise_id');
    }
}
