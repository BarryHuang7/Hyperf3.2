<?php

declare(strict_types=1);

namespace App\Job\Api;

use Hyperf\AsyncQueue\Job;
use Hyperf\Context\ApplicationContext;
use App\Service\Api\Stripe\StripeService;
use App\Service\LoggerService;
use Throwable;

class OrderOverdueJob extends Job
{
    // 最大重试次数（实际执行次数 = 此值 + 1）
    protected int $maxAttempts = 2;

    // Job 里只放可序列化的普通数据，不要放容器、PDO 等对象
    public string $orderNo;

    public function __construct(string $orderNo)
    {
        $this->orderNo = $orderNo;
    }

    public function handle(): void
    {
        $container = ApplicationContext::getContainer();
        $loggerService = $container->get(LoggerService::class);

        try {
            $stripeService = $container->get(StripeService::class);

            $result = $stripeService->orderOverdue($this->orderNo);

            $loggerService->info('异步延迟队列-检查积分订单超时-成功', $result);
        } catch (Throwable $e) {
            $recordArray = [
                'order_no' => $this->orderNo,
                'error' => $e->getMessage(),
            ];
            $loggerService->error('异步延迟队列-检查积分订单超时-失败', $recordArray);
            $loggerService->info('异步延迟队列-检查积分订单超时-重试', $recordArray);
            // 抛出异常交给队列重试
            throw $e;
        }
    }
}
