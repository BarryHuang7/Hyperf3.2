<?php

declare(strict_types=1);

namespace App\Listener;

use App\Service\LoggerService;
use App\Service\WebSocketService;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\OnWorkerExit;
use Hyperf\WebSocketServer\Collector\FdCollector;
use Throwable;

/**
 * Worker 进程退出时清理该进程持有的 websocket 连接记录
 *
 * Worker 被 stop/reload 时，Swoole 直接关闭连接，不会逐个触发 onClose，
 * Redis 里的 worker_id:fd 记录会残留，前端从 WEBSOCKET_USERS 拿到旧连接后推送就会失败。
 * FdCollector 是进程内静态数据，正好保存了当前 Worker 建连的所有 fd。
 *
 * 注意：onWorkerExit 不在协程上下文中执行，且此时事件循环正在停止，
 * 不能依赖新建协程去执行清理，服务层因此提供了基于 phpredis 的同步清理。
 *
 * 需要 server.php 的 settings 中开启 reload_async，否则 onWorkerExit 不会触发
 */
#[Listener]
class WebSocketWorkerExitListener implements ListenerInterface
{
    /**
     * reload_async 模式下 onWorkerExit 会被重复触发，清理逻辑只执行一次
     */
    private static bool $cleaned = false;

    public function __construct(
        protected WebSocketService $webSocketService,
        protected LoggerService $loggerService,
    ) {
    }

    public function listen(): array
    {
        return [
            OnWorkerExit::class,
        ];
    }

    public function process(object $event): void
    {
        if (! $event instanceof OnWorkerExit || self::$cleaned) {
            return;
        }
        self::$cleaned = true;

        $workerId = $event->workerId;
        // FdCollector 里是不走 onClose 直接死掉的连接，
        // closedFds 里是走了 onClose、但协程 Redis 清理没成功（关服阶段超时）的连接
        $fds = array_unique(array_merge(
            array_keys(FdCollector::list()),
            WebSocketService::closedFds()
        ));

        if ($fds === []) {
            return;
        }

        $this->loggerService->info('WebSocket WorkerExit 开始清理连接', [
            'worker_id' => $workerId,
            'count' => count($fds),
        ]);

        try {
            $count = $this->webSocketService->clearConnectionsOnExit($workerId, $fds);

            if ($count === null) {
                // 没装 ext-redis 时只能退回协程清理，执行时机不保证
                Coroutine::create(function () use ($workerId, $fds) {
                    foreach ($fds as $fd) {
                        $this->webSocketService->clearConnection($workerId, (int) $fd);
                    }
                });

                $this->loggerService->warning('WebSocket WorkerExit 无同步 Redis 客户端，已降级为协程清理', [
                    'worker_id' => $workerId,
                    'count' => count($fds),
                ]);

                return;
            }

            $this->loggerService->info('WebSocket WorkerExit 清理连接完成', [
                'worker_id' => $workerId,
                'count' => $count,
            ]);

            WebSocketService::clearClosedFds();
        } catch (Throwable $e) {
            $this->loggerService->error('WebSocket WorkerExit 清理连接失败', [
                'worker_id' => $workerId,
                'count' => count($fds),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
