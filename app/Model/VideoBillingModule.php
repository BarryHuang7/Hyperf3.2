<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\SoftDeletes;

/**
 * @property int $moudle_id
 * @property int $video_feature_type
 * @property string $moudle_name
 * @property string $moudle_specifications
 * @property string $charging_standard
 * @property string $minimum_guaranteed_deduction_fee
 * @property string $hd
 * @property string $2k
 * @property string $4k
 * @property string $fps30
 * @property string $fps45
 * @property string $fps60
 * @property string $above_fps60
 * @property string $fps90
 * @property string $above_fps90
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon $deleted_at
 */
class VideoBillingModule extends Model
{
    use SoftDeletes;

    protected ?string $table = 'video_billing_module';

    protected string $primaryKey = 'moudle_id';

    protected array $fillable = [
        'moudle_id',
        'video_feature_type',
        'moudle_name',
        'moudle_specifications',
        'charging_standard',
        'minimum_guaranteed_deduction_fee',
        'hd',
        '2k',
        '4k',
        'fps30',
        'fps45',
        'fps60',
        'above_fps60',
        'fps90',
        'above_fps90',
    ];

    protected array $hidden = [
        'deleted_at',
    ];

    protected array $casts = [
        'moudle_id' => 'integer',
        'video_feature_type' => 'integer',
        'charging_standard' => 'decimal:2',
        'minimum_guaranteed_deduction_fee' => 'decimal:2',
        'hd' => 'decimal:2',
        '2k' => 'decimal:2',
        '4k' => 'decimal:2',
        'fps30' => 'decimal:2',
        'fps45' => 'decimal:2',
        'fps60' => 'decimal:2',
        'above_fps60' => 'decimal:2',
        'fps90' => 'decimal:2',
        'above_fps90' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
