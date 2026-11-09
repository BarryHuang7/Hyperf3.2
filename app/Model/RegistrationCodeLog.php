<?php

declare(strict_types=1);

namespace App\Model;

/**
 * 本地注册码激活记录
 *
 * @property int $registration_code_log_id
 * @property string $registration_code
 * @property int $member_id
 * @property int $order_master_id
 * @property string $order_no
 * @property \Carbon\Carbon $created_at
 */
class RegistrationCodeLog extends Model
{
    protected ?string $table = 'registration_code_log';

    protected string $primaryKey = 'registration_code_log_id';

    // 该表只有 created_at 字段（DB 默认值填充），没有 updated_at，因此关闭框架自动时间戳
    public bool $timestamps = false;

    protected array $fillable = [
        'registration_code',
        'member_id',
        'order_master_id',
        'order_no',
    ];

    protected array $casts = [
        'registration_code_log_id' => 'integer',
        'member_id' => 'integer',
        'order_master_id' => 'integer',
        'created_at' => 'datetime',
    ];
}
