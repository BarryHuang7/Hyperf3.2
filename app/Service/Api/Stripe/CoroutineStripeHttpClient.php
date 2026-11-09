<?php

declare(strict_types=1);

namespace App\Service\Api\Stripe;

use Hyperf\Guzzle\ClientFactory;
use Stripe\HttpClient\ClientInterface;

class CoroutineStripeHttpClient implements ClientInterface
{
    public function __construct(private ClientFactory $clientFactory)
    {
    }

    public function request(
        $method,
        $absUrl,
        $headers,
        $params,
        $hasFile,
        $apiMode = 'v1',
        $maxNetworkRetries = null
    ) {
        $client = $this->clientFactory->create([
            'timeout' => 10.0,           // 总超时
            'connect_timeout' => 3.0,    // 连接超时
        ]);

        $options = [];

        // 处理请求体：Stripe 的 POST 参数通常是表单格式
        if ($method === 'post' || $method === 'delete') {
            if ($hasFile) {
                // 文件上传场景，Stripe 使用 multipart
                $options['multipart'] = $this->buildMultipart($params);
            } else {
                $options['form_params'] = $params;
            }
        } elseif (!empty($params)) {
            // GET 请求参数放到 query
            $absUrl .= '?' . http_build_query($params);
        }

        // 设置请求头
        $options['headers'] = $this->normalizeHeaders($headers);

        // 发起请求
        $response = $client->request($method, $absUrl, $options);

        $body = (string) $response->getBody();
        $statusCode = $response->getStatusCode();
        $respHeaders = $response->getHeaders();

        return [$body, $statusCode, $respHeaders];
    }

    private function buildMultipart(array $params): array
    {
        $multipart = [];
        foreach ($params as $key => $value) {
            $multipart[] = ['name' => $key, 'contents' => $value];
        }
        return $multipart;
    }

    private function normalizeHeaders(array $headers): array
    {
        $result = [];
        foreach ($headers as $header) {
            // Stripe 传入的 headers 是 "Key: Value" 格式的字符串数组
            [$key, $value] = explode(':', $header, 2);
            $result[trim($key)] = trim($value);
        }
        return $result;
    }
}
