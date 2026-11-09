<?php

declare(strict_types=1);

namespace App\Model;

/**
 * @property int $stripe_json_id
 * @property string $order_no
 * @property array $checkout_session_json
 * @property array $payment_intent_json
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class StripeJson extends Model
{
    protected ?string $table = 'stripe_json';

    protected string $primaryKey = 'stripe_json_id';

    protected array $fillable = [
        'order_no',
        'checkout_session_json',
        'payment_intent_json',
    ];

    protected array $casts = [
        'stripe_json_id' => 'integer',
        // 读写自动 json_encode / json_decode（decode 为关联数组）
        'checkout_session_json' => 'json',
        'payment_intent_json' => 'json',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
