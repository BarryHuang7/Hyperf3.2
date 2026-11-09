<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\SoftDeletes;

/**
 * @property int $admin_id
 * @property string $email
 * @property string $password
 * @property string $admin_name
 * @property int $status
 * @property string $description
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon $deleted_at
 */
class AdminUser extends Model
{
    use SoftDeletes;

    protected ?string $table = 'admin_user';

    protected string $primaryKey = 'admin_id';

    protected array $fillable = [
        'email',
        'password',
        'admin_name',
        'status',
        'description',
    ];

    protected array $hidden = [
        'password',
        'deleted_at',
    ];

    protected array $casts = [
        'admin_id' => 'integer',
        'status' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
