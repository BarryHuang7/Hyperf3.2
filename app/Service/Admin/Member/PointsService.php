<?php

declare(strict_types=1);

namespace App\Service\Admin\Member;

use App\Constants\ListCode;
use App\Constants\OrderCode;
use App\Exception\BusinessException;
use App\Exception\VersionConflictException;
use App\Model\Member;
use App\Model\MemberOrderDetail;
use App\Model\MemberOrderMaster;
use App\Model\MemberPointsLog;
use App\Service\LoggerService;
use App\Service\UtilsService;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Redis\Redis;
use Throwable;

class PointsService
{
    /**
     * 调整会员积分时的乐观锁最大重试次数
     */
    private const ADJUST_MAX_ATTEMPTS = 3;

    /**
     * 积分统计的四个时间范围字段
     */
    private const STATISTICS_RANGES = ['today', 'month', 'year', 'all'];

    #[Inject]
    protected LoggerService $loggerService;

    #[Inject]
    protected UtilsService $utilsService;

    #[Inject]
    protected Redis $redis;

    /**
     * 获取会员积分管理列表（分页）
     * @param string $email 邮箱模糊匹配
     * @param int|null $memberId 会员id精确匹配：null 不过滤
     * @param int $page
     * @param int $pageSize
     * @return array
     */
    public function getMemberPointsList(string $email, ?int $memberId, int $page, int $pageSize): array
    {
        try {
            $page = max(ListCode::DEFAULT_PAGE, $page);
            $pageSize = min(max(ListCode::DEFAULT_PAGE, $pageSize), ListCode::MAX_PAGE_SIZE);
            $email = trim($email);

            $buildQuery = function () use ($email, $memberId) {
                return Member::query()
                    ->when($email !== '', function ($query) use ($email) {
                        $query->where('email', $email);
                    })
                    ->when($memberId !== null, function ($query) use ($memberId) {
                        $query->where('member_id', $memberId);
                    });
            };

            $total = (int) $buildQuery()->count();
            $totalPages = $total > 0 ? (int) ceil($total / $pageSize) : 0;

            $list = [];
            if ($total > 0) {
                $members = $buildQuery()
                    ->select(
                        'member_id',
                        'email',
                        'member_name',
                        'status',
                        'points',
                        'is_subscribe',
                        'google2fa_enabled',
                        'automatically_create',
                        'description',
                        'created_at',
                        'updated_at'
                    )
                    ->orderByDesc('member_id')
                    ->orderByDesc('created_at')
                    ->forPage($page, $pageSize)
                    ->get();

                $list = $members->toArray();
            }

            return [
                'code' => 200,
                'status' => 200,
                'data' => [
                    'list' => $list,
                    'pagination' => [
                        'page' => $page,
                        'page_size' => $pageSize,
                        'total' => $total,
                        'total_pages' => $totalPages,
                    ],
                ],
                'msg' => 'Success',
            ];
        } catch (BusinessException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->loggerService->error('获取会员积分管理列表失败', [
                'email' => $email,
                'member_id' => $memberId,
                'page' => $page,
                'page_size' => $pageSize,
                'error' => $e->getMessage(),
            ]);
            throw new BusinessException(500, '获取会员积分管理列表失败，请稍后重试');
        }
    }

    /**
     * 获取会员积分详情：会员信息、订单详情（含积分到期时间）
     * @param int $memberId
     * @param int $page
     * @param int $pageSize
     * @return array
     */
    public function getMemberPointsDetail(
        int $memberId,
        int $page = ListCode::DEFAULT_PAGE,
        int $pageSize = ListCode::DEFAULT_PAGE_SIZE
    ): array {
        try {
            $page = max(ListCode::DEFAULT_PAGE, $page);
            $pageSize = min(max(ListCode::DEFAULT_PAGE, $pageSize), ListCode::MAX_PAGE_SIZE);

            $orders = $this->getOrders($memberId, $page, $pageSize);

            return [
                'code' => 200,
                'status' => 200,
                'data' => $orders,
                'msg' => 'Success',
            ];
        } catch (BusinessException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->loggerService->error('获取会员积分详情失败', [
                'member_id' => $memberId,
                'order_page' => $page,
                'order_page_size' => $pageSize,
                'error' => $e->getMessage(),
            ]);
            throw new BusinessException(500, '获取会员积分详情失败，请稍后重试');
        }
    }

