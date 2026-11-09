<?php

declare(strict_types=1);

namespace App\Service;

use App\Constants\WebSocketCode;
use App\Service\LoggerService;
use App\Service\WebSocketService;
use Hyperf\Redis\Redis;
use Hyperf\Di\Annotation\Inject;
use Hyperf\WebSocketServer\Sender;
use Throwable;

class WebSocketPushService
{
    #[Inject]
    protected LoggerService $loggerService;

    #[Inject]
    protected Sender $sender;

    #[Inject]
    protected Redis $redis;

    /**
     * 推送消息给指定用户
     * @param int $memberId
     * @param array $data
     * @param string $msg
     * @param string $logMsg
     * @return void
     */
    public function pushToUser(int $memberId, array $data, string $msg = '', string $logMsg = ''): void
    {
        try {
            $target = $this->redis->hGet(WebSocketCode::WEBSOCKET_USERS, (string) $memberId);
            $websocketMsg = '失败';

            if (!empty($target)) {
                $fd = WebSocketService::parseField((string) $target)['fd'];

                $this->sender->push($fd, json_encode([
                    'code' => 200,
                    'data' => $data,
                    'msg' => $msg,
                ]));
                $websocketMsg = '成功';
            }

            $this->loggerService->info($logMsg . '通知 WebSocket 推送' . $websocketMsg, [
                'member_id' => $memberId,
                'target' => $target,
                'data' => $data,
            ]);
        } catch (Throwable $e) {
            $this->loggerService->error($logMsg . '通知 WebSocket 推送异常', [
                'member_id' => $memberId,
                'data' => $data,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
