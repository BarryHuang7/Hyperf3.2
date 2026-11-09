<?php

declare(strict_types=1);

namespace App\Controller\Api\Member;

use App\Constants\ListCode;
use App\Controller\AbstractController;
use App\Service\UtilsService;
use App\Service\Api\Member\OrderService;
use App\Validation\Api\OrderListRequest;
use App\Validation\Api\OrderRequest;
use App\Validation\Api\ConsumptionPointsRequest;
use Hyperf\Di\Annotation\Inject;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

class OrderController extends AbstractController
{
    #[Inject]
    protected OrderService $orderService;

    #[Inject]
    protected UtilsService $utilsService;

    /**
     * 获取积分订单
     */
    public function getOrderList(OrderListRequest $request): PsrResponseInterface
    {
        $memberId = $this->utilsService->getMemberId($this->request);
        $changeType = (int) ($request->input('change_type') ?: 0);
        $page = (int) ($request->input('page') ?: ListCode::DEFAULT_PAGE);
        $pageSize = (int) ($request->input('page_size') ?: ListCode::DEFAULT_PAGE_SIZE);

        $result = $this->orderService->getOrderList($memberId, $changeType, $page, $pageSize);

        return $this->returnData($result);
    }

    /**
     * 获取积分订单详情
     */
    public function getOrderDetail(OrderRequest $request): PsrResponseInterface
    {
        $memberId = $this->utilsService->getMemberId($this->request);
        $orderNo = (string) $request->input('order_no');

        $result = $this->orderService->getOrderDetail($memberId, $orderNo);

        return $this->returnData($result);
    }

    /**
     * 消耗积分
     */
    public function consumptionPoints(ConsumptionPointsRequest $request): PsrResponseInterface
    {
        $memberId = $this->utilsService->getMemberId($this->request);
        $featureType = (int) $request->input('feature_type');
        $durationSeconds = (float) $request->input('duration_seconds');
        $width = (int) $request->input('width');
        $height = (int) $request->input('height');
        $fps = (float) $request->input('fps');

        $result = $this->orderService->consumptionPoints(
            $memberId,
            $featureType,
            $durationSeconds,
            $width,
            $height,
            $fps
        );

        return $this->returnData($result);
    }
}
