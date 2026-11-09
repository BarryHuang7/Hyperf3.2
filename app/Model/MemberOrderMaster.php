<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\SoftDeletes;

/**
 * @property int $order_master_id
 * @property string $order_no
 * @property int $member_id
 * @property int $payment_status
 * @property string $stripe_checkout_id
 * @property string $stripe_checkout_url
 * @property string $payment_intent_id
 * @property \Carbon\Carbon $paid_at
 * @property int $flag
 * @property int $change_type
 * @property string $change_points
 * @property string $price
 * @property int $version
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon $deleted_at
 */
class MemberOrderMaster extends Model
{
    use SoftDeletes;

    protected ?string $table = 'member_order_master';

    protected string $primaryKey = 'order_master_id';

    protected array $fillable = [
        'order_no',
        'member_id',
        'payment_status',
        'stripe_checkout_id',
        'stripe_checkout_url',
        'payment_intent_id',
        'paid_at',
        'flag',
        'change_type',
        'change_points',
        'price',
        'version',
    ];

    protected array $hidden = [
        'version',
        'deleted_at',
    ];

    protected array $casts = [
        'order_master_id' => 'integer',
        'member_id' => 'integer',
        'payment_status' => 'integer',
        'flag' => 'integer',
        'change_type' => 'integer',
        'change_points' => 'decimal:2',
        'price' => 'decimal:2',
        'version' => 'integer',
        'paid_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
