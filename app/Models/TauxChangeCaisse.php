<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TauxChangeCaisse extends Model
{
    protected $table = 'taux_change_caisse';

    protected $fillable = [
        'caisse_id',
        'devise_source',
        'devise_cible',
        'taux_achat',
        'taux_vente',
        'ancien_taux_achat',
        'ancien_taux_vente',
        'date_taux',
        'modifie_par',
        'motif',
        'statut',
        'valide_par',
        'date_validation',
        'notes_validation',
    ];

    protected $casts = [
        'taux_achat'        => 'decimal:4',
        'taux_vente'        => 'decimal:4',
        'ancien_taux_achat' => 'decimal:4',
        'ancien_taux_vente' => 'decimal:4',
        'date_taux'         => 'date',
        'date_validation'   => 'datetime',
    ];

    // ============================================================
    // RELATIONS
    // ============================================================

    public function caisse()
    {
        return $this->belongsTo(Caisses::class, 'caisse_id');
    }

    public function deviseSource()
    {
        return $this->belongsTo(Devise::class, 'devise_source');
    }

    public function deviseCible()
    {
        return $this->belongsTo(Devise::class, 'devise_cible');
    }

    public function modificateur()
    {
        return $this->belongsTo(Utilisateur::class, 'modifie_par');
    }

    public function validateur()
    {
        return $this->belongsTo(Utilisateur::class, 'valide_par');
    }

    // ============================================================
    // SCOPES
    // ============================================================

    public function scopeEnAttente($query)
    {
        return $query->where('statut', 'EN_ATTENTE');
    }

    public function scopeValide($query)
    {
        return $query->where('statut', 'VALIDE');
    }

    public function scopeParCaisse($query, int $caisseId)
    {
        return $query->where('caisse_id', $caisseId);
    }

    // ============================================================
    // LOGIQUE MÉTIER — miroir de Taux_Change_Succursale
    // ============================================================

    public static function getTauxActif(
        int $caisseId,
        int $deviseSourceId,
        int $deviseCibleId
    ): ?self {
        // Priorité 1 — EN_ATTENTE (utilisé immédiatement même avant validation)
        $enAttente = self::where('caisse_id', $caisseId)
            ->where('devise_source', $deviseSourceId)
            ->where('devise_cible', $deviseCibleId)
            ->where('statut', 'EN_ATTENTE')
            ->orderBy('created_at', 'desc')
            ->first();

        if ($enAttente) return $enAttente;

        // Priorité 2 — VALIDE
        return self::where('caisse_id', $caisseId)
            ->where('devise_source', $deviseSourceId)
            ->where('devise_cible', $deviseCibleId)
            ->where('statut', 'VALIDE')
            ->orderBy('created_at', 'desc')
            ->first();
    }

    public static function getDernierTauxValide(
        int $caisseId,
        int $deviseSourceId,
        int $deviseCibleId
    ): ?self {
        return self::where('caisse_id', $caisseId)
            ->where('devise_source', $deviseSourceId)
            ->where('devise_cible', $deviseCibleId)
            ->where('statut', 'VALIDE')
            ->orderBy('created_at', 'desc')
            ->first();
    }

    public function valider(int $validateurId, ?string $notes = null): bool
    {
        $this->statut           = 'VALIDE';
        $this->valide_par       = $validateurId;
        $this->date_validation  = now()->format('Y-m-d H:i:s');
        $this->notes_validation = $notes;
        return $this->save();
    }

    public function rejeter(int $validateurId, string $notes): bool
    {
        $this->statut           = 'REJETE';
        $this->valide_par       = $validateurId;
        $this->date_validation  = now()->format('Y-m-d H:i:s');
        $this->notes_validation = $notes;
        return $this->save();
    }
}