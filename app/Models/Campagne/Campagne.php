<?php

namespace App\Models\Campagne;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use App\Models\Utilisateur;
use App\Models\Partenaire\Partenaire;
use App\Models\Produit;
use App\Models\Zone;
use App\Models\AgentDsa;

class Campagne extends Model
{
    use HasFactory;

    protected $table = 'campagnes';

    protected $fillable = [
        'code',
        'nom',
        'description',
        'id_partenaire',
        'type_campagne',
        'date_debut',
        'date_fin',
        'statut',
        'id_createur',
        'id_validateur',
    ];

    protected $casts = [
        'date_debut' => 'datetime',
        'date_fin' => 'datetime',
        'type_campagne' => 'string',
        'statut' => 'string',
    ];

    // --- RELATIONS ---

    public function partenaire(): BelongsTo
    {
        return $this->belongsTo(Partenaire::class, 'id_partenaire');
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'id_createur');
    }

    public function validateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'id_validateur');
    }

    public function zones(): BelongsToMany
    {
        return $this->belongsToMany(Zone::class, 'campagne_zones', 'id_campagne', 'id_zone')
                    ->withPivot('id_superviseur')
                    ->withTimestamps();
    }

    public function produits(): BelongsToMany
    {
        return $this->belongsToMany(Produit::class, 'campagne_produits', 'id_campagne', 'id_produit')
                    ->withPivot('objectif_quantite', 'objectif_montant', 'prix_special', 'offre_speciale')
                    ->withTimestamps();
    }

    /**
     * ✅ CORRECTION : On utilise belongsToMany et on précise la jointure avec AgentDsa
     */
    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(AgentDsa::class, 'campagne_agents', 'id_campagne', 'id_agent')
                    ->withPivot('id_zone', 'id_superviseur', 'date_debut_affectation', 'date_fin_affectation', 'statut_affectation')
                    ->withTimestamps();
    }

    // --- RELATIONS INTERNES (Pour les règles et opérations) ---

    public function regles(): HasMany
    {
        return $this->hasMany(CampagneRegle::class, 'id_campagne');
    }

    public function operations(): HasMany
    {
        return $this->hasMany(CampagneOperation::class, 'id_campagne');
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(CommissionGeneree::class, 'id_campagne');
    }

    // --- FONCTION POUR RÉCUPÉRER LES TYPES DYNAMIQUEMENT ---

    /**
     * Retourne la liste des types de campagne disponibles dans l'ENUM.
     * S'adapte automatiquement si vous ajoutez de nouvelles valeurs en base.
     */
    public static function getTypesDisponibles(): array
    {
        // 1. On lit la définition de la colonne depuis MySQL
        $result = DB::select("SHOW COLUMNS FROM campagnes WHERE Field = 'type_campagne'");
        
        if (empty($result)) return [];

        // 2. On extrait les valeurs de l'ENUM
        $type = $result[0]->Type; // ex: enum('VENTE','EPARGNE','KYC','MIXTE',...)
        preg_match("/^enum\(\'(.*)\'\)$/", $type, $matches);
        if (!isset($matches[1])) return [];
        
        $values = explode("','", $matches[1]);

        // 3. On prépare les labels formatés pour chaque valeur
        $labelsPersonnalises = [
            'VENTE'           => 'Vente de produits',
            'EPARGNE'         => "Collecte d'épargne",
            'VISITE'          => 'Visites clients',
            'PLAINTE'         => 'Gestion des plaintes',
            'SUGGESTION'      => 'Suggestions clients',
            'CREATION_CLIENT' => 'Création de clients',
        ];

        $types = [];
        foreach ($values as $value) {
            // On cache MIXTE du frontend car il est géré automatiquement par les checkboxes
            if ($value === 'MIXTE') continue;

            $types[] = [
                'value' => $value,
                'label' => $labelsPersonnalises[$value] ?? ucfirst(strtolower(str_replace('_', ' ', $value)))
            ];
        }

        return $types;
    }
}