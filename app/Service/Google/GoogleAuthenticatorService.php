<?php

declare(strict_types=1);

namespace App\Service\Google;

use function Hyperf\Support\env;
use function FriendsOfHyperf\Encryption\encrypt;

use App\Constants\AuthCode;
use App\Exception\BusinessException;
use App\Model\Member;
use Hyperf\Redis\Redis;
use Hyperf\Di\Annotation\Inject;
use Hyperf\DbConnection\Db;
use Hejunjie\GoogleAuthenticator\GoogleAuthenticator;

class GoogleAuthenticatorService
{
    #[Inject]
    protected Redis $redis;

    /**
     * 开启google 2fa
     * @param int $memberId
     * @param string $memberEmail
     * @return array
     */
    public function enableGoogle2fa(int $memberId, string $memberEmail): array
    {
        $ga = new GoogleAuthenticator();
        $secret = $ga->generateSecret();
        $this->redis->set(AuthCode::MEMBER_GOOGLE2FA_SETUP . $memberId, $secret, AuthCode::MEMBER_REGISTER_VERIFICATION_CODE_TTL);

        $dir = BASE_PATH . '/runtime/google2fa';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $qrcodeUrl = $dir . '/qrcode_'.$memberId.'.png';

        $result = $ga->getQRCodeFile(
            issuer: env('APP_NAME'),
            label: $memberEmail,
            secret: $secret,
            path: $qrcodeUrl
        );

        $base64 = base64_encode(file_get_contents($result));
        @unlink($result);

        return [
            'qrCode' => 'data:image/png;base64,' . $base64,
        ];
    }

    /**
     * 绑定google 2fa
     * @param string $code
     * @param int $memberId
     * @return array
     */
    public function confirmGoogle2fa(string $code, int $memberId): array
    {
        $member = Member::query()
            ->select('google2fa_enabled', 'google2fa_secret', 'version')
            ->where('member_id', $memberId)
            ->first();

        if (!$member) {
            throw new BusinessException(400, '会员不存在');
        }

        if ($member->google2fa_enabled) {
            throw new BusinessException(400, '会员已开启Google 2FA');
        }

        $key = AuthCode::MEMBER_GOOGLE2FA_SETUP . $memberId;
        $secret = $this->redis->get($key);

        if (!$secret) {
            throw new BusinessException(400, '密钥已过期请重试');
        }

        if ($this->checkCode($secret, $code)) {
            Member::query(true)->where('member_id', $memberId)->update([
                'google2fa_enabled' => 1,
                'google2fa_secret' => encrypt($secret),
                'version' => Db::raw('version + 1'),
            ]);

            $this->redis->del($key);

            return [
                'code' => 200,
                'status' => 200,
                'data' => [],
                'msg' => 'Success',
            ];
        }

        return [
            'code' => 400,
            'status' => 400,
            'data' => [
                'code' => $code
            ],
            'msg' => '认证失败，验证码错误或过期',
        ];
    }

    /**
     * 校验google 2fa code
     * @param string $secret
     * @param string $code
     * @return bool
     */
    public function checkCode(string $secret, string $code): bool
    {
        $ga = new GoogleAuthenticator();
        return $ga->checkCode($secret, $code);
    }
}
