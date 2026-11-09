<?php

declare(strict_types=1);

namespace App\Controller\Api\Member;

use App\Constants\ListCode;
use App\Controller\AbstractController;
use App\Service\UtilsService;
use App\Service\Api\Member\PointsTaskService;
use App\Validation\Api\PointsTaskRequest;
use App\Validation\Api\PointsTaskOrderRequest;
use App\Validation\Api\UpdatePointsTaskRequest;
use App\Validation\Api\EncodePointsTaskNoRequest;
use Hyperf\Di\Annotation\Inject;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

class PointsTaskController extends AbstractController
{
    #[Inject]
    protected PointsTaskService $pointsTaskService;

    #[Inject]
    protected UtilsService $utilsService;

    /**
     * 获取积分任务列表
     */
    public function getPointsTaskList(PointsTaskRequest $request): PsrResponseInterface
    {
        $memberId = $this->utilsService->getMemberId($this->request);
        $page = (int) ($request->input('page') ?: ListCode::DEFAULT_PAGE);
        $pageSize = (int) ($request->input('page_size') ?: ListCode::DEFAULT_PAGE_SIZE);
        $videoFeatureType = (int) ($request->input('video_feature_type') ?: 0);
        $status = (int) ($request->input('status') ?: 0);

        $result = $this->pointsTaskService->getPointsTaskList($memberId, $videoFeatureType, $status, $page, $pageSize);

        return $this->returnData($result);
    }

    /**
     * 获取积分消耗任务对应订单记录
     */
    public function getPointsTaskOrder(PointsTaskOrderRequest $request): PsrResponseInterface
    {
        $memberId = $this->utilsService->getMemberId($this->request);
        $pointsTaskId = (int) ($request->input('points_task_id') ?: 0);

        $result = $this->pointsTaskService->getPointsTaskOrder($memberId, $pointsTaskId);

        return $this->returnData($result);
    }

    /**
     * 更新积分任务平台信息
     */
    public function updatePointsTaskPlatformInfo(UpdatePointsTaskRequest $request): PsrResponseInterface
    {
        $memberId = $this->utilsService->getMemberId($this->request);
        $pointsTaskNo = (string) $request->input('points_task_no');
        $platformId = (string) $request->input('platform_id');
        $platformType = (int) $request->input('platform_type');
        $result = $this->pointsTaskService->updatePointsTaskPlatformInfo($memberId, $pointsTaskNo, $platformId, $platformType);

        return $this->returnData($result);
    }

    /**
     * 查询任务状态
     * @param string $pointsTaskNo
     * @return array
     */
    public function getTaskStatus(string $pointsTaskNo): array
    {
        return $this->pointsTaskService->getTaskStatus($pointsTaskNo);
    }

    /**
     * Base64积分任务编码
     */
    public function encodePointsTaskNo(EncodePointsTaskNoRequest $request): PsrResponseInterface
    {
        $pointsTaskNo = (string) $request->input('points_task_no');
        $token = (string) $this->utilsService->getMemberToken($this->request);
        $result = $this->pointsTaskService->encodePointsTaskNo($pointsTaskNo, $token);

        return $this->returnData($result);
    }
}