    /**
     * 积分订单详情（分页）：订单主表 + 每单的积分批次明细
     * @param int $memberId
     * @param int $page
     * @param int $pageSize
     * @return array
     */
    private function getOrders(int $memberId, int $page, int $pageSize): array
    {
        $total = (int) MemberOrderMaster::query()
            ->where('member_id', $memberId)
            ->count();

        $totalPages = $total > 0 ? (int) ceil($total / $pageSize) : 0;

        $list = [];
        if ($total > 0) {
            $orders = MemberOrderMaster::query()
                ->select(
                    'order_master_id',
                    'order_no',
                    'payment_status',
                    'stripe_checkout_url',
                    'flag',
                    'change_type',
                    'change_points',
                    'created_at',
                    'updated_at'
                )
                ->where('member_id', $memberId)
                ->orderByDesc('created_at')
                ->orderByDesc('order_master_id')
                ->forPage($page, $pageSize)
                ->get();

            // 一次性取出当前页订单的积分批次，按订单分组，避免逐单查询
            $orderDetails = MemberOrderDetail::query()
                ->select(
                    'order_details_id',
                    'order_master_id',
                    'status',
                    'change_points',
                    'expiration_date',
                    'created_at',
                    'updated_at'
                )
                ->whereIn('order_master_id', $orders->pluck('order_master_id')->all())
                ->orderBy('order_details_id')
                ->get();

            $detailsByOrder = [];
            foreach ($orderDetails as $detail) {
                $detailsByOrder[(int) $detail->order_master_id][] = [
                    'order_details_id' => (int) $detail->order_details_id,
                    'status' => (int) $detail->status,
                    'points' => number_format((float) $detail->change_points, 2, '.', ''),
                    'expiration_date' => $detail->expiration_date?->toDateTimeString(),
                    'created_at' => $detail->created_at?->toDateTimeString(),
                ];
            }

            foreach ($orders as $order) {
                $list[] = [
                    'order_no' => (string) $order->order_no,
                    'payment_status' => (int) $order->payment_status,
                    'change_type' => (int) $order->change_type,
                    'flag' => (int) $order->flag,
                    'change_points' => number_format((float) $order->change_points, 2, '.', ''),
                    'created_at' => $order->created_at?->toDateTimeString(),
                    'details' => $detailsByOrder[(int) $order->order_master_id] ?? [],
                ];
            }
        }

        return [
            'list' => $list,
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ];
    }

