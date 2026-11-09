<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Hyperf\Redis\Redis;
use Hyperf\Context\ResponseContext;
use Hyperf\Di\Annotation\Inject;
use App\Service\CorsService;
use App\Service\LoggerService;
use App\Service\UtilsService;
use App\Constants\AuthCode;

class AuthMiddleware implements MiddlewareInterface
{
    #[Inject]
    protected LoggerService $loggerService;

    #[Inject]
    protected UtilsService $utilsService;

    public function __construct(protected HttpResponse $response, protected Redis $redis)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // 跨域头必须加在最终返回的响应上
        return CorsService::addHeaders($this->handleRequest($request, $handler));
    }

    private function handleRequest(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // 直接放行 OPTIONS 预检请求：不鉴权、不进路由，跨域头由 process() 统一补上
        if ($request->getMethod() === 'OPTIONS') {
            return ResponseContext::get()->withStatus(204);
        }

        $path = $request->getUri()->getPath();
        $method = $request->getMethod();
        $ip = $this->utilsService->getClientRealIp($request);

        // 路由白名单，直接放行
        $whitelist = [
            '/api/register',
            '/api/login',
            '/api/verification-code',
            '/api/send-email-verification-code',
            '/api/stripe/webhook',
            '/api/get-plan-list',
            '/api/create-checkout-session',
            '/api/assemblyai/webhook',
            '/api/wavespeedai/webhook',
            '/api/tencentcloudci/webhook',
            '/api/volcengineai/webhook',
            '/admin/login',
        ];
        if (in_array($path, $whitelist, true)) {
            $this->loggerService->info('请求记录', [
                'path' => $path,
                'method' => $method,
                'ip' => $ip,
            ]);

            return $handler->handle($request);
        }

        $token = $request->getHeaderLine('Authorization');

        $this->loggerService->info('请求记录', [
            'path' => $path,
            'method' => $method,
            'token' => $token,
            'ip' => $ip,
        ]);

        if (empty($token)) {
            $this->loggerService->error('401 Unauthorized', [
                'path' => $path,
                'method' => $method,
            ]);

            return $this->response->json([
                'code' => 401,
                'data' => '',
                'msg' => 'Unauthorized',
                'error' => ''
            ])->withStatus(401);
        }

        $isAdmin = strpos($path, '/admin') !== false ? true : false;
        $prefix = $isAdmin ? AuthCode::ADMIN_LOGIN_KEY : AuthCode::MEMBER_LOGIN_KEY;
        $userInfo = $this->redis->get($prefix.$token);

        if (empty($userInfo)) {
            $this->loggerService->error('403 Forbidden', [
                'path' => $path,
                'method' => $method,
                'token' => $prefix.$token,
            ]);

            return $this->response->json([
                'code' => 403,
                'data' => '',
                'msg' => 'Forbidden',
                'error' => ''
            ])->withStatus(403);
        }

        $userInfoArray = json_decode($userInfo, true) ?: [];

        if ($isAdmin ? empty($userInfoArray['admin_id']) : empty($userInfoArray['member_id'])) {
            $this->loggerService->error('403 Forbidden', [
                'path' => $path,
                'method' => $method,
                'token' => $prefix.$token,
            ]);

            return $this->response->json([
                'code' => 403,
                'data' => '',
                'msg' => 'Forbidden',
                'error' => ''
            ])->withStatus(403);
        }

        $request = $request->withAttribute('token', $token);
        $request = $request->withAttribute('user_info', $userInfoArray);

        return $handler->handle($request);
    }
}
