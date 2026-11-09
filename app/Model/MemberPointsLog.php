<?php

declare(strict_types=1);

namespace App\Model;

/**
 * @property int $points_log_id
 * @property int $member_id
 * @property int $order_id
 * @property string $order_no
 * @property int $points_task_id
 * @property string $points_task_no
 * @property int $flag
 * @property int $change_type
 * @property string $change_points
 * @property string $before_points
 * @property string $after_points
 * @property int $video_width
 * @property int $video_height
 * @property string $video_frame_rate
 * @property int $video_feature_type
 * @property string $video_duration_seconds
 * @property string $charging_standard
 * @property string $description
 * @property string $audit_notes
 * @property \Carbon\Carbon $created_at
 */
class MemberPointsLog extends Model
{
    protected ?string $table = 'member_points_log';

    protected string $primaryKey = 'points_log_id';

    // 该表只有 created_at 字段（DB 默认值填充），没有 updated_at，因此关闭框架自动时间戳
    public bool $timestamps = false;

    protected array $fillable = [
        'member_id',
        'order_id',
        'order_no',
        'points_task_id',
        'points_task_no',
        'flag',
        'change_type',
        'change_points',
        'before_points',
        'after_points',
        'video_width',
        'video_height',
        'video_frame_rate',
        'video_feature_type',
        'video_duration_seconds',
        'charging_standard',
        'description',
        'audit_notes',
    ];

    protected array $casts = [
        'points_log_id' => 'integer',
        'member_id' => 'integer',
        'order_id' => 'integer',
        'points_task_id' => 'integer',
        'flag' => 'integer',
        'change_type' => 'integer',
        'change_points' => 'decimal:2',
        'before_points' => 'decimal:2',
        'after_points' => 'decimal:2',
        'video_width' => 'integer',
        'video_height' => 'integer',
        'video_frame_rate' => 'decimal:2',
        'video_feature_type' => 'integer',
        'video_duration_seconds' => 'decimal:4',
        'charging_standard' => 'decimal:2',
        'created_at' => 'datetime',
    ];
}
