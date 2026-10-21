<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Class Entite
 * 
 * @property int $id
 * @property string $code
 * @property string $nom
 * @property bool|null $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * 
 * @property Collection|EcrituresComptable[] $ecritures_comptables
 * @property Collection|EntiteOperationJournal[] $entite_operation_journals
 * @property Collection|Succursale[] $succursales
 *
 * @package App\Models
 */
class Entite extends Model
{
	protected $table = 'entites';

	protected $casts = [
		'is_active' => 'bool'
	];

	protected $fillable = [
		'code',
		'nom',
		'is_active'
	];

	public function ecritures_comptables()
	{
		return $this->hasMany(EcrituresComptable::class);
	}

	public function entite_operation_journals()
	{
		return $this->hasMany(EntiteOperationJournal::class);
	}

	public function succursales()
	{
		return $this->hasMany(Succursale::class);
	}
	
	// Dans le modèle Entite (app/Models/Entite.php)
    public function resultats()
    {
        return $this->hasMany(Resultat::class, 'entite_id');
    }
    
    // Dans la classe Entite, ajouter cette méthode :

    public function tvaComptables()
    {
        return $this->hasMany(TvaComptable::class, 'entite_id');
    }


}
