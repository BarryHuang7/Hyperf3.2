<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\SoftDeletes;

/**
 * @property int $points_task_id
 * @property string $points_task_no
 * @property int $member_id
 * @property int $video_feature_type
 * @property int $video_width
 * @property int $video_height
 * @property string $video_frame_rate
 * @property string $video_duration_seconds
 * @property string $charging_standard
 * @property string $deduct_points
 * @property string $platform_id
 * @property int $platform_type
 * @property int $status
 * @property int $version
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon $deleted_at
 */
class MemberPointsTask extends Model
{
    use SoftDeletes;

    protected ?string $table = 'member_points_task';

    protected string $primaryKey = 'points_task_id';

    protected array $fillable = [
        'points_task_no',
        'member_id',
        'video_feature_type',
        'video_width',
        'video_height',
        'video_frame_rate',
        'video_duration_seconds',
        'charging_standard',
        'deduct_points',
        'platform_id',
        'platform_type',
        'status',
        'version',
    ];

    protected array $hidden = [
        'version',
        'deleted_at',
    ];

    protected array $casts = [
        'points_task_id' => 'integer',
        'member_id' => 'integer',
        'video_feature_type' => 'integer',
        'video_width' => 'integer',
        'video_height' => 'integer',
        'video_frame_rate' => 'decimal:2',
        'video_duration_seconds' => 'decimal:4',
        'charging_standard' => 'decimal:2',
        'deduct_points' => 'decimal:2',
        'platform_type' => 'integer',
        'status' => 'integer',
        'version' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
