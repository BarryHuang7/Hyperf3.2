<?php

declare(strict_types=1);

namespace App\Service\Api\Member;

use App\Constants\ListCode;
use App\Constants\OrderCode;
use App\Exception\BusinessException;
use App\Exception\VersionConflictException;
use App\Model\MemberPointsTask;
use App\Model\MemberPointsTaskOrder;
use App\Service\LoggerService;
use App\Service\WebSocketPushService;
use App\Service\Api\Member\OrderService;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Throwable;

class PointsTaskService
{
    #[Inject]
    protected LoggerService $loggerService;

    #[Inject]
    protected OrderService $orderService;

    #[Inject]
    protected WebSocketPushService $webSocketPushService;

    /**
     * 获取积分任务列表（分页）
     * @param int $memberId
     * @param int $videoFeatureType
     * @param int $status
     * @param int $page
     * @param int $pageSize
     * @return array
     */
    public function getPointsTaskList(int $memberId, int $videoFeatureType, int $status, int $page, int $pageSize): array
    {
        try {
            $page = max(ListCode::DEFAULT_PAGE, $page);
            $pageSize = min(max(ListCode::DEFAULT_PAGE, $pageSize), ListCode::MAX_PAGE_SIZE);

            $total = (int) MemberPointsTask::query()
                ->where('member_id', $memberId)
                ->when($videoFeatureType > 0, function ($query) use ($videoFeatureType) {
                    $query->where('video_feature_type', $videoFeatureType);
                })
                ->when($status > 0, function ($query) use ($status) {
                    $query->where('status', $status);
                })
                ->count();

            $totalPages = $total > 0 ? (int) ceil($total / $pageSize) : 0;

            $list = [];
            if ($total > 0) {
                $orders = MemberPointsTask::query()
                    ->where('member_id', $memberId)
                    ->select(
                        'points_task_id',
                        'points_task_no',
                        'video_feature_type',
                        'video_width',
                        'video_height',
                        'video_frame_rate',
                        'video_duration_seconds',
                        'charging_standard',
                        'deduct_points',
                        'status',
                        'created_at',
                    )
                    ->when($videoFeatureType > 0, function ($query) use ($videoFeatureType) {
                        $query->where('video_feature_type', $videoFeatureType);
                    })
                    ->when($status > 0, function ($query) use ($status) {
                        $query->where('status', $status);
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
            $this->loggerService->error('获取积分任务列表失败', [
                'member_id' => $memberId,
                'video_feature_type' => $videoFeatureType,
                'status' => $status,
                'page' => $page,
                'page_size' => $pageSize,
                'error' => $e->getMessage(),
            ]);
            throw new BusinessException(500, '获取积分任务列表失败，请稍后重试');
        }
    }

    /**
     * 获取积分消耗任务对应订单记录
     * @param int $memberId
     * @param int $pointsTaskId
     * @return array
     */
    public function getPointsTaskOrder(int $memberId, int $pointsTaskId): array
    {
        try {
            $orders = MemberPointsTaskOrder::query()
                ->join('member_points_task as b', 'member_points_task_order.points_task_id', '=', 'b.points_task_id')
                ->select(
                    'member_points_task_order.order_no', 'member_points_task_order.deduct_points',
                    'member_points_task_order.created_at'
                )
                ->where('member_points_task_order.points_task_id', $pointsTaskId)
                ->where('b.member_id', $memberId)
                ->whereNull('b.deleted_at')
                ->orderBy('member_points_task_order.order_no')
                ->get();

            return [
                'code' => 200,
                'status' => 200,
                'data' => $orders,
                'msg' => 'Success',
            ];
        } catch (BusinessException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->loggerService->error('获取积分消耗任务对应订单记录失败', [
                'member_id' => $memberId,
                'points_task_id' => $pointsTaskId,
                'error' => $e->getMessage(),
            ]);
            throw new BusinessException(500, '获取积分消耗任务对应订单记录失败，请稍后重试');
        }
    }

    /**
     * 获取积分任务平台信息
     * @param int $memberId
     * @param int $pointsTaskNo
     * @return array
     */
    public function getPointsTaskPlatformInfo(int $memberId, int $pointsTaskNo): array
    {
        try {
            $task = MemberPointsTask::query()
                ->select('platform_id', 'platform_type', 'status')
                ->where('member_id', $memberId)
                ->where('points_task_no', $pointsTaskNo)
                ->first();

            if (!$task) {
                throw new BusinessException(404, '积分任务不存在');
            }

            return [
                'code' => 200,
                'status' => 200,
                'data' => $task->toArray(),
                'msg' => 'Success',
            ];
        } catch (BusinessException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->loggerService->error('获取积分任务平台信息失败', [
                'member_id' => $memberId,
                'points_task_no' => $pointsTaskNo,
                'error' => $e->getMessage(),
            ]);
            throw new BusinessException(500, '获取积分任务平台信息失败，请稍后重试');
        }
    }

    /**
     * 更新积分任务平台信息：前端创建AI任务后调用更新平台id
     * @param int $memberId
     * @param string $pointsTaskNo
     * @param string $platformId
     * @param int $platformType
     * @return array
     */
    public function updatePointsTaskPlatformInfo(int $memberId, string $pointsTaskNo, string $platformId, int $platformType): array
    {
        $context = [
            'member_id' => $memberId,
            'points_task_no' => $pointsTaskNo,
            'platform_id' => $platformId,
            'platform_type' => $platformType,
        ];

        try {
            $task = MemberPointsTask::query()
                ->select('points_task_id', 'version')
                ->where('member_id', $memberId)
                ->where('points_task_no', $pointsTaskNo)
                ->where('status', OrderCode::TASK_STATUS_DEFAULT)
                ->first();

            if ($task === null) {
                $this->loggerService->warning('更新积分任务平台信息：任务不存在', $context);
                throw new BusinessException(400, '任务不存在');
            }

            $updatedTask = MemberPointsTask::query(true)
                ->where('points_task_no', $pointsTaskNo)
                ->where('member_id', $memberId)
                ->where('status', OrderCode::TASK_STATUS_DEFAULT)
                ->where('version', (int) $task->version)
                ->update([
                    'platform_type' => $platformType,
                    'platform_id' => $platformId,
                    'status' => OrderCode::TASK_STATUS_PROCESSING,
                    'version' => (int) $task->version + 1,
                ]);

            if ($updatedTask === 0) {
                $this->loggerService->warning('更新积分任务平台信息：会员版本冲突', $context + [
                    'version' => (int) $task->version,
                ]);
                throw new VersionConflictException('member_id=' . $memberId);
            }

            return [
                'code' => 200,
                'status' => 200,
                'data' => [],
                'msg' => 'Update Successful',
            ];
        } catch (BusinessException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->loggerService->error('更新积分任务平台信息失败', $context + [
                'error' => $e->getMessage(),
            ]);
            throw new BusinessException(500, '更新积分任务平台信息失败，请稍后重试');
        }
    }

    /**
     * 更新任务状态：平台webhook回调后更新任务状态；任务失败时在同一事务内返还积分
     * @param string $pointsTaskNo
     * @param int $status 平台回调的任务状态：1-成功，4-失败
     * @param string $platformType
     * @return void
     */
    public function updateTaskStatus(string $pointsTaskNo, int $status, string $platformType): void
    {
        $context = [
            'points_task_no' => $pointsTaskNo,
            'status' => $status,
            'platform_type' => $platformType,
        ];

        if (!in_array($status, [OrderCode::TASK_STATUS_SUCCESS, OrderCode::TASK_STATUS_FAILED], true)) {
            $this->loggerService->warning('更新积分任务状态：任务状态不合法', $context);
            throw new BusinessException(400, '任务状态不合法');
        }

        try {
            Db::transaction(function () use ($pointsTaskNo, $status, $platformType, $context) {
                // 行锁读取任务，避免同一任务被并发回调重复处理
                $task = MemberPointsTask::query()
                    ->select('points_task_id', 'member_id', 'status', 'version')
                    ->where('points_task_no', $pointsTaskNo)
                    ->lockForUpdate()
                    ->first();

                if ($task === null) {
                    $this->loggerService->warning('更新积分任务状态：任务不存在', $context);
                    throw new BusinessException(404, '积分任务不存在');
                }

                // 幂等：重复回调且已经是目标状态时不再更新；失败状态顺带补一次返还（返还本身幂等）
                if ((int) $task->status === $status) {
                    if ($status === OrderCode::TASK_STATUS_FAILED) {
                        $this->orderService->refundPoints($pointsTaskNo);
                    }
                    return $this->loggerService->info('积分任务状态已更新为目标状态，无需重复更新', $context);
                }

                if ((int) $task->status !== OrderCode::TASK_STATUS_PROCESSING) {
                    $this->loggerService->warning('更新积分任务状态：当前状态不允许更新', $context + [
                        'current_status' => (int) $task->status,
                    ]);
                    throw new BusinessException(409, '当前任务状态不允许更新');
                }

                $updatedTask = MemberPointsTask::query(true)
                    ->where('points_task_id', $task->points_task_id)
                    ->where('status', OrderCode::TASK_STATUS_PROCESSING)
                    ->where('version', (int) $task->version)
                    ->update([
                        'status' => $status,
                        'version' => (int) $task->version + 1,
                    ]);

                if ($updatedTask === 0) {
                    $this->loggerService->warning('更新积分任务状态：任务版本冲突', $context + [
                        'version' => (int) $task->version,
                    ]);
                    throw new VersionConflictException('points_task_id=' . $task->points_task_id);
                }

                // 任务失败：返还本次任务扣减的积分（失败会抛异常，任务状态一并回滚后由队列重试）
                if ($status === OrderCode::TASK_STATUS_FAILED) {
                    $this->orderService->refundPoints($pointsTaskNo);
                }

                // 发送websocket给前端
                $this->webSocketPushService->pushToUser(
                    (int) $task->member_id,
                    [
                        'points_task_no' => $pointsTaskNo,
                        'status' => $status
                    ],
                    'Task completed',
                    $platformType . ' Webhook 发送AI任务' . ($status === OrderCode::TASK_STATUS_SUCCESS ? '成功' : '失败'),
                );

                $this->loggerService->info('更新积分任务状态完成', $context);
            });
        } catch (BusinessException $e) {
            $this->loggerService->error('更新积分任务状态失败', $context + [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } catch (Throwable $e) {
            $this->loggerService->error('更新积分任务状态失败', $context + [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new BusinessException(500, '更新积分任务状态失败，请稍后重试', $e);
        }
    }

    /**
     * 查询任务状态
     * @param string $pointsTaskNo
     * @return array
     */
    public function getTaskStatus(string $pointsTaskNo): array
    {
        try {
            $task = MemberPointsTask::query()
                ->select('status')
                ->where('points_task_no', $pointsTaskNo)
                ->first();

            if (!$task) {
                $this->loggerService->warning('查询积分任务状态：任务不存在', [
                    'points_task_no' => $pointsTaskNo,
                ]);
                return [
                    'code' => 404,
                    'data' => null,
                    'msg' => 'Task not found'
                ];
            }

            return [
                'code' => 200,
                'data' => [
                    'points_task_no' => $pointsTaskNo,
                    'status' => $task->status
                ],
                'msg' => 'Task completed'
            ];
        } catch (Throwable $e) {
            $this->loggerService->error('查询积分任务状态失败', [
                'points_task_no' => $pointsTaskNo,
                'error' => $e->getMessage(),
            ]);
            return [
                'code' => 500,
                'data' => [
                    'points_task_no' => $pointsTaskNo,
                ],
                'msg' => '查询积分任务状态失败，请稍后重试'
            ];
        }
    }

    /**
     * Base64积分任务编码
     * @param string $pointsTaskNo
     * @param string $token
     */
    public function encodePointsTaskNo(string $pointsTaskNo, string $token): array
    {
        return [
            'code' => 200,
            'status' => 200,
            'data' => [
                'userData' => base64_encode(json_encode([
                    'points_task_no' => $pointsTaskNo,
                    'token' => $token
                ]))
            ],
            'msg' => 'Success',
        ];
    }
}
