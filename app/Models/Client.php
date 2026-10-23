<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;

class Client extends Authenticatable
{
    use HasFactory, SoftDeletes, Notifiable, HasApiTokens;

    protected $table = 'clients';

    /* ═══════════════════════════════════════════════════════════
       CONSTANTES
       ═══════════════════════════════════════════════════════════ */

    const CATEGORIES = [
        'STANDARD' => ['label' => 'Standard', 'seuil_achats' => 0,  'seuil_montant' => 0],
        'PREMIUM'  => ['label' => 'Premium',  'seuil_achats' => 10, 'seuil_montant' => 1000],
        'VIP'      => ['label' => 'VIP',      'seuil_achats' => 50, 'seuil_montant' => 5000],
    ];

    const TYPES_CLIENT = [
        'PERSONNE_PHYSIQUE' => 'Personne physique',
        'ENTREPRISE'        => 'Entreprise',
        'AUTRE'             => 'Autre',
    ];

    const SEXES       = ['M', 'F'];
    const STATUTS     = ['ACTIF', 'INACTIF', 'BLOQUE', 'EN_ATTENTE'];
    const STATUTS_DSA = ['ACTIF', 'INACTIF', 'BLOQUE'];
    const KYC_STATUTS = ['AUCUN', 'EN_ATTENTE', 'EN_COURS', 'VALIDE', 'REJETE'];
    const SOURCES     = ['DSA', 'POS', 'IMPORT'];

    /* ═══════════════════════════════════════════════════════════
       FILLABLE / HIDDEN / CASTS / APPENDS
       ═══════════════════════════════════════════════════════════ */

    protected $fillable = [
        // POS
        'code_client', 'nom_complet', 'email', 'telephone', 'password', 'adresse',
        'categorie_client', 'statut', 'nombre_achats', 'total_achats',
        'date_dernier_achat', 'dernier_connexion',

        // DSA / fusion
        'type_client',
        'agent_dsa_id', 'zone_id', 'sexe', 'date_naissance', 'telephone_normalise',
        'profession', 'activite_economique', 'piece_identite_type',
        'piece_identite_numero', 'kyc_statut', 'source', 'local_uuid', 'metadata',
        'cree_par', 'modifie_par',
        'raison_sociale', 'numero_rc', 'numero_impot', 'representant_legal',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'date_naissance'     => 'date',
        'date_dernier_achat' => 'datetime',
        'dernier_connexion'  => 'datetime',
        'total_achats'       => 'decimal:2',
        'metadata'           => 'array',
    ];

    protected $appends = [
        'statut_label',
        'categorie_label',
        'type_client_label',
        'kyc_complet',
    ];

