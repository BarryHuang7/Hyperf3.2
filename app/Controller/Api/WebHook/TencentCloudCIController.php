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

class TencentCloudCIController
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
            $this->loggerService->error('TencentCloudCI Webhook Payload is empty', ['payload' => $payload]);
            return $response->raw('Bad Request')->withStatus(400);
        }

        $payload = json_decode($payload, true);

        if (
            !is_array($payload)
            || empty($payload['JobsDetail'][0]['Operation']['UserData'])
            || empty($payload['JobsDetail'][0]['State'])
            || empty($payload['JobsDetail'][0]['JobId'])
        ) {
            $this->loggerService->error('TencentCloudCI Webhook Payload is not a valid JSON array', ['payload' => $payload]);
            return $response->raw('Bad Request')->withStatus(400);
        }

        $userDataBase64 = $payload['JobsDetail'][0]['Operation']['UserData'] ?? null;
        $userDataJson = base64_decode($userDataBase64, true);

        if ($userDataJson === false) {
            $this->loggerService->error('TencentCloudCI Webhook UserData is empty', ['payload' => $payload, 'user_data' => $userDataBase64]);
            return $response->raw('Bad Request')->withStatus(400);
        }

        $userData = json_decode($userDataJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->loggerService->error('TencentCloudCI Webhook UserData is not valid JSON', ['payload' => $payload, 'user_data' => $userDataJson]);
            return $response->raw('Bad Request')->withStatus(400);
        }

        /**
         * 调用接口时 https://{BucketName-APPID}.ci.{Region}.myqcloud.com/jobs
         * 请求参数
         * <UserData>请求/api/base64-points-task-no返回的userData字段</UserData>
         * <CallBack>{host}/api/tencentcloudci/webhook</CallBack>
         * <CallBackFormat>JSON</CallBackFormat>
         */
        $token = $userData['token'] ?? null;
        $pointsTaskNo = $userData['points_task_no'] ?? null;

        $tokenValid = $this->utilsService->verificationToken($token);

        if (!$tokenValid || empty($pointsTaskNo)) {
            $this->loggerService->error('TencentCloudCI Webhook Token or Points Task No is empty', [
                'payload' => $payload,
                'token' => $token,
                'points_task_no' => $pointsTaskNo
            ]);
            return $response->raw('Bad Request')->withStatus(400);
        }

        $status = $payload['JobsDetail'][0]['State'] ?? null;
        $jobId = $payload['JobsDetail'][0]['JobId'] ?? null;

        $context = [
            'payload' => $payload,
            'token' => $token,
            'points_task_no' => $pointsTaskNo,
            'status' => $status,
            'job_id' => $jobId,
        ];

        $this->loggerService->info('TencentCloudCI Webhook Payload: ', $context);

        try {
            $message = new MemberPointsProducer([
                'points_task_no' => $pointsTaskNo,
                'status' => $status,
                'platform_type' => 'TencentCloudCI',
            ]);
            $this->producer->produce($message);
        } catch (\Exception $e) {
            $this->loggerService->error('TencentCloudCI Webhook: Failed to push rabbitmq', $context + [
                'exception' => $e->getMessage(),
            ]);
        }

        return $response->raw('OK')->withStatus(200);
    }
}
