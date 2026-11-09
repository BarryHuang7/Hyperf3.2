<?php

declare(strict_types=1);

namespace App\Constants;

use Hyperf\Constants\AbstractConstants;
use Hyperf\Constants\Annotation\Constants;

#[Constants]
class OrderCode extends AbstractConstants
{
    /**
     * 支付状态：无需支付
     */
    public const PAYMENT_STATUS_NONE = 0;

    /**
     * 支付状态：待支付
     */
    public const PAYMENT_STATUS_PENDING = 1;

    /**
     * 支付状态：已支付完成
     */
    public const PAYMENT_STATUS_PAID = 2;

    /**
     * 支付状态：支付失败
     */
    public const PAYMENT_STATUS_FAILED = 3;

    /**
     * 支付状态：支付超时
     */
    public const PAYMENT_STATUS_TIMEOUT = 4;

    /**
     * 积分变动方向：减少
     */
    public const FLAG_DECREASE = 0;

    /**
     * 积分变动方向：新增
     */
    public const FLAG_INCREASE = 1;

    /**
     * 积分流水类型：充值
     */
    public const CHANGE_TYPE_RECHARGE = 1;

    /**
     * 积分流水类型：消费
     */
    public const CHANGE_TYPE_CONSUME = 2;

    /**
     * 积分流水类型：过期
     */
    public const CHANGE_TYPE_EXPIRE = 3;

    /**
     * 积分流水类型：管理员调整
     */
    public const CHANGE_TYPE_ADMIN_ADJUST = 4;

    /**
     * 积分流水类型：本地模式注册赠送
     */
    public const CHANGE_TYPE_GIFT = 5;

    /**
     * 积分流水类型：任务失败返还
     */
    public const CHANGE_TYPE_REFUND = 6;

    /**
     * 积分流水类型：订单退款
     */
    public const CHANGE_TYPE_ORDER_REFUND = 7;

    /**
     * 退款状态：默认
     */
    public const REFUND_STATUS_DEFAULT = 0;

    /**
     * 退款状态：退款成功
     */
    public const REFUND_STATUS_SUCCESS = 1;

    /**
     * 退款状态：退款申请通过（执行退款中）
     */
    public const REFUND_STATUS_APPROVED = 2;

    /**
     * 退款状态：退款申请拒绝
     */
    public const REFUND_STATUS_REJECTED = 3;

    /**
     * 退款状态：申请退款
     */
    public const REFUND_STATUS_APPLIED = 4;

    /**
     * 退款状态：退款失败
     */
    public const REFUND_STATUS_FAILED = 5;

    /**
     * 退款申请有效天数：支付完成后 N 天内可申请
     */
    public const REFUND_WINDOW_DAYS = 14;

    /**
     * 退款条件 Redis 缓存键前缀
     */
    public const REFUND_ELIGIBILITY_CACHE_KEY_PREFIX = 'member:order_refund:eligibility:';

    /**
     * 退款条件 Redis 缓存有效期（秒）：10 分钟
     */
    public const REFUND_ELIGIBILITY_CACHE_TTL = 600;

    /**
     * 详情表积分状态：未激活
     */
    public const DETAIL_STATUS_NOT_ACTIVE = 0;

    /**
     * 详情表积分状态：激活
     */
    public const DETAIL_STATUS_ACTIVE = 1;

    /**
     * 详情表积分状态：已消耗完
     */
    public const DETAIL_STATUS_USED = 2;

    /**
     * 详情表积分状态：已过期
     */
    public const DETAIL_STATUS_EXPIRED = 3;

    /**
     * 充值积分有效天数：下单后 N 天统一过期
     */
    public const POINTS_EXPIRE_DAYS = 365;

    /**
     * 会员积分统计 Redis 缓存键
     */
    public const MEMBER_POINTS_STATISTICS_CACHE_KEY = 'admin:member_points_statistics';

    /**
     * 会员积分统计 Redis 缓存有效期（秒）：10 分钟
     */
    public const MEMBER_POINTS_STATISTICS_CACHE_TTL = 600;