    /* ═══════════════════════════════════════════════════════════
       BOOT
       ═══════════════════════════════════════════════════════════ */

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($client) {
            if (empty($client->code_client)) {
                $client->code_client = static::genererCodeClient();
            }
            if (! empty($client->telephone) && empty($client->telephone_normalise)) {
                $client->telephone_normalise = preg_replace('/[^0-9]/', '', $client->telephone);
            }
        });

        static::updating(function ($client) {
            if ($client->isDirty('telephone')) {
                $client->telephone_normalise = preg_replace('/[^0-9]/', '', $client->telephone ?? '');
            }
        });
    }

    /* ═══════════════════════════════════════════════════════════
       RELATIONS POS
       ═══════════════════════════════════════════════════════════ */

    public function paiements()            { return $this->hasMany(Paiement::class, 'client_id'); }
    public function kycDocuments()         { return $this->hasMany(KycDocument::class, 'client_id'); }
    public function historiqueConnexions() { return $this->hasMany(ClientHistoriqueConnexion::class, 'client_id'); }
    public function factures()             { return $this->hasMany(Facture::class, 'client_id'); }
    public function notifications()        { return $this->hasMany(ClientNotification::class, 'client_id'); }
    public function commandes()            { return $this->hasMany(CommandeClient::class, 'client_id'); }

    /* ═══════════════════════════════════════════════════════════
       RELATIONS DSA (fusion)
       ═══════════════════════════════════════════════════════════ */

    public function agentDsa()
    {
        return $this->belongsTo(AgentDsa::class, 'agent_dsa_id');
    }

    public function zone()
    {
        return $this->belongsTo(Zone::class, 'zone_id');
    }

    public function createur()
    {
        return $this->belongsTo(Utilisateur::class, 'cree_par');
    }

    public function modificateur()
    {
        return $this->belongsTo(Utilisateur::class, 'modifie_par');
    }

    public function demandes()
    {
        return $this->hasMany(Demande::class, 'client_id');
    }

    public function kycDossiers()
    {
        return $this->hasMany(KycDossier::class, 'client_id');
    }

    public function comptesDsa()
    {
        return $this->hasMany(CompteDsa::class, 'client_id');
    }

    public function consentements()
    {
        return $this->hasMany(ConsentementClient::class, 'client_id');
    }

    /* ═══════════════════════════════════════════════════════════
       ACCESSORS
       ═══════════════════════════════════════════════════════════ */

    public function getStatutLabelAttribute(): string
    {
        return match ($this->statut) {
            'ACTIF'      => 'Actif',
            'INACTIF'    => 'Inactif',
            'BLOQUE'     => 'Bloqué',
            'EN_ATTENTE' => 'En attente',
            default      => $this->statut ?? 'Inconnu',
        };
    }

    public function getCategorieLabelAttribute(): string
    {
        return self::CATEGORIES[$this->categorie_client]['label'] ?? $this->categorie_client ?? 'Standard';
    }

    public function getTypeClientLabelAttribute(): string
    {
        return self::TYPES_CLIENT[$this->type_client] ?? $this->type_client ?? '—';
    }

    public function getKycStatutLabelAttribute(): string
    {
        return match ($this->kyc_statut) {
            'AUCUN'      => 'Aucun KYC',
            'EN_ATTENTE' => 'En attente',
            'EN_COURS'   => 'En cours',
            'VALIDE'     => 'Validé',
            'REJETE'     => 'Rejeté',
            default      => $this->kyc_statut,
        };
    }

    public function getSexeLabelAttribute(): string
    {
        return match ($this->sexe) {
            'M' => 'Masculin',
            'F' => 'Féminin',
            default => '—',
        };
    }

    public function getKycCompletAttribute(): bool
    {
        $documentsRequis = ['CIN', 'ATTESTATION'];
        $documentsSoumis = $this->kycDocuments()
            ->where('statut', 'VERIFIE')
            ->pluck('type_document')
            ->toArray();
        return empty(array_diff($documentsRequis, $documentsSoumis));
    }

    /* ═══════════════════════════════════════════════════════════
       MÉTHODES MÉTIER POS
       ═══════════════════════════════════════════════════════════ */

    public static function genererCodeClient(): string
    {
        $prefix = 'CLI';
        $year   = date('Y');
        $random = str_pad(random_int(1, 99999), 5, '0', STR_PAD_LEFT);
        $code   = "{$prefix}{$year}{$random}";
        while (self::where('code_client', $code)->exists()) {
            $random = str_pad(random_int(1, 99999), 5, '0', STR_PAD_LEFT);
            $code   = "{$prefix}{$year}{$random}";
        }
        return $code;
    }

    public static function genererMotDePasseTemporaire($id): string
    {
        return substr(md5($id), 0, 8);
    }

    public function setPasswordAttribute($value)
    {
        if (! empty($value)) {
            $this->attributes['password'] = Hash::make($value);
        }
    }

    public function enregistrerConnexion(string $ip, bool $success = true): void
    {
        $this->historiqueConnexions()->create([
            'ip_address' => $ip,
            'login_at'   => now(),
            'success'    => $success,
        ]);
        if ($success) {
            $this->dernier_connexion = now();
            $this->save();
        }
    }

    public function incrementerAchats(float $montant): void
    {
        $this->nombre_achats++;
        $this->total_achats += $montant;
        $this->date_dernier_achat = now();
        $this->save();
        $this->verifierUpgradeCategorie();
    }

    public function verifierUpgradeCategorie(): void
    {
        if ($this->categorie_client === 'STANDARD') {
            if ($this->nombre_achats >= self::CATEGORIES['PREMIUM']['seuil_achats'] ||
                $this->total_achats >= self::CATEGORIES['PREMIUM']['seuil_montant']) {
                $this->categorie_client = 'PREMIUM';
                $this->save();
            }
        }
        if ($this->categorie_client === 'PREMIUM') {
            if ($this->nombre_achats >= self::CATEGORIES['VIP']['seuil_achats'] ||
                $this->total_achats >= self::CATEGORIES['VIP']['seuil_montant']) {
                $this->categorie_client = 'VIP';
                $this->save();
            }
        }
    }

    public function activer()    { $this->statut = 'ACTIF';   $this->save(); }
    public function desactiver() { $this->statut = 'INACTIF'; $this->save(); }
    public function bloquer()    { $this->statut = 'BLOQUE';  $this->save(); }
    public function debloquer()  { $this->statut = 'ACTIF';   $this->save(); }

    public function annulerAchat(float $montant): void
    {
        $this->nombre_achats = max(0, $this->nombre_achats - 1);
        $this->total_achats  = max(0, (float) $this->total_achats - $montant);
        $this->save();
    }

    public function reinitialiserMotDePasse(): string
    {
        $nouveauMotDePasse = self::genererMotDePasseTemporaire($this->id);
        $this->password = $nouveauMotDePasse;
        $this->save();
        return $nouveauMotDePasse;
    }

    /* ═══════════════════════════════════════════════════════════
       SCOPES
       ═══════════════════════════════════════════════════════════ */

    public function scopeActifs($q)                { return $q->where('statut', 'ACTIF'); }
    public function scopeEnAttente($q)             { return $q->where('statut', 'EN_ATTENTE'); }
    public function scopeParCategorie($q, $cat)    { return $q->where('categorie_client', $cat); }
    public function scopeParType($q, $type)        { return $q->where('type_client', $type); }
    public function scopeKycValide($q)             { return $q->where('kyc_statut', 'VALIDE'); }
    public function scopeKycNonValide($q)          { return $q->whereIn('kyc_statut', ['AUCUN','EN_ATTENTE','EN_COURS','REJETE']); }
    public function scopePourAgent($q, int $id)    { return $q->where('agent_dsa_id', $id); }

    public function scopeKycIncomplet($query)
    {
        return $query->whereDoesntHave('kycDocuments', function ($q) {
            $q->where('statut', 'VERIFIE')
              ->whereIn('type_document', ['CIN', 'ATTESTATION']);
        });
    }

    public function scopeRecherche($q, string $term)
    {
        $t = '%' . $term . '%';
        return $q->where(function ($w) use ($t) {
            $w->where('nom_complet', 'like', $t)
              ->orWhere('code_client', 'like', $t)
              ->orWhere('telephone', 'like', $t)
              ->orWhere('telephone_normalise', 'like', $t)
              ->orWhere('email', 'like', $t)
              ->orWhere('raison_sociale', 'like', $t);
        });
    }
}