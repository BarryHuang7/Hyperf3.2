<?php

declare(strict_types=1);

namespace App\Constants;

use Hyperf\Constants\AbstractConstants;
use Hyperf\Constants\Annotation\Constants;

#[Constants]
class AuthCode extends AbstractConstants
{
    /**
     * 登录验证码
     */
    public const LOGIN_VERIFICATION_CODE = 'login_verification_code:';

    // member------------------------------

    /**
     * Token 有效期（秒）-2小时，与 Redis 中保存的过期时间保持一致
     */
    public const MEMBER_TOKEN_TTL = 7200;

    /**
     * JWT 签名密钥
     */
    public const MEMBER_JWT_SECRET = 'uniCD-member-jwt-secret';

    /**
     * 幂等性秘钥
     */
    public const MEMBER_IDEMPOTENCY_KEY = 'idempotency:member:register:';

    /**
     * 验证码有效时间（秒）
     */
    public const MEMBER_VERIFICATION_CODE_TTL = 60;

    /**
     * 注册验证码
     */
    public const MEMBER_REGISTER_VERIFICATION_CODE = 'email_verification_code:';

    /**
     * 登录redis秘钥
     */
    public const MEMBER_LOGIN_KEY = 'member_login:';

    /**
     * 注册验证码验证码有效时间（秒）
     */
    public const MEMBER_REGISTER_VERIFICATION_CODE_TTL = 300;

    /**
     * 刷新 Token 幂等性秘钥
     */
    public const MEMBER_REFRESH_TOKEN_IDEMPOTENCY_KEY = 'idempotency:member:refresh_token:';

    /**
     * Token 有效期（秒）-30天
     */
    public const MEMBER_REFRESH_TOKEN_TTL = 2592000;

    /**
     * 防止重复获取登录 Token
     */
    public const MEMBER_CURRENT_TOKEN = 'member_current_token:';

    /**
     * Google 2FA设置密钥
     */
    public const MEMBER_GOOGLE2FA_SETUP = 'member_google2fa:setup:';

    /**
     * 创建 Stripe 幂等性秘钥
     */
    public const MEMBER_STRIPE_IDEMPOTENCY_KEY = 'idempotency:member:stripe:';

    /**
     * 登录 幂等性秘钥
     */
    public const MEMBER_LOGIN_IDEMPOTENCY_KEY = 'idempotency:member:login:';

    /**
     * 校验本地模式注册码 幂等性秘钥
     */
    public const MEMBER_REGISTRATION_CODE_IDEMPOTENCY = 'idempotency:member:registration_code:';

    // admin------------------------------

    /**
     * Token 有效期（秒）-6小时，与 Redis 中保存的过期时间保持一致
     */
    public const ADMIN_TOKEN_TTL = 21600;

    /**
     * JWT 签名密钥
     */
    public const ADMIN_JWT_SECRET = 'uniCD-user-jwt-secret';

    /**
     * 幂等性秘钥
     */
    public const ADMIN_IDEMPOTENCY_KEY = 'idempotency:admin:register:';

    /**
     * 登录redis秘钥
     */
    public const ADMIN_LOGIN_KEY = 'admin_login:';

    /**
     * 防止重复获取登录 Token
     */
    public const ADMIN_CURRENT_TOKEN = 'admin_current_token:';
}