    /**
     * 订单超时时间（秒）
     */
    public const ORDER_TIMEOUT = 1800;

    /**
     * 视频功能类型：AI 字幕生成
     */
    public const VIDEO_FEATURE_TYPE_SUBTITLE = 1;

    /**
     * 视频功能类型：视频超分放大
     */
    public const VIDEO_FEATURE_TYPE_UPSCALER = 2;

    /**
     * 视频功能类型：SDR 转 HDR 增强
     */
    public const VIDEO_FEATURE_TYPE_HDR = 3;

    /**
     * 视频功能类型：视频 AI 上色
     */
    public const VIDEO_FEATURE_TYPE_COLORIZER = 4;

    /**
     * 视频功能类型：跨语言视频翻译
     */
    public const VIDEO_FEATURE_TYPE_TRANSLATOR = 5;

    /**
     * 积分任务状态：默认
     */
    public const TASK_STATUS_DEFAULT = 0;

    /**
     * 积分任务状态：成功
     */
    public const TASK_STATUS_SUCCESS = 1;

    /**
     * 积分任务状态：进行中
     */
    public const TASK_STATUS_PROCESSING = 2;

    /**
     * 积分任务状态：未开始
     */
    public const TASK_STATUS_NOT_STARTED = 3;

    /**
     * 积分任务状态：失败
     */
    public const TASK_STATUS_FAILED = 4;

    /**
     * 积分任务状态：用户取消
     */
    public const TASK_STATUS_USER_CANCEL = 5;

    /**
     * 积分任务的 AI 平台类型：默认
     */
    public const TASK_PLATFORM_TYPE_DEFAULT = 0;

    /**
     * 积分任务的 AI 平台类型：AssemblyAI
     */
    public const TASK_PLATFORM_TYPE_ASSEMBLYAI = 1;

    /**
     * 积分任务的 AI 平台类型：WaveSpeed
     */
    public const TASK_PLATFORM_TYPE_WAVESPEED = 2;

    /**
     * 积分任务的 AI 平台类型：火山引擎
     */
    public const TASK_PLATFORM_TYPE_VOLCENGINE = 3;

    /**
     * 积分任务的 AI 平台类型：腾讯云
     */
    public const TASK_PLATFORM_TYPE_TENCENT = 4;

    /**
     * 分辨率判定：高清（1080p 及以下）的宽度上限
     */
    public const VIDEO_RESOLUTION_HD_WIDTH = 1920;

    /**
     * 分辨率判定：高清（1080p 及以下）的高度上限
     */
    public const VIDEO_RESOLUTION_HD_HEIGHT = 1080;

    /**
     * 分辨率判定：4K 超清宽高阈值（宽或高超过该值按 4K 计）
     */
    public const VIDEO_RESOLUTION_4K_THRESHOLD = 2560;

    /**
     * 帧率判定：常规帧率上限
     */
    public const VIDEO_FPS_30 = 30;

    /**
     * 帧率判定：中帧率上限
     */
    public const VIDEO_FPS_45 = 45;

    /**
     * 帧率判定：高帧率上限
     */
    public const VIDEO_FPS_60 = 60;

    /**
     * 帧率判定：超高帧率上限
     */
    public const VIDEO_FPS_90 = 90;

    /**
     * 计费默认视频参数：宽度
     */
    public const VIDEO_DEFAULT_WIDTH = 1920;

    /**
     * 计费默认视频参数：高度
     */
    public const VIDEO_DEFAULT_HEIGHT = 1080;

    /**
     * 计费默认视频参数：帧率
     */
    public const VIDEO_DEFAULT_FPS = 30;

    /**
     * 计费积分保留小数位
     */
    public const VIDEO_CREDITS_PRECISION = 2;

    /**
     * 单次任务最低兜底扣费（计费模块未配置该值时使用）
     */
    public const VIDEO_MIN_CHARGE = 0.5;
}
