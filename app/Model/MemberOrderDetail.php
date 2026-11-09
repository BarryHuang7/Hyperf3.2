<?php

declare(strict_types=1);

namespace App\Model;

/**
 * @property int $order_details_id
 * @property int $order_master_id
 * @property int $member_id
 * @property int $status
 * @property string $change_points
 * @property \Carbon\Carbon $expiration_date
 * @property int $version
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class MemberOrderDetail extends Model
{
    protected ?string $table = 'member_order_details';

    protected string $primaryKey = 'order_details_id';

    protected array $fillable = [
        'order_master_id',
        'member_id',
        'status',
        'change_points',
        'expiration_date',
        'version',
    ];

    protected array $hidden = [
        'version',
    ];

    protected array $casts = [
        'order_details_id' => 'integer',
        'order_master_id' => 'integer',
        'member_id' => 'integer',
        'status' => 'integer',
        'change_points' => 'decimal:2',
        'expiration_date' => 'datetime',
        'version' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
