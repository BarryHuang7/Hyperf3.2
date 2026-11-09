<?php

declare(strict_types=1);

namespace App\Controller\Api\WebHook;

use Hyperf\Amqp\Producer;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use App\Service\UtilsService;
use App\Service\LoggerService;
use App\Amqp\Producers\MemberPointsProducer;

class VolcengineAIMediaKitController
{
    #[Inject]
    protected LoggerService $loggerService;

    #[Inject]
    protected UtilsService $utilsService;

    #[Inject]
    private Producer $producer;

    public function handle(RequestInterface $request, ResponseInterface $response)
    {
        $payload = $request->getBody()->getContents();

        if (empty($payload)) {
            $this->loggerService->error('VolcengineAIMediaKit Webhook Payload is empty', ['payload' => $payload]);
            return $response->raw('Bad Request')->withStatus(400);
        }

        $payload = json_decode($payload, true);

        if (
            !is_array($payload)
            || empty($payload['Data']['CallbackArgs'])
            || empty($payload['Data']['Code'])
            || empty($payload['TaskId'])
        ) {
            $this->loggerService->error('VolcengineAIMediaKit Webhook Payload is not a valid JSON array', ['payload' => $payload]);
            return $response->raw('Bad Request')->withStatus(400);
        }

        $callbackArgsBase64 = $payload['Data']['CallbackArgs'] ?? null;
        $callbackArgsJson = base64_decode($callbackArgsBase64, true);

        if ($callbackArgsJson === false) {
            $this->loggerService->error('VolcengineAIMediaKit Webhook CallbackArgs is empty', ['payload' => $payload, 'callback_args' => $callbackArgsBase64]);
            return $response->raw('Bad Request')->withStatus(400);
        }

        $callbackArgs = json_decode($callbackArgsJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->loggerService->error('VolcengineAIMediaKit Webhook CallbackArgs is not valid JSON', ['payload' => $payload, 'callback_args' => $callbackArgsJson]);
            return $response->raw('Bad Request')->withStatus(400);
        }

        /**
         * 调用接口时 https://mediakit.cn-beijing.volces.com/api/v1/tools/enhance-video-fast
         * 请求参数 { 
         *  "callback_url": "{host}/api/volcengineai/webhook",
         *  "callback_args": "请求/api/base64-points-task-no返回的userData字段"
         */
        $token = $callbackArgs['token'] ?? null;
        $pointsTaskNo = $callbackArgs['points_task_no'] ?? null;

        $tokenValid = $this->utilsService->verificationToken($token);

        if (!$tokenValid || empty($pointsTaskNo)) {
            $this->loggerService->error('VolcengineAIMediaKit Webhook Token or Points Task No is empty', [
                'payload' => $payload,
                'token' => $token,
                'points_task_no' => $pointsTaskNo
            ]);
            return $response->raw('Bad Request')->withStatus(400);
        }

        $status = $payload['Data']['Code'];
        $taskId = $payload['TaskId'];

        $context = [
            'payload' => $payload,
            'token' => $token,
            'points_task_no' => $pointsTaskNo,
            'status' => $status,
            'task_id' => $taskId,
        ];

        $this->loggerService->info('VolcengineAIMediaKit Webhook Payload: ', $context);

        try {
            $message = new MemberPointsProducer([
                'points_task_no' => $pointsTaskNo,
                'status' => $status,
                'platform_type' => 'VolcengineAIMediaKit',
            ]);
            $this->producer->produce($message);
        } catch (\Exception $e) {
            $this->loggerService->error('VolcengineAIMediaKit Webhook: Failed to push rabbitmq', $context + [
                'exception' => $e->getMessage(),
            ]);
        }

        return $response->raw('OK')->withStatus(200);
    }

}
