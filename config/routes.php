<?php

declare(strict_types=1);

use Hyperf\HttpServer\Router\Router;

/**
 * php bin/hyperf.php gen:controller 控制器名Controller
 * php bin/hyperf.php gen:model 数据表名
 */

// Router::addRoute(['GET', 'POST', 'HEAD'], '/', 'App\Controller\IndexController@index');

Router::addGroup('/api', function () {
    // 注册
    Router::post('/register', 'App\Controller\Api\Member\AuthController@register');
    // 登录
    Router::post('/login', 'App\Controller\Api\Member\AuthController@login');
    // 获取验证码
    Router::get('/verification-code', 'App\Controller\Api\Member\AuthController@getVerificationCode');
    // 登出
    Router::post('/logout', 'App\Controller\Api\Member\AuthController@logout');
    // 刷新Token
    Router::post('/refresh-token', 'App\Controller\Api\Member\AuthController@refreshToken');
    // 发送注册邮箱验证码
    Router::post('/send-email-verification-code', 'App\Controller\Api\Member\AuthController@sendEmailVerificationCode');
    // 开启Google 2FA
    Router::post('/enable-google2fa', 'App\Controller\Api\Member\AuthController@enableGoogle2fa');
    // 绑定Google 2FA
    Router::post('/confirm-google2fa', 'App\Controller\Api\Member\AuthController@confirmGoogle2fa');
    // 重置密码
    Router::post('/reset-password', 'App\Controller\Api\Member\AuthController@resetPassword');
    // 创建Stripe结账会话
    Router::post('/create-checkout-session', 'App\Controller\Api\Stripe\StripeController@createCheckoutSession');
    // Stripe支付回调
    Router::post('/stripe/webhook', 'App\Controller\Api\WebHook\StripeController@handle');
    // 获取套餐列表
    Router::get('/get-plan-list', 'App\Controller\Api\Stripe\StripeController@getPlanList');
    // 需求废弃：检查订单退款条件
    // Router::get('/check-refund', 'App\Controller\Api\Stripe\StripeController@checkRefund');
    // 需求废弃：会员申请退款
    // Router::post('/apply-refund', 'App\Controller\Api\Stripe\StripeController@applyRefund');
    // 获取积分订单充值列表
    Router::post('/get-order-list', 'App\Controller\Api\Member\OrderController@getOrderList');
    // 获取积分订单详情
    Router::get('/get-order-detail', 'App\Controller\Api\Member\OrderController@getOrderDetail');
    // 消耗积分
    Router::post('/consumption-points', 'App\Controller\Api\Member\OrderController@consumptionPoints');
    // 获取积分消耗任务记录列表
    Router::post('/get-points-task-list', 'App\Controller\Api\Member\PointsTaskController@getPointsTaskList');
    // 获取积分消耗任务对应订单记录
    Router::get('/get-points-task-order', 'App\Controller\Api\Member\PointsTaskController@getPointsTaskOrder');
    // 获取会员积分和即将过期积分
    Router::get('/get-member-points', 'App\Controller\Api\Member\MemberController@getPoints');
    // 校验本地模式注册码
    Router::post('/verify-registration-code', 'App\Controller\Api\Member\MemberController@verifyRegistrationCode');
    // 更新积分任务平台信息
    Router::put('/update-points-task', 'App\Controller\Api\Member\PointsTaskController@updatePointsTaskPlatformInfo');
    // AssemblyAI任务回调
    Router::post('/assemblyai/webhook', 'App\Controller\Api\WebHook\AssemblyAIController@handle');
    // WaveSpeedAI任务回调
    Router::post('/wavespeedai/webhook', 'App\Controller\Api\WebHook\WaveSpeedAIController@handle');
    // TencentCloudCI任务回调
    Router::post('/tencentcloudci/webhook', 'App\Controller\Api\WebHook\TencentCloudCIController@handle');
    // Base64积分任务编码
    Router::post('/base64-points-task-no', 'App\Controller\Api\Member\PointsTaskController@encodePointsTaskNo');
    // VolcengineAIMediaKit任务回调
    Router::post('/volcengineai/webhook', 'App\Controller\Api\WebHook\VolcengineAIMediaKitController@handle');
});

Router::addGroup('/admin', function () {
    // 注册
    Router::post('/register', 'App\Controller\Admin\User\AuthController@register');
    // 登录
    Router::post('/login', 'App\Controller\Admin\User\AuthController@login');
    // 登出
    Router::post('/logout', 'App\Controller\Admin\User\AuthController@logout');
    // 会员积分管理列表
    Router::post('/get-member-points-list', 'App\Controller\Admin\Member\PointsController@getMemberPointsList');
    // 会员积分详情
    Router::post('/get-member-points-detail', 'App\Controller\Admin\Member\PointsController@getMemberPointsDetail');
    // 会员积分流水
    Router::post('/get-member-points-log-list', 'App\Controller\Admin\Member\PointsController@getMemberPointsLogList');
    // 会员积分增减
    Router::post('/adjust-member-points', 'App\Controller\Admin\Member\PointsController@adjustMemberPoints');
    // 积分消耗统计
    Router::get('/get-member-points-statistics', 'App\Controller\Admin\Member\PointsController@getMemberPointsStatistics');
    // 需求废弃：Stripe退款
    // Router::post('/refund', 'App\Controller\Admin\Stripe\StripeController@refund');
});

Router::addServer('ws', function () {
    Router::get('/websocket', 'App\Controller\WS\WebSocketController');
});