    /**
     * 获取会员积分流水（分页）
     * @param int $memberId
     * @param int $page
     * @param int $pageSize
     * @return array
     */
    public function getMemberPointsLogList(int $memberId, int $page, int $pageSize): array
    {
        try {
            $page = max(ListCode::DEFAULT_PAGE, $page);
            $pageSize = min(max(ListCode::DEFAULT_PAGE, $pageSize), ListCode::MAX_PAGE_SIZE);

            $total = (int) MemberPointsLog::query()
                ->where('member_id', $memberId)
                ->count();

            $totalPages = $total > 0 ? (int) ceil($total / $pageSize) : 0;

            $list = [];
            if ($total > 0) {
                $logs = MemberPointsLog::query()
                    ->select(
                        'points_log_id',
                        'order_no',
                        'points_task_id',
                        'points_task_no',
                        'flag',
                        'change_type',
                        'change_points',
                        'before_points',
                        'after_points',
                        'video_width',
                        'video_height',
                        'video_frame_rate',
                        'video_feature_type',
                        'video_duration_seconds',
                        'charging_standard',
                        'description',
                        'created_at',
                    )
                    ->where('member_id', $memberId)
                    ->orderByDesc('created_at')
                    ->orderByDesc('points_log_id')
                    ->forPage($page, $pageSize)
                    ->get();

                foreach ($logs as $log) {
                    $list[] = [
                        'points_log_id' => (int) $log->points_log_id,
                        'order_no' => (string) $log->order_no,
                        'points_task_id' => $log->points_task_id === null ? null : (int) $log->points_task_id,
                        'points_task_no' => (string) $log->points_task_no,
                        'flag' => (int) $log->flag,
                        'change_type' => (int) $log->change_type,
                        'change_points' => number_format((float) $log->change_points, 2, '.', ''),
                        'before_points' => number_format((float) $log->before_points, 2, '.', ''),
                        'after_points' => number_format((float) $log->after_points, 2, '.', ''),
                        'video_feature_type' => $log->video_feature_type === null ? null : (int) $log->video_feature_type,
                        'description' => (string) $log->description,
                        'created_at' => $log->created_at?->toDateTimeString(),
                    ];
                }
            }

            return [
                'code' => 200,
                'status' => 200,
                'data' => [
                    'list' => $list,
                    'pagination' => [
                        'page' => $page,
                        'page_size' => $pageSize,
                        'total' => $total,
                        'total_pages' => $totalPages,
                    ],
                ],
                'msg' => 'Success',
            ];
        } catch (BusinessException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->loggerService->error('获取会员积分流水失败', [
                'member_id' => $memberId,
                'page' => $page,
                'page_size' => $pageSize,
                'error' => $e->getMessage(),
            ]);
            throw new BusinessException(500, '获取会员积分流水失败，请稍后重试');
        }
    }

