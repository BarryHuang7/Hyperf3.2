<?php

declare(strict_types=1);

namespace App\Service\Api\Member;

use App\Constants\ListCode;
use App\Constants\OrderCode;
use App\Exception\BusinessException;
use App\Exception\VersionConflictException;
use App\Model\Member;
use App\Model\MemberOrderDetail;
use App\Model\MemberOrderMaster;
use App\Model\MemberPointsLog;
use App\Model\MemberPointsTask;
use App\Model\MemberPointsTaskOrder;
use App\Model\VideoBillingModule;
use App\Service\UtilsService;
use App\Service\LoggerService;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Database\Model\SoftDeletingScope;
use Throwable;

class OrderService
{
    /**
     * 乐观锁冲突的最大重试次数
     */
    private const DEDUCT_MAX_ATTEMPTS = 3;

    /**
     * Colorizer 帧率阶梯：帧率上限 => 候选字段（对应积分/分钟单价），本档位未配置时向低档位回退
     */
    private const COLORIZER_FPS_BRACKETS = [
        OrderCode::VIDEO_FPS_30 => ['fps30'],
        OrderCode::VIDEO_FPS_45 => ['fps45', 'fps30'],
        OrderCode::VIDEO_FPS_60 => ['fps60', 'fps45', 'fps30'],
        OrderCode::VIDEO_FPS_90 => ['fps90', 'fps60', 'fps45', 'fps30'],
        PHP_INT_MAX => ['above_fps90', 'fps90', 'fps60', 'fps45', 'fps30'],
    ];

    /**
     * Upscaler / HDR 帧率系数：帧率上限 => 候选字段（对应帧率加成系数），本档位未配置时向低档位回退
     */
    private const MULTIPLIER_FPS_BRACKETS = [
        OrderCode::VIDEO_FPS_30 => ['fps30'],
        OrderCode::VIDEO_FPS_60 => ['fps60', 'fps30'],
        PHP_INT_MAX => ['above_fps60', 'fps60', 'fps30'],
    ];

    #[Inject]
    protected LoggerService $loggerService;

    #[Inject]
    protected UtilsService $utilsService;

