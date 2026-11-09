<?php

declare(strict_types=1);

namespace App\Service\Admin\Stripe;

use Throwable;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Redis\Redis;
use App\Constants\OrderCode;
use App\Exception\BusinessException;
use App\Exception\VersionConflictException;
use App\Model\Member;
use App\Model\MemberOrderDetail;
use App\Model\MemberOrderMaster;
use App\Model\MemberOrderRefund;
use App\Model\MemberPointsLog;
use App\Model\MemberPointsTaskOrder;
use App\Service\LoggerService;
use App\Service\Api\Stripe\StripeService as ApiStripeService;

class StripeService
{
    /**
     * 扣减退款积分的乐观锁最大重试次数
     */
    private const REFUND_MAX_ATTEMPTS = 3;

    /**
     * 执行退款互斥锁前缀：防止并发请求重复调用 Stripe
     */
    private const EXECUTE_LOCK_KEY_PREFIX = 'member:order_refund:executing:';

    /**
     * 执行退款互斥锁有效期（秒）
     */
    private const EXECUTE_LOCK_TTL = 300;

    #[Inject]
    protected LoggerService $loggerService;

    #[Inject]
    protected Redis $redis;

    /**
     * 复用会员端 Stripe 服务：退款条件判断与 Stripe 客户端
     */
    #[Inject]
    protected ApiStripeService $apiStripeService;

