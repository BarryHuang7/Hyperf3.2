<?php

declare(strict_types=1);

namespace App\Controller\WS;

use Hyperf\Contract\OnCloseInterface;
use Hyperf\Contract\OnMessageInterface;
use Hyperf\Contract\OnOpenInterface;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Redis\Redis;
use Hyperf\WebSocketServer\Context as WsContext;
use Throwable;
use App\Constants\WebSocketCode;
use App\Controller\Api\Stripe\StripeController;
use App\Controller\Api\Member\PointsTaskController;
use App\Service\LoggerService;
use App\Service\UtilsService;
use App\Service\WebSocketService;

class WebSocketController implements OnMessageInterface, OnOpenInterface, OnCloseInterface
{
    #[Inject]
    protected StripeController $stripeController;

    #[Inject]
    protected PointsTaskController $pointsTaskController;

    #[Inject]
    protected LoggerService $loggerService;

    #[Inject]
    protected UtilsService $utilsService;

    public function __construct(protected Redis $redis, protected WebSocketService $webSocketService)
    {
    }

    // 连接打开
    public function onOpen($server, $request): void
    {
        $fd = (int) $request->fd;
        // SWOOLE_BASE 模式下 fd 只在当前 Worker 内唯一，需要配合 worker_id 使用
        $workerId = (int) $server->worker_id;
        $field = WebSocketService::buildField($workerId, $fd);

        $serverInfo = (array) ($request->server ?? []);
        $ip = $this->utilsService->getClientRealIpFromHeaders(
            (array) ($request->header ?? []),
            (string) ($serverInfo['remote_addr'] ?? '')
        );

        $userInfo = WsContext::get('user_info', []);
        $memberId = $userInfo['member_id'] ?? 0;

        // 握手成功后触发，可推送欢迎消息
        $server->push($fd, json_encode([
            'code' => 200,
            'msg' => 'Welcome!',
        ]));

        $this->loggerService->wsinfo('请求记录-open', [
            'member_id' => $memberId,
            'ip' => $ip,
        ]);

        $this->redis->hSet(WebSocketCode::WEBSOCKET_FDS, $field, $memberId);
        $this->redis->hSet(WebSocketCode::WEBSOCKET_USERS, (string) $memberId, $field);
        $this->redis->sadd(WebSocketCode::WEBSOCKET_ONLINE_USERS, json_encode([
            'user_id' => (int) $memberId,
            'fd' => $fd,
            'worker_id' => $workerId,
        ]));
    }

    // 发送消息
    public function onMessage($server, $frame): void
    {
        $data = $frame->data;
        $fd = (int) $frame->fd;

        $this->loggerService->wsinfo('请求记录-message', [
            'fd' => $fd,
            'data' => $data,
        ]);

        // 兜底轮询接口
        $dataArray = json_decode($data, true);
        if (!empty($dataArray['path']) && !empty($dataArray['params'])) {
            $path = $dataArray['path'];
            $params = $dataArray['params'];

            switch ($path) {
                // 检查订单状态
                case 'checkOrderStatus':
                    if (!empty($params['order_no'])) {
                        $orderNo = $params['order_no'];
                        $result = $this->stripeController->getOrderStatus($orderNo);
                        $server->push($fd, json_encode($result));
                    }
                    break;
                // 查询任务状态
                case 'getTaskStatus':
                    if (!empty($params['points_task_no'])) {
                        $pointsTaskNo = $params['points_task_no'];
                        $result = $this->pointsTaskController->getTaskStatus($pointsTaskNo);
                        $server->push($fd, json_encode($result));
                    }
                    break;
            }
        }
    }

    // 连接关闭
    public function onClose($server, int $fd, int $reactorId): void
    {
        $workerId = (int) $server->worker_id;
        WebSocketService::markClosed($fd);

        if (WebSocketService::isShuttingDown() || $this->isWorkerExiting($server)) {
            // 停止服务时事件循环已经停止，onClose 里的协程 Redis 会一直阻塞
            // （Swoole 报 all coroutines asleep - deadlock 后直接退出进程），
            // 这里不做 I/O，交给 WebSocketWorkerStopListener 用同步客户端清理
            WebSocketService::markShuttingDown();
        } else {
            try {
                $this->webSocketService->clearConnection($workerId, $fd);
            } catch (Throwable) {
                // 清理失败不影响连接关闭，未清理的记录由 WorkerStop / WorkerExit 兜底
            }
        }

        // 清理对应上下文
        WsContext::release($fd);
    }

    /**
     * Worker 是否正在退出
     * @param mixed $server
     * @return bool
     */
    private function isWorkerExiting($server): bool
    {
        if (! defined('SWOOLE_WORKER_EXIT') || ! method_exists($server, 'getWorkerStatus')) {
            return false;
        }

        return $server->getWorkerStatus() === SWOOLE_WORKER_EXIT;
    }
}
