<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
return [
    'http' => [
        // 拦截器
        \App\Middleware\AuthMiddleware::class,
        // 验证器
        \Hyperf\Validation\Middleware\ValidationMiddleware::class,
    ],
    'ws' => [
        // 拦截器
        \App\Middleware\WSAuthMiddleware::class,
    ],
];
