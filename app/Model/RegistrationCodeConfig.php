<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\SoftDeletes;

/**
 * 本地注册码对应套餐赠送积分配置
 *
 * @property int $package_id
 * @property string $package_name
 * @property string $package_explain
 * @property string $one_year
 * @property string $lifetime
 * @property string $remark
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon $deleted_at
 */
class RegistrationCodeConfig extends Model
{
    use SoftDeletes;

    protected ?string $table = 'registration_code_config';

    protected string $primaryKey = 'package_id';

    protected array $fillable = [
        'package_name',
        'package_explain',
        'one_year',
        'lifetime',
        'remark',
    ];

    protected array $casts = [
        'package_id' => 'integer',
        'one_year' => 'decimal:2',
        'lifetime' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
