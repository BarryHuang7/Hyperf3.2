<?php

declare(strict_types=1);

namespace App\Model;

/**
 * @property int $points_task_order_id
 * @property int $points_task_id
 * @property int $order_master_id
 * @property string $order_no
 * @property int $order_details_id
 * @property string $deduct_points
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon $deleted_at
 */
class MemberPointsTaskOrder extends Model
{
    protected ?string $table = 'member_points_task_order';

    protected string $primaryKey = 'points_task_order_id';

    protected array $fillable = [
        'points_task_id',
        'order_master_id',
        'order_no',
        'order_details_id',
        'deduct_points',
    ];

    protected array $hidden = [
        'deleted_at',
    ];

    protected array $casts = [
        'points_task_order_id' => 'integer',
        'points_task_id' => 'integer',
        'order_master_id' => 'integer',
        'order_details_id' => 'integer',
        'deduct_points' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
