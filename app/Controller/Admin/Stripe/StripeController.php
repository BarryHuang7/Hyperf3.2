<?php

declare(strict_types=1);

namespace App\Controller\Admin\Stripe;

// use Hyperf\Di\Annotation\Inject;
use App\Controller\AbstractController;
// use App\Service\Admin\Stripe\StripeService;
// use App\Validation\Admin\StripeRefundRequest;
// use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
// use App\Service\UtilsService;

class StripeController extends AbstractController
{
    // #[Inject]
    // protected StripeService $stripeService;

    // #[Inject]
    // protected UtilsService $utilsService;

    /**
     * 需求废弃：Stripe退款（第三个接口）-未完成review
     * 先判断退款条件，通过后调用 Stripe SDK 退款，扣减会员积分并写积分流水
     */
    // public function refund(StripeRefundRequest $request): PsrResponseInterface
    // {
    //     $adminId = $this->utilsService->getAdminId($this->request);
    //     $refundId = (int) $request->input('refund_id');

    //     $result = $this->stripeService->executeRefund($adminId, $refundId);

    //     return $this->returnData([
    //         'code' => 200,
    //         'data' => $result,
    //         'msg' => '退款成功',
    //         'status' => 200,
    //     ]);
    // }
}
