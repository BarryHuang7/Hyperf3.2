<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\SoftDeletes;

/**
 * @property int $plan_id
 * @property string $plan_name
 * @property string $points
 * @property string $price
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon $deleted_at
 */
class PlanPricing extends Model
{
    use SoftDeletes;

    protected ?string $table = 'plan_pricing';

    protected string $primaryKey = 'plan_id';

    protected array $fillable = [
        'plan_id',
        'plan_name',
        'points',
        'price',
    ];

    protected array $hidden = [
        'deleted_at',
    ];

    protected array $casts = [
        'plan_id' => 'integer',
        'points' => 'decimal:2',
        'price' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
