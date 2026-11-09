<?php

declare(strict_types=1);

namespace App\Service\Api\Member;

use function FriendsOfHyperf\Encryption\decrypt;

use App\Exception\BusinessException;
use App\Model\Member;
use App\Service\LoggerService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Redis\Redis;
use Ramsey\Uuid\Uuid;
use App\Service\UtilsService;
use App\Service\Api\Notice\EmailService;
use App\Service\Google\GoogleAuthenticatorService;
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

    #[Inject]
    protected EmailService $emailService;

    #[Inject]
    protected GoogleAuthenticatorService $googleAuthenticatorService;

    /**
     * 注册
     * @param string $email 邮箱
     * @param string $password 密码
     * @param string $memberName 会员名称
     * @param int $verificationCode 验证码
     * @param bool $noVerificationRequired 免验证码-用于未注册用户
     * @return array
     */
    public function register(string $email, string $password, string $memberName, int $verificationCode, bool $noVerificationRequired = false): array
    {
        try {
            $email = strtolower(trim($email));

            // 幂等处理：同一幂等键只允许处理一次，防止重复提交
            $this->utilsService->acquireIdempotency(AuthCode::MEMBER_IDEMPOTENCY_KEY . md5($email), 5);

            // 检查邮箱是否已存在
            $exists = Member::query()->select('member_id')->where('email', $email)->exists();
            if ($exists) {
                throw new BusinessException(400, '该邮箱已被注册');
            }

            $redisVerificationCodeKey = AuthCode::MEMBER_REGISTER_VERIFICATION_CODE . $email;
            if (!$noVerificationRequired) {
                $redisVerificationCode = $this->redis->get($redisVerificationCodeKey);
    
                if (!$redisVerificationCode || (int) $redisVerificationCode !== $verificationCode) {
                    throw new BusinessException(400, '验证码错误或已过期');
                }
            }

            $member = Member::create([
                'email' => $email,
                'password' => $this->utilsService->encryptPassword($password),
                'member_name' => $memberName,
                'status' => 1,
                'points' => 0,
                'is_subscribe' => 0,
                'automatically_create' => $noVerificationRequired ? 1 : 0,
                'version' => 0,
                'description' => '',
            ]);

            $this->loggerService->info('用户注册成功', $member->toArray());

            if (!$noVerificationRequired) {
                $this->redis->del($redisVerificationCodeKey);
            }

            return [
                'code' => 200,
                'status' => 200,
                'data' => [],
                'msg' => '注册成功',
            ];
        } catch (BusinessException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->loggerService->error('用户注册失败', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
            throw new BusinessException(500, '用户注册失败，请稍后重试');
        }
    }

    /**
     * 登录
     * @param string $email
     * @param string $password
     * @param string $verifyCode
     * @param string $verifyCodeUUID
     * @param string $tempToken
     * @param string $gaCode
     * @return array
     */
    public function login(
        string $email,
        string $password,
        string $verifyCode,
        string $verifyCodeUUID,
        string $tempToken,
        string $gaCode
    ): array {
        try {
            if (empty($gaCode)) {
                if (empty($verifyCode) || empty($verifyCodeUUID)) {
                    throw new BusinessException(400, '验证码不能为空');
                }
    
                $redisVerifyCode = $this->redis->get(AuthCode::LOGIN_VERIFICATION_CODE . $verifyCodeUUID);
                if (!$redisVerifyCode || $redisVerifyCode !== $verifyCode) {
                    throw new BusinessException(400, '验证码已过期或无效');
                }
            }

            $email = strtolower(trim($email));

            $member = Member::query()
                ->select('member_id', 'email', 'password', 'member_name', 'points', 'google2fa_enabled', 'google2fa_secret')
                ->where('email', $email)
                ->where('status', 1)
                ->first();

            // 用户不存在或密码错误
            if (!$member || !password_verify($password, $member->password)) {
                throw new BusinessException(401, '邮箱或密码错误或已封禁');
            }

            $memberId = $member->member_id;
            $memberEmail = $member->email;
            $memberName = $member->member_name;
            $memberGoogle2faEnabled = $member->google2fa_enabled;
            $memberArray = [
                'email' => $memberEmail,
                'member_name' => $memberName,
                'points' => $member->points,
                'google2fa_enabled' => $memberGoogle2faEnabled,
            ];

            $this->utilsService->acquireIdempotency(AuthCode::MEMBER_LOGIN_IDEMPOTENCY_KEY . $memberId, 3);

            // 用户开启google 2fa
            if ($memberGoogle2faEnabled) {
                if (empty($gaCode) || empty($tempToken)) {
                    $tempTTL = AuthCode::MEMBER_REGISTER_VERIFICATION_CODE_TTL;

                    // 生成临时token
                    $tempToken = $this->utilsService->getToken($memberId, $memberEmail, AuthCode::MEMBER_JWT_SECRET, $tempTTL);

                    $memberInfo = json_encode([
                        'member_id' => $memberId,
                        'email' => $memberEmail,
                        'member_name' => $memberName,
                    ], JSON_UNESCAPED_UNICODE);
                    
                    $tempBearerToken = 'Bearer ' . $tempToken;
                    $tempTokenKey = AuthCode::MEMBER_LOGIN_KEY . 'temp:' . $tempBearerToken;

                    $this->redis->set($tempTokenKey, $memberInfo, $tempTTL);

                    return [
                        'code' => 200,
                        'status' => 200,
                        'data' => [
                            'token' => $tempBearerToken,
                            'expires_in' => $tempTTL,
                        ],
                        'msg' => '开始验证Google 2FA',
                    ];
                }

                $tempTokenKey = AuthCode::MEMBER_LOGIN_KEY . 'temp:' . $tempToken;
                $tempUserInfoArray = $this->redis->get($tempTokenKey);

                if (!$tempUserInfoArray) {
                    throw new BusinessException(401, '临时凭证无效或已过期，请重新登录');
                }

                $tempUserInfo = json_decode($tempUserInfoArray, true);

                if (empty($tempUserInfo['member_id']) || (string) $tempUserInfo['member_id'] !== (string) $memberId) {
                    throw new BusinessException(401, '临时凭证无效或已过期，请重新登录');
                }

                $secret = decrypt($member->google2fa_secret);
                if (!$this->googleAuthenticatorService->checkCode($secret, $gaCode)) {
                    throw new BusinessException(401, 'Google 2FA 验证失败');
                }

                $this->redis->del($tempTokenKey);
            }

            $currentTokenKey = AuthCode::MEMBER_CURRENT_TOKEN . $memberId;
            $isToken = $this->redis->get($currentTokenKey);

            if ($isToken) {
                $this->loggerService->info('用户重复登录', $memberArray + ['token' => $isToken]);

                return [
                    'code' => 200,
                    'status' => 200,
                    'data' => [
                        'token' => $isToken,
                        'expires_in' => $this->redis->ttl(AuthCode::MEMBER_LOGIN_KEY . $isToken),
                        'member' => $memberArray,
                    ],
                    'msg' => '已登录',
                ];
            }

            $token = $this->utilsService->getToken($memberId, $memberEmail, AuthCode::MEMBER_JWT_SECRET, AuthCode::MEMBER_TOKEN_TTL);
            $bearerToken = 'Bearer ' . $token;

            // 保存到 Redis，2 小时过期
            $memberInfo = json_encode([
                'member_id' => $memberId,
                'email' => $memberEmail,
                'member_name' => $memberName,
            ], JSON_UNESCAPED_UNICODE);

            $this->redis->set(AuthCode::MEMBER_LOGIN_KEY . $bearerToken, $memberInfo, AuthCode::MEMBER_TOKEN_TTL);
            $this->redis->del(AuthCode::LOGIN_VERIFICATION_CODE . $verifyCodeUUID);
            if (!$isToken) {
                $this->redis->set($currentTokenKey, $bearerToken, AuthCode::MEMBER_TOKEN_TTL);
            }

            $this->loggerService->info('用户登录成功', $memberArray);

            return [
                'code' => 200,
                'status' => 200,
                'data' => [
                    'token' => $bearerToken,
                    'expires_in' => AuthCode::MEMBER_TOKEN_TTL,
                    'member' => $memberArray,
                ],
                'msg' => '登录成功',
            ];
        } catch (BusinessException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->loggerService->error('用户登录失败', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
            throw new BusinessException(500, '用户登录失败，请稍后重试');
        }
    }

    /**
     * 登出
     * @param int $memberId
     * @param string $bearerToken
     * @return array
     */
    public function logout(int $memberId, string $bearerToken): array
    {
        try {
            // 删除 Redis 中的 token
            $currentTokenKey = AuthCode::MEMBER_CURRENT_TOKEN . $memberId;
            if ($this->redis->get($currentTokenKey) === $bearerToken) {
                $this->redis->del($currentTokenKey);
            }
            $deleted = $this->redis->del(AuthCode::MEMBER_LOGIN_KEY . $bearerToken);

            if ($deleted === 0) {
                throw new BusinessException(400, '无效的 token 或已过期');
            }

            $this->loggerService->info('用户登出成功', [
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
            $this->loggerService->error('用户登出失败', [
                'token' => $bearerToken,
                'error' => $e->getMessage(),
            ]);
            throw new BusinessException(500, '用户登出失败，请稍后重试');
        }
    }

    /**
     * 生成登录验证码
     * @param string $headerVerifyCode
     * @return array
     */
    public function getVerificationCode(string $headerVerifyCode): array
    {
        try {
            $uuid = '';
            $result = $this->utilsService->render();

            if ($headerVerifyCode) {
                $uuid = $headerVerifyCode;
            } else {
                $uuid = Uuid::uuid4()->toString();
            }

            if (!empty($result['key'])) {
                $this->redis->set(AuthCode::LOGIN_VERIFICATION_CODE . $uuid, $result['key'], AuthCode::MEMBER_VERIFICATION_CODE_TTL);

                return [
                    'code' => 200,
                    'status' => 200,
                    'data' => [
                        'uuid' => $uuid,
                        'img' => $result['img']
                    ],
                ];
            } else {
                throw new BusinessException(500, '验证码生成失败，请稍后重试');
            }
        } catch (BusinessException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->loggerService->error('获取验证码失败', [
                'error' => $e->getMessage(),
            ]);
            throw new BusinessException(500, '获取验证码失败，请稍后重试');
        }
    }

    /**
     * 发送注册邮箱验证码
     * @param string $email
     * @param string $ip
     * @return array
     */
    public function sendEmailVerificationCode(string $email, string $ip): array
    {
        try {
            $this->utilsService->acquireIdempotency(AuthCode::MEMBER_IDEMPOTENCY_KEY . $ip, 60);

            $email = strtolower(trim($email));

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new BusinessException(400, '无效的邮箱地址');
            }

            $exists = Member::query()->select('member_id')->where('email', $email)->exists();
            if ($exists) {
                throw new BusinessException(400, '该邮箱已被注册');
            }

            // 生成随机验证码
            $verificationCode = rand(100000, 999999);

            // 保存到 Redis，5分钟过期
            $this->redis->set(AuthCode::MEMBER_REGISTER_VERIFICATION_CODE . $email, $verificationCode, AuthCode::MEMBER_REGISTER_VERIFICATION_CODE_TTL);

            // 发送邮件
            $html = '<p>Your verification code is: <strong>' . $verificationCode . '</strong>, The validity period is 5 minutes.</p>';
            $this->emailService->sendEmail($email, $html, 'Registration Verification Code');

            return [
                'code' => 200,
                'status' => 200,
                'data' => null,
                'msg' => '验证码发送成功，请注意查收',
            ];
        } catch (BusinessException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->loggerService->error('发送邮箱验证码失败', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
            throw new BusinessException(500, '发送邮箱验证码失败，请稍后重试');
        }
    }

    /**
     * 刷新token
     * @param string $ip
     * @param string $token
     * @param int $memberId
     * @return array
     */
    public function refreshToken(string $ip, string $token, int $memberId): array
    {
        try {
            $this->utilsService->acquireIdempotency(AuthCode::MEMBER_REFRESH_TOKEN_IDEMPOTENCY_KEY.$ip, 5);

            $tokenKey = AuthCode::MEMBER_LOGIN_KEY . $token;
            $memberInfo = $this->redis->get($tokenKey);

            if ($memberInfo) {
                // 刷新token，30天过期
                $this->redis->set($tokenKey, $memberInfo, AuthCode::MEMBER_REFRESH_TOKEN_TTL);
                $this->redis->set(AuthCode::MEMBER_CURRENT_TOKEN . $memberId, $token, AuthCode::MEMBER_REFRESH_TOKEN_TTL);

                $this->loggerService->info('刷新Token过期时间', [
                    'token' => $token,
                    'ip' => $ip,
                    'memberInfo' => json_decode($memberInfo, true),
                ]);

                return [
                    'code' => 200,
                    'status' => 200,
                    'data' => [
                        'token' => $token,
                        'expires_in' => AuthCode::MEMBER_REFRESH_TOKEN_TTL,
                    ],
                    'msg' => 'Success',
                ];
            }

            return [
                'code' => 400,
                'status' => 400,
                'data' => [
                    'token' => $token,
                ],
                'msg' => 'Refresh failed',
            ];
        } catch (BusinessException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->loggerService->error('刷新Token过期时间失败', [
                'token' => $token,
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);
            throw new BusinessException(500, '刷新Token过期时间失败，请稍后重试');
        }
    }

    /**
     * 开启google 2fa
     * @param int $memberId
     * @param string $memberEmail
     * @return array
     */
    public function enableGoogle2fa(int $memberId, string $memberEmail): array
    {
        try {
            return [
                'code' => 200,
                'status' => 200,
                'data' => $this->googleAuthenticatorService->enableGoogle2fa($memberId, $memberEmail),
                'msg' => 'Success',
            ];
        } catch (BusinessException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->loggerService->error('开启Google2FA失败', [
                'memberId' => $memberId,
                'memberEmail' => $memberEmail,
                'error' => $e->getMessage(),
            ]);
            throw new BusinessException(500, '开启Google2FA失败，请稍后重试');
        }
    }

    /**
     * 绑定google 2fa
     * @param string $code
     * @param int $memberId
     * @return array
     */
    public function confirmGoogle2fa(string $code, int $memberId): array
    {
        try {
            $result = $this->googleAuthenticatorService->confirmGoogle2fa($code, $memberId);
            $this->loggerService->info('会员绑定Google 2FA成功', ['memberId' => $memberId]);

            return $result;
        } catch (BusinessException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->loggerService->error('绑定Google2FA失败', [
                'code' => $code,
                'memberId' => $memberId,
                'error' => $e->getMessage(),
            ]);
            throw new BusinessException(500, '绑定Google2FA失败，请稍后重试');
        }
    }

    /**
     * 免密登录
     * @param int $memberId
     * @return array
     */
    public function passwordFreeLogin(int $memberId, string $memberEmail): array {
        try {
            $currentTokenKey = AuthCode::MEMBER_CURRENT_TOKEN . $memberId;
            $isToken = $this->redis->get($currentTokenKey);

            if ($isToken) {
                $this->loggerService->info('用户重复免密登录', [
                    'member_id' => $memberId,
                    'email' => $memberEmail,
                    'token' => $isToken,
                ]);

                return [
                    'code' => 200,
                    'status' => 200,
                    'data' => [
                        'token' => $isToken,
                        'expires_in' => $this->redis->ttl(AuthCode::MEMBER_LOGIN_KEY . $isToken),
                    ],
                    'msg' => '已免密登录',
                ];
            }

            $token = $this->utilsService->getToken($memberId, $memberEmail, AuthCode::MEMBER_JWT_SECRET, AuthCode::MEMBER_TOKEN_TTL);
            $bearerToken = 'Bearer ' . $token;

            // 保存到 Redis，2 小时过期
            $memberInfo = json_encode([
                'member_id' => $memberId,
                'email' => $memberEmail,
                'member_name' => $memberEmail,
            ], JSON_UNESCAPED_UNICODE);

            $this->redis->set(AuthCode::MEMBER_LOGIN_KEY . $bearerToken, $memberInfo, AuthCode::MEMBER_TOKEN_TTL);
            if (!$isToken) {
                $this->redis->set($currentTokenKey, $bearerToken, AuthCode::MEMBER_TOKEN_TTL);
            }

            $this->loggerService->info('用户免密登录成功', [
                'member_id' => $memberId,
                'email' => $memberEmail,
                'token' => $bearerToken,
            ]);

            return [
                'code' => 200,
                'status' => 200,
                'data' => [
                    'token' => $bearerToken,
                    'expires_in' => AuthCode::MEMBER_TOKEN_TTL,
                    'member' => [
                        'email' => $memberEmail,
                        'member_name' => $memberEmail,
                        'points' => 0,
                    ],
                ],
                'msg' => '免密登录成功',
            ];
        } catch (BusinessException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->loggerService->error('用户免密登录失败', [
                'member_id' => $memberId,
                'email' => $memberEmail,
                'error' => $e->getMessage(),
            ]);
            throw new BusinessException(500, '用户免密登录失败，请稍后重试');
        }
    }

    /**
     * 重置密码
     * @param int $memberId 会员ID
     * @param string $oldPassword 原密码
     * @param string $newPassword 新密码
     * @return array
     */
    public function resetPassword(int $memberId, string $oldPassword, string $newPassword): array
    {
        try {
            $member = Member::query()
                ->select('member_id', 'email', 'password', 'version')
                ->where('member_id', $memberId)
                ->first();

            if (!$member) {
                throw new BusinessException(400, '账号不存在');
            }

            if (!password_verify($oldPassword, $member->password)) {
                throw new BusinessException(400, '原密码错误');
            }

            if (password_verify($newPassword, $member->password)) {
                throw new BusinessException(400, '新密码不能与旧密码相同');
            }

            $memberVersion = (int) $member->version;
            $updated = Member::query(true)
                ->where('member_id', $memberId)
                ->where('version', $memberVersion)
                ->update([
                    'password' => $this->utilsService->encryptPassword($newPassword),
                    'version' => $memberVersion + 1,
                ]);

            if ($updated === 0) {
                $this->loggerService->warning('重置密码：会员版本冲突', [
                    'member_id' => $memberId,
                    'version' => $memberVersion,
                ]);
                throw new BusinessException(409, '账号信息已被修改，请重试');
            }

            $this->loggerService->info('用户重置密码成功', [
                'member_id' => $memberId,
                'email' => $member->email,
            ]);

            return [
                'code' => 200,
                'status' => 200,
                'data' => null,
                'msg' => '密码重置成功',
            ];
        } catch (BusinessException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->loggerService->error('用户重置密码失败', [
                'member_id' => $memberId,
                'error' => $e->getMessage(),
            ]);
            throw new BusinessException(500, '密码重置失败，请稍后重试');
        }
    }
}