    /**
     * 积分消耗统计：统计所有会员的充值、消耗、过期、发放总积分，
     * 分别按全部时间、本月、本年、今日汇总；结果缓存 10 分钟，返回本次查询时间
     * @return array
     */
    public function getMemberPointsStatistics(): array
    {
        try {
            $cacheKey = OrderCode::MEMBER_POINTS_STATISTICS_CACHE_KEY;

            // 缓存异常时降级为直接查库，不影响统计接口可用性
            try {
                $cached = $this->redis->get($cacheKey);
            } catch (Throwable $e) {
                $cached = null;
                $this->loggerService->warning('读取积分统计缓存失败', ['error' => $e->getMessage()]);
            }

            if (is_string($cached) && $cached !== '') {
                $cachedData = json_decode($cached, true);
                if (is_array($cachedData) && isset($cachedData['fetched_at'])) {
                    return [
                        'code' => 200,
                        'status' => 200,
                        'data' => $cachedData,
                        'msg' => 'Success',
                    ];
                }
            }

            $todayStart = date('Y-m-d 00:00:00');
            $monthStart = date('Y-m-01 00:00:00');
            $yearStart = date('Y-01-01 00:00:00');

            // 一次聚合按「流水类型 + 变动方向」分组取出各统计口径，避免多次全表扫描；
            // 管理员调整有增加/减少两种方向，需按 flag 拆分后才能只取增加的部分
            $rows = MemberPointsLog::query()
                ->select('change_type', 'flag')
                ->selectRaw('COALESCE(SUM(change_points), 0) AS total_points')
                ->selectRaw('COALESCE(SUM(CASE WHEN created_at >= ? THEN change_points ELSE 0 END), 0) AS today_points', [$todayStart])
                ->selectRaw('COALESCE(SUM(CASE WHEN created_at >= ? THEN change_points ELSE 0 END), 0) AS month_points', [$monthStart])
                ->selectRaw('COALESCE(SUM(CASE WHEN created_at >= ? THEN change_points ELSE 0 END), 0) AS year_points', [$yearStart])
                ->whereIn('change_type', [
                    OrderCode::CHANGE_TYPE_RECHARGE,
                    OrderCode::CHANGE_TYPE_CONSUME,
                    OrderCode::CHANGE_TYPE_EXPIRE,
                    OrderCode::CHANGE_TYPE_ADMIN_ADJUST,
                    OrderCode::CHANGE_TYPE_GIFT,
                ])
                ->groupBy(['change_type', 'flag'])
                ->get();

            $sums = [];
            foreach ($rows as $row) {
                $sums[(int) $row->change_type][(int) $row->flag] = [
                    'today' => (float) $row->today_points,
                    'month' => (float) $row->month_points,
                    'year' => (float) $row->year_points,
                    'all' => (float) $row->total_points,
                ];
            }

            $rechargeSums = $sums[OrderCode::CHANGE_TYPE_RECHARGE][OrderCode::FLAG_INCREASE] ?? [];
            $consumeSums = $sums[OrderCode::CHANGE_TYPE_CONSUME][OrderCode::FLAG_DECREASE] ?? [];
            $expireSums = $sums[OrderCode::CHANGE_TYPE_EXPIRE][OrderCode::FLAG_DECREASE] ?? [];
            $adminIncreaseSums = $sums[OrderCode::CHANGE_TYPE_ADMIN_ADJUST][OrderCode::FLAG_INCREASE] ?? [];
            $giftSums = $sums[OrderCode::CHANGE_TYPE_GIFT][OrderCode::FLAG_INCREASE] ?? [];

            // 发放总积分 = 充值积分 + 本地版赠送积分 + 管理员手动增加积分
            $issuedSums = [];
            foreach (self::STATISTICS_RANGES as $range) {
                $issuedSums[$range] = ($rechargeSums[$range] ?? 0) + ($giftSums[$range] ?? 0) + ($adminIncreaseSums[$range] ?? 0);
            }

            $data = [
                // 本次查询时间
                'fetched_at' => date('Y-m-d H:i:s'),
                // 充值
                'recharge' => $this->formatStatistics($rechargeSums),
                // 消费
                'consume' => $this->formatStatistics($consumeSums),
                // 过期
                'expire' => $this->formatStatistics($expireSums),
                // 发放
                'issued' => $this->formatStatistics($issuedSums),
            ];

            // 写缓存失败只记日志，本次查询结果照常返回
            try {
                $this->redis->set(
                    $cacheKey,
                    json_encode($data, JSON_UNESCAPED_UNICODE),
                    OrderCode::MEMBER_POINTS_STATISTICS_CACHE_TTL
                );
            } catch (Throwable $e) {
                $this->loggerService->warning('写入积分统计缓存失败', ['error' => $e->getMessage()]);
            }

            return [
                'code' => 200,
                'status' => 200,
                'data' => $data,
                'msg' => 'Success',
            ];
        } catch (BusinessException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->loggerService->error('获取积分消耗统计失败', [
                'error' => $e->getMessage(),
            ]);
            throw new BusinessException(500, '获取积分消耗统计失败，请稍后重试');
        }
    }

    /**
     * 把某类流水的各时间范围合计格式化为两位小数字符串，无数据时返回 0.00
     * @param array $sums
     * @return array
     */
    private function formatStatistics(array $sums): array
    {
        $result = [];
        foreach (self::STATISTICS_RANGES as $range) {
            $result[$range] = number_format((float) ($sums[$range] ?? 0), 2, '.', '');
        }

        return $result;
    }

