<?php

declare(strict_types=1);

namespace App\Service\Api\Member;

use App\Constants\AuthCode;
use App\Constants\OrderCode;
use App\Controller\GuzzleController;
use App\Exception\BusinessException;
use App\Exception\VersionConflictException;
use App\Model\Member;
use App\Model\MemberOrderDetail;
use App\Model\MemberOrderMaster;
use App\Model\MemberPointsLog;
use App\Model\RegistrationCodeConfig;
use App\Model\RegistrationCodeLog;
use App\Service\LoggerService;
use App\Service\UtilsService;
use Hyperf\Database\Model\Collection;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Throwable;

use function Hyperf\Config\config;

class RegistrationCodeService
{
    /**
     * 乐观锁冲突最大重试次数
     */
    private const MAX_ATTEMPTS = 3;

    /**
     * 授权期限：一年期，取配置表 one_year
     */
    private const GRANT_TYPE_ONE_YEAR = 'one_year';

    /**
     * 授权期限：永久期，取配置表 lifetime
     */
    private const GRANT_TYPE_LIFETIME = 'lifetime';

    /**
     * 外部接口 products 项中标识授权期限的默认字段名
     */
    private const DEFAULT_DURATION_FIELD = 'duration';

    /**
     * 授权期限取值：一年期
     */
    private const DURATION_ONE_YEAR_VALUES = ['one_year', 'one-year', '1year', '1_year', 'year', 'annual', '一年', '一年期'];

    /**
     * 授权期限取值：永久期
     */
    private const DURATION_LIFETIME_VALUES = ['lifetime', 'life', 'permanent', 'forever', '永久', '永久期'];

    /**
     * 产品名包含该关键字时，按配置表中包含该关键字的套餐匹配（AuraGet -> AuraGet V1 大包）
     */
    private const AURAGET_KEYWORD = 'auraget';

    #[Inject]
    protected LoggerService $loggerService;

    #[Inject]
    protected UtilsService $utilsService;

    #[Inject]
    protected GuzzleController $guzzleController;

    /**
     * 校验本地模式注册码：校验通过后按套餐配置生成赠送积分订单
     * @param int $memberId
     * @param string $registrationCode
     * @return array
     */
    public function verify(int $memberId, string $registrationCode): array
    {
        $registrationCode = trim($registrationCode);
        $context = [
            'member_id' => $memberId,
            'registration_code' => $this->maskCode($registrationCode),
        ];

        if ($registrationCode === '') {
            throw new BusinessException(400, '注册码不能为空');
        }

        try {
            if ($this->isCodeRedeemed($memberId, $registrationCode)) {
                $this->loggerService->warning('校验本地模式注册码：该注册码已使用', $context);
                throw new BusinessException(400, '该注册码已使用，请勿重复提交');
            }

            // 同一会员同一注册码并发占位，避免重复点击重复发放积分（覆盖外部接口 10s 超时）
            $this->utilsService->acquireIdempotency(
                AuthCode::MEMBER_REGISTRATION_CODE_IDEMPOTENCY . $memberId . ':' . md5($registrationCode),
                30
            );

            $response = $this->requestRegQuery($registrationCode);

            $this->loggerService->info('本地模式注册码接口返回', array_merge($context, [
                'registered' => $response['registered'] ?? null,
                'products' => $response['products'] ?? null,
            ]));

            // 只有 registered 为 true 才继续发放
            if (!$this->isRegistered($response['registered'] ?? null)) {
                $this->loggerService->warning('校验本地模式注册码：注册码无效或未注册', $context);
                throw new BusinessException(400, '注册码无效或未注册');
            }

            $products = $response['products'] ?? [];
            if (!is_array($products)) {
                $this->loggerService->error('校验本地模式注册码：products 字段格式异常', array_merge($context, [
                    'products' => $products,
                ]));
                throw new BusinessException(500, '注册码校验服务返回数据格式异常');
            }

            $grants = $this->matchGrants($products, $context);
            if (count($grants) === 0) {
                $this->loggerService->warning('校验本地模式注册码：没有可赠送积分的套餐', $context);
                throw new BusinessException(400, '该注册码没有可赠送的积分套餐');
            }

            $result = $this->retryOnVersionConflict(
                fn () => $this->createGiftOrder($memberId, $registrationCode, $grants),
                $context
            );

            $this->loggerService->info('本地模式注册码赠送积分成功', array_merge($context, $result));

            return [
                'code' => 200,
                'status' => 200,
                'data' => $result,
                'msg' => 'Success',
            ];
        } catch (BusinessException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->loggerService->error('校验本地模式注册码失败', array_merge($context, [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]));
            throw new BusinessException(500, '注册码校验失败，请稍后重试');
        }
    }

