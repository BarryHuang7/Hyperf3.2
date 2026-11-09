<?php

declare(strict_types=1);

namespace App\Job\Api;

use Hyperf\AsyncQueue\Job;
use FriendsOfHyperf\Mail\Message;
use FriendsOfHyperf\Mail\Facade\Mail;
use Hyperf\Context\ApplicationContext;
use App\Service\LoggerService;

class SendEmailJob extends Job
{
    // 最大重试次数（实际执行次数 = 此值 + 1）
    protected int $maxAttempts = 2;

    // Job 里只放可序列化的普通数据，不要放容器、PDO 等对象
    public string $email;
    public string $html;
    public string $subject;

    public function __construct(string $email, string $html, string $subject)
    {
        $this->email = $email;
        $this->html = $html;
        $this->subject = $subject;
    }

    public function handle(): void
    {
        $container = ApplicationContext::getContainer();
        $loggerService = $container->get(LoggerService::class);

        $email = $this->email;
        $subject = $this->subject;

        try {
            Mail::html($this->html, function (Message $message) use ($email, $subject) {
                $message->to($email)->subject($subject);
            });

            $loggerService->info('异步队列-邮件发送-成功', ['email' => $email]);
        } catch (\Throwable $e) {
            $recordArray = [
                'email' => $email,
                'error' => $e->getMessage(),
            ];
            $loggerService->error('异步队列-邮件发送-失败', $recordArray);
            $loggerService->info('异步队列-邮件发送-重试', $recordArray);
            throw $e;
        }
    }
}
