<?php

declare(strict_types=1);

namespace App\Exception\Handler;

use App\Exception\BusinessException;
use App\Service\CorsService;
use Hyperf\Validation\ValidationException;
use Hyperf\HttpMessage\Exception\HttpException;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ServerRequestInterface;
use Hyperf\Context\Context;
use Psr\Http\Message\ResponseInterface;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;
use Throwable;

class AppExceptionHandler extends ExceptionHandler
{
    private LoggerInterface $infoLogger;
    private LoggerInterface $errorLogger;

    public function __construct(LoggerFactory $loggerFactory)
    {
        $this->infoLogger = $loggerFactory->get('default', 'info');
        $this->errorLogger = $loggerFactory->get('default', 'error');
    }

    public function handle(Throwable $throwable, ResponseInterface $response): ResponseInterface
    {
        // 验证器
        if ($throwable instanceof ValidationException) {
            $code = 422;
            $message = '请求参数验证失败';
            $errors = $throwable->validator->errors()->getMessages();

            $request = Context::get(ServerRequestInterface::class);
            $path = $request ? $request->getUri()->getPath() : 'unknown';

            $this->infoLogger->info('path: '.$path.', '.$message.': '.json_encode($errors, JSON_UNESCAPED_UNICODE));
        }
        // 业务异常
        else if ($throwable instanceof BusinessException) {
            $code = $throwable->getCode() ?: 400;
            $message = $throwable->getMessage();
            $errors = null;
        }
        // http异常
        else if ($throwable instanceof HttpException) {
            $code = $throwable->getStatusCode() ?: 500;
            $message = $throwable->getMessage() ?: 'HTTP异常';
            $errors = null;
        }
        else {
            $code = 500;
            $message = '服务器内部错误';
            $errors = null;

            // 记录错误日志
            $this->errorLogger->error(sprintf('%s[%s] in %s', $throwable->getMessage(), $throwable->getLine(), $throwable->getFile()));
            $this->errorLogger->error($throwable->getTraceAsString());
        }

        $data = json_encode([
            'code' => $code,
            'data' => '',
            'msg' => $message,
            'error' => $errors,
        ], JSON_UNESCAPED_UNICODE);

        $this->stopPropagation();

        return CorsService::addHeaders(
            $response
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                ->withStatus($code)
                ->withBody(new SwooleStream($data))
        );
    }

    // 声明该处理器能处理所有异常类型
    public function isValid(Throwable $throwable): bool
    {
        return true;
    }
}