    /**
     * 注册码是否已被该会员兑换过
     * @param int $memberId
     * @param string $registrationCode
     * @return bool
     */
    private function isCodeRedeemed(int $memberId, string $registrationCode): bool
    {
        return RegistrationCodeLog::query()
            ->where('member_id', $memberId)
            ->where('registration_code', $registrationCode)
            ->exists();
    }

    /**
     * 调用外部 regquery.php 校验注册码
     * @param string $registrationCode
     * @return array
     */
    private function requestRegQuery(string $registrationCode): array
    {
        $url = trim((string) config('reg_query.url', ''));

        if ($url === '') {
            $this->loggerService->error('校验本地模式注册码：未配置 regquery 接口地址', [
                'env' => 'REG_QUERY_URL',
            ]);
            throw new BusinessException(500, '注册码校验服务未配置，请联系管理员');
        }

        // GuzzleController 内部已做超时、异常捕获与日志记录；失败会抛出异常
        $response = $this->guzzleController->request(
            'POST',
            $url,
            [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            json_encode(['code' => $registrationCode], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        // 兼容接口未按 application/json 返回的情况：GuzzleController 会把原始 body 包在数组里
        if (!array_key_exists('registered', $response) && isset($response['body']) && is_string($response['body'])) {
            $decoded = json_decode($response['body'], true);
            if (is_array($decoded)) {
                $response = $decoded;
            }
        }

        if (!array_key_exists('registered', $response)) {
            $this->loggerService->error('校验本地模式注册码：接口返回缺少 registered 字段', [
                'url' => $url,
                'response' => $response,
            ]);
            throw new BusinessException(500, '注册码校验服务返回数据格式异常');
        }

        return $response;
    }

    /**
     * 判断外部接口返回的 registered 是否为 true（兼容 true / "true" / 1 / "1"）
     * @param mixed $registered
     * @return bool
     */
    private function isRegistered(mixed $registered): bool
    {
        if (is_bool($registered)) {
            return $registered;
        }

        if ($registered === null || is_array($registered) || is_object($registered)) {
            return false;
        }

        return filter_var($registered, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }

    /**
     * 把接口返回的 products 匹配到套餐配置，并换算成可发放的积分明细
     * @param array $products
     * @param array $context 仅用于日志
     * @return array<int, array{package_name: string, grant_type: string, change_points: string, expiration_date: ?string}>
     */
    private function matchGrants(array $products, array $context): array
    {
        $configs = RegistrationCodeConfig::query()->get();

        if ($configs->isEmpty()) {
            $this->loggerService->error('校验本地模式注册码：套餐配置为空', $context);
            throw new BusinessException(500, '注册码套餐配置不存在');
        }

        $grants = [];
        $matchedKeys = [];

        foreach ($products as $index => $product) {
            // 兼容 products 直接是产品名字符串的返回格式
            if (is_string($product)) {
                $product = ['name' => $product];
            }

            if (!is_array($product)) {
                $this->loggerService->warning('校验本地模式注册码：products 项格式异常', array_merge($context, [
                    'product' => $product,
                ]));
                continue;
            }

            $productName = trim((string) ($product['name'] ?? ''));

            // 兼容以产品名为键的返回格式：{"AuraGet": {...}}
            if ($productName === '' && is_string($index)) {
                $productName = trim($index);
            }

            if ($productName === '') {
                $this->loggerService->warning('校验本地模式注册码：products 项缺少 name', array_merge($context, [
                    'product' => $product,
                ]));
                continue;
            }

            $config = $this->matchConfig($configs, $productName);
            if ($config === null) {
                $this->loggerService->warning('校验本地模式注册码：产品未匹配到套餐配置', array_merge($context, [
                    'product_name' => $productName,
                ]));
                continue;
            }

            $grantType = $this->resolveGrantType($product, $config);
            if ($grantType === null) {
                $this->loggerService->warning('校验本地模式注册码：授权期限无法识别', array_merge($context, [
                    'product_name' => $productName,
                    'product' => $product,
                ]));
                continue;
            }

            $changePoints = round(
                $grantType === self::GRANT_TYPE_ONE_YEAR ? (float) $config->one_year : (float) $config->lifetime,
                OrderCode::VIDEO_CREDITS_PRECISION
            );

            if ($changePoints <= 0) {
                // 免费工具等套餐赠送积分为 0，不生成订单明细
                $this->loggerService->info('校验本地模式注册码：套餐无赠送积分，跳过', array_merge($context, [
                    'product_name' => $productName,
                    'grant_type' => $grantType,
                ]));
                continue;
            }

            // 同一套餐同一期限只发放一次
            $matchedKey = $config->package_id . ':' . $grantType;
            if (isset($matchedKeys[$matchedKey])) {
                continue;
            }
            $matchedKeys[$matchedKey] = true;

            $grants[] = [
                'package_name' => (string) $config->package_name,
                'grant_type' => $grantType,
                'change_points' => number_format($changePoints, 2, '.', ''),
                // 一年期积分 365 天后过期，永久期积分不设过期时间
                'expiration_date' => $grantType === self::GRANT_TYPE_ONE_YEAR
                    ? date('Y-m-d H:i:s', strtotime('+' . OrderCode::POINTS_EXPIRE_DAYS . ' days'))
                    : null,
            ];
        }

        return $grants;
    }

    /**
     * 产品名匹配套餐配置：优先 package_name 精确匹配（忽略大小写），
     * 其次产品名含 AuraGet 时匹配 package_name 含该关键字的套餐
     * @param Collection $configs
     * @param string $productName
     * @return RegistrationCodeConfig|null
     */
    private function matchConfig(Collection $configs, string $productName): ?RegistrationCodeConfig
    {
        $productNameLower = mb_strtolower($productName, 'UTF-8');

        foreach ($configs as $config) {
            if (mb_strtolower(trim((string) $config->package_name), 'UTF-8') === $productNameLower) {
                return $config;
            }
        }

        if (mb_strpos($productNameLower, self::AURAGET_KEYWORD) !== false) {
            foreach ($configs as $config) {
                if (mb_strpos(mb_strtolower((string) $config->package_name, 'UTF-8'), self::AURAGET_KEYWORD) !== false) {
                    return $config;
                }
            }
        }

        return null;
    }

    /**
     * 解析产品授权期限，决定取配置表的 one_year 还是 lifetime
     * @param array $product
     * @param RegistrationCodeConfig $config
     * @return string|null 无法识别时返回 null
     */
    private function resolveGrantType(array $product, RegistrationCodeConfig $config): ?string
    {
        $durationField = (string) config('reg_query.duration_field', self::DEFAULT_DURATION_FIELD);
        $value = $product[$durationField] ?? null;

        // 配置的字段名取不到时，兼容接口里常见的期限字段名
        if ($value === null) {
            foreach (['duration', 'license_type', 'type', 'period', 'validity'] as $field) {
                if (array_key_exists($field, $product)) {
                    $value = $product[$field];
                    break;
                }
            }
        }

        $grantType = $this->normalizeGrantType($value);
        if ($grantType !== null) {
            return $grantType;
        }

        // 期限缺失或无法识别时，仅当配置表只有一列有积分才兜底，避免发错期限的积分
        $oneYearPoints = (float) $config->one_year;
        $lifetimePoints = (float) $config->lifetime;

        if ($oneYearPoints > 0 && $lifetimePoints <= 0) {
            return self::GRANT_TYPE_ONE_YEAR;
        }

        if ($lifetimePoints > 0 && $oneYearPoints <= 0) {
            return self::GRANT_TYPE_LIFETIME;
        }

        return null;
    }

    /**
     * 把接口返回的期限取值归一化为 one_year / lifetime
     * @param mixed $value
     * @return string|null
     */
    private function normalizeGrantType(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value) || is_object($value)) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return $value > 0 ? self::GRANT_TYPE_ONE_YEAR : self::GRANT_TYPE_LIFETIME;
        }

        $normalized = mb_strtolower(trim((string) $value), 'UTF-8');

        if (in_array($normalized, self::DURATION_ONE_YEAR_VALUES, true)) {
            return self::GRANT_TYPE_ONE_YEAR;
        }

        if (in_array($normalized, self::DURATION_LIFETIME_VALUES, true)) {
            return self::GRANT_TYPE_LIFETIME;
        }

        if (is_numeric($normalized)) {
            return (float) $normalized > 0 ? self::GRANT_TYPE_ONE_YEAR : self::GRANT_TYPE_LIFETIME;
        }

        return null;
    }

    /**
     * 生成赠送订单：主表一条、明细按套餐多条、积分流水一条（与主表一对一），最后更新会员积分
     * @param int $memberId
     * @param string $registrationCode
     * @param array $grants
     * @return array
     */
    private function createGiftOrder(int $memberId, string $registrationCode, array $grants): array
    {
        return Db::transaction(function () use ($memberId, $registrationCode, $grants) {
            $member = Member::query()
                ->select('member_id', 'points', 'version')
                ->where('member_id', $memberId)
                ->lockForUpdate()
                ->first();

            if ($member === null) {
                $this->loggerService->warning('本地模式注册码赠送积分：会员不存在', [
                    'member_id' => $memberId,
                ]);
                throw new BusinessException(400, '会员不存在');
            }

            // 事务内再校验一次兑换记录，防止并发请求在外部接口校验期间重复发放
            if ($this->isCodeRedeemed($memberId, $registrationCode)) {
                $this->loggerService->warning('本地模式注册码赠送积分：该注册码已使用', [
                    'member_id' => $memberId,
                ]);
                throw new BusinessException(400, '该注册码已使用，请勿重复提交');
            }

            $beforePoints = (float) $member->points;
            $totalPoints = 0.0;
            $detailRows = [];

            foreach ($grants as $grant) {
                $changePoints = (float) $grant['change_points'];
                $totalPoints = round($totalPoints + $changePoints, OrderCode::VIDEO_CREDITS_PRECISION);
                $detailRows[] = [
                    'member_id' => $memberId,
                    'status' => OrderCode::DETAIL_STATUS_ACTIVE,
                    'change_points' => number_format($changePoints, 2, '.', ''),
                    'expiration_date' => $grant['expiration_date'],
                    'version' => 0,
                ];
            }

            if ($totalPoints <= 0 || count($detailRows) === 0) {
                throw new BusinessException(400, '该注册码没有可赠送的积分套餐');
            }

            $afterPoints = round($beforePoints + $totalPoints, OrderCode::VIDEO_CREDITS_PRECISION);
            $changePoints = number_format($totalPoints, 2, '.', '');
            // 赠送订单号以 G 开头，区别于用户下单与管理员调整（A 开头）
            $orderNo = 'G' . $this->utilsService->generateNo();

            // 1. 订单主表：无需支付
            $orderMasterId = (int) MemberOrderMaster::create([
                'order_no' => $orderNo,
                'member_id' => $memberId,
                'payment_status' => OrderCode::PAYMENT_STATUS_NONE,
                'flag' => OrderCode::FLAG_INCREASE,
                'change_type' => OrderCode::CHANGE_TYPE_GIFT,
                'change_points' => $changePoints,
                'price' => '0.00',
                'version' => 0,
            ])->getKey();

            // 2. 订单明细：对应 unicd_registration_code_config 中命中的赠送积分配置
            MemberOrderDetail::insert(array_map(
                fn (array $row): array => array_merge($row, ['order_master_id' => $orderMasterId]),
                $detailRows
            ));

            // 3. 积分流水：与订单主表一对一
            MemberPointsLog::create([
                'member_id' => $memberId,
                'order_id' => $orderMasterId,
                'order_no' => $orderNo,
                'points_task_id' => null,
                'points_task_no' => '',
                'flag' => OrderCode::FLAG_INCREASE,
                'change_type' => OrderCode::CHANGE_TYPE_GIFT,
                'change_points' => $changePoints,
                'before_points' => number_format($beforePoints, 2, '.', ''),
                'after_points' => number_format($afterPoints, 2, '.', ''),
                'description' => $this->buildGiftDescription($registrationCode, $grants, $totalPoints),
            ]);

            // 4. 本地注册码兑换记录：作为「注册码已使用」的标记，与订单在同一事务内落库
            RegistrationCodeLog::create([
                'registration_code' => $registrationCode,
                'member_id' => $memberId,
                'order_master_id' => $orderMasterId,
                'order_no' => $orderNo,
            ]);

            // 5. 会员积分：按读取时的版本做乐观锁，冲突则整笔回滚后重试
            $updated = Member::query(true)
                ->where('member_id', $memberId)
                ->where('version', (int) $member->version)
                ->update([
                    'points' => number_format($afterPoints, 2, '.', ''),
                    'version' => (int) $member->version + 1,
                ]);

            if ($updated === 0) {
                $this->loggerService->warning('本地模式注册码赠送积分：会员版本冲突', [
                    'member_id' => $memberId,
                    'version' => (int) $member->version,
                ]);
                throw new VersionConflictException('member_id=' . $memberId);
            }

            return [
                'order_no' => $orderNo,
                'order_master_id' => $orderMasterId,
                'change_points' => $changePoints,
                'before_points' => number_format($beforePoints, 2, '.', ''),
                'after_points' => number_format($afterPoints, 2, '.', ''),
                'details' => array_map(
                    fn (array $grant): array => [
                        'package_name' => $grant['package_name'],
                        'grant_type' => $grant['grant_type'],
                        'change_points' => $grant['change_points'],
                        'expiration_date' => $grant['expiration_date'],
                    ],
                    $grants
                ),
            ];
        });
    }

    /**
     * 组装积分流水描述
     * @param string $registrationCode
     * @param array $grants
     * @param float $totalPoints
     * @return string
     */
    private function buildGiftDescription(string $registrationCode, array $grants, float $totalPoints): string
    {
        $packages = array_map(
            fn (array $grant): string => sprintf(
                '%s（%s）%s积分',
                $grant['package_name'],
                $grant['grant_type'] === self::GRANT_TYPE_ONE_YEAR ? '一年期' : '永久期',
                $grant['change_points']
            ),
            $grants
        );

        $description = sprintf(
            '本地模式注册码%s赠送积分：%s，合计%s积分',
            $this->maskCode($registrationCode),
            implode('；', $packages),
            number_format($totalPoints, 2, '.', '')
        );

        return mb_substr($description, 0, 255, 'UTF-8');
    }

    /**
     * 乐观锁冲突重试：抛出 VersionConflictException 时整笔事务回滚并重试
     * @param callable $callback
     * @param array $context 仅用于日志
     * @return mixed
     */
    private function retryOnVersionConflict(callable $callback, array $context): mixed
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; ++$attempt) {
            try {
                return $callback();
            } catch (VersionConflictException $e) {
                $this->loggerService->warning('本地模式注册码赠送积分：版本冲突，重试', array_merge($context, [
                    'attempt' => $attempt,
                    'max_attempts' => self::MAX_ATTEMPTS,
                    'conflict' => $e->getMessage(),
                ]));
            }
        }

        throw new BusinessException(409, '会员积分正在被其他操作修改，请稍后重试');
    }

    /**
     * 日志脱敏：保留首尾各 2 位，中间打码
     * @param string $registrationCode
     * @return string
     */
    private function maskCode(string $registrationCode): string
    {
        $length = mb_strlen($registrationCode, 'UTF-8');

        if ($length <= 4) {
            return str_repeat('*', max(1, $length));
        }

        return mb_substr($registrationCode, 0, 2, 'UTF-8')
            . str_repeat('*', 4)
            . mb_substr($registrationCode, -2, null, 'UTF-8');
    }
}