    /**
     * 执行Stripe退款（第三个接口）
     * 先调用退款条件判断，通过后调用 Stripe SDK 退款，再在同一事务内扣减会员积分并写积分流水
     * @param int $adminId 操作的管理员id
     * @param int $refundId 退款记录id
     * @return array
     */
    public function executeRefund(int $adminId, int $refundId): array
    {
        $context = [
            'admin_id' => $adminId,
            'refund_id' => $refundId,
        ];

        $lockKey = self::EXECUTE_LOCK_KEY_PREFIX . $refundId;
        $lockAcquired = false;

        try {
            $refund = MemberOrderRefund::query()->where('refund_id', $refundId)->first();

            if ($refund === null) {
                throw new BusinessException(400, '退款记录不存在');
            }

            $context['order_no'] = (string) $refund->order_no;
            $context['member_id'] = (int) $refund->member_id;

            $status = (int) $refund->status;
            // 退款记录版本号，后续状态更新均按读到的版本做乐观锁
            $refundVersion = (int) $refund->version;

            // 已退款直接返回，保证重复点击不会重复退款
            if ($status === OrderCode::REFUND_STATUS_SUCCESS) {
                $this->loggerService->info('Stripe退款：记录已退款，跳过', $context);

                return [
                    'refund_id' => $refundId,
                    'order_no' => (string) $refund->order_no,
                    'member_id' => (int) $refund->member_id,
                    'status' => OrderCode::REFUND_STATUS_SUCCESS,
                    'already_refunded' => true,
                    'stripe_refund_id' => (string) ($refund->stripe_refund_id ?? ''),
                ];
            }

            if ($status === OrderCode::REFUND_STATUS_REJECTED) {
                throw new BusinessException(400, '该退款申请已被拒绝，不能执行退款');
            }

            // 只有申请退款/退款申请通过/退款失败可以执行；退款申请通过可能是一次中断遗留的执行
            if (!in_array($status, [
                OrderCode::REFUND_STATUS_APPLIED,
                OrderCode::REFUND_STATUS_APPROVED,
                OrderCode::REFUND_STATUS_FAILED,
            ], true)) {
                throw new BusinessException(400, '退款记录状态不允许执行退款');
            }

            // 互斥锁：同一退款记录同一时间只允许一个执行流程；锁到期后允许重试中断遗留的执行
            $lockAvailable = true;
            try {
                $lockAcquired = (bool) $this->redis->set($lockKey, '1', ['NX', 'EX' => self::EXECUTE_LOCK_TTL]);
            } catch (Throwable $e) {
                $lockAvailable = false;
                $this->loggerService->warning('Stripe退款：获取互斥锁失败，降级继续执行', $context + [
                    'error' => $e->getMessage(),
                ]);
            }

            if ($lockAvailable && !$lockAcquired) {
                throw new BusinessException(409, '该退款记录正在处理中，请稍后重试');
            }

            $master = MemberOrderMaster::query()
                ->select(
                    'order_master_id',
                    'order_no',
                    'member_id',
                    'payment_status',
                    'payment_intent_id',
                    'change_type',
                    'change_points',
                    'version'
                )
                ->where('order_master_id', $refund->order_master_id)
                ->first();

            if ($master === null) {
                throw new BusinessException(400, '退款订单不存在');
            }

            if ((int) $master->member_id !== (int) $refund->member_id || (string) $master->order_no !== (string) $refund->order_no) {
                $this->loggerService->error('Stripe退款：退款记录与订单不匹配', $context);
                throw new BusinessException(500, '退款记录与订单不匹配，请人工核对');
            }

            if ((int) $master->payment_status !== OrderCode::PAYMENT_STATUS_PAID) {
                throw new BusinessException(400, '订单未支付完成，不能退款');
            }

            if (empty($master->payment_intent_id)) {
                throw new BusinessException(400, '订单缺少Stripe支付信息，不能退款');
            }

            $changePoints = round((float) $master->change_points, OrderCode::VIDEO_CREDITS_PRECISION);
            if ($changePoints <= 0) {
                throw new BusinessException(500, '订单退款积分异常，不能退款');
            }

            // 调用第二个接口判断，执行退款时必须查库，不能使用 10 分钟缓存
            $eligibility = $this->apiStripeService->checkRefundEligibility((int) $refund->member_id, (string) $refund->order_no, true);
            if (!$eligibility['allowed']) {
                $this->markRefundStatus($refundId, OrderCode::REFUND_STATUS_REJECTED, [
                    OrderCode::REFUND_STATUS_APPLIED,
                    OrderCode::REFUND_STATUS_APPROVED,
                    OrderCode::REFUND_STATUS_FAILED,
                ], $refundVersion);
                $this->apiStripeService->clearRefundEligibilityCache((int) $refund->member_id, (string) $refund->order_no);

                $this->loggerService->warning('Stripe退款：订单不满足退款条件', $context + [
                    'reason' => $eligibility['reason'],
                ]);
                throw new BusinessException(400, $eligibility['reason'] ?: '订单不满足退款条件');
            }

            // 退款前校验会员积分，避免退款成功后无法扣回
            $memberPoints = Member::query()->where('member_id', $refund->member_id)->value('points');
            if ($memberPoints === null) {
                throw new BusinessException(400, '会员不存在');
            }

            if ((float) $memberPoints < $changePoints) {
                $this->loggerService->warning('Stripe退款：会员积分不足以扣回', $context + [
                    'member_points' => number_format((float) $memberPoints, 2, '.', ''),
                    'change_points' => number_format($changePoints, 2, '.', ''),
                ]);
                throw new BusinessException(400, '会员当前积分不足以扣回退款积分，请先核对会员积分');
            }

            // 抢占退款记录：申请退款/退款失败 -> 退款申请通过；
            // 已是退款申请通过说明上次执行中断（如 Stripe 已退款但扣减未完成），持有互斥锁时直接继续
            if (in_array($status, [OrderCode::REFUND_STATUS_APPLIED, OrderCode::REFUND_STATUS_FAILED], true)) {
                $claimed = $this->markRefundStatus($refundId, OrderCode::REFUND_STATUS_APPROVED, [$status], $refundVersion);

                if (!$claimed) {
                    $currentStatus = MemberOrderRefund::query()->where('refund_id', $refundId)->value('status');

                    if ((int) $currentStatus === OrderCode::REFUND_STATUS_SUCCESS) {
                        $this->loggerService->info('Stripe退款：记录已被其他操作退款成功', $context);

                        return [
                            'refund_id' => $refundId,
                            'order_no' => (string) $refund->order_no,
                            'member_id' => (int) $refund->member_id,
                            'status' => OrderCode::REFUND_STATUS_SUCCESS,
                            'already_refunded' => true,
                            'stripe_refund_id' => (string) ($refund->stripe_refund_id ?? ''),
                        ];
                    }

                    throw new BusinessException(409, '退款记录状态已变化，请刷新后重试');
                }

                $refundVersion++;
            } else {
                $this->loggerService->info('Stripe退款：继续执行中断的退款流程', $context);
            }

            // 全额退款：按 payment_intent 发起，不传 amount 即退全部；幂等键保证重试不会重复退款
            try {
                $stripeRefund = $this->apiStripeService->getClient()->refunds->create([
                    'payment_intent' => (string) $master->payment_intent_id,
                    'reason' => 'requested_by_customer',
                    'metadata' => [
                        'refund_id' => (string) $refundId,
                        'order_no' => (string) $refund->order_no,
                        'member_id' => (string) $refund->member_id,
                        'admin_id' => (string) $adminId,
                    ],
                ], ['idempotency_key' => 'member_order_refund_' . $refundId]);
            } catch (Throwable $e) {
                $this->markRefundStatus($refundId, OrderCode::REFUND_STATUS_FAILED, [OrderCode::REFUND_STATUS_APPROVED], $refundVersion);
                $this->apiStripeService->clearRefundEligibilityCache((int) $refund->member_id, (string) $refund->order_no);

                $this->loggerService->error('Stripe退款：调用Stripe退款接口失败', $context + [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                throw new BusinessException(500, 'Stripe退款失败：' . $e->getMessage());
            }

            $context['stripe_refund_id'] = (string) $stripeRefund->id;
            $context['stripe_refund_status'] = (string) $stripeRefund->status;

            // 先把 Stripe 退款单号落库：即使后续扣减失败，也能凭该单号与 Stripe 对账
            if ($this->updateRefundStripeId($refundId, (string) $stripeRefund->id, $refundVersion)) {
                $refundVersion++;
            } else {
                $this->loggerService->warning('Stripe退款：写入Stripe退款单号失败，将由成功事务补写', $context);
            }

            // succeeded：退款已完成；pending：Stripe 已受理、资金退回中，均按退款成功处理
            if (!in_array((string) $stripeRefund->status, ['succeeded', 'pending'], true)) {
                $this->markRefundStatus($refundId, OrderCode::REFUND_STATUS_FAILED, [OrderCode::REFUND_STATUS_APPROVED], $refundVersion);
                $this->apiStripeService->clearRefundEligibilityCache((int) $refund->member_id, (string) $refund->order_no);

                $this->loggerService->error('Stripe退款：Stripe退款未成功', $context);
                throw new BusinessException(500, 'Stripe退款未成功：' . $stripeRefund->status);
            }

            // Stripe 已退款，执行数据库扣减；失败时退款记录停留在「退款申请通过」，重试会命中幂等键继续完成扣减
            $result = $this->retryOnVersionConflict(
                fn () => $this->deductRefundPoints($adminId, $refundId, $master, (string) $stripeRefund->id),
                $context
            );

            $this->apiStripeService->clearRefundEligibilityCache((int) $refund->member_id, (string) $refund->order_no);

            $this->loggerService->info('Stripe退款成功', array_merge($context, $result));

            return $result;
        } catch (BusinessException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->loggerService->error('Stripe退款执行异常', array_merge($context, [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]));
            throw new BusinessException(500, 'Stripe退款执行失败，请稍后重试');
        } finally {
            if ($lockAcquired) {
                try {
                    $this->redis->del($lockKey);
                } catch (Throwable $e) {
                    $this->loggerService->warning('Stripe退款：释放互斥锁失败', $context + [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * 事务内扣减退款积分：锁定会员与订单批次，置批次为已消耗完、写积分流水、扣减会员积分、更新退款记录状态
     * @param int $adminId
     * @param int $refundId
     * @param MemberOrderMaster $master
     * @param string $stripeRefundId
     * @return array
     */
    private function deductRefundPoints(int $adminId, int $refundId, MemberOrderMaster $master, string $stripeRefundId): array
    {
        return Db::transaction(function () use ($adminId, $refundId, $master, $stripeRefundId) {
            $refund = MemberOrderRefund::query()
                ->where('refund_id', $refundId)
                ->lockForUpdate()
                ->first();

            if ($refund === null) {
                throw new BusinessException(400, '退款记录不存在');
            }

            if ((int) $refund->status !== OrderCode::REFUND_STATUS_APPROVED) {
                throw new BusinessException(400, '退款记录状态已变化，请刷新后重试');
            }

            $refundVersion = (int) $refund->version;

            if ((int) $refund->order_master_id !== (int) $master->order_master_id) {
                throw new BusinessException(500, '退款记录与订单不匹配，请人工核对');
            }

            $memberId = (int) $refund->member_id;
            $orderNo = (string) $refund->order_no;
            $changePoints = round((float) $master->change_points, OrderCode::VIDEO_CREDITS_PRECISION);

            // 与消耗积分保持相同的加锁顺序：先锁会员，再锁订单批次，避免并发死锁
            $member = Member::query()
                ->select('member_id', 'points', 'version')
                ->where('member_id', $memberId)
                ->lockForUpdate()
                ->first();

            if ($member === null) {
                throw new BusinessException(400, '会员不存在');
            }

            $beforePoints = (float) $member->points;
            if ($beforePoints < $changePoints) {
                throw new BusinessException(400, '会员当前积分不足以扣回退款积分，请先核对会员积分');
            }

            $details = MemberOrderDetail::query()
                ->select('order_details_id', 'status', 'change_points', 'version')
                ->where('order_master_id', $master->order_master_id)
                ->where('member_id', $memberId)
                ->lockForUpdate()
                ->get();

            if (count($details) === 0) {
                throw new BusinessException(500, '订单积分批次不存在，请人工核对');
            }

            $detailPoints = 0.0;
            foreach ($details as $detail) {
                if ((int) $detail->status !== OrderCode::DETAIL_STATUS_ACTIVE) {
                    $this->loggerService->warning('Stripe退款：订单积分已被使用', [
                        'refund_id' => $refundId,
                        'order_details_id' => (int) $detail->order_details_id,
                        'status' => (int) $detail->status,
                    ]);
                    throw new BusinessException(400, '订单积分已被使用，不能退款');
                }

                $detailPoints += (float) $detail->change_points;
            }

            $detailPoints = round($detailPoints, OrderCode::VIDEO_CREDITS_PRECISION);

            // 批次剩余积分必须与订单积分一致，防止只退一部分或重复退
            if (abs($detailPoints - $changePoints) > 0.009) {
                $this->loggerService->error('Stripe退款：订单积分批次与订单积分不一致', [
                    'refund_id' => $refundId,
                    'order_no' => $orderNo,
                    'change_points' => number_format($changePoints, 2, '.', ''),
                    'detail_points' => number_format($detailPoints, 2, '.', ''),
                ]);
                throw new BusinessException(500, '订单积分批次与订单积分不一致，请人工核对');
            }

            if (MemberPointsTaskOrder::query()->where('order_no', $orderNo)->exists()) {
                $this->loggerService->warning('Stripe退款：订单积分已被使用', [
                    'refund_id' => $refundId,
                    'order_no' => $orderNo,
                ]);
                throw new BusinessException(400, '订单积分已被使用，不能退款');
            }

            // 1. 批次置为已消耗完，移出 FIFO 扣减范围
            foreach ($details as $detail) {
                $detailVersion = (int) $detail->version;
                $updated = MemberOrderDetail::query(true)
                    ->where('order_details_id', $detail->order_details_id)
                    ->where('status', OrderCode::DETAIL_STATUS_ACTIVE)
                    ->where('version', $detailVersion)
                    ->update([
                        'status' => OrderCode::DETAIL_STATUS_USED,
                        'version' => $detailVersion + 1,
                    ]);

                if ($updated === 0) {
                    throw new VersionConflictException('order_details_id=' . $detail->order_details_id);
                }
            }

            $afterPoints = round($beforePoints - $changePoints, OrderCode::VIDEO_CREDITS_PRECISION);

            // 2. 生成订单退款积分流水
            MemberPointsLog::create([
                'member_id' => $memberId,
                'order_id' => (int) $master->order_master_id,
                'order_no' => $orderNo,
                'points_task_id' => null,
                'points_task_no' => '',
                'flag' => OrderCode::FLAG_DECREASE,
                'change_type' => OrderCode::CHANGE_TYPE_ORDER_REFUND,
                'change_points' => number_format($changePoints, 2, '.', ''),
                'before_points' => number_format($beforePoints, 2, '.', ''),
                'after_points' => number_format($afterPoints, 2, '.', ''),
                'description' => sprintf(
                    '订单%s退款成功，扣减%s积分（Stripe退款id:%s）',
                    $orderNo,
                    number_format($changePoints, 2, '.', ''),
                    $stripeRefundId
                ),
                'audit_notes' => sprintf('管理员id:%s执行Stripe退款', $adminId),
            ]);

            // 3. 乐观锁扣减会员积分
            $updatedMember = Member::query(true)
                ->where('member_id', $memberId)
                ->where('version', (int) $member->version)
                ->update([
                    'points' => number_format($afterPoints, 2, '.', ''),
                    'version' => (int) $member->version + 1,
                ]);

            if ($updatedMember === 0) {
                throw new VersionConflictException('member_id=' . $memberId);
            }

            // 4. 退款记录置为退款成功（乐观锁：状态仍为退款申请通过且版本号未变化）
            $updatedRefund = MemberOrderRefund::query(true)
                ->where('refund_id', $refundId)
                ->where('status', OrderCode::REFUND_STATUS_APPROVED)
                ->where('version', $refundVersion)
                ->update([
                    'status' => OrderCode::REFUND_STATUS_SUCCESS,
                    'stripe_refund_id' => $stripeRefundId,
                    'version' => $refundVersion + 1,
                ]);

            if ($updatedRefund === 0) {
                throw new VersionConflictException('refund_id=' . $refundId);
            }

            return [
                'refund_id' => $refundId,
                'order_no' => $orderNo,
                'member_id' => $memberId,
                'status' => OrderCode::REFUND_STATUS_SUCCESS,
                'already_refunded' => false,
                'change_points' => number_format($changePoints, 2, '.', ''),
                'before_points' => number_format($beforePoints, 2, '.', ''),
                'after_points' => number_format($afterPoints, 2, '.', ''),
                'stripe_refund_id' => $stripeRefundId,
            ];
        }, self::REFUND_MAX_ATTEMPTS);
    }

    /**
     * 按指定前置状态和版本号更新退款记录状态（乐观锁）
     * @param int $refundId
     * @param int $status 目标状态
     * @param array $fromStatuses 允许的前置状态
     * @param int $expectedVersion 读取到的版本号
     * @return bool
     */
    private function markRefundStatus(int $refundId, int $status, array $fromStatuses, int $expectedVersion): bool
    {
        $updated = MemberOrderRefund::query(true)
            ->where('refund_id', $refundId)
            ->whereIn('status', $fromStatuses)
            ->where('version', $expectedVersion)
            ->update([
                'status' => $status,
                'version' => $expectedVersion + 1,
            ]);

        return $updated > 0;
    }

    /**
     * 记录 Stripe 退款单号（乐观锁），便于退款扣减失败时对账
     * @param int $refundId
     * @param string $stripeRefundId
     * @param int $expectedVersion
     * @return bool
     */
    private function updateRefundStripeId(int $refundId, string $stripeRefundId, int $expectedVersion): bool
    {
        $updated = MemberOrderRefund::query(true)
            ->where('refund_id', $refundId)
            ->where('status', OrderCode::REFUND_STATUS_APPROVED)
            ->where('version', $expectedVersion)
            ->update([
                'stripe_refund_id' => $stripeRefundId,
                'version' => $expectedVersion + 1,
            ]);

        return $updated > 0;
    }

    /**
     * 乐观锁冲突重试：抛出 VersionConflictException 时整笔事务回滚并重试
     * @param callable $callback
     * @param array $context 仅用于日志
     * @return mixed
     */
    private function retryOnVersionConflict(callable $callback, array $context): mixed
    {
        for ($attempt = 1; $attempt <= self::REFUND_MAX_ATTEMPTS; ++$attempt) {
            try {
                return $callback();
            } catch (VersionConflictException $e) {
                $this->loggerService->warning('Stripe退款：版本冲突，重试', array_merge($context, [
                    'attempt' => $attempt,
                    'max_attempts' => self::REFUND_MAX_ATTEMPTS,
                    'conflict' => $e->getMessage(),
                ]));
            }
        }

        throw new BusinessException(409, '退款数据正在被其他操作修改，请稍后重试');
    }
}
