<?php

declare(strict_types=1);

namespace App\Controller\Api\WebHook;

use function Hyperf\Config\config;

use Throwable;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;
use App\Constants\OrderCode;
use App\Service\LoggerService;
use App\Service\WebSocketPushService;
use App\Service\Api\Notice\EmailService;
use App\Service\Api\Stripe\StripeService;
use App\Model\Member;
use App\Model\StripeJson;
use App\Model\MemberPointsLog;
use App\Model\MemberOrderMaster;
use App\Model\MemberOrderDetail;

class StripeController
{
    #[Inject]
    protected LoggerService $loggerService;

    #[Inject]
    protected StripeService $stripeService;

    #[Inject]
    protected EmailService $emailService;

    #[Inject]
    protected WebSocketPushService $webSocketPushService;

    public function handle(RequestInterface $request, ResponseInterface $response)
    {
        // 1. 获取 Stripe 签名头
        $sigHeader = $request->getHeaderLine('Stripe-Signature');
        if (empty($sigHeader)) {
            $this->loggerService->info('Stripe Missing signature: '.$sigHeader);
            return $response->raw('Missing signature')->withStatus(400);
        }

        // 2. 获取原始请求体
        $payload = $request->getBody()->getContents();

        // 3. 从配置读取签名密钥
        $endpointSecret = config('stripe.webhook_secret');

        // $this->loggerService->info('Stripe endpointSecret: '.$endpointSecret);
        // $this->loggerService->info('Stripe payload: '.json_encode($payload));
        // $this->loggerService->info('Stripe sigHeader: '.$sigHeader);

        try {
            // 4. 验证签名并构造事件对象
            $event = Webhook::constructEvent($payload, $sigHeader, $endpointSecret);
        } catch (SignatureVerificationException $e) {
            $this->loggerService->info('Stripe Invalid signature: '.$e->getMessage());
            // 签名验证失败，返回 400
            return $response->raw('Invalid signature')->withStatus(400);
        } catch (\UnexpectedValueException $e) {
            $this->loggerService->info('Stripe Invalid payload: '.$e->getMessage());
            // 请求体不是合法 JSON
            return $response->raw('Invalid payload')->withStatus(400);
        }

        // 5. 根据事件类型处理业务
        switch ($event->type) {
            case 'checkout.session.expired':
                $session = $event->data->object;

                $this->loggerService->info('Stripe checkout.session.expired: '.json_encode($session));

                $this->stripeService->orderOverdue($session->metadata->order_no);
                break;
            case 'payment_intent.succeeded':
                $paymentIntent = $event->data->object;

                $this->loggerService->info('Stripe payment_intent.succeeded: '.json_encode($paymentIntent));

                $orderNo = $paymentIntent->metadata->order_no;
                $exists = StripeJson::query()->select('stripe_json_id')->where('order_no', $orderNo)->exists();

                if ($exists) {
                    StripeJson::query()
                        ->where('order_no', $orderNo)
                        ->update(['payment_intent_json' => $paymentIntent->toJSON()]);
                } else {
                    StripeJson::create([
                        'order_no' => $orderNo,
                        'payment_intent_json' => $paymentIntent->toJSON(),
                    ]);
                }

                $this->handleOrder($paymentIntent->id, $orderNo);
                break;
            default:
                $this->loggerService->info('Stripe default: '.json_encode($event));
                break;
        }

        // 6. 必须返回 2xx，否则 Stripe 会重试
        return $response->raw('OK')->withStatus(200);
    }

