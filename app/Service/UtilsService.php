<?php

declare(strict_types=1);

namespace App\Service;

use Ramsey\Uuid\Uuid;
use Firebase\JWT\JWT;
use Hyperf\Redis\Redis;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ServerRequestInterface;
use App\Exception\BusinessException;
use App\Constants\AuthCode;

class UtilsService
{
    #[Inject]
    protected Redis $redis;

    /**
     * 获取客户端真实 IP
	 * @param ServerRequestInterface $request
	 * @return string
     */
    public function getClientRealIp(ServerRequestInterface $request): string
    {
        // 按优先级获取 IP
        $ip = $this->getIpFromPriority(static fn (string $header): string => $request->getHeaderLine($header));

        if ($ip) {
            return $ip;
        }

        // 最终回退
        $serverParams = $request->getServerParams();
        return $serverParams['remote_addr'] ?? '0.0.0.0';
    }

    /**
     * 从头部数组获取客户端真实 IP（适用于 WebSocket 握手等非 PSR-7 请求）
     * @param array $headers 头部数组，键名不区分大小写
     * @param string $remoteAddr 连接的对端地址，头部取不到时作为回退
     * @return string
     */
    public function getClientRealIpFromHeaders(array $headers, string $remoteAddr = ''): string
    {
        $ip = $this->getIpFromPriority(static function (string $header) use ($headers): string {
            foreach ($headers as $key => $value) {
                if (strtolower((string) $key) !== $header) {
                    continue;
                }

                return is_array($value) ? implode(',', array_map('strval', $value)) : (string) $value;
            }

            return '';
        });

        if ($ip) {
            return $ip;
        }

        return $this->isValidIp($remoteAddr) ? $remoteAddr : '0.0.0.0';
    }

	/**
	 * 根据优先级获取客户端 IP
	 * @param callable $getHeader 按头字段名取值，缺失时返回空字符串
	 * @return string
	 */
    private function getIpFromPriority(callable $getHeader): string
    {
        // 头字段优先级列表（按从高到低）
        $headers = [
            'x-real-ip',
            'x-forwarded-for',
            'client-ip',
            'x-forwarded',
            'forwarded-for',
        ];

        foreach ($headers as $header) {
            $value = $getHeader($header);
            if (empty($value)) {
                continue;
            }

            // 处理多个 IP（如 X-Forwarded-For）
            if (strpos($value, ',') !== false) {
                $ips = array_map('trim', explode(',', $value));
                foreach ($ips as $ip) {
                    if ($this->isValidIp($ip)) {
                        return $ip;
                    }
                }
            } else {
                if ($this->isValidIp($value)) {
                    return $value;
                }
            }
        }

        return '';
    }

	/**
	 * 验证 IP 地址是否有效
	 * @param string $ip
	 * @return bool
	 */
    private function isValidIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * 获取登录 token
     * @param int $userId
     * @param string $userName
     * @param string $jwtSecret
     * @param int $tokenTTL
     * @return string
     */
    public function getToken(int $userId, string $userName, string $jwtSecret, int $tokenTTL): string
    {
        $key = $this->generateKeyFromUuid($jwtSecret);

        // 构建 claims
        $claims = [
            'id' => $userId,
            'name' => $userName,
        ];

        // 构建 payload（包含标准 claims）
        $payload = array_merge([
            // JWT ID（唯一标识）
            'jti' => Uuid::uuid4()->toString(),
            // 签发人
            'iss' => 'admin',
            // 主题
            'sub' => 'JWT AUTH',
            // 签发时间
            'iat' => time(),
            // 生效时间
            'nbf' => time(),
            // 过期时间
            'exp' => time() + $tokenTTL,
        ], $claims);

        // 使用 HS256 算法签名
        return JWT::encode($payload, $key, 'HS256');
    }

    /**
     * 生成密钥
     * @param string $jwtSecret
     * @return string
     */
    private static function generateKeyFromUuid(string $jwtSecret): string
    {
        // 生成 UUID
        $uuid = Uuid::uuid4()->toString();
        // 将 UUID 转换为字节
        $uuidBytes = $uuid;
        // 使用 HMAC-SHA512 生成密钥
        return hash_hmac('sha256', $uuidBytes, $jwtSecret, true);
    }

    /**
     * 幂等占位：同一幂等键只允许通过一次
     * @param string $idempotencyKey
     * @param int $ttl
     */
    public function acquireIdempotency(string $idempotencyKey, int $ttl = 5): void
    {
        // NX：键不存在才写入；EX：5 秒后自动释放，避免长期占用
        $acquired = $this->redis->set($idempotencyKey, '1', ['NX', 'EX' => $ttl]);

        if (!$acquired) {
            throw new BusinessException(400, '请勿重复操作');
        }
    }

