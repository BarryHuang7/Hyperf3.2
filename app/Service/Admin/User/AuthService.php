<?php

declare(strict_types=1);

namespace App\Service\Admin\User;

use App\Exception\BusinessException;
use App\Model\AdminUser;
use App\Service\LoggerService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Redis\Redis;
use App\Service\UtilsService;
use App\Constants\AuthCode;
use Throwable;

class AuthService
{
    #[Inject]
    protected Redis $redis;

    #[Inject]
    protected LoggerService $loggerService;

    #[Inject]
    protected UtilsService $utilsService;

    /**
     * 注册
     * @param string $email
     * @param string $password
     * @param string $adminName
     * @return array
     */
    public function register(string $email, string $password, string $adminName): array
    {
        try {
            $email = strtolower(trim($email));

            // 幂等处理：同一幂等键只允许处理一次，防止重复提交
            $this->utilsService->acquireIdempotency(AuthCode::ADMIN_IDEMPOTENCY_KEY . md5($email), 5);

            // 检查邮箱是否已存在
            $exists = AdminUser::query()->select('admin_id')->where('email', $email)->exists();
            if ($exists) {
                throw new BusinessException(400, '该邮箱已被注册');
            }

            $admin = AdminUser::create([
                'email' => $email,
                'password' => $this->utilsService->encryptPassword($password),
                'admin_name' => $adminName,
                'status' => 1,
                'description' => '',
            ]);

            $this->loggerService->info('管理员注册成功', $admin->toArray());

            return [
                'code' => 200,
                'status' => 200,
                'data' => [],
                'msg' => '注册成功',
            ];
        } catch (BusinessException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->loggerService->error('管理员注册失败', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
            throw new BusinessException(500, '管理员注册失败，请稍后重试');
        }
    }

    /**
     * 登录
     * @param string $email
     * @param string $password
     * @param string $verifyCode
     * @param string $verifyCodeUUID
     * @return array
     */
    public function login(string $email, string $password, string $verifyCode, string $verifyCodeUUID): array
    {
        try {
            if (empty($verifyCode) || empty($verifyCodeUUID)) {
                throw new BusinessException(400, '验证码不能为空');
            }

            $redisVerifyCode = $this->redis->get(AuthCode::LOGIN_VERIFICATION_CODE . $verifyCodeUUID);
            if (!$redisVerifyCode || $redisVerifyCode !== $verifyCode) {
                throw new BusinessException(400, '验证码已过期或无效');
            }

            $email = strtolower(trim($email));

            $admin = AdminUser::query()
                ->select('admin_id', 'email', 'password', 'admin_name')
                ->where('email', $email)
                ->where('status', 1)
                ->first();

            // 用户不存在或密码错误
            if (!$admin || !password_verify($password, $admin->password)) {
                throw new BusinessException(401, '邮箱或密码错误或已封禁');
            }

            $adminId = $admin->admin_id;
            $adminEmail = $admin->email;
            $adminName = $admin->admin_name;

            $currentTokenKey = AuthCode::ADMIN_CURRENT_TOKEN . $adminId;
            $isToken = $this->redis->get($currentTokenKey);

            if ($isToken) {
                $this->loggerService->info('管理员重复登录', $admin->toArray() + ['token' => $isToken]);

                return [
                    'code' => 200,
                    'status' => 200,
                    'data' => [
                        'token' => $isToken,
                        'expires_in' => $this->redis->ttl(AuthCode::ADMIN_LOGIN_KEY . $isToken),
                        'member' => [
                            'email' => $adminEmail,
                            'admin_name' => $adminName,
                        ],
                    ],
                    'msg' => '已登录',
                ];
            }

            $token = $this->utilsService->getToken($adminId, $adminEmail, AuthCode::ADMIN_JWT_SECRET, AuthCode::ADMIN_TOKEN_TTL);
            $bearerToken = 'Bearer ' . $token;

            // 保存到 Redis，6 小时过期
            $memberInfo = json_encode([
                'admin_id' => $adminId,
                'email' => $adminEmail,
                'admin_name' => $adminName,
            ], JSON_UNESCAPED_UNICODE);

            $this->redis->set(AuthCode::ADMIN_LOGIN_KEY . $bearerToken, $memberInfo, AuthCode::ADMIN_TOKEN_TTL);
            $this->redis->del(AuthCode::LOGIN_VERIFICATION_CODE . $verifyCodeUUID);
            if (!$isToken) {
                $this->redis->set($currentTokenKey, $bearerToken, AuthCode::ADMIN_TOKEN_TTL);
            }

            $this->loggerService->info('管理员登录成功', $admin->toArray());

            return [
                'code' => 200,
                'status' => 200,
                'data' => [
                    'token' => $bearerToken,
                    'expires_in' => AuthCode::ADMIN_TOKEN_TTL,
                    'member' => [
                        'email' => $adminEmail,
                        'admin_name' => $adminName,
                    ],
                ],
                'msg' => '登录成功',
            ];
        } catch (BusinessException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->loggerService->error('管理员登录失败', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
            throw new BusinessException(500, '管理员登录失败，请稍后重试');
        }
    }

    /**
     * 登出
     * @param int $adminId
     * @param string $bearerToken
     * @return array
     */
    public function logout(int $adminId, string $bearerToken): array
    {
        try {
            // 删除 Redis 中的 token
            $currentTokenKey = AuthCode::ADMIN_CURRENT_TOKEN . $adminId;
            if ($this->redis->get($currentTokenKey) === $bearerToken) {
                $this->redis->del($currentTokenKey);
            }
            $deleted = $this->redis->del(AuthCode::ADMIN_LOGIN_KEY . $bearerToken);

            if ($deleted === 0) {
                throw new BusinessException(400, '无效的 token 或已过期');
            }

            $this->loggerService->info('管理员登出成功', [
                'token' => $bearerToken,
            ]);

            return [
                'code' => 200,
                'status' => 200,
                'data' => null,
                'msg' => '登出成功',
            ];
        } catch (BusinessException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->loggerService->error('管理员登出失败', [
                'token' => $bearerToken,
                'error' => $e->getMessage(),
            ]);
            throw new BusinessException(500, '管理员登出失败，请稍后重试');
        }
    }
}
