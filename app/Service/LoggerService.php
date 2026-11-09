<?php

declare(strict_types=1);

namespace App\Service;

use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

class LoggerService
{
    private LoggerInterface $infoLogger;
    private LoggerInterface $wsinfoLogger;
    private LoggerInterface $errorLogger;
    private LoggerInterface $warningLogger;

    public function __construct(LoggerFactory $loggerFactory)
    {
        $this->infoLogger = $loggerFactory->get('default', 'info');
        $this->wsinfoLogger = $loggerFactory->get('default', 'wsinfo');
        $this->errorLogger = $loggerFactory->get('default', 'error');
        $this->warningLogger = $loggerFactory->get('default', 'default');
    }

    /**
     * 记录 Info 级别日志
     * @param string $message
     * @param array $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->infoLogger->info($message, $context);
    }

    /**
     * 记录 WebSocket Info 级别日志
     * @param string $message
     * @param array $context
     */
    public function wsinfo(string $message, array $context = []): void
    {
        $this->wsinfoLogger->info($message, $context);
    }

    /**
     * 记录 Warning 级别日志
     * @param string $message
     * @param array $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->warningLogger->warning($message, $context);
    }

    /**
     * 记录 Error 级别日志
     * @param string $message
     * @param array $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->errorLogger->error($message, $context);
    }
}