    /**
     * 获取当前会员的积分订单列表（分页）
     * @param int $memberId
     * @param int $changeType
     * @param int $page
     * @param int $pageSize
     * @return array
     */
    public function getOrderList(int $memberId, int $changeType, int $page = ListCode::DEFAULT_PAGE, int $pageSize = ListCode::DEFAULT_PAGE_SIZE): array
    {
        try {
            $page = max(ListCode::DEFAULT_PAGE, $page);
            $pageSize = min(max(ListCode::DEFAULT_PAGE, $pageSize), ListCode::MAX_PAGE_SIZE);

            $total = (int) MemberOrderMaster::query()
                ->where('member_id', $memberId)
                ->when($changeType > 0, function ($query) use ($changeType) {
                    $query->where('change_type', $changeType);
                })
                ->count();

            $totalPages = $total > 0 ? (int) ceil($total / $pageSize) : 0;

            $list = [];
            if ($total > 0) {
                $orders = MemberOrderMaster::query()
                    ->where('member_id', $memberId)
                    ->select(
                        'order_no',
                        'payment_status',
                        'change_type',
                        'flag',
                        'change_points',
                        'created_at',
                        Db::raw('CASE WHEN `payment_status` = 1 THEN `stripe_checkout_url` ELSE NULL END AS `stripe_checkout_url`'),
                    )
                    ->when($changeType > 0, function ($query) use ($changeType) {
                        $query->where('change_type', $changeType);
                    })
                    ->orderByDesc('created_at')
                    ->forPage($page, $pageSize)
                    ->get();

                if (count($orders) > 0) {
                    $list = $orders->toArray();
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
            $this->loggerService->error('获取积分订单列表失败', [
                'member_id' => $memberId,
                'change_type' => $changeType,
                'page' => $page,
                'page_size' => $pageSize,
                'error' => $e->getMessage(),
            ]);
            throw new BusinessException(500, '获取积分订单列表失败，请稍后重试');
        }
    }

    /**
     * 获取当前会员的积分订单详情
     * @param int $memberId
     * @param string $orderNo
     * @return array
     */
    public function getOrderDetail(int $memberId, string $orderNo): array
    {
        try {
            $details = MemberOrderMaster::query()
                ->withoutGlobalScope(SoftDeletingScope::class)
                ->from('member_order_master as a')
                ->join('member_order_details as b', 'a.order_master_id', '=', 'b.order_master_id')
                ->select('b.order_details_id', 'b.status', 'b.change_points', 'b.expiration_date', 'b.created_at')
                ->where('a.order_no', $orderNo)
                ->where('a.member_id', $memberId)
                ->whereNull('a.deleted_at')
                ->orderBy('b.order_details_id')
                ->get();

            return [
                'code' => 200,
                'status' => 200,
                'data' => $details,
                'msg' => 'Success',
            ];
        } catch (BusinessException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->loggerService->error('获取积分订单详情失败', [
                'member_id' => $memberId,
                'order_no' => $orderNo,
                'error' => $e->getMessage(),
            ]);
            throw new BusinessException(500, '获取积分订单详情失败，请稍后重试');
        }
    }

    /**
     * 删除当前会员的积分订单（软删除）
     * @param int $memberId
     * @param string $orderNo
     * @return array
     */
    // public function deleteOrder(int $memberId, string $orderNo): array
    // {
    //     try {
    //         $order = MemberOrderMaster::query()
    //             ->select('order_master_id', 'order_no', 'payment_status', 'version')
    //             ->where('order_no', $orderNo)
    //             ->where('member_id', $memberId)
    //             ->first();

    //         if ($order === null) {
    //             $this->loggerService->warning('删除积分订单：订单不存在', [
    //                 'member_id' => $memberId,
    //                 'order_no' => $orderNo,
    //             ]);
    //             throw new BusinessException(400, '订单不存在');
    //         }

    //         $paymentStatus = (int) $order->payment_status;
    //         if ($paymentStatus === OrderCode::PAYMENT_STATUS_PENDING) {
    //             throw new BusinessException(400, '待支付订单暂不可删除，支付超时后会自动取消');
    //         }

    //         // 重新校验会员归属与支付状态，避免并发下删到刚变成待支付的订单；
    //         // query(true) 让模型缓存构建器在删除后自动失效该订单缓存。
    //         $deleted = MemberOrderMaster::query(true)
    //             ->where('order_master_id', $order->order_master_id)
    //             ->where('member_id', $memberId)
    //             ->where('payment_status', '!=', OrderCode::PAYMENT_STATUS_PENDING)
    //             ->where('version', $order->version)
    //             ->delete();

    //         if ($deleted === 0) {
    //             $this->loggerService->warning('删除积分订单：订单状态已变化，删除未生效', [
    //                 'member_id' => $memberId,
    //                 'order_no' => $orderNo,
    //             ]);
    //             throw new BusinessException(400, '订单状态已变化，请刷新后重试');
    //         }

    //         $this->loggerService->info('删除积分订单成功', [
    //             'member_id' => $memberId,
    //             'order_no' => $orderNo,
    //             'order_master_id' => $order->order_master_id,
    //             'payment_status' => $paymentStatus,
    //         ]);

    //         return [
    //             'code' => 200,
    //             'status' => 200,
    //             'data' => [
    //                 'order_no' => $orderNo,
    //             ],
    //             'msg' => '删除成功',
    //         ];
    //     } catch (BusinessException $e) {
    //         throw $e;
    //     } catch (Throwable $e) {
    //         $this->loggerService->error('删除积分订单失败', [
    //             'member_id' => $memberId,
    //             'order_no' => $orderNo,
    //             'error' => $e->getMessage(),
    //         ]);
    //         throw new BusinessException(500, '删除订单失败，请稍后重试');
    //     }
    // }

    /**
     * 消耗积分：校验会员余额是否足够，够则按「最早到期优先」逐单扣减，并写积分流水
     * @param int $memberId
     * @param int $featureType 1-Subtitle字母，2-Upscaler放大器，3-HDR高动态范围，4-Colorizer着色器，5-Translator语言翻译
     * @param float $durationSeconds 视频处理的时长（秒）
     * @param int $width 视频分辨率-宽度
     * @param int $height 视频分辨率-高度
     * @param float $fps 视频帧率
     * @return array
     */
    public function consumptionPoints(
        int $memberId,
        int $featureType,
        float $durationSeconds,
        int $width,
        int $height,
        float $fps
    ): array {
        $context = [
            'member_id' => $memberId,
            'video_feature_type' => $featureType,
            'video_duration_seconds' => $durationSeconds,
            'video_width' => $width,
            'video_height' => $height,
            'video_frame_rate' => $fps,
        ];

        try {
            $module = $this->getVideoBillingModule($featureType);
            $changePoints = $this->calculateTaskCredits($module, $durationSeconds, $width, $height, $fps);
            $context['change_points'] = $changePoints;

            // 1. 余额校验：会员总积分不低于本次任务扣减，不足直接拦截，避免只扣了一部分再回滚
            $memberPoints = Member::query()
                ->where('member_id', $memberId)
                ->value('points');

            if ($memberPoints === null) {
                $this->loggerService->warning('消耗积分：会员不存在', $context);
                throw new BusinessException(400, '会员不存在');
            }

            if ((float) $memberPoints < $changePoints) {
                $this->loggerService->warning('消耗积分：会员积分不足', array_merge($context, [
                    'member_points' => number_format((float) $memberPoints, 2, '.', ''),
                ]));
                throw new BusinessException(400, sprintf(
                    '积分不足，本次任务需要 %s 积分，当前可用 %s 积分',
                    number_format($changePoints, 2, '.', ''),
                    number_format((float) $memberPoints, 2, '.', '')
                ));
            }

            // 2. 逐单扣减：详情扣减 -> 扣会员积分 -> 生成积分任务与流水，整批在同一事务内完成；
            //    任一环节版本冲突都整单回滚后重试，保证不会出现「详情已扣、余额未扣」的中间态
            $result = $this->retryOnVersionConflict(
                fn () => $this->deductPointsByFifo($memberId, $changePoints, [
                    'video_feature_type' => $featureType,
                    'video_duration_seconds' => $durationSeconds,
                    'video_width' => $width,
                    'video_height' => $height,
                    'video_frame_rate' => $fps,
                    'charging_standard' => (float) $module->charging_standard,
                ]),
                $context
            );

            $this->loggerService->info('消耗积分成功', array_merge($context, $result));

            return [
                'code' => 200,
                'status' => 200,
                'data' => [
                    'points_task_id' => $result['points_task_id'],
                    'points_task_no' => $result['points_task_no'],
                    'change_points' => $changePoints,
                    'before_points' => $result['before_points'],
                    'after_points' => $result['after_points'],
                    'details' => $result['details'],
                    'exhausted_orders' => $result['exhausted_orders'],
                ],
                'msg' => 'Success',
            ];
        } catch (BusinessException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->loggerService->error('消耗积分失败', array_merge($context, [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]));
            throw new BusinessException(500, '扣除积分失败，请稍后重试');
        }
    }

    /**
     * FIFO 扣减：按过期日从近到远逐单扣减，直到扣满本次任务所需积分；
     * 扣减完成后生成一条积分任务记录，并按订单拆分记录各自的扣减额（member_points_task_order），
     * 本次任务的所有流水都挂在同一个积分任务下
     * @param int $memberId
     * @param float $changePoints 本次任务需要扣减的积分（已按计费模块计算并保留两位小数）
     * @param array $videoParams 写入积分任务表与流水表的视频任务参数（含扣费标准 charging_standard）
     * @return array{points_task_id: int, before_points: string, after_points: string, details: array, exhausted_orders: array}
     */
    private function deductPointsByFifo(int $memberId, float $changePoints, array $videoParams): array
    {
        return Db::transaction(function () use ($memberId, $changePoints, $videoParams) {
            // 1. 会员积分行锁读取：锁住会员行，串行化同一会员的并发扣减，before/after 才不会算错
            $member = Member::query()
                ->select('member_id', 'points', 'version')
                ->where('member_id', $memberId)
                ->lockForUpdate()
                ->first();

            if ($member === null) {
                $this->loggerService->warning('消耗积分：会员不存在', ['member_id' => $memberId]);
                throw new BusinessException(400, '会员不存在');
            }

            $beforePoints = (float) $member->points;
            if ($beforePoints < $changePoints) {
                $this->loggerService->warning('消耗积分：会员积分不足', [
                    'member_id' => $memberId,
                    'member_points' => number_format($beforePoints, 2, '.', ''),
                    'change_points' => number_format($changePoints, 2, '.', ''),
                ]);
                throw new BusinessException(400, sprintf(
                    '积分不足，本次任务需要 %s 积分，当前可用 %s 积分',
                    number_format($changePoints, 2, '.', ''),
                    number_format($beforePoints, 2, '.', '')
                ));
            }

            // 2. 待扣详情按过期日从近到远（无过期日的最后），同一到期时间按主键稳定排序
            //    同时取出所属订单号，写流水时直接使用，避免逐单回查
            $details = MemberOrderDetail::query()
                ->from('member_order_details as d')
                ->join('member_order_master as m', 'd.order_master_id', '=', 'm.order_master_id')
                ->whereNull('m.deleted_at')
                ->where('d.member_id', $memberId)
                ->where('d.status', OrderCode::DETAIL_STATUS_ACTIVE)
                ->where(function ($query) {
                    // 已过期但定时任务还没清理的积分不再参与扣减
                    $query->whereNull('d.expiration_date')
                        ->orWhere('d.expiration_date', '>', date('Y-m-d H:i:s'));
                })
                ->select('d.order_details_id', 'd.order_master_id', 'd.change_points', 'd.expiration_date', 'd.version', 'm.order_no')
                ->orderByRaw('`expiration_date` IS NULL')
                ->orderBy('d.expiration_date')
                ->orderBy('d.order_details_id')
                ->get();

            if (count($details) === 0) {
                $this->loggerService->warning('消耗积分：没有可扣减的积分批次', [
                    'member_id' => $memberId,
                    'change_points' => number_format($changePoints, 2, '.', ''),
                ]);
                throw new BusinessException(400, '没有可扣减的积分批次');
            }

            $remainingPoints = $changePoints;
            $deductedDetails = [];
            $exhaustedOrders = [];
            // 本次任务从哪些积分批次各扣了多少：每个批次一条，返还时按批次原样加回
            $deductedTaskOrders = [];
            // 消费流水先收集，待积分任务创建后再统一写入，保证每条流水都挂上 points_task_id
            $logRows = [];
            // 每个详情各自生成一条流水，before/after 按扣减顺序连续计算
            $runningPoints = $beforePoints;

            foreach ($details as $detail) {
                if ($remainingPoints <= 0) {
                    break;
                }

                $batchPoints = (float) $detail->change_points;
                $deductPoints = min($batchPoints, $remainingPoints);
                $deductPoints = round($deductPoints, OrderCode::VIDEO_CREDITS_PRECISION);
                if ($deductPoints <= 0) {
                    // 批次余额已被扣到 0 但状态没同步，跳过，避免写 0 积分流水
                    continue;
                }

                $detailVersion = (int) $detail->version;
                // 计算是否消耗完这笔积分
                $afterDetailPoints = round($batchPoints - $deductPoints, OrderCode::VIDEO_CREDITS_PRECISION);
                $isExhausted = $afterDetailPoints <= 0;

                $updatedDetail = MemberOrderDetail::query(true)
                    ->where('order_details_id', $detail->order_details_id)
                    ->where('status', OrderCode::DETAIL_STATUS_ACTIVE)
                    ->where('version', $detailVersion)
                    ->update([
                        'status' => $isExhausted ? OrderCode::DETAIL_STATUS_USED : OrderCode::DETAIL_STATUS_ACTIVE,
                        'change_points' => number_format($afterDetailPoints, 2, '.', ''),
                        'version' => $detailVersion + 1,
                    ]);

                if ($updatedDetail === 0) {
                    // 该批次已被其他请求扣减/过期，整单回滚后重试，不在此基础上继续扣
                    $this->loggerService->warning('消耗积分：详情订单版本冲突', [
                        'member_id' => $memberId,
                        'order_details_id' => $detail->order_details_id,
                        'version' => $detailVersion,
                    ]);
                    throw new VersionConflictException('order_details_id=' . $detail->order_details_id);
                }

                $detailBeforePoints = $runningPoints;
                $runningPoints = round($runningPoints - $deductPoints, OrderCode::VIDEO_CREDITS_PRECISION);

                $orderNo = (string) $detail->order_no;
                // 按批次记录扣减额：返还时据此精确回填到被扣减的那个 member_order_details
                $deductedTaskOrders[] = [
                    'order_master_id' => (int) $detail->order_master_id,
                    'order_no' => $orderNo,
                    'order_details_id' => (int) $detail->order_details_id,
                    'deduct_points' => number_format($deductPoints, 2, '.', ''),
                ];

                // 3. 收集消费流水
                $logRows[] = [
                    'order_id' => $detail->order_master_id,
                    'order_no' => $orderNo,
                    'change_points' => number_format($deductPoints, 2, '.', ''),
                    'before_points' => number_format($detailBeforePoints, 2, '.', ''),
                    'after_points' => number_format($runningPoints, 2, '.', ''),
                    'description' => sprintf(
                        'AI任务消耗：订单%s 扣减%s积分，本批次剩余%s积分',
                        $detail->order_no,
                        number_format($deductPoints, 2, '.', ''),
                        number_format($afterDetailPoints, 2, '.', '')
                    ),
                ];

                $deductedDetails[] = [
                    'order_details_id' => $detail->order_details_id,
                    'order_master_id' => $detail->order_master_id,
                    'deduct_points' => number_format($deductPoints, 2, '.', ''),
                    'remaining_points' => number_format($afterDetailPoints, 2, '.', ''),
                    'status' => $isExhausted ? OrderCode::DETAIL_STATUS_USED : OrderCode::DETAIL_STATUS_ACTIVE,
                ];

                if ($isExhausted) {
                    $exhaustedOrders[] = $detail->order_details_id;
                }

                $remainingPoints = round($remainingPoints - $deductPoints, OrderCode::VIDEO_CREDITS_PRECISION);
            }

            if ($remainingPoints > 0) {
                // 详情批次总额与会员总积分不一致（数据异常），回滚整笔扣减，不产生半扣记录
                $this->loggerService->error('消耗积分：可扣批次积分不足以覆盖本次任务', [
                    'member_id' => $memberId,
                    'change_points' => number_format($changePoints, 2, '.', ''),
                    'deducted_points' => number_format($changePoints - $remainingPoints, 2, '.', ''),
                    'remaining_points' => number_format($remainingPoints, 2, '.', ''),
                ]);
                throw new BusinessException(500, '扣除积分失败，积分批次与余额不一致');
            }

            $pointsTaskNo = 'PT'.$this->utilsService->generateNo();
            // 4. 生成积分任务：一次 AI 任务一条记录，记录视频参数、扣费标准与扣减总额
            $pointsTaskId = (int) MemberPointsTask::create([
                'points_task_no' => $pointsTaskNo,
                'member_id' => $memberId,
                'video_feature_type' => $videoParams['video_feature_type'],
                'video_width' => $videoParams['video_width'],
                'video_height' => $videoParams['video_height'],
                'video_frame_rate' => number_format((float) $videoParams['video_frame_rate'], 2, '.', ''),
                'video_duration_seconds' => number_format((float) $videoParams['video_duration_seconds'], 4, '.', ''),
                'charging_standard' => number_format((float) $videoParams['charging_standard'], 2, '.', ''),
                'deduct_points' => number_format($changePoints, 2, '.', ''),
                'status' => OrderCode::TASK_STATUS_DEFAULT,
                'version' => 0,
            ])->getKey();

            // 5. 记录本次任务从哪些积分订单/批次扣减、各扣多少（跨订单/批次扣减时一个任务对应多条记录），多条合并成一次 INSERT
            MemberPointsTaskOrder::insert(array_map(
                fn (array $taskOrder): array => array_merge($taskOrder, [
                    'points_task_id' => $pointsTaskId,
                ]),
                $deductedTaskOrders
            ));

            // 6. 写积分流水（消费），全部挂在本次积分任务下，多条合并成一次 INSERT
            MemberPointsLog::insert(array_map(
                fn (array $logRow): array => array_merge($logRow, [
                    'member_id' => $memberId,
                    'points_task_id' => $pointsTaskId,
                    'points_task_no' => $pointsTaskNo,
                    'flag' => OrderCode::FLAG_DECREASE,
                    'change_type' => OrderCode::CHANGE_TYPE_CONSUME,
                    'video_feature_type' => $videoParams['video_feature_type'],
                    'video_duration_seconds' => $videoParams['video_duration_seconds'],
                    'video_width' => $videoParams['video_width'],
                    'video_height' => $videoParams['video_height'],
                    'video_frame_rate' => $videoParams['video_frame_rate'],
                    'charging_standard' => number_format((float) $videoParams['charging_standard'], 2, '.', ''),
                ]),
                $logRows
            ));

            // 7. 会员积分余额扣减：按读取时快照做乐观锁更新，冲突则整单回滚重试
            $updatedMember = Member::query(true)
                ->where('member_id', $memberId)
                ->where('version', (int) $member->version)
                ->update([
                    'points' => number_format($runningPoints, 2, '.', ''),
                    'version' => (int) $member->version + 1,
                ]);

            if ($updatedMember === 0) {
                $this->loggerService->warning('消耗积分：会员版本冲突', [
                    'member_id' => $memberId,
                    'version' => (int) $member->version,
                ]);
                throw new VersionConflictException('member_id=' . $memberId);
            }

            return [
                'points_task_id' => $pointsTaskId,
                'points_task_no' => $pointsTaskNo,
                'before_points' => number_format($beforePoints, 2, '.', ''),
                'after_points' => number_format($runningPoints, 2, '.', ''),
                'details' => $deductedDetails,
                'exhausted_orders' => $exhaustedOrders,
            ];
        }, self::DEDUCT_MAX_ATTEMPTS);
    }

    /**
     * 乐观锁冲突重试：业务回调抛出 VersionConflictException 时整笔事务回滚并重试
     * @param callable $callback
     * @param array $context 仅用于日志
     * @return mixed
     */
    private function retryOnVersionConflict(callable $callback, array $context): mixed
    {
        for ($attempt = 1; $attempt <= self::DEDUCT_MAX_ATTEMPTS; ++$attempt) {
            try {
                return $callback();
            } catch (VersionConflictException $e) {
                $this->loggerService->warning('消耗积分：版本冲突，重试', array_merge($context, [
                    'attempt' => $attempt,
                    'max_attempts' => self::DEDUCT_MAX_ATTEMPTS,
                    'conflict' => $e->getMessage(),
                ]));
            }
        }

        // 重试次数耗尽：多为同一会员并发扣减过于频繁，提示用户重试
        throw new BusinessException(409, '积分正在被其他任务扣减，请稍后重试');
    }

    /**
     * 读取视频计费模块配置，未配置时抛业务异常
     * @param int $featureType 1-Subtitle字母，2-Upscaler放大器，3-HDR高动态范围，4-Colorizer着色器，5-Translator语言翻译
     * @return VideoBillingModule
     */
    private function getVideoBillingModule(int $featureType): VideoBillingModule
    {
        $module = VideoBillingModule::query()
            ->where('video_feature_type', $featureType)
            ->first();

        if ($module === null) {
            $this->loggerService->warning('计算任务积分：计费模块不存在', [
                'video_feature_type' => $featureType,
            ]);
            throw new BusinessException(400, '视频计费模块不存在');
        }

        return $module;
    }

    /**
     * 计算云端 AI 任务扣除积分
     * @param VideoBillingModule $module 视频计费模块配置
     * @param float $durationSeconds 视频处理的时长（秒）
     * @param int $width 视频分辨率-宽度
     * @param int $height 视频分辨率-高度
     * @param float $fps 视频帧率
     * @return float 最终扣除积分数（保留两位小数，不低于模块配置的最低兜底扣费）
     */
    private function calculateTaskCredits(
        VideoBillingModule $module,
        float $durationSeconds,
        int $width = OrderCode::VIDEO_DEFAULT_WIDTH,
        int $height = OrderCode::VIDEO_DEFAULT_HEIGHT,
        float $fps = OrderCode::VIDEO_DEFAULT_FPS
    ): float {
        $featureType = (int) $module->video_feature_type;
        $durationMinutes = max(0, $durationSeconds) / 60;
        $baseRate = (float) $module->charging_standard;

        switch ($featureType) {
            case OrderCode::VIDEO_FEATURE_TYPE_SUBTITLE:
            case OrderCode::VIDEO_FEATURE_TYPE_TRANSLATOR:
                // 独立固定计费：扣费标准 × 时长，不受分辨率、帧率影响
                $totalCredits = $durationMinutes * $baseRate;
                break;
            case OrderCode::VIDEO_FEATURE_TYPE_COLORIZER:
                // 帧率阶梯计费：按帧率档位直接取对应的积分/分钟单价（30 / 45 / 60 / 90 / 90以上）
                $fpsRate = $this->resolveFpsValue($module, $fps, self::COLORIZER_FPS_BRACKETS, $baseRate);
                $totalCredits = $durationMinutes * $fpsRate;
                break;
            case OrderCode::VIDEO_FEATURE_TYPE_UPSCALER:
            case OrderCode::VIDEO_FEATURE_TYPE_HDR:
                // 动态浮动计费：扣费标准 × 分辨率系数 × 帧率系数（30 / 60 / 60以上）
                $resolutionMultiplier = $this->resolveResolutionMultiplier($module, $width, $height);
                $fpsMultiplier = $this->resolveFpsValue($module, $fps, self::MULTIPLIER_FPS_BRACKETS, 1.0);
                $totalCredits = $durationMinutes * $baseRate * $resolutionMultiplier * $fpsMultiplier;
                break;
            default:
                $this->loggerService->warning('计算任务积分：未知的视频功能类型', [
                    'video_feature_type' => $featureType,
                ]);
                throw new BusinessException(400, '未知的视频功能类型');
        }

        // 单次任务最低兜底扣费，防止极短任务扣 0 积分
        $minimumCharge = (float) ($module->minimum_guaranteed_deduction_fee ?? OrderCode::VIDEO_MIN_CHARGE);

        return max($minimumCharge, round($totalCredits, OrderCode::VIDEO_CREDITS_PRECISION));
    }

    /**
     * 解析分辨率对应的系数（4k / 2k / hd），未配置时向低档位回退
     * @param VideoBillingModule $module
     * @param int $width
     * @param int $height
     * @return float 未配置任何档位时返回 1.0
     */
    private function resolveResolutionMultiplier(VideoBillingModule $module, int $width, int $height): float
    {
        $columns = match (true) {
            $width > OrderCode::VIDEO_RESOLUTION_4K_THRESHOLD || $height > OrderCode::VIDEO_RESOLUTION_4K_THRESHOLD => ['4k', '2k', 'hd'],
            $width > OrderCode::VIDEO_RESOLUTION_HD_WIDTH || $height > OrderCode::VIDEO_RESOLUTION_HD_HEIGHT => ['2k', 'hd'],
            default => ['hd'],
        };

        foreach ($columns as $column) {
            $value = $module->getAttribute($column);
            if ($value !== null) {
                return (float) $value;
            }
        }

        return 1.0;
    }

    /**
     * 解析帧率档位对应的取值：用于 Colorizer 时为积分/分钟单价，用于 Upscaler / HDR 时为帧率系数
     * 本档位未配置时向低档位回退，避免因漏配某档而按更高档扣费
     * @param VideoBillingModule $module
     * @param float $fps
     * @param array<int, array<int, string>> $brackets 帧率上限 => 候选字段（按优先级排列）
     * @param float $default 所有相关档位都未配置时的兜底值
     * @return float
     */
    private function resolveFpsValue(VideoBillingModule $module, float $fps, array $brackets, float $default): float
    {
        $columns = [];
        foreach ($brackets as $maxFps => $fields) {
            if ($fps <= $maxFps) {
                $columns = $fields;
                break;
            }
        }

        foreach ($columns as $column) {
            $value = $module->getAttribute($column);
            if ($value !== null) {
                return (float) $value;
            }
        }

        return $default;
    }

    /**
     * 积分任务失败返还积分
     * @param string $pointsTaskNo
     * @return array{points_task_no: string, refunded: bool, refund_points: string, before_points: string, after_points: string, orders: array}
     */
    public function refundPoints(string $pointsTaskNo): array
    {
        $context = ['points_task_no' => $pointsTaskNo];

        try {
            return Db::transaction(function () use ($pointsTaskNo, $context) {
                // 1. 锁定任务：只有失败状态的任务才允许返还
                $task = MemberPointsTask::query()
                    ->select(
                        'points_task_id',
                        'points_task_no',
                        'member_id',
                        'video_feature_type',
                        'video_width',
                        'video_height',
                        'video_frame_rate',
                        'video_duration_seconds',
                        'charging_standard',
                        'deduct_points',
                        'status',
                        'version'
                    )
                    ->where('points_task_no', $pointsTaskNo)
                    ->lockForUpdate()
                    ->first();

                if ($task === null) {
                    $this->loggerService->warning('返还积分：任务不存在', $context);
                    throw new BusinessException(400, '积分任务不存在');
                }

                if ((int) $task->status !== OrderCode::TASK_STATUS_FAILED) {
                    $this->loggerService->warning('返还积分：任务状态不是失败', $context + [
                        'status' => (int) $task->status,
                    ]);
                    throw new BusinessException(400, '只有失败的任务才能返还积分');
                }

                // 2. 幂等：已写过返还流水说明这笔任务已经返还过，直接返回
                $refunded = MemberPointsLog::query()
                    ->where('points_task_no', $pointsTaskNo)
                    ->where('change_type', OrderCode::CHANGE_TYPE_REFUND)
                    ->exists();

                if ($refunded) {
                    $this->loggerService->warning('返还积分：任务已返还，跳过', $context);
                    return [
                        'points_task_no' => $pointsTaskNo,
                        'refunded' => false,
                        'refund_points' => '0.00',
                        'before_points' => '0.00',
                        'after_points' => '0.00',
                        'orders' => [],
                    ];
                }

                // 3. 取出本次任务从哪些积分批次扣减了多少积分
                $taskOrders = MemberPointsTaskOrder::query()
                    ->where('points_task_id', $task->points_task_id)
                    ->orderBy('points_task_order_id')
                    ->lockForUpdate()
                    ->get();

                if (count($taskOrders) === 0) {
                    $this->loggerService->error('返还积分：任务扣减订单记录不存在', $context);
                    throw new BusinessException(500, '积分任务扣减订单记录不存在');
                }

                // 4. 行锁读取会员，保证 before/after 与并发变动不会算错
                $member = Member::query()
                    ->select('member_id', 'points', 'version')
                    ->where('member_id', $task->member_id)
                    ->lockForUpdate()
                    ->first();

                if ($member === null) {
                    $this->loggerService->warning('返还积分：会员不存在', $context + [
                        'member_id' => (int) $task->member_id,
                    ]);
                    throw new BusinessException(400, '会员不存在');
                }

                $beforePoints = (float) $member->points;
                $runningPoints = $beforePoints;
                $refundTotal = 0.0;
                $refundOrders = [];
                $logRows = [];

                foreach ($taskOrders as $taskOrder) {
                    $orderNo = (string) $taskOrder->order_no;
                    $refundPoints = round((float) $taskOrder->deduct_points, OrderCode::VIDEO_CREDITS_PRECISION);
                    if ($refundPoints <= 0) {
                        // 扣减额为 0 的记录没有可返还积分，跳过
                        continue;
                    }

                    $orderMasterId = (int) $taskOrder->order_master_id;
                    $orderDetailsId = (int) $taskOrder->order_details_id;

                    // 直接按扣减时记录的批次id返还：扣哪个批次就加回哪个批次
                    $detail = MemberOrderDetail::query()
                        ->select('order_details_id', 'order_master_id', 'change_points', 'version')
                        ->where('order_details_id', $orderDetailsId)
                        ->where('member_id', $task->member_id)
                        ->lockForUpdate()
                        ->first();

                    if ($detail === null) {
                        $this->loggerService->error('返还积分：扣减批次不存在', $context + [
                            'order_no' => $orderNo,
                            'order_details_id' => $orderDetailsId,
                        ]);
                        throw new BusinessException(500, sprintf('积分订单%s的扣减批次不存在', $orderNo));
                    }

                    $detailVersion = (int) $detail->version;
                    $detailAfterPoints = round(
                        (float) $detail->change_points + $refundPoints,
                        OrderCode::VIDEO_CREDITS_PRECISION
                    );

                    $updatedDetail = MemberOrderDetail::query(true)
                        ->where('order_details_id', $detail->order_details_id)
                        ->where('version', $detailVersion)
                        ->update([
                            'status' => OrderCode::DETAIL_STATUS_ACTIVE,
                            'change_points' => number_format($detailAfterPoints, 2, '.', ''),
                            'version' => $detailVersion + 1,
                        ]);

                    if ($updatedDetail === 0) {
                        $this->loggerService->warning('返还积分：积分批次版本冲突', $context + [
                            'order_details_id' => (int) $detail->order_details_id,
                            'version' => $detailVersion,
                        ]);
                        throw new VersionConflictException('order_details_id=' . $detail->order_details_id);
                    }

                    $logBeforePoints = $runningPoints;
                    $runningPoints = round($runningPoints + $refundPoints, OrderCode::VIDEO_CREDITS_PRECISION);
                    $refundTotal = round($refundTotal + $refundPoints, OrderCode::VIDEO_CREDITS_PRECISION);

                    $logRows[] = [
                        'member_id' => $task->member_id,
                        'order_id' => $orderMasterId,
                        'order_no' => $orderNo,
                        'points_task_id' => $task->points_task_id,
                        'points_task_no' => $task->points_task_no,
                        'flag' => OrderCode::FLAG_INCREASE,
                        'change_type' => OrderCode::CHANGE_TYPE_REFUND,
                        'change_points' => number_format($refundPoints, 2, '.', ''),
                        'before_points' => number_format($logBeforePoints, 2, '.', ''),
                        'after_points' => number_format($runningPoints, 2, '.', ''),
                        'video_feature_type' => $task->video_feature_type,
                        'video_duration_seconds' => $task->video_duration_seconds,
                        'video_width' => $task->video_width,
                        'video_height' => $task->video_height,
                        'video_frame_rate' => $task->video_frame_rate,
                        'charging_standard' => $task->charging_standard,
                        'description' => sprintf(
                            'AI任务失败返还：任务%s 订单%s 返还%s积分',
                            $pointsTaskNo,
                            $orderNo,
                            number_format($refundPoints, 2, '.', '')
                        ),
                    ];

                    $refundOrders[] = [
                        'order_no' => $orderNo,
                        'order_details_id' => (int) $detail->order_details_id,
                        'refund_points' => number_format($refundPoints, 2, '.', ''),
                        'detail_points' => number_format($detailAfterPoints, 2, '.', ''),
                    ];
                }

                // 所有扣减记录都为 0 时无需写流水
                if (count($logRows) === 0) {
                    $this->loggerService->warning('返还积分：没有可返还的积分', $context);
                    return [
                        'points_task_no' => $pointsTaskNo,
                        'refunded' => false,
                        'refund_points' => '0.00',
                        'before_points' => number_format($beforePoints, 2, '.', ''),
                        'after_points' => number_format($beforePoints, 2, '.', ''),
                        'orders' => [],
                    ];
                }

                // 5. 写返还流水（每个扣减订单一条）
                MemberPointsLog::insert($logRows);

                // 6. 会员积分余额增加，按读取时的版本做乐观锁，冲突则整笔回滚重试
                $updatedMember = Member::query(true)
                    ->where('member_id', $task->member_id)
                    ->where('version', (int) $member->version)
                    ->update([
                        'points' => number_format($runningPoints, 2, '.', ''),
                        'version' => (int) $member->version + 1,
                    ]);

                if ($updatedMember === 0) {
                    $this->loggerService->warning('返还积分：会员版本冲突', $context + [
                        'member_id' => (int) $task->member_id,
                        'version' => (int) $member->version,
                    ]);
                    throw new VersionConflictException('member_id=' . $task->member_id);
                }

                $this->loggerService->info('返还积分成功', $context + [
                    'member_id' => (int) $task->member_id,
                    'refund_points' => number_format($refundTotal, 2, '.', ''),
                    'before_points' => number_format($beforePoints, 2, '.', ''),
                    'after_points' => number_format($runningPoints, 2, '.', ''),
                ]);

                return [
                    'points_task_no' => $pointsTaskNo,
                    'refunded' => true,
                    'refund_points' => number_format($refundTotal, 2, '.', ''),
                    'before_points' => number_format($beforePoints, 2, '.', ''),
                    'after_points' => number_format($runningPoints, 2, '.', ''),
                    'orders' => $refundOrders,
                ];
            });
        } catch (BusinessException $e) {
            $this->loggerService->error('返还积分失败', $context + ['error' => $e->getMessage()]);
            throw $e;
        } catch (Throwable $e) {
            $this->loggerService->error('返还积分失败', $context + [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new BusinessException(500, '返还积分失败，请稍后重试', $e);
        }
    }
}
