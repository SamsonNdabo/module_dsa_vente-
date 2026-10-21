<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class EntiteOperationJournal
 * 
 * @property int $id
 * @property int $entite_id
 * @property int $operation_journal_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * 
 * @property Entite $entite
 * @property OperationsJournal $operations_journal
 *
 * @package App\Models
 */
class EntiteOperationJournal extends Model
{
	protected $table = 'entite_operation_journal';

	protected $casts = [
		'entite_id' => 'int',
		'operation_journal_id' => 'int'
	];

	protected $fillable = [
		'entite_id',
		'operation_journal_id'
	];

	public function entite()
	{
		return $this->belongsTo(Entite::class);
	}

	public function operations_journal()
	{
		return $this->belongsTo(OperationsJournal::class, 'operation_journal_id');
	}
}