    /**
     * 密码加密
     * @param string $password
     */
    public function encryptPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT, array('cost' => 12));
    }

    /**
     * 获取member_info
     */
    public function getMemberInfo(RequestInterface $request): array
    {
        return $request->getAttribute('user_info', []);
    }

    /**
     * 获取member_id
     */
    public function getMemberId(RequestInterface $request): int
    {
        $userInfo = $request->getAttribute('user_info', []);
        $memberId = is_array($userInfo) ? (int) ($userInfo['member_id'] ?? 0) : 0;

        if ($memberId <= 0) {
            throw new BusinessException(401, '未登录或登录已过期');
        }

        return $memberId;
    }

    /**
     * 获取admin_id
     */
    public function getAdminId(RequestInterface $request): int
    {
        $userInfo = $request->getAttribute('user_info', []);
        $adminId = is_array($userInfo) ? (int) ($userInfo['admin_id'] ?? 0) : 0;

        if ($adminId <= 0) {
            throw new BusinessException(401, '未登录或登录已过期');
        }

        return $adminId;
    }

    /**
     * 获取请求头部的token
     */
    public function getAuthorizationToken(RequestInterface $request): string
    {
        return $request->getAttribute('token', '');
    }

    /**
     * 生成编号：时间戳 + 随机数
     */
    public function generateNo(): string
    {
        $prefix = date('YmdHis');
        $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $maxIndex = strlen($chars) - 1;
        $suffix = '';

        for ($i = 0; $i < 10; $i++) {
            $suffix .= $chars[random_int(0, $maxIndex)];
        }

        return $prefix . $suffix;
    }

    /**
     * 是否合法日期（严格模式，排除溢出）
     * @param string $value
     * @param string $format
     * @return bool
     */
    function isStrictDate(string $value, string $format = 'Y-m-d'): bool
    {
        $date = \DateTime::createFromFormat($format, $value);
        // 必须成功创建对象，且格式化后与原始输入完全一致，才能排除溢出的非法日期
        return $date && $date->format($format) === $value;
    }

    /**
     * 获取member token
     */
    public function getMemberToken(RequestInterface $request): string
    {
        return $request->getAttribute('token', '');
    }

    /**
     * 校验token是否有效
     * @param string $token
     * @return bool
     */
    public function verificationToken(string $token): bool
    {
        if (empty($token)) {
            return false;
        }

        $prefix = AuthCode::MEMBER_LOGIN_KEY;
        $userInfo = $this->redis->get($prefix.$token);

        if (empty($userInfo)) {
            return false;
        }

        return true;
    }

    /**
     * 生成随机字符，排除易混淆的 0/O/1/I/l
     * @return string
     */
    private function randomCode(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code  = '';
        $max   = strlen($chars) - 1;

        for ($i = 0; $i < 6; $i++) {
            $code .= $chars[random_int(0, $max)];
        }

        return $code;
    }

    /**
     * 用 GD 绘制验证码，返回 PNG 二进制
     * @return array
     */
    public function render(): array
    {
        $code = $this->randomCode();
        $width  = 120;
        $height = 40;

        $img = imagecreatetruecolor($width, $height);

        // 浅色背景
        $bg = imagecolorallocate($img, 245, 245, 245);
        imagefill($img, 0, 0, $bg);

        // 干扰点
        for ($i = 0; $i < 100; $i++) {
            $color = imagecolorallocate($img, random_int(180, 240), random_int(180, 240), random_int(180, 240));
            imagesetpixel($img, random_int(0, $width), random_int(0, $height), $color);
        }

        // 干扰线
        for ($i = 0; $i < 3; $i++) {
            $color = imagecolorallocate($img, random_int(150, 220), random_int(150, 220), random_int(150, 220));
            imageline(
                $img,
                random_int(0, $width),
                random_int(0, $height),
                random_int(0, $width),
                random_int(0, $height),
                $color
            );
        }

        // 绘制字符，每个字符随机颜色和轻微偏移
        $charWidth = (int) (($width - 20) / strlen($code));
        for ($i = 0; $i < strlen($code); $i++) {
            $color = imagecolorallocate($img, random_int(0, 120), random_int(0, 120), random_int(0, 120));
            $x     = 10 + $i * $charWidth + random_int(-3, 3);
            $y     = random_int(8, 15);
            imagestring($img, 5, $x, $y, $code[$i], $color);
        }

        // 输出到缓冲区
        ob_start();
        imagepng($img);
        $data = ob_get_clean();

        return [
            'key' => $code,
            'img' => 'data:image/png;base64,' . base64_encode($data),
        ];
    }
}