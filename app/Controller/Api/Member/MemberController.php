<?php

declare(strict_types=1);

namespace App\Controller\Api\Member;

use App\Controller\AbstractController;
use App\Service\UtilsService;
use App\Service\Api\Member\MemberService;
use App\Service\Api\Member\RegistrationCodeService;
use App\Validation\Api\RegistrationCodeRequest;
use Hyperf\Di\Annotation\Inject;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

class MemberController extends AbstractController
{
    #[Inject]
    protected UtilsService $utilsService;

    #[Inject]
    protected MemberService $memberService;

    #[Inject]
    protected RegistrationCodeService $registrationCodeService;

    /**
     * 获取会员积分和即将过期积分
     */
    public function getPoints(): PsrResponseInterface
    {
        $memberId = $this->utilsService->getMemberId($this->request);
        $result = $this->memberService->getPoints($memberId);
        return $this->returnData($result);
    }

    /**
     * 校验本地模式注册码
     */
    public function verifyRegistrationCode(RegistrationCodeRequest $request): PsrResponseInterface
    {
        $memberId = $this->utilsService->getMemberId($this->request);
        $registrationCode = trim((string) ($request->input('registration_code') ?: $request->input('code')));

        $result = $this->registrationCodeService->verify($memberId, $registrationCode);

        return $this->returnData($result);
    }
}
