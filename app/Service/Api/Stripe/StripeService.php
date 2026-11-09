<?php

declare(strict_types=1);

namespace App\Service\Api\Stripe;

use function Hyperf\Config\config;
use function Hyperf\AsyncQueue\dispatch;

use Throwable;
use Stripe\ApiRequestor;
use Stripe\StripeClient;
use Stripe\Checkout\Session;
use App\Model\Member;
use App\Model\StripeJson;
use App\Model\MemberOrderRefund;
use App\Model\MemberOrderDetail;
use App\Model\MemberPointsTaskOrder;
use App\Constants\AuthCode;
use App\Constants\OrderCode;
use App\Service\UtilsService;
use App\Service\LoggerService;
use App\Service\Api\Notice\EmailService;
use App\Service\Api\Member\AuthService;
use App\Job\Api\OrderOverdueJob;
use App\Model\PlanPricing;
use App\Model\MemberOrderMaster;
use App\Exception\BusinessException;
use App\Exception\VersionConflictException;
use Hyperf\Amqp\Producer;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Redis\Redis;
use App\Service\Api\Stripe\CoroutineStripeHttpClient;
use App\Amqp\Producers\MemberOrderProducer;

class StripeService
{
    /**
     * 退款申请时的乐观锁最大重试次数
     */
    private const REFUND_MAX_ATTEMPTS = 3;

    #[Inject]
    protected LoggerService $loggerService;

    #[Inject]
    protected UtilsService $utilsService;

    #[Inject]
    protected EmailService $emailService;

    #[Inject]
    protected Redis $redis;

    #[Inject]
    private Producer $producer;

    #[Inject]
    private AuthService $authService;

    private ?StripeClient $client = null;
    private string $secretKey;

    public function __construct(CoroutineStripeHttpClient $httpClient)
    {
        // 全局设置一次即可，确保后续所有 Stripe 调用都走协程客户端
        ApiRequestor::setHttpClient($httpClient);

        $this->secretKey = config('stripe.secret_key', '');

        if (empty($this->secretKey)) {
            throw new \RuntimeException('Stripe secret key is not configured.');
        }

        $this->client = new StripeClient($this->secretKey);
    }

    public function getClient(): StripeClient
    {
        if ($this->client === null) {
            $this->client = new StripeClient($this->secretKey);
        }
        return $this->client;
    }