    /**
     * 管理员增减会员积分
     * @param int $adminId 操作的管理员id
     * @param int $memberId 会员id
     * @param int $flag 积分变动方向：0 减少，1 增加
     * @param string $changePoints 变动积分（恒为正数，最多两位小数）
     * @param string $remark 调整原因
     * @param string $auditNotes 审计备注
     * @param string $expirationDate 过期日（增加积分时必传，减少时忽略）
     * @return array
     */
    public function adjustMemberPoints(
        int $adminId,
        int $memberId,
        int $flag,
        string $changePoints,
        string $remark,
        string $auditNotes,
        string $expirationDate = ''
    ): array {
        $context = [
            'admin_id' => $adminId,
            'member_id' => $memberId,
            'flag' => $flag,
            'change_points' => $changePoints,
            'remark' => $remark,
            'audit_notes' => $auditNotes,
            'expiration_date' => $expirationDate,
        ];

        try {
            $changePointsValue = round((float) $changePoints, OrderCode::VIDEO_CREDITS_PRECISION);
            if ($changePointsValue <= 0) {
                throw new BusinessException(400, '变动积分必须大于0');
            }

            $changePoints = number_format($changePointsValue, 2, '.', '');

            // 只有增加积分才生成可用批次，过期日由管理员动态传入
            $expirationDateValue = null;
            if ($flag === OrderCode::FLAG_INCREASE) {
                $expirationDate = trim($expirationDate);

                if ($expirationDate === '') {
                    throw new BusinessException(400, '增加积分时过期日不能为空');
                }

                // 特殊值 -1 表示永久有效，实际存储为null
                if ($expirationDate === '-1') {
                    $expirationDateValue = null;
                } else {
                    if (!$this->utilsService->isStrictDate($expirationDate, 'Y-m-d')) {
                        throw new BusinessException(400, '过期日格式不正确');
                    }

                    $expirationTimestamp = strtotime($expirationDate);
                    if ($expirationTimestamp === false) {
                        throw new BusinessException(400, '过期日格式不正确');
                    }
                    $expirationDateValue = date('Y-m-d H:i:s', $expirationTimestamp);
                }
            }

            $result = $this->retryOnVersionConflict(
                fn () => $this->applyAdjustment($adminId, $memberId, $flag, $changePoints, $remark, $auditNotes, $expirationDateValue),
                $context
            );

            $this->loggerService->info('管理员调整会员积分成功', array_merge($context, $result));

            return [
                'code' => 200,
                'status' => 200,
                'data' => $result,
                'msg' => '调整成功',
            ];
        } catch (BusinessException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->loggerService->error('管理员调整会员积分失败', array_merge($context, [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]));
            throw new BusinessException(500, '调整会员积分失败，请稍后重试');
        }
    }

