<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/**
 * 乐观锁版本冲突：数据在读取与更新之间被其他请求改动，需要整单回滚后重试
 */
class VersionConflictException extends RuntimeException
{
}
