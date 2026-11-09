<?php

declare(strict_types=1);

namespace App\Service;

use App\Constants\WebSocketCode;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Redis\Redis;
use Throwable;

class WebSocketService
{
    /**
     * 本进程内 onClose 处理过的 fd
     *
     * 关服阶段事件循环已停止，onClose 里的协程 Redis 会超时失败，
     * 这些 fd 由 WorkerExit 用同步客户端兜底清理
     * @var array<int, true>
     */
    private static array $closedFds = [];

    /**
     * 是否已进入 Worker 停止阶段
     *
     * 停止阶段事件循环已经停止，任何协程 I/O 都会卡死，需要改用同步客户端
     */
    private static bool $shuttingDown = false;

    public static function markShuttingDown(): void
    {
        self::$shuttingDown = true;
    }

    public static function isShuttingDown(): bool
    {
        return self::$shuttingDown;
    }

    public function __construct(
        protected Redis $redis,
        protected ConfigInterface $config,
    ) {
    }

    /**
     * 记录 onClose 处理过的 fd
     * @param int $fd
     */
    public static function markClosed(int $fd): void
    {
        self::$closedFds[$fd] = true;
    }

    /**
     * @return int[]
     */
    public static function closedFds(): array
    {
        return array_keys(self::$closedFds);
    }

    public static function clearClosedFds(): void
    {
        self::$closedFds = [];
    }

    /**
     * 生成 Redis 中的连接标识
     *
     * SWOOLE_BASE 模式下 fd 只在当前 Worker 进程内唯一，跨进程会重复，
     * 所以统一使用 worker_id:fd 作为唯一标识
     * @param int $workerId
     * @param int $fd
     * @return string
     */
    public static function buildField(int $workerId, int $fd): string
    {
        return $workerId . ':' . $fd;
    }

    /**
     * 解析连接标识
     * @param string $field
     * @return array{worker_id: int, fd: int}
     */
    public static function parseField(string $field): array
    {
        $parts = explode(':', $field, 2);

        return ['worker_id' => (int) $parts[0], 'fd' => (int) $parts[1]];
    }

    /**
     * 清理某个连接的记录（连接正常关闭时调用，运行在协程上下文中）
     * @param int $workerId
     * @param int $fd
     */
    public function clearConnection(int $workerId, int $fd): void
    {
        $this->clearWith($this->redis, $workerId, $fd);
    }

    /**
     * 清理一批连接记录（Worker 退出时调用）
     *
     * onWorkerExit 不在协程上下文中，而且此时事件循环正在停止，新建的协程可能永远得不到执行，
     * 所以这里使用 phpredis 同步客户端清理。没有安装 ext-redis 时返回 null，由调用方降级处理。
     * @param int $workerId
     * @param int[] $fds
     * @return int|null 实际清理的连接数，同步客户端不可用时返回 null
     */
    public function clearConnectionsOnExit(int $workerId, array $fds): ?int
    {
        $client = $this->makeSyncClient();

        if (! $client instanceof \Redis) {
            return null;
        }

        try {
            $count = 0;
            foreach ($fds as $fd) {
                $count += $this->clearWith($client, $workerId, (int) $fd);
            }

            return $count;
        } finally {
            $client->close();
        }
    }

    /**
     * 同步 Redis 客户端（phpredis）
     * @return \Redis|null
     */
    protected function makeSyncClient(): ?\Redis
    {
        if (! extension_loaded('redis')) {
            return null;
        }

        $config = $this->config->get('redis.default', []);
        $client = new \Redis();
        // 退出阶段不能无限阻塞，Redis 不可用时直接放弃
        $client->setOption(\Redis::OPT_READ_TIMEOUT, 1.0);

        try {
            if (! $client->connect(
                (string) ($config['host'] ?? '127.0.0.1'),
                (int) ($config['port'] ?? 6379),
                1.0
            )) {
                return null;
            }

            if (! empty($config['auth'])) {
                $client->auth($config['auth']);
            }

            $db = (int) ($config['db'] ?? 0);
            if ($db > 0) {
                $client->select($db);
            }

            return $client;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param \Redis|Redis $client 协程客户端与 phpredis 同步客户端同名方法，这里统一处理
     * @param int $workerId
     * @param int $fd
     * @return int 是否清理了记录（0/1）
     */
    protected function clearWith(\Redis|Redis $client, int $workerId, int $fd): int
    {
        $field = self::buildField($workerId, $fd);
        $memberId = $client->hGet(WebSocketCode::WEBSOCKET_FDS, $field);

        if ($memberId === false || $memberId === null) {
            return 0;
        }

        // worker_id:fd -> member_id
        $client->hDel(WebSocketCode::WEBSOCKET_FDS, $field);

        // 在线用户集合（成员 JSON 必须和 onOpen 写入时完全一致才能被移除）
        $client->sRem(WebSocketCode::WEBSOCKET_ONLINE_USERS, json_encode([
            'user_id' => (int) $memberId,
            'fd' => $fd,
            'worker_id' => $workerId,
        ]));

        // member_id -> worker_id:fd，同一用户重复登录时只删除指向当前连接的记录，
        // 避免把用户在其他连接（其他 Worker / 重新连接）上登记的记录误删
        $current = $client->hGet(WebSocketCode::WEBSOCKET_USERS, (string) $memberId);
        if ($current !== false && $current !== null && $current === $field) {
            $client->hDel(WebSocketCode::WEBSOCKET_USERS, (string) $memberId);
        }

        return 1;
    }
}
