<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\SoftDeletes;

/**
 * @property int $member_id
 * @property string $email
 * @property string $password
 * @property string $member_name
 * @property int $status
 * @property string $points
 * @property int $is_subscribe
 * @property int $google2fa_enabled
 * @property string $google2fa_secret
 * @property int $automatically_create
 * @property int $version
 * @property string $description
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon $deleted_at
 */
class Member extends Model
{
    use SoftDeletes;

    protected ?string $table = 'member';

    protected string $primaryKey = 'member_id';

    protected array $fillable = [
        'email',
        'password',
        'member_name',
        'status',
        'points',
        'google2fa_enabled',
        'google2fa_secret',
        'automatically_create',
        'description',
    ];

    protected array $hidden = [
        'password',
        'google2fa_secret',
        'version',
        'deleted_at',
    ];

    protected array $casts = [
        'member_id' => 'integer',
        'status' => 'integer',
        'points' => 'decimal:2',
        'is_subscribe' => 'integer',
        'google2fa_enabled' => 'integer',
        'automatically_create' => 'integer',
        'version' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
