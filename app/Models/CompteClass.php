<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Class CompteClass
 * 
 * @property int $id
 * @property string $numero
 * @property string $nom
 * @property string $type
 * @property string|null $sens_debit
 * @property string|null $sens_credit
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * 
 * @property Collection|PlanComptable[] $plan_comptables
 *
 * @package App\Models
 */
class CompteClass extends Model
{
	protected $table = 'compte_classes';

	protected $fillable = [
		'numero',
		'nom',
		'type',
		'sens_debit',
		'sens_credit'
	];

	public function plan_comptables()
	{
		return $this->hasMany(PlanComptable::class, 'classe_id');
	}
}
