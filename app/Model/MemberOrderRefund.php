<?php

declare(strict_types=1);

namespace App\Model;

/**
 * @property int $refund_id
 * @property int $order_master_id
 * @property string $order_no
 * @property int $member_id
 * @property int $status
 * @property string $price
 * @property string $stripe_refund_id
 * @property string $reason_for_application
 * @property string $description
 * @property int $version
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class MemberOrderRefund extends Model
{
    protected ?string $table = 'member_order_refund';

    protected string $primaryKey = 'refund_id';

    protected array $fillable = [
        'order_master_id',
        'order_no',
        'member_id',
        'status',
        'price',
        'stripe_refund_id',
        'reason_for_application',
        'description',
        'version',
    ];

    protected array $hidden = [
        'version',
    ];

    protected array $casts = [
        'refund_id' => 'integer',
        'order_master_id' => 'integer',
        'member_id' => 'integer',
        'status' => 'integer',
        'price' => 'decimal:2',
        'version' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