    /**
     * 创建stripe结账会话
     * @param int $planId
     * @param int $memberId
     * @param string $orderNo
     * @param string $email
     * @param string $ip
     * @return Session
     */
    public function createCheckoutSession(int $planId, int $memberId, string $orderNo, string $email, string $ip): Session
    {
        try {
            $this->utilsService->acquireIdempotency(AuthCode::MEMBER_STRIPE_IDEMPOTENCY_KEY . $ip, 5);

            $token = '';
            if ($memberId === 0) {
                $member = Member::select('member_id', 'automatically_create')->where('email', $email)->first();

                // 可能是之前自动创建过的会员，又再次创建订单
                if (!$member) {
                    $register = $this->authService->register($email, '123456', $email, 0, true);
        
                    if ($register['code'] !== 200) {
                        throw new BusinessException(400, '创建会员失败');
                    }

                    $member = Member::select('member_id')->where('email', $email)->first();
    
                    if (!$member) {
                        throw new BusinessException(400, '创建的新会员不存在');
                    }
                }

                $memberId = $member->member_id;
                $login = $this->authService->passwordFreeLogin($memberId, $email);

                if ($login['code'] !== 200) {
                    throw new BusinessException(400, '会员免密登录失败');
                }

                $token = '&token=' . $login['data']['token'];
            }

            $plan = PlanPricing::select('plan_name', 'price', 'points')->where('plan_id', $planId)->first();

            if (!$plan) {
                throw new BusinessException(400, '套餐不存在');
            }

            $params = [
                'line_items' => [[
                    'price_data' => [
                        'currency' => 'usd',
                        'product_data' => [
                            'name' => $plan->plan_name,
                        ],
                        // 单位是分，2000 = $20.00
                        'unit_amount' => $plan->price * 100,
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment',
                'success_url' => 'http://localhost/?session_id={CHECKOUT_SESSION_ID}&order_no='.$orderNo.$token,
                'cancel_url' => 'http://localhost/cancel?order_no='.$orderNo.$token,
                // 过期时间30分钟
                'expires_at' => time() + 30 * 60,
                'metadata' => [
                    'order_no' => $orderNo,
                    'email' => $email,
                ],
                'payment_intent_data' => [
                    'metadata' => [
                        'order_no' => $orderNo,
                        'email' => $email,
                    ],
                ],
            ];

            $this->loggerService->info('Stripe创建结账会话: '.json_encode($params));

            $session = $this->getClient()->checkout->sessions->create($params);

            $this->loggerService->info('Stripe创建结账会话成功: '.json_encode($session));

            $stripeCheckoutId = $session->id;
            $changePoints = $plan->points;

            $message = new MemberOrderProducer([
                'memberId' => $memberId,
                'stripeCheckoutId' => $stripeCheckoutId,
                'changePoints' => $changePoints,
                'price' => $plan->price,
                'orderNo' => $orderNo,
                'stripeCheckoutUrl' => $session->url,
            ]);
            $this->producer->produce($message);

            StripeJson::create([
                'order_no' => $orderNo,
                'checkout_session_json' => $session,
            ]);

            $this->loggerService->info('积分订单已推送到RabbitMQ', [
                'member_id' => $memberId,
                'stripe_checkout_id' => $stripeCheckoutId,
                'change_points' => $changePoints,
                'order_no' => $orderNo,
                'email' => $email,
                'ip' => $ip,
            ]);

            return $session;
        } catch (BusinessException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->loggerService->error('Stripe创建结账会话失败', [
                'order_no' => $orderNo,
                'error' => $e->getMessage(),
            ]);
            throw new BusinessException(500, 'Stripe创建结账会话失败，请稍后重试');
        }
    }

    /**
     * 创建充值积分订单
     * @param int $memberId
     * @param string $stripeCheckoutId
     * @param float|string $changePoints 充值积分，恒为正数
     * @param string $orderNo
     * @param string $stripeCheckoutUrl
     * @param float|string $price 套餐支付金额（USD）
     * @return array
     */
    public function createRechargeOrder(int $memberId, string $stripeCheckoutId, float|string $changePoints, string $orderNo, string $stripeCheckoutUrl, float|string $price = '0.00'): array
    {
        if ((float) $changePoints <= 0) {
            throw new BusinessException(400, '充值积分必须大于0');
        }

        // 数据库字段为 decimal(12,2)，统一保留两位小数，避免浮点误差
        $changePoints = number_format((float) $changePoints, 2, '.', '');
        $price = number_format((float) $price, 2, '.', '');

        try {
            return Db::transaction(function () use ($memberId, $stripeCheckoutId, $changePoints, $orderNo, $stripeCheckoutUrl, $price) {
                $master = MemberOrderMaster::create([
                    'order_no' => $orderNo,
                    'member_id' => $memberId,
                    'payment_status' => OrderCode::PAYMENT_STATUS_PENDING,
                    'stripe_checkout_id' => $stripeCheckoutId,
                    'stripe_checkout_url' => $stripeCheckoutUrl,
                    'flag' => OrderCode::FLAG_INCREASE,
                    'change_type' => OrderCode::CHANGE_TYPE_RECHARGE,
                    'change_points' => $changePoints,
                    'price' => $price,
                    'version' => 0,
                ]);

                $expirationDate = date('Y-m-d H:i:s', strtotime('+' . OrderCode::POINTS_EXPIRE_DAYS . ' days'));
                $orderMasterId = $master->order_master_id;

                MemberOrderDetail::create([
                    'order_master_id' => $orderMasterId,
                    'member_id' => $memberId,
                    'status' => OrderCode::DETAIL_STATUS_NOT_ACTIVE,
                    'change_points' => $changePoints,
                    'expiration_date' => $expirationDate,
                    'version' => 0,
                ]);

                // 创建30分钟订单超时任务
                dispatch(new OrderOverdueJob($orderNo), 1800);

                return [
                    'order_master_id' => $orderMasterId,
                    'order_no' => $orderNo,
                    'member_id' => $memberId,
                    'change_points' => $changePoints,
                    'stripe_checkout_id' => $stripeCheckoutId,
                ];
            });
        } catch (BusinessException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->loggerService->error('创建积分订单失败', [
                'member_id' => $memberId,
                'stripe_checkout_id' => $stripeCheckoutId,
                'change_points' => $changePoints,
                'error' => $e->getMessage(),
            ]);
            throw new BusinessException(500, '创建积分订单失败，请稍后重试');
        }
    }

    /**
     * 监听还未支付的积分订单
     * @param string $orderNo
     * @return array
     */
    public function orderOverdue(string $orderNo): array
    {
        $recordArray = ['order_no' => $orderNo];

        if (empty($orderNo)) {
            $this->loggerService->error('积分订单超时检查缺少订单编号', $recordArray);
            return $recordArray + ['result' => 'missing_order_no'];
        }

        try {
            $master = MemberOrderMaster::select('payment_status', 'order_master_id', 'member_id', 'version')
                ->where('order_no', $orderNo)
                ->first();
            if ($master === null) {
                $this->loggerService->error('积分订单超时检查未找到订单', $recordArray);
                return $recordArray + ['result' => 'order_not_found'];
            }

            $paymentStatus = (int) $master->payment_status;
            $recordArray['order_master_id'] = $master->order_master_id;
            $recordArray['member_id'] = $master->member_id;
            $recordArray['payment_status'] = $paymentStatus;
            $recordArray['version'] = $master->version;

            // 只处理待支付的订单，其余状态（无需支付/已支付/支付失败）无需变更
            if ($paymentStatus !== OrderCode::PAYMENT_STATUS_PENDING) {
                $this->loggerService->info('积分订单超时检查跳过：订单状态非待支付', $recordArray);
                return $recordArray + ['result' => 'skipped'];
            }

            // 乐观锁：仅在「待支付」且版本号未变化时改为「支付超时」
            // 用 query(true) 让模型缓存构建器在更新后自动失效该订单缓存。
            $updated = MemberOrderMaster::query(true)
                ->where('order_master_id', $master->order_master_id)
                ->where('payment_status', OrderCode::PAYMENT_STATUS_PENDING)
                ->where('version', $master->version)
                ->update([
                    'payment_status' => OrderCode::PAYMENT_STATUS_TIMEOUT,
                    'version' => $master->version + 1,
                ]);

            if ($updated === 0) {
                // 版本号或状态已变化，说明支付回调等流程已处理过该订单，不重复操作
                $this->loggerService->warning('积分订单超时检查状态冲突，订单已被其他流程处理', $recordArray);
                return $recordArray + ['result' => 'version_conflict'];
            }

            $this->loggerService->info('积分订单超时检查成功：订单已置为支付超时', $recordArray);

            $member = Member::query()
                ->select('email')
                ->where('member_id', $master->member_id)
                ->first();

            if ($member === null) {
                $this->loggerService->warning('积分订单超时检查会员不存在', $recordArray);
                return $recordArray + ['result' => 'member_not_exist'];
            }

            // 发邮件通知用户
            $html = '<p>Your order [<strong>'.$orderNo.'</strong>] has been overdue for 30 minutes without payment. It has now been cancelled.</p>';
            $this->emailService->sendEmail($member->email, $html, 'Order Overdue');

            return $recordArray + ['result' => 'timeout'];
        } catch (Throwable $e) {
            // 记日志后抛给队列重试（网络抖动等临时故障重试可恢复）
            $this->loggerService->error('积分订单超时检查异常', $recordArray + [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * 查询订单支付状态
     * @param string $orderNo 订单编号
     * @return array
     */
    public function getOrderStatus(string $orderNo): array
    {
        try {
            if (empty($orderNo)) {
                return [
                    'code' => 400,
                    'data' => ['order_no' => $orderNo],
                    'msg' => '缺少订单编号',
                ];
            }

            $master = MemberOrderMaster::select('order_no', 'member_id', 'payment_status', 'change_points', 'stripe_checkout_url', 'created_at')
                ->where('order_no', $orderNo)
                ->first();

            if ($master === null) {
                $this->loggerService->warning('查询积分订单状态：订单不存在', ['order_no' => $orderNo]);
                return [
                    'code' => 400,
                    'data' => ['order_no' => $orderNo],
                    'msg' => '订单不存在',
                ];
            }

            $paymentStatus = (int) $master->payment_status;

            return [
                'code' => 200,
                'data' => [
                    'order_no' => $orderNo,
                    'payment_status' => $paymentStatus,
                    'change_points' => (string) $master->change_points,
                    'stripe_checkout_url' => (string) $master->stripe_checkout_url,
                    'created_at' => $master->created_at ? $master->created_at->toDateTimeString() : '',
                ],
                'msg' => 'Success'
            ];
        } catch (Throwable $e) {
            $this->loggerService->error('查询积分订单状态失败', [
                'order_no' => $orderNo,
                'error' => $e->getMessage(),
            ]);
            return [
                'code' => 500,
                'data' => ['order_no' => $orderNo],
                'msg' => '查询订单状态失败，请稍后重试',
            ];
        }
    }

    /**
     * 获取套餐列表
     */
    public function getPlanList(): array
    {
        try {
            $plans = PlanPricing::select('plan_id', 'plan_name', 'points', 'price')->get()->toArray();
            return [
                'code' => 200,
                'data' => $plans,
                'msg' => 'Success',
                'status' => 200,
            ];
        } catch (Throwable $e) {
            $this->loggerService->error('查询套餐列表失败', [
                'error' => $e->getMessage(),
            ]);
            return [
                'code' => 500,
                'data' => [],
                'msg' => '查询套餐列表失败，请稍后重试',
                'status' => 500,
            ];
        }
    }

    /**
     * 检查订单是否满足退款条件
     * 规则：订单已支付完成且未超过14天、订单积分从未被使用；会员端查询结果缓存10分钟
     * @param int $memberId 会员id
     * @param string $orderNo 订单编号
     * @param bool $ignoreCache 申请/执行退款时传 true，强制查库，避免使用过期缓存
     * @return array
     */
    public function checkRefundEligibility(int $memberId, string $orderNo, bool $ignoreCache = false): array
    {
        $cacheKey = $this->getRefundEligibilityCacheKey($memberId, $orderNo);

        if (!$ignoreCache) {
            // 缓存异常时降级为直接查库，不影响接口可用性
            try {
                $cached = $this->redis->get($cacheKey);
            } catch (Throwable $e) {
                $cached = null;
                $this->loggerService->warning('读取退款条件缓存失败', ['error' => $e->getMessage()]);
            }

            if (is_string($cached) && $cached !== '') {
                $cachedData = json_decode($cached, true);
                if (is_array($cachedData) && isset($cachedData['allowed'], $cachedData['can_apply'])) {
                    return $cachedData;
                }
            }
        }

        try {
            $data = $this->buildRefundEligibility($memberId, $orderNo);
        } catch (BusinessException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->loggerService->error('检查订单退款条件失败', [
                'member_id' => $memberId,
                'order_no' => $orderNo,
                'error' => $e->getMessage(),
            ]);
            throw new BusinessException(500, '检查退款条件失败，请稍后重试');
        }

        // 写缓存失败只记日志，本次结果照常返回
        try {
            $this->redis->set(
                $cacheKey,
                json_encode($data, JSON_UNESCAPED_UNICODE),
                OrderCode::REFUND_ELIGIBILITY_CACHE_TTL
            );
        } catch (Throwable $e) {
            $this->loggerService->warning('写入退款条件缓存失败', ['error' => $e->getMessage()]);
        }

        return $data;
    }

    /**
     * 会员申请退款
     * 先调用退款条件检查，通过后创建退款记录并把订单类型改为订单退款
     * @param int $memberId
     * @param string $orderNo
     * @param string $reason 申请原因
     * @return array
     */
    public function applyRefund(int $memberId, string $orderNo, string $reason): array
    {
        $context = [
            'member_id' => $memberId,
            'order_no' => $orderNo,
            'reason_for_application' => $reason,
        ];

        try {
            // 申请时必须实时判断，不能用 10 分钟缓存，避免积分刚被消费仍能提交申请
            $eligibility = $this->checkRefundEligibility($memberId, $orderNo, true);
            if (!$eligibility['can_apply']) {
                throw new BusinessException(400, $eligibility['apply_reason'] ?: '当前订单不能申请退款');
            }

            $result = $this->retryOnVersionConflict(
                fn () => $this->createRefundApplication($memberId, $orderNo, $reason),
                $context
            );

            $this->clearRefundEligibilityCache($memberId, $orderNo);
            $this->loggerService->info('会员申请退款成功', array_merge($context, $result));

            return $result;
        } catch (BusinessException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->loggerService->error('会员申请退款失败', array_merge($context, [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]));
            throw new BusinessException(500, '申请退款失败，请稍后重试');
        }
    }

    /**
     * 清除退款条件缓存（申请/执行退款后调用，避免继续返回过期状态）
     * @param int $memberId
     * @param string $orderNo
     */
    public function clearRefundEligibilityCache(int $memberId, string $orderNo): void
    {
        try {
            $this->redis->del($this->getRefundEligibilityCacheKey($memberId, $orderNo));
        } catch (Throwable $e) {
            $this->loggerService->warning('清除退款条件缓存失败', [
                'member_id' => $memberId,
                'order_no' => $orderNo,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 实时计算订单退款条件
     * @param int $memberId
     * @param string $orderNo
     * @return array
     */
    private function buildRefundEligibility(int $memberId, string $orderNo): array
    {
        $master = MemberOrderMaster::query()
            ->select(
                'order_master_id',
                'order_no',
                'member_id',
                'payment_status',
                'payment_intent_id',
                'paid_at',
                'change_type',
                'change_points',
                'version',
                'created_at',
                'updated_at'
            )
            ->where('order_no', $orderNo)
            ->where('member_id', $memberId)
            ->first();

        if ($master === null) {
            throw new BusinessException(400, '订单不存在');
        }

        $orderMasterId = (int) $master->order_master_id;
        $paidAt = $master->paid_at;

        if ($paidAt === null) {
            throw new BusinessException(400, '订单支付时间不存在，请联系管理员');
        }

        $deadline = $paidAt?->copy()->addDays(OrderCode::REFUND_WINDOW_DAYS);

        $result = [
            'order_no' => (string) $master->order_no,
            'change_points' => number_format((float) $master->change_points, 2, '.', ''),
            'paid_at' => $paidAt?->toDateTimeString(),
            'refund_deadline' => $deadline?->toDateTimeString(),
            // 是否满足退款规则（后台执行退款时使用）
            'allowed' => true,
            'reason' => '',
            // 最近一次退款申请状态
            'refund_status' => null,
            // 是否还能提交退款申请（规则通过且没有处理中的退款记录）
            'can_apply' => false,
            'apply_reason' => '',
        ];

        if ((int) $master->payment_status !== OrderCode::PAYMENT_STATUS_PAID) {
            $result['allowed'] = false;
            $result['reason'] = '订单未支付完成，不能退款';
        } elseif ($deadline === null || time() >= $deadline->getTimestamp()) {
            $result['allowed'] = false;
            $result['reason'] = '订单支付完成已超过' . OrderCode::REFUND_WINDOW_DAYS . '天，不能退款';
        } else {
            $result['reason'] = $this->checkOrderPointsUnused($orderMasterId, (string) $master->order_no);
            if ($result['reason'] !== '') {
                $result['allowed'] = false;
            }
        }

        // 最近一次退款记录：申请中/通过/成功都不可再次申请；拒绝/失败允许重新申请
        $refund = MemberOrderRefund::query()
            ->select('refund_id', 'status')
            ->where('order_no', $orderNo)
            ->orderByDesc('refund_id')
            ->first();
        $refundStatus = $refund === null ? null : (int) $refund->status;
        $result['refund_status'] = $refundStatus;

        $refundPending = in_array($refundStatus, [
            OrderCode::REFUND_STATUS_DEFAULT,
            OrderCode::REFUND_STATUS_SUCCESS,
            OrderCode::REFUND_STATUS_APPROVED,
            OrderCode::REFUND_STATUS_APPLIED,
        ], true);

        $result['can_apply'] = $result['allowed'] && !$refundPending;

        if ($result['can_apply']) {
            $result['apply_reason'] = '';
        } elseif ($refundStatus === OrderCode::REFUND_STATUS_SUCCESS) {
            // 已退款成功的订单给出明确提示
            $result['apply_reason'] = '该订单已经退款';
        } elseif ($result['reason'] !== '') {
            $result['apply_reason'] = $result['reason'];
        } else {
            $result['apply_reason'] = '该订单已有退款申请，请勿重复提交';
        }

        return $result;
    }

    /**
     * 检查订单积分是否从未被使用
     * @param int $orderMasterId
     * @param string $orderNo
     * @return string 可退款返回空字符串，否则返回不可退款原因
     */
    private function checkOrderPointsUnused(int $orderMasterId, string $orderNo): string
    {
        $details = MemberOrderDetail::query()
            ->select('order_details_id', 'status', 'change_points', 'expiration_date')
            ->where('order_master_id', $orderMasterId)
            ->get();

        if (count($details) === 0) {
            return '订单积分批次不存在，请联系客服处理';
        }

        $now = time();
        foreach ($details as $detail) {
            // 批次只要不是「激活」状态，就说明积分已被使用或已过期
            if ((int) $detail->status !== OrderCode::DETAIL_STATUS_ACTIVE) {
                return '订单积分已被使用，不能退款';
            }

            if ($detail->expiration_date !== null && $detail->expiration_date->getTimestamp() <= $now) {
                return '订单积分已过期，不能退款';
            }
        }

        // 消费时按批次记录扣减来源，存在记录即说明该订单积分被使用过（含失败任务已返还的情况）
        $used = MemberPointsTaskOrder::query()
            ->where('order_no', $orderNo)
            ->exists();

        return $used ? '订单积分已被使用，不能退款' : '';
    }

    /**
     * 创建退款申请：同一事务内改写订单类型并写入退款记录
     * @param int $memberId
     * @param string $orderNo
     * @param string $reason
     * @return array
     */
    private function createRefundApplication(int $memberId, string $orderNo, string $reason): array
    {
        return Db::transaction(function () use ($memberId, $orderNo, $reason) {
            // 行锁串行化同一订单的并发申请，避免重复创建退款记录
            $master = MemberOrderMaster::query()
                ->select('order_master_id', 'order_no', 'member_id', 'payment_status', 'change_points', 'price', 'version')
                ->where('order_no', $orderNo)
                ->where('member_id', $memberId)
                ->lockForUpdate()
                ->first();

            if ($master === null) {
                throw new BusinessException(400, '订单不存在');
            }

            if ((int) $master->payment_status !== OrderCode::PAYMENT_STATUS_PAID) {
                throw new BusinessException(400, '订单未支付完成，不能退款');
            }

            // 退款金额直接取订单主表记录的实际支付金额
            $price = number_format((float) $master->price, 2, '.', '');
            if ((float) $price <= 0) {
                throw new BusinessException(500, '订单支付金额异常，不能退款');
            }

            $activeRefund = MemberOrderRefund::query()
                ->select('refund_id')
                ->where('order_no', $orderNo)
                ->whereIn('status', [
                    OrderCode::REFUND_STATUS_DEFAULT,
                    OrderCode::REFUND_STATUS_SUCCESS,
                    OrderCode::REFUND_STATUS_APPROVED,
                    OrderCode::REFUND_STATUS_APPLIED,
                ])
                ->lockForUpdate()
                ->first();

            if ($activeRefund !== null) {
                throw new BusinessException(400, '该订单已有退款申请，请勿重复提交');
            }

            $orderMasterId = (int) $master->order_master_id;
            $version = (int) $master->version;

            // 乐观锁：版本号未变化才允许把订单类型改为订单退款
            $updated = MemberOrderMaster::query(true)
                ->where('order_master_id', $orderMasterId)
                ->where('version', $version)
                ->update([
                    'change_type' => OrderCode::CHANGE_TYPE_ORDER_REFUND,
                    'version' => $version + 1,
                ]);

            if ($updated === 0) {
                throw new VersionConflictException('order_master_id=' . $orderMasterId);
            }

            $refundId = (int) MemberOrderRefund::create([
                'order_master_id' => $orderMasterId,
                'order_no' => $orderNo,
                'member_id' => $memberId,
                'status' => OrderCode::REFUND_STATUS_APPLIED,
                'price' => $price,
                'reason_for_application' => $reason,
                'version' => 0,
            ])->getKey();

            return [
                'refund_id' => $refundId,
                'order_no' => $orderNo,
                'member_id' => $memberId,
                'status' => OrderCode::REFUND_STATUS_APPLIED,
                'price' => $price,
                'change_points' => number_format((float) $master->change_points, 2, '.', ''),
            ];
        }, self::REFUND_MAX_ATTEMPTS);
    }

    /**
     * 退款条件缓存键：按会员+订单隔离，避免读取到他人订单的退款条件
     * @param int $memberId
     * @param string $orderNo
     * @return string
     */
    private function getRefundEligibilityCacheKey(int $memberId, string $orderNo): string
    {
        return OrderCode::REFUND_ELIGIBILITY_CACHE_KEY_PREFIX . $memberId . ':' . $orderNo;
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
                $this->loggerService->warning('退款申请：版本冲突，重试', array_merge($context, [
                    'attempt' => $attempt,
                    'max_attempts' => self::REFUND_MAX_ATTEMPTS,
                    'conflict' => $e->getMessage(),
                ]));
            }
        }

        throw new BusinessException(409, '订单正在被其他操作处理，请稍后重试');
    }
}