    /**
     * 处理支付成功的积分订单
     * @param string $orderNo
     */
    private function handleOrder(string $paymentIntentId, string $orderNo)
    {
        /**
         * 记录错误日志的数组
         */
        $recordArray = [
            'payment_intent_id' => $paymentIntentId,
            'order_no' => $orderNo,
        ];

        if (empty($paymentIntentId) || empty($orderNo)) {
            $this->loggerService->error('Stripe Webhook 缺少 paymentIntentId 或 orderNo', $recordArray);
            return;
        }

        try {
            Db::transaction(function () use ($orderNo, $paymentIntentId, $recordArray) {
                $master = MemberOrderMaster::select(
                        'order_master_id', 'member_id', 'version', 'change_points',
                        'payment_status', 'flag', 'change_type'
                    )
                    ->where('order_no', $orderNo)
                    ->first();
                if ($master === null) {
                    $this->loggerService->error('Stripe Webhook 未找到对应订单', $recordArray);
                    return;
                }

                $orderMasterId = $master->order_master_id;
                $masterVersion = $master->version;
                $memberId = $master->member_id;
                $changePoints = $master->change_points;

                $recordArray['order_master_id'] = $orderMasterId;
                $recordArray['member_id'] = $memberId;
                $recordArray['change_points'] = $changePoints;

                // 幂等：订单已是「已支付」，说明此前已处理过，直接返回，不重复入账
                if ((int) $master->payment_status === OrderCode::PAYMENT_STATUS_PAID) {
                    $this->loggerService->info('Stripe Webhook 订单已支付，跳过重复处理', $recordArray);
                    return;
                }

                // 乐观锁：仅在「待支付」状态下按版本号原子更新，防止并发回调重复处理。
                // 用 query(true) 让模型缓存构建器在更新后自动失效该订单缓存。
                $updatedMaster = MemberOrderMaster::query(true)
                    ->where('order_master_id', $orderMasterId)
                    ->where('payment_status', OrderCode::PAYMENT_STATUS_PENDING)
                    ->where('version', $masterVersion)
                    ->update([
                        'payment_status' => OrderCode::PAYMENT_STATUS_PAID,
                        'payment_intent_id' => $paymentIntentId,
                        // 记录 Stripe 支付完成时间，供退款 14 天窗口判断使用
                        'paid_at' => date('Y-m-d H:i:s'),
                        'version' => $masterVersion + 1,
                    ]);

                if ($updatedMaster === 0) {
                    // 状态或版本号已变化（可能并发回调已处理），仅记日志，不重复操作数据库
                    $this->loggerService->warning('Stripe Webhook master订单状态冲突，已被其他回调处理', $recordArray);
                    return;
                }

                $this->loggerService->info('Stripe Webhook 主订单更新完成', $recordArray);

                $updatedDetails = MemberOrderDetail::query(true)
                    ->where('order_master_id', $orderMasterId)
                    ->where('status', OrderCode::DETAIL_STATUS_NOT_ACTIVE)
                    ->update([
                        'status' => OrderCode::DETAIL_STATUS_ACTIVE,
                        'version' => Db::raw('version + 1'),
                    ]);

                $recordArray['updated_details'] = $updatedDetails;
                $this->loggerService->info('Stripe Webhook 详情订单激活完成', $recordArray);

                $changePoints = number_format((float) $changePoints, 2, '.', '');

                // 用行锁读取会员当前积分，避免并发下 before/after 计算错误
                $member = Member::query()
                    ->select('member_id', 'email', 'points')
                    ->where('member_id', $memberId)
                    ->lockForUpdate()
                    ->first();
                if ($member === null) {
                    throw new \RuntimeException('会员不存在 member_id=' . $memberId);
                }

                $beforePoints = number_format((float) $member->points, 2, '.', '');
                $afterPoints = number_format((float) $beforePoints + (float) $changePoints, 2, '.', '');

                // 更新会员积分余额（并同步 +1 会员版本号）
                // 用 query(true) 让模型缓存构建器在更新后自动失效该会员缓存。
                Member::query(true)
                    ->where('member_id', $memberId)
                    ->update([
                        'points' => Db::raw('points + ' . $changePoints),
                        'version' => Db::raw('version + 1'),
                    ]);

                $this->loggerService->info('Stripe Webhook 会员积分更新完成', $recordArray);

                // 生成积分流水
                MemberPointsLog::create([
                    'member_id' => $memberId,
                    'order_id' => $orderMasterId,
                    'order_no' => $orderNo,
                    'flag' => $master->flag,
                    'change_type' => $master->change_type,
                    'change_points' => $changePoints,
                    'before_points' => $beforePoints,
                    'after_points' => $afterPoints,
                    'description' => 'Stripe 支付成功，充值积分入账',
                ]);

                $this->loggerService->info('Stripe Webhook 订单支付处理成功', $recordArray);
                
                // 发送websocket给前端
                $this->webSocketPushService->pushToUser(
                    (int) $memberId,
                    [
                        'order_no' => $orderNo,
                        'change_points' => $changePoints,
                    ],
                    'Payment successful',
                    'Stripe Webhook 发送支付成功',
                );

                // 发送邮件通知
                $html = '<p>Your order [<strong>'.$orderNo.'</strong>] has been fully paid and your points have been credited <strong>'.$changePoints.'</strong>.</p>';
                $this->emailService->sendEmail($member->email, $html, 'Payment Successful');
            });
        } catch (Throwable $e) {
            // 业务异常只记日志：Webhook 已验签并收到，若返回 4xx Stripe 会持续重试造成重复入账，
            // 因此这里统一吞掉异常并返回 2xx，让支付处理通过人工对账兜底。
            $this->loggerService->error('Stripe Webhook 处理订单异常', [
                'payment_intent_id' => $paymentIntentId,
                'order_no' => $orderNo,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
