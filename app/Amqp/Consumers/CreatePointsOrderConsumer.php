<?php

declare(strict_types=1);

namespace App\Amqp\Consumers;

use Hyperf\Amqp\Annotation\Consumer;
use Hyperf\Amqp\Message\ConsumerMessage;
use Hyperf\Amqp\Result;
use PhpAmqpLib\Message\AMQPMessage;
use Hyperf\Di\Annotation\Inject;
use App\Service\LoggerService;
use App\Job\Api\CreatePointsOrderJob;
use Hyperf\AsyncQueue\Driver\DriverFactory;

#[Consumer(exchange: "hyperf.internal", routingKey: "member.order", queue: "queue_create_points_order", nums: 1)]
class CreatePointsOrderConsumer extends ConsumerMessage
{
    #[Inject]
    protected DriverFactory $driverFactory;

	#[Inject]
    protected LoggerService $loggerService;

    public function consumeMessage($data, AMQPMessage $message): Result
    {
		try {
            if (empty($data['memberId']) || empty($data['stripeCheckoutId']) || !isset($data['changePoints']) || empty($data['orderNo']) || empty($data['stripeCheckoutUrl'])) {
                $this->loggerService->error("RabbitMQ消息-创建积分订单-失败，参数错误", ['data' => $data]);
                return Result::ACK;
            }

			$job = new CreatePointsOrderJob($data['memberId'], $data['stripeCheckoutId'], $data['changePoints'], $data['orderNo'], $data['stripeCheckoutUrl'], $data['price'] ?? '0.00');

            // 推送到异步队列，立即返回，不等待发信结果
            $this->driverFactory->get('default')->push($job);

            $this->loggerService->info('RabbitMQ消息-创建积分订单-成功', ['data' => $data]);

			return Result::ACK;
		} catch (\Exception $e) {
			$this->loggerService->error("RabbitMQ消息-创建积分订单-失败", [
                'data' => $data,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
			return Result::NACK;
		}
    }
}
