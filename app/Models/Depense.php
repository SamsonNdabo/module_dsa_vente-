<?php

namespace App\Models;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use App\Services\DepenseService;

class Depense extends Model
{
    protected $table = 'depenses';

    protected $fillable = [
        'succursale_id',
        'caisse_id',
        'categorie_depense_id',
        'devise_id',
        'id_creator',
        'montant',
        'motif',
        'reference',
        'date_depense',
        'statut',
        'created_by',
        'valide_par',
        'date_validation',
        'notes_validation',
    ];

    protected $casts = [
        'date_depense' => 'date',
        'date_validation' => 'datetime',
        'montant' => 'decimal:2',
    ];

    public function succursale()
    {
        return $this->belongsTo(Succursale::class);
    }

    public function caisse()
    {
        return $this->belongsTo(Caisses::class);
    }

    public function categorie()
    {
        return $this->belongsTo(CategorieDepense::class, 'categorie_depense_id');
    }

    public function devise()
    {
        return $this->belongsTo(Devise::class);
    }

    public function createur()
    {
        return $this->belongsTo(Utilisateur::class, 'created_by');
    }

    public function validateur()
    {
        return $this->belongsTo(Utilisateur::class, 'valide_par');
    }

    // Scopes
    public function scopeEnAttente($query)
    {
        return $query->where('statut', 'EN_ATTENTE');
    }

    public function scopeValide($query)
    {
        return $query->where('statut', 'VALIDE');
    }

    public function scopeRejete($query)
    {
        return $query->where('statut', 'REJETE');
    }

    public function scopeParSuccursale($query, $succursaleId)
    {
        return $query->where('succursale_id', $succursaleId);
    }

    // Méthodes métier
    public function valider($validateurId, $notes = null)
    {
        if ($this->statut !== 'EN_ATTENTE') {
            throw new \Exception('Seule une dépense en attente peut être validée.');
        }
        

        // Vérifier les plafonds avant de valider
        $service = new DepenseService();
        $service->verifierPlafondDepense((float) $this->montant, $this->devise_id, $this->succursale_id);
        $service->verifierPlafondJournalier($this->succursale_id, (float) $this->montant, $this->devise_id);


        // Débiter la caisse
        DB::transaction(function () use ($validateurId, $notes) {
            $caisse = $this->caisse;
            if (!$caisse->debiter(
                (float) $this->montant,
                $this->devise_id,
                "Dépense : " . $this->motif,
                $this->reference ?? 'DEP-' . $this->id,
                $validateurId,
                'DEPENSE' ,
                $this->id
            )) {
                throw new \Exception('Échec du débit de la caisse. Solde insuffisant ou caisse non opérationnelle.');
            }

            $this->statut = 'VALIDE';
            $this->valide_par = $validateurId;
            $this->date_validation = now();
            $this->notes_validation = $notes;
            $this->save();
        });

        return $this;
    }

    public function rejeter($validateurId, $notes)
    {
        if ($this->statut !== 'EN_ATTENTE') {
            throw new \Exception('Seule une dépense en attente peut être rejetée.');
        }

        $this->statut = 'REJETE';
        $this->valide_par = $validateurId;
        $this->date_validation = now();
        $this->notes_validation = $notes;
        $this->save();

        return $this;
    }

    public function idByCodeDepense($code)
    {
        return $this->where('code', $code)->value('id');
    }
}