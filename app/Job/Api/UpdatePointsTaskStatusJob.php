<?php

declare(strict_types=1);

namespace App\Job\Api;

use Hyperf\AsyncQueue\Job;
use Hyperf\Context\ApplicationContext;
use App\Service\Api\Member\PointsTaskService;
use App\Service\LoggerService;
use Throwable;

class UpdatePointsTaskStatusJob extends Job
{
    // 最大重试次数（实际执行次数 = 此值 + 1）
    protected int $maxAttempts = 4;

    // Job 里只放可序列化的普通数据，不要放容器、PDO 等对象
    public string $pointsTaskNo;
    public int $status;
    public string $platformType;

    public function __construct(string $pointsTaskNo, int $status, string $platformType)
    {
        $this->pointsTaskNo = $pointsTaskNo;
        $this->status = $status;
        $this->platformType = $platformType;
    }

    public function handle(): void
    {
        $container = ApplicationContext::getContainer();
        $loggerService = $container->get(LoggerService::class);

        try {
            $pointsTaskService = $container->get(PointsTaskService::class);

            // 更新失败会抛出异常，交给队列按 maxAttempts 重试
            $pointsTaskService->updateTaskStatus(
                $this->pointsTaskNo,
                $this->status,
                $this->platformType
            );

            $loggerService->info('异步队列-更新积分任务状态-成功', [
                'points_task_no' => $this->pointsTaskNo,
                'status' => $this->status,
            ]);
        } catch (Throwable $e) {
            $recordArray = [
                'points_task_no' => $this->pointsTaskNo,
                'status' => $this->status,
                'error' => $e->getMessage(),
            ];
            $loggerService->error('异步队列-更新积分任务状态-失败', $recordArray);
            $loggerService->info('异步队列-更新积分任务状态-重试', $recordArray);
            // 抛出异常交给队列重试
            throw $e;
        }
    }
}
