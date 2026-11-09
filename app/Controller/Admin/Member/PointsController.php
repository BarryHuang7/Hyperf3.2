<?php

declare(strict_types=1);

namespace App\Controller\Admin\Member;

use App\Constants\ListCode;
use App\Controller\AbstractController;
use App\Service\Admin\Member\PointsService;
use App\Service\UtilsService;
use App\Validation\Admin\MemberPointsAdjustRequest;
use App\Validation\Admin\MemberPointsDetailRequest;
use App\Validation\Admin\MemberPointsListRequest;
use App\Validation\Admin\MemberPointsLogListRequest;
use Hyperf\Di\Annotation\Inject;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

class PointsController extends AbstractController
{
    #[Inject]
    protected PointsService $pointsService;

    #[Inject]
    protected UtilsService $utilsService;

    /**
     * 会员积分管理列表
     */
    public function getMemberPointsList(MemberPointsListRequest $request): PsrResponseInterface
    {
        $email = (string) ($request->input('email') ?: '');
        $memberId = $request->input('member_id');
        $memberId = ($memberId === null || $memberId === '') ? null : (int) $memberId;
        $page = (int) ($request->input('page') ?: ListCode::DEFAULT_PAGE);
        $pageSize = (int) ($request->input('page_size') ?: ListCode::DEFAULT_PAGE_SIZE);

        $result = $this->pointsService->getMemberPointsList($email, $memberId, $page, $pageSize);

        return $this->returnData($result);
    }

    /**
     * 会员积分详情（订单详情，含积分到期时间）
     */
    public function getMemberPointsDetail(MemberPointsDetailRequest $request): PsrResponseInterface
    {
        $memberId = (int) $request->input('member_id');
        $page = (int) ($request->input('page') ?: ListCode::DEFAULT_PAGE);
        $pageSize = (int) ($request->input('page_size') ?: ListCode::DEFAULT_PAGE_SIZE);

        $result = $this->pointsService->getMemberPointsDetail($memberId, $page, $pageSize);

        return $this->returnData($result);
    }

    /**
     * 会员积分流水
     */
    public function getMemberPointsLogList(MemberPointsLogListRequest $request): PsrResponseInterface
    {
        $memberId = (int) $request->input('member_id');
        $page = (int) ($request->input('page') ?: ListCode::DEFAULT_PAGE);
        $pageSize = (int) ($request->input('page_size') ?: ListCode::DEFAULT_PAGE_SIZE);

        $result = $this->pointsService->getMemberPointsLogList($memberId, $page, $pageSize);

        return $this->returnData($result);
    }

    /**
     * 会员积分增减
     */
    public function adjustMemberPoints(MemberPointsAdjustRequest $request): PsrResponseInterface
    {
        $adminId = $this->utilsService->getAdminId($this->request);
        $memberId = (int) $request->input('member_id');
        $flag = (int) $request->input('flag');
        $changePoints = (string) $request->input('change_points');
        $remark = (string) $request->input('remark');
        $auditNotes = (string) $request->input('audit_notes');
        $expirationDate = (string) ($request->input('expiration_date') ?: '');

        $result = $this->pointsService->adjustMemberPoints($adminId, $memberId, $flag, $changePoints, $remark, $auditNotes, $expirationDate);

        return $this->returnData($result);
    }

    /**
     * 积分消耗统计
     */
    public function getMemberPointsStatistics(): PsrResponseInterface
    {
        $result = $this->pointsService->getMemberPointsStatistics();

        return $this->returnData($result);
    }
}
