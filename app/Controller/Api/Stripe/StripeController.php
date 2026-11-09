<?php

declare(strict_types=1);

namespace App\Controller\Api\Stripe;

use Hyperf\Di\Annotation\Inject;
use App\Controller\AbstractController;
use App\Service\Api\Stripe\StripeService;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use App\Validation\Api\CheckoutSessionRequest;
// use App\Validation\Api\ApplyRefundRequest;
// use App\Validation\Api\OrderRequest;
use App\Service\UtilsService;

class StripeController extends AbstractController
{
    #[Inject]
    protected StripeService $stripeService;

    #[Inject]
    protected UtilsService $utilsService;

    /**
     * 创建stripe结账会话
     */
    public function createCheckoutSession(CheckoutSessionRequest $request): PsrResponseInterface
    {
        $planId = $request->input('plan_id');
        // 创建订单即注册
        $email = $request->input('email') ?? '';
        $memberId = $email ? 0 : $this->utilsService->getMemberId($this->request);
        $orderNo = $this->utilsService->generateNo();
        $ip = $this->utilsService->getClientRealIp($this->request);

        $session = $this->stripeService->createCheckoutSession($planId, $memberId, $orderNo, $email, $ip);

        return $this->returnData([
            'code' => 200,
            'data' => [
                'stripe_checkout_id' => $session->id,
                'url' => $session->url,
                'order_no' => $orderNo,
            ],
            'msg' => '',
            'error' => '',
            'status' => 200,
        ]);
    }

    /**
     * 查询订单支付状态
     */
    public function getOrderStatus(string $orderNo): array
    {
        return $this->stripeService->getOrderStatus($orderNo);
    }

    /**
     * 获取套餐列表
     */
    public function getPlanList(): PsrResponseInterface
    {
        $result = $this->stripeService->getPlanList();

        return $this->returnData($result);
    }

    /**
     * 需求废弃：检查订单退款条件-已完成review
     */
    // public function checkRefund(OrderRequest $request): PsrResponseInterface
    // {
    //     $memberId = $this->utilsService->getMemberId($this->request);
    //     $orderNo = (string) $request->input('order_no');

    //     $result = $this->stripeService->checkRefundEligibility($memberId, $orderNo);

    //     return $this->returnData([
    //         'code' => 200,
    //         'data' => $result,
    //         'msg' => $result['can_apply'] ? '可以申请退款' : ($result['apply_reason'] ?: '不可申请退款'),
    //         'status' => 200,
    //     ]);
    // }

    /**
     * 需求废弃：会员申请退款-未完成review
     */
    // public function applyRefund(ApplyRefundRequest $request): PsrResponseInterface
    // {
    //     $memberId = $this->utilsService->getMemberId($this->request);
    //     $orderNo = (string) $request->input('order_no');
    //     $reason = (string) $request->input('reason_for_application');

    //     $result = $this->stripeService->applyRefund($memberId, $orderNo, $reason);

    //     return $this->returnData([
    //         'code' => 200,
    //         'data' => $result,
    //         'msg' => '退款申请已提交',
    //         'status' => 200,
    //     ]);
    // }
}
