<?php

declare(strict_types=1);

namespace App\Controller\Api\WebHook;

use function Hyperf\Config\config;

use Hyperf\Amqp\Producer;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use App\Service\LoggerService;
use App\Amqp\Producers\MemberPointsProducer;

class WaveSpeedAIController
{
    #[Inject]
    protected LoggerService $loggerService;

    #[Inject]
    private Producer $producer;

    public function handle(RequestInterface $request, ResponseInterface $response)
    {
        $webhookId = $request->getHeaderLine('webhook-id');
        $timestamp = $request->getHeaderLine('webhook-timestamp');
        $signatureHeader = $request->getHeaderLine('webhook-signature');
        $payload = $request->getBody()->getContents();

        $context = [
            'payload' => $payload,
            'webhook_id' => $webhookId,
            'timestamp' => $timestamp,
            'signature' => $signatureHeader
        ];

        if (!$this->handleSignature($payload, $webhookId, $timestamp, $signatureHeader)) {
            return $response->raw('Invalid signature')->withStatus(400);
        }

        $payload = json_decode($payload, true);

        if (!is_array($payload) || empty($payload['status']) || empty($request->query('points_task_no'))) {
            $this->loggerService->error('WaveSpeedAI Webhook Payload is not a valid JSON array', $context);
            return $response->raw('Bad Request')->withStatus(400);
        }

        $status = $payload['status'] ?? null;
        // 调用接口时 https://api.wavespeed.ai/api/v3/wavespeed-ai/video-upscaler?standard_webhook={host}/api/wavespeedai/webhook?points_task_no={points_task_no}
        $pointsTaskNo = $request->query('points_task_no');

        $context = $context + [
            'pointsTaskNo' => $pointsTaskNo,
        ];

        $this->loggerService->info('WaveSpeedAI Webhook Payload: ', $context);

        try {
            $message = new MemberPointsProducer([
                'points_task_no' => $pointsTaskNo,
                'status' => $status,
                'platform_type' => 'WaveSpeedAI',
            ]);
            $this->producer->produce($message);
        } catch (\Exception $e) {
            $this->loggerService->error('WaveSpeedAI Webhook: Failed to push rabbitmq', $context + ['exception' => $e->getMessage()]);
        }

        return $response->raw('OK')->withStatus(200);
    }

    /**
     * 签名验证
     * @param string $payload
     * @param string $webhookId
     * @param string $timestamp
     * @param string $signatureHeader
     * @return bool
     */
    private function handleSignature(string $payload, string $webhookId, string $timestamp, string $signatureHeader): bool
    {
        $context = [
            'payload' => $payload,
            'webhook_id' => $webhookId,
            'timestamp' => $timestamp,
            'signature' => $signatureHeader
        ];

        // 1. 校验时间戳（防重放）5分钟内有效
        if (abs(time() - (int) $timestamp) > 300) {
            $this->loggerService->error('WaveSpeedAI Webhook handleSignature Timestamp too old', $context);
            return false;
        }

        // 2. 解析签名头 v1,<base64>（可能以空格分隔携带多个签名）
        $receivedSignatures = [];
        foreach (explode(' ', $signatureHeader) as $item) {
            $parts = explode(',', $item, 2);
            // 按道理应该是v3，但实际返回了v1
            if (count($parts) === 2 && in_array($parts[0], ['v1', 'v3'], true)) {
                $receivedSignatures[] = $parts[1];
            }
        }
        if (empty($receivedSignatures)) {
            $this->loggerService->error('WaveSpeedAI Webhook handleSignature Invalid signature format', $context);
            return false;
        }

        // 3. 构造待签名内容
        $signedContent = $webhookId . '.' . $timestamp . '.' . $payload;

        // 4. 获取密钥：去掉 whsec_ 前缀后还需 base64 解码
        $secret = (string) config('services.wavespeed.webhook_secret');
        $key = str_starts_with($secret, 'whsec_') ? base64_decode(substr($secret, 6), true) : $secret;
        if ($key === false || $key === '') {
            $this->loggerService->error('WaveSpeedAI Webhook handleSignature Invalid secret', $context);
            return false;
        }

        // 5. 计算并比对（签名是原始摘要的 base64，不是 hex）
        $expectedSignature = base64_encode(hash_hmac('sha256', $signedContent, $key, true));

        foreach ($receivedSignatures as $receivedSignature) {
            if (hash_equals($expectedSignature, $receivedSignature)) {
                return true;
            }
        }

        $this->loggerService->error('WaveSpeedAI Webhook handleSignature Invalid signature', $context);
        return false;
    }
}
