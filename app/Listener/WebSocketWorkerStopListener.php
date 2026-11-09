<?php

declare(strict_types=1);

namespace App\Listener;

use App\Service\LoggerService;
use App\Service\WebSocketService;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\OnWorkerStop;
use Hyperf\WebSocketServer\Collector\FdCollector;
use Throwable;

/**
 * Worker 停止时清理 websocket 连接记录
 *
 * 停止服务时 Swoole 会先逐条触发 onClose，此时事件循环已经停止，
 * onClose 里的协程 Redis 会一直阻塞（Swoole 会报 all coroutines asleep - deadlock），
 * Worker 直接退出，根本等不到 WorkerExit。
 *
 * onWorkerStop 回调不在协程上下文中，可以用 phpredis 同步清理，
 * 同时打上标记让 onClose 跳过协程 Redis。
 */
#[Listener]
class WebSocketWorkerStopListener implements ListenerInterface
{
    public function __construct(
        protected WebSocketService $webSocketService,
        protected LoggerService $loggerService,
    ) {
    }

    public function listen(): array
    {
        return [
            OnWorkerStop::class,
        ];
    }

    public function process(object $event): void
    {
        if (! $event instanceof OnWorkerStop) {
            return;
        }

        WebSocketService::markShuttingDown();

        $workerId = $event->workerId;
        // FdCollector 是还没走 onClose 就断掉的连接；
        // closedFds 是 onClose 已经处理过（协程侧没来得及清理）的连接
        $fds = array_unique(array_merge(
            array_keys(FdCollector::list()),
            WebSocketService::closedFds()
        ));

        if ($fds === []) {
            return;
        }

        $this->loggerService->info('WebSocket WorkerStop 开始清理连接', [
            'worker_id' => $workerId,
            'count' => count($fds),
        ]);

        try {
            $count = $this->webSocketService->clearConnectionsOnExit($workerId, $fds);

            if ($count === null) {
                $this->loggerService->warning('WebSocket WorkerStop 无同步 Redis 客户端（ext-redis），跳过清理', [
                    'worker_id' => $workerId,
                    'count' => count($fds),
                ]);

                return;
            }

            WebSocketService::clearClosedFds();

            $this->loggerService->info('WebSocket WorkerStop 清理连接完成', [
                'worker_id' => $workerId,
                'count' => $count,
            ]);
        } catch (Throwable $e) {
            $this->loggerService->error('WebSocket WorkerStop 清理连接失败', [
                'worker_id' => $workerId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
