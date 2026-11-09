<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Hyperf\Redis\Redis;
use Hyperf\WebSocketServer\Context as WsContext;
use Hyperf\Di\Annotation\Inject;
use App\Service\LoggerService;
use App\Constants\AuthCode;

class WSAuthMiddleware implements MiddlewareInterface
{
    #[Inject]
    protected LoggerService $loggerService;

    public function __construct(protected HttpResponse $response, protected Redis $redis)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();
        $params = $request->getQueryParams();

        if (empty($params['token'])) {
            $this->loggerService->error('401 Unauthorized', [
                'path' => $path,
                'method' => $method,
                'params' => $params,
            ]);

            return $this->response->json([
                'code' => 401,
                'data' => '',
                'msg' => 'Unauthorized',
                'error' => ''
            ])->withStatus(401);
        }

        $token = $params['token'];

        if (empty($token)) {
            $this->loggerService->error('401 Unauthorized', [
                'path' => $path,
                'method' => $method,
                'params' => $params,
                'token' => $token,
            ]);

            return $this->response->json([
                'code' => 401,
                'data' => '',
                'msg' => 'Unauthorized',
                'error' => ''
            ])->withStatus(401);
        }

        // 现在暂时只有用户端用到websocket
        $prefix = AuthCode::MEMBER_LOGIN_KEY;
        $userInfo = $this->redis->get($prefix.$token);

        if (empty($userInfo)) {
            $this->loggerService->error('403 Forbidden', [
                'path' => $path,
                'method' => $method,
                'params' => $params,
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

        if (empty($userInfoArray['member_id'])) {
            $this->loggerService->error('403 Forbidden', [
                'path' => $path,
                'method' => $method,
                'params' => $params,
                'token' => $prefix.$token,
            ]);

            return $this->response->json([
                'code' => 403,
                'data' => '',
                'msg' => 'Forbidden',
                'error' => ''
            ])->withStatus(403);
        }

        WsContext::set('token', $token);
        WsContext::set('user_info', $userInfoArray);

        return $handler->handle($request);
    }
}
