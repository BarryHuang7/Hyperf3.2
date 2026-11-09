<?php

declare(strict_types=1);

use function Hyperf\Support\env;

return [
    // 本地模式注册码校验接口地址（POST JSON：{"code":"注册码"}）
    'url' => (string) env('REG_QUERY_URL', ''),
    // 外部接口 products 项中表示授权期限的字段名
    'duration_field' => (string) env('REG_QUERY_DURATION_FIELD', 'duration'),
];
