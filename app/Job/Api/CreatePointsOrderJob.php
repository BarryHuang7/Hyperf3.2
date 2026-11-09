<?php

declare(strict_types=1);

namespace App\Job\Api;

use Hyperf\AsyncQueue\Job;
use Hyperf\Context\ApplicationContext;
use App\Service\Api\Stripe\StripeService;
use App\Service\LoggerService;
use Throwable;

class CreatePointsOrderJob extends Job
{
    // 最大重试次数（实际执行次数 = 此值 + 1）
    protected int $maxAttempts = 2;

    // Job 里只放可序列化的普通数据，不要放容器、PDO 等对象
    public int $memberId;
    public string $stripeCheckoutId;
    public string $changePoints;
    public string $orderNo;
    public string $stripeCheckoutUrl;
    public string $price;

    public function __construct(int $memberId, string $stripeCheckoutId, float|string $changePoints, string $orderNo, string $stripeCheckoutUrl, float|string $price = '0.00')
    {
        $this->memberId = $memberId;
        $this->stripeCheckoutId = $stripeCheckoutId;
        $this->changePoints = (string) $changePoints;
        $this->orderNo = $orderNo;
        $this->stripeCheckoutUrl = $stripeCheckoutUrl;
        $this->price = (string) $price;
    }

    public function handle(): void
    {
        $container = ApplicationContext::getContainer();
        $loggerService = $container->get(LoggerService::class);

        try {
            $stripeService = $container->get(StripeService::class);

            $result = $stripeService->createRechargeOrder(
                $this->memberId,
                $this->stripeCheckoutId,
                $this->changePoints,
                $this->orderNo,
                $this->stripeCheckoutUrl,
                $this->price,
            );

            $loggerService->info('异步队列-创建积分订单-成功', $result);
        } catch (Throwable $e) {
            $recordArray = [
                'member_id' => $this->memberId,
                'stripe_checkout_id' => $this->stripeCheckoutId,
                'change_points' => $this->changePoints,
                'order_no' => $this->orderNo,
                'error' => $e->getMessage(),
            ];
            $loggerService->error('异步队列-创建积分订单-失败', $recordArray);
            $loggerService->info('异步队列-创建积分订单-重试', $recordArray);
            // 抛出异常交给队列重试
            throw $e;
        }
    }
}
