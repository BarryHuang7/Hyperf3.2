<?php

declare(strict_types=1);

namespace App\Service\Api\Notice;

use App\Exception\BusinessException;
use App\Service\LoggerService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Redis\Redis;
use App\Amqp\Producers\MemberNoticeProducer;
use Hyperf\Amqp\Producer;
use Throwable;

class EmailService
{
    #[Inject]
    protected Redis $redis;

    #[Inject]
    protected LoggerService $loggerService;

    #[Inject]
    private Producer $producer;

    /**
     * 发送邮箱通知
     * @param string $email
     * @param string $html
     * @param string $subject
     * @return array
     */
    public function sendEmail(string $email, string $html, string $subject): array
    {
        try {
            $email = strtolower(trim($email));

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new BusinessException(400, '无效的邮箱地址');
            }

            $message = new MemberNoticeProducer([
                'email' => $email,
                'html' => $html,
                'subject' => $subject,
            ]);
            $this->producer->produce($message);

            return [
                'code' => 200,
                'status' => 200,
                'data' => null,
                'msg' => '邮箱发送成功，请注意查收',
            ];
        } catch (BusinessException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->loggerService->error('发送邮箱失败', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
            throw new BusinessException(500, '发送邮箱失败，请稍后重试');
        }
    }
}
