<?php
// app/Models/Client.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens; 
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;


class Client extends Authenticatable
{
    use HasFactory, SoftDeletes, Notifiable, HasApiTokens;

    protected $table = 'clients';

const CATEGORIES = [
    'STANDARD' => [
        'label' => 'Standard',
        'seuil_achats' => 0,
        'seuil_montant' => 0
    ],

    'PREMIUM' => [
        'label' => 'Premium',
        'seuil_achats' => 10,
        'seuil_montant' => 1000
    ],

    'VIP' => [
        'label' => 'VIP',
        'seuil_achats' => 50,
        'seuil_montant' => 5000
    ]
];
    
    const STATUTS = ['ACTIF', 'INACTIF', 'BLOQUE', 'EN_ATTENTE'];

    protected $fillable = [
        'code_client',
        'nom_complet',
        'email',
        'telephone',
        'password',
        'adresse',
        'categorie_client',
        'statut',
        'nombre_achats',
        'total_achats',
        'date_dernier_achat',
        'dernier_connexion'
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'dernier_connexion' => 'datetime',
        'date_dernier_achat' => 'datetime',
        'total_achats' => 'decimal:2'
    ];

    protected $appends = ['statut_label', 'categorie_label', 'kyc_complet'];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($client) {
            if (empty($client->code_client)) {
                $client->code_client = static::genererCodeClient();
            }
        });
    }

    public function paiements()
    {
        return $this->hasMany(
            Paiement::class,
            'client_id'
        );
    }
    public static function genererCodeClient(): string
    {
        $prefix = 'CLI';
        $year = date('Y');
        $random = str_pad(random_int(1, 99999), 5, '0', STR_PAD_LEFT);
        $code = "{$prefix}{$year}{$random}";
        
        while (self::where('code_client', $code)->exists()) {
            $random = str_pad(random_int(1, 99999), 5, '0', STR_PAD_LEFT);
            $code = "{$prefix}{$year}{$random}";
        }
        
        return $code;
    }

    public static function genererMotDePasseTemporaire($id): string
    {
        return substr(md5($id), 0, 8);
    }

    public function setPasswordAttribute($value)
    {
        if (!empty($value)) {
            $this->attributes['password'] = Hash::make($value);
        }
    }

    // Relations
    public function kycDocuments()
    {
        return $this->hasMany(KycDocument::class, 'client_id');
    }

    public function historiqueConnexions()
    {
        return $this->hasMany(ClientHistoriqueConnexion::class, 'client_id');
    }

    public function factures()
    {
        return $this->hasMany(Facture::class, 'client_id');
    }



        public function notifications()
    {
        return $this->hasMany(ClientNotification::class, 'client_id');
    }



    public function commandes()
    {
        return $this->hasMany(CommandeClient::class, 'client_id');
    }

    // Accesseurs
    public function getStatutLabelAttribute(): string
    {
        $labels = [
            'ACTIF' => 'Actif',
            'INACTIF' => 'Inactif',
            'BLOQUE' => 'Bloqué',
            'EN_ATTENTE' => 'En attente de vérification'
        ];
        return $labels[$this->statut] ?? $this->statut ?? 'Inconnu';
    }

    public function getCategorieLabelAttribute(): string
    {
        return self::CATEGORIES[$this->categorie_client]['label'] ?? $this->categorie_client ?? 'Standard';
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

    // Méthodes métier
    public function enregistrerConnexion(string $ip, bool $success = true): void
    {
        $this->historiqueConnexions()->create([
            'ip_address' => $ip,
            'login_at' => now(),
            'success' => $success
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
    
    public static function syncFromSharedUser(array $sharedUser): self
{
    if (!isset($sharedUser['email'])) {
        throw new \Exception('Email utilisateur manquant');
    }

    $clientData = [

        'nom_complet' => trim(
            ($sharedUser['prenom'] ?? '') . ' ' .
            ($sharedUser['nom'] ?? '')
        ),

        'telephone' => $sharedUser['telephone'] ?? null,

        'adresse' => $sharedUser['adresse'] ?? null,

        'dernier_connexion' => now(),
    ];

    // Recherche client existant
    $client = self::where('email', $sharedUser['email'])->first();

    // =========================================
    // Création
    // =========================================
    if (!$client) {

        $client = self::create(array_merge($clientData, [

            'email' => $sharedUser['email'],

            'categorie_client' => 'STANDARD',

            'statut' => 'ACTIF',

            'nombre_achats' => 0,

            'total_achats' => 0,
        ]));

    } else {

        // =========================================
        // Synchronisation intelligente
        // =========================================

        $client->fill($clientData);

        // Sauvegarde uniquement si changement
        if ($client->isDirty()) {
            $client->save();
        }
    }

    return $client->fresh();
}
// 

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

    public function activer(): void
    {
        $this->statut = 'ACTIF';
        $this->save();
    }

    public function desactiver(): void
    {
        $this->statut = 'INACTIF';
        $this->save();
    }

    public function bloquer(): void
    {
        $this->statut = 'BLOQUE';
        $this->save();
    }

    public function debloquer(): void
    {
        $this->statut = 'ACTIF';
        $this->save();
    }
    
    public function annulerAchat(float $montant): void
    {
        $this->nombre_achats = max(0, $this->nombre_achats - 1);
    
        $this->total_achats = max(
            0,
            (float) $this->total_achats - $montant
        );
    
        $this->save();
    }

    public function reinitialiserMotDePasse(): string
    {
        $nouveauMotDePasse = self::genererMotDePasseTemporaire($this->id);
        $this->password = $nouveauMotDePasse;
        $this->save();
        return $nouveauMotDePasse;
    }

    // Scopes
    public function scopeActifs($query)
    {
        return $query->where('statut', 'ACTIF');
    }

    public function scopeEnAttente($query)
    {
        return $query->where('statut', 'EN_ATTENTE');
    }

    public function scopeParCategorie($query, $categorie)
    {
        return $query->where('categorie_client', $categorie);
    }

    public function scopeKycIncomplet($query)
    {
        return $query->whereDoesntHave('kycDocuments', function($q) {
            $q->where('statut', 'VERIFIE')
              ->whereIn('type_document', ['CIN', 'ATTESTATION']);
        });
    }
}