<?php

declare(strict_types=1);

namespace App\Controller\Api\Member;

use App\Controller\AbstractController;
use App\Service\UtilsService;
use App\Service\Api\Member\AuthService;
use App\Validation\Api\LoginRequest;
use App\Validation\Api\RegisterRequest;
use App\Validation\Api\ResetPasswordRequest;
use App\Validation\Api\ConfirmGoogle2faRequest;
use Hyperf\Di\Annotation\Inject;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

class AuthController extends AbstractController
{
    #[Inject]
    protected AuthService $authService;

    #[Inject]
    protected UtilsService $utilsService;

    /**
     * 注册
     */
    public function register(RegisterRequest $request): PsrResponseInterface
    {
        $email = $request->input('email');
        $password = $request->input('password');
        $memberName = $request->input('member_name');
        $verificationCode = (int) $request->input('verification_code');

        $result = $this->authService->register($email, $password, $memberName, $verificationCode);

        return $this->returnData($result);
    }

    /**
     * 登录
     */
    public function login(LoginRequest $request): PsrResponseInterface
    {
        $email = $request->input('email');
        $password = $request->input('password');
        $verifyCode = $request->input('verify_code');
        $tempToken = $request->input('temp_token') ?? '';
        $gaCode = $request->input('2fa_code') ?? '';
        $verifyCodeUUID = $this->request->header('VerifyCode', '');

        $result = $this->authService->login($email, $password, $verifyCode, $verifyCodeUUID, $tempToken, $gaCode);

        return $this->returnData($result);
    }

    /**
     * 登出
     */
    public function logout(): PsrResponseInterface
    {
        $bearerToken = $this->utilsService->getAuthorizationToken($this->request);
        $memberId = $this->utilsService->getMemberId($this->request);

        $result = $this->authService->logout($memberId, $bearerToken);

        return $this->returnData($result);
    }

    /**
     * 获取验证码
     */
    public function getVerificationCode(): PsrResponseInterface
    {
        $headerVerifyCode = $this->request->header('VerifyCode') ?: '';

        $result = $this->authService->getVerificationCode($headerVerifyCode);

        return $this->returnData($result);
    }

    /**
     * 发送邮箱验证码
     */
    public function sendEmailVerificationCode(): PsrResponseInterface
    {
        $email = $this->request->input('email', '');
        $ip = $this->utilsService->getClientRealIp($this->request);

        $result = $this->authService->sendEmailVerificationCode($email, $ip);

        return $this->returnData($result);
    }

    /**
     * 刷新token
     */
    public function refreshToken(): PsrResponseInterface
    {
        $ip = $this->utilsService->getClientRealIp($this->request);
        $token = $this->utilsService->getAuthorizationToken($this->request);
        $memberId = $this->utilsService->getAdminId($this->request);

        $result = $this->authService->refreshToken($ip, $token, $memberId);

        return $this->returnData($result);
    }

    /**
     * 开启google 2fa
     */
    public function enableGoogle2fa(): PsrResponseInterface
    {
        $memberInfo = $this->utilsService->getMemberInfo($this->request);
        $memberId = (int) $memberInfo['member_id'] ?? 0;
        $memberEmail = $memberInfo['email'] ?? '';

        $result = $this->authService->enableGoogle2fa($memberId, $memberEmail);

        return $this->returnData($result);
    }

    /**
     * 绑定google 2fa
     */
    public function confirmGoogle2fa(ConfirmGoogle2faRequest $request): PsrResponseInterface
    {
        $code = $request->input('code');
        $memberId = $this->utilsService->getMemberId($this->request);

        $result = $this->authService->confirmGoogle2fa($code, $memberId);

        return $this->returnData($result);
    }

    /**
     * 重置密码
     */
    public function resetPassword(ResetPasswordRequest $request): PsrResponseInterface
    {
        $memberId = $this->utilsService->getMemberId($this->request);
        $oldPassword = $request->input('old_password');
        $newPassword = $request->input('new_password');

        $result = $this->authService->resetPassword($memberId, $oldPassword, $newPassword);

        return $this->returnData($result);
    }
}
