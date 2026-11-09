<?php

declare(strict_types=1);

namespace App\Amqp\Consumers;

use Hyperf\Amqp\Annotation\Consumer;
use Hyperf\Amqp\Message\ConsumerMessage;
use Hyperf\Amqp\Result;
use PhpAmqpLib\Message\AMQPMessage;
use Hyperf\Di\Annotation\Inject;
use App\Service\LoggerService;
use App\Job\Api\UpdatePointsTaskStatusJob;
use App\Constants\OrderCode;
use Hyperf\AsyncQueue\Driver\DriverFactory;

#[Consumer(exchange: "hyperf.internal", routingKey: "member.points", queue: "queue_update_points_task", nums: 1)]
class UpdatePointsTaskConsumer extends ConsumerMessage
{
    #[Inject]
    protected DriverFactory $driverFactory;

	#[Inject]
    protected LoggerService $loggerService;

    public function consumeMessage($data, AMQPMessage $message): Result
    {
		try {
            if (empty($data['points_task_no']) || empty($data['status']) || !isset($data['platform_type'])) {
                $this->loggerService->error("RabbitMQ消息-更新积分任务-失败，参数错误", ['data' => $data]);
                return Result::ACK;
            }

			$job = new UpdatePointsTaskStatusJob(
                $data['points_task_no'],
                $data['status'] === 'Success' ? OrderCode::TASK_STATUS_SUCCESS : OrderCode::TASK_STATUS_FAILED,
                $data['platform_type']
            );

            // 推送到异步队列，立即返回，不等待发信结果
            $this->driverFactory->get('default')->push($job);

            $this->loggerService->info('RabbitMQ消息-更新积分任务-成功', ['data' => $data]);

			return Result::ACK;
		} catch (\Exception $e) {
			$this->loggerService->error("RabbitMQ消息-更新积分任务-失败", [
                'data' => $data,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
			return Result::NACK;
		}
    }
}