    /**
     * 执行积分调整：在同一事务内完成，冲突时整笔回滚后重试
     * @param int $adminId
     * @param int $memberId
     * @param int $flag
     * @param string $changePoints
     * @param string $remark
     * @param string $auditNotes
     * @param string|null $expirationDate 增加积分时新建批次的过期日，减少时为 null
     * @return array
     */
    private function applyAdjustment(int $adminId, int $memberId, int $flag, string $changePoints, string $remark, string $auditNotes, ?string $expirationDate): array
    {
        return Db::transaction(function () use ($adminId, $memberId, $flag, $changePoints, $remark, $auditNotes, $expirationDate) {
            $member = Member::query()
                ->select('member_id', 'points', 'version')
                ->where('member_id', $memberId)
                ->first();

            if ($member === null) {
                $this->loggerService->warning('管理员调整会员积分：会员不存在', [
                    'admin_id' => $adminId,
                    'member_id' => $memberId,
                ]);
                throw new BusinessException(400, '会员不存在');
            }

            $beforePoints = (float) $member->points;
            $changePointsValue = (float) $changePoints;

            // 减少积分前先校验余额，避免把积分扣成负数
            if ($flag === OrderCode::FLAG_DECREASE && $beforePoints < $changePointsValue) {
                $this->loggerService->warning('管理员调整会员积分：积分不足', [
                    'admin_id' => $adminId,
                    'member_id' => $memberId,
                    'member_points' => number_format($beforePoints, 2, '.', ''),
                    'change_points' => $changePoints,
                ]);
                throw new BusinessException(400, sprintf(
                    '会员积分不足，当前可用 %s 积分，本次需要减少 %s 积分',
                    number_format($beforePoints, 2, '.', ''),
                    $changePoints
                ));
            }

            $afterPoints = round(
                $flag === OrderCode::FLAG_INCREASE
                    ? $beforePoints + $changePointsValue
                    : $beforePoints - $changePointsValue,
                OrderCode::VIDEO_CREDITS_PRECISION
            );

            // Adjust调整订单号以 A 开头，区别于用户下单的积分订单号
            $orderNo = 'A' . $this->utilsService->generateNo();

            // 1. 生成积分订单主表：管理员调整无需支付
            $orderMasterId = (int) MemberOrderMaster::create([
                'order_no' => $orderNo,
                'member_id' => $memberId,
                'payment_status' => OrderCode::PAYMENT_STATUS_NONE,
                'flag' => $flag,
                'change_type' => OrderCode::CHANGE_TYPE_ADMIN_ADJUST,
                'change_points' => $changePoints,
                'price' => '0.00',
                'version' => 0,
            ])->getKey();

            // 2. 生成积分订单明细
            MemberOrderDetail::create([
                'order_master_id' => $orderMasterId,
                'member_id' => $memberId,
                'status' => $flag === OrderCode::FLAG_INCREASE ? OrderCode::DETAIL_STATUS_ACTIVE : OrderCode::DETAIL_STATUS_USED,
                'change_points' => $changePoints,
                'expiration_date' => $flag === OrderCode::FLAG_INCREASE ? $expirationDate : null,
                'version' => 0,
            ]);

            // 3. 写积分流水，描述记录操作管理员与调整原因
            $action = $flag === OrderCode::FLAG_INCREASE ? '增加' : '减少';
            MemberPointsLog::create([
                'member_id' => $memberId,
                'order_id' => $orderMasterId,
                'order_no' => $orderNo,
                'points_task_id' => null,
                'points_task_no' => '',
                'flag' => $flag,
                'change_type' => OrderCode::CHANGE_TYPE_ADMIN_ADJUST,
                'change_points' => $changePoints,
                'before_points' => number_format($beforePoints, 2, '.', ''),
                'after_points' => number_format($afterPoints, 2, '.', ''),
                'description' => sprintf(
                    '管理员id:%s%s%s积分，原因是：%s',
                    $adminId,
                    $action,
                    $changePoints,
                    $remark
                ),
                'audit_notes' => $auditNotes,
            ]);

            // 4. 按读取时的版本号更新会员积分，版本冲突则整笔回滚后重试
            $updated = Member::query(true)
                ->where('member_id', $memberId)
                ->where('version', (int) $member->version)
                ->update([
                    'points' => number_format($afterPoints, 2, '.', ''),
                    'version' => (int) $member->version + 1,
                ]);

            if ($updated === 0) {
                $this->loggerService->warning('管理员调整会员积分：会员版本冲突', [
                    'admin_id' => $adminId,
                    'member_id' => $memberId,
                    'version' => (int) $member->version,
                ]);
                throw new VersionConflictException('member_id=' . $memberId);
            }

            return [
                'order_no' => $orderNo,
                'member_id' => $memberId,
                'flag' => $flag,
                'change_points' => $changePoints,
                'before_points' => number_format($beforePoints, 2, '.', ''),
                'after_points' => number_format($afterPoints, 2, '.', ''),
                'expiration_date' => $expirationDate,
            ];
        });
    }

    /**
     * 乐观锁冲突重试：抛出 VersionConflictException 时整笔事务回滚并重试
     * @param callable $callback
     * @param array $context 仅用于日志
     * @return mixed
     */
    private function retryOnVersionConflict(callable $callback, array $context): mixed
    {
        for ($attempt = 1; $attempt <= self::ADJUST_MAX_ATTEMPTS; ++$attempt) {
            try {
                return $callback();
            } catch (VersionConflictException $e) {
                $this->loggerService->warning('管理员调整会员积分：版本冲突，重试', array_merge($context, [
                    'attempt' => $attempt,
                    'max_attempts' => self::ADJUST_MAX_ATTEMPTS,
                    'conflict' => $e->getMessage(),
                ]));
            }
        }

        throw new BusinessException(409, '会员积分正在被其他操作修改，请稍后重试');
    }
}
