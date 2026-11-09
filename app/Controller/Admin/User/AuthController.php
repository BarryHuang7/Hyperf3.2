<?php

declare(strict_types=1);

namespace App\Controller\Admin\User;

use App\Controller\AbstractController;
use App\Service\UtilsService;
use App\Service\Admin\User\AuthService;
use App\Validation\Admin\LoginRequest;
use App\Validation\Admin\RegisterRequest;
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
        $adminName = $request->input('admin_name');

        $result = $this->authService->register($email, $password, $adminName);

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
        $verifyCodeUUID = $this->request->header('VerifyCode', '');

        $result = $this->authService->login($email, $password, $verifyCode, $verifyCodeUUID);

        return $this->returnData($result);
    }

    /**
     * 登出
     */
    public function logout(): PsrResponseInterface
    {
        $bearerToken = $this->utilsService->getAuthorizationToken($this->request);
        $adminId = $this->utilsService->getAdminId($this->request);

        $result = $this->authService->logout($adminId, $bearerToken);

        return $this->returnData($result);
    }
}
