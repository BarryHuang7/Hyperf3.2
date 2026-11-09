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

class AssemblyAIController
{
    #[Inject]
    protected LoggerService $loggerService;

    #[Inject]
    protected UtilsService $utilsService;

    #[Inject]
    private Producer $producer;

    public function handle(RequestInterface $request, ResponseInterface $response)
    {
        $token = $request->getHeaderLine('Authorization');
        $tokenValid = $this->utilsService->verificationToken($token);

        if (!$tokenValid) {
            $this->loggerService->error('AssemblyAI Webhook Token is empty', ['token' => $token]);
            return $response->raw('Bad Request')->withStatus(400);
        }

        $payload = $request->getBody()->getContents();

        if (empty($payload)) {
            $this->loggerService->error('AssemblyAI Webhook Payload is empty', ['payload' => $payload]);
            return $response->raw('Bad Request')->withStatus(400);
        }

        $payload = json_decode($payload, true);
        if (!is_array($payload) || empty($payload['status']) || empty($payload['transcript_id']) || empty($request->query('points_task_no'))) {
            $this->loggerService->error('AssemblyAI Webhook Payload is not a valid JSON array', ['payload' => $payload]);
            return $response->raw('Bad Request')->withStatus(400);
        }

        $status = $payload['status'] ?? null;
        $transcriptId = $payload['transcript_id'] ?? null;
        /**
         * 调用接口时 https://api.assemblyai.com/v2/transcript
         * 请求参数 {
         *  "webhook_url": "{host}/api/assemblyai/webhook?points_task_no={points_task_no}",
         *  "webhook_auth_header_name": "Authorization",
         *  "webhook_auth_header_value": "{token}"
         * }
         */
        $pointsTaskNo = $request->query('points_task_no');

        $context = [
            'payload' => $payload,
            'token' => $token,
            'status' => $status,
            'transcript_id' => $transcriptId,
            'points_task_no' => $pointsTaskNo
        ];

        $this->loggerService->info('AssemblyAI Webhook Payload: ', $context);

        try {
            $message = new MemberPointsProducer([
                'points_task_no' => $pointsTaskNo,
                'status' => $status,
                'platform_type' => 'AssemblyAI',
            ]);
            $this->producer->produce($message);
        } catch (\Exception $e) {
            $this->loggerService->error('AssemblyAI Webhook: Failed to push rabbitmq', $context + [
                'exception' => $e->getMessage(),
            ]);
        }

        return $response->raw('OK')->withStatus(200);
    }
}
