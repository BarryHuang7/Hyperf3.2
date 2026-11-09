<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Http\Message\ResponseInterface;

class CorsService
{
    /**
     * 给响应补充跨域头
     * 中间件正常返回和异常处理器兜底返回都要经过这里，避免报错时浏览器只看到跨域失败
     * @param ResponseInterface $response
     * @return ResponseInterface
     */
    public static function addHeaders(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, VerifyCode')
            ->withHeader('Access-Control-Max-Age', '86400');
    }
}
