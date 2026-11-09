<?php

declare(strict_types=1);

namespace App\Controller;

use GuzzleHttp\HandlerStack;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Guzzle\ClientFactory;
use App\Service\LoggerService;

class GuzzleController
{
	#[Inject]
    protected LoggerService $loggerService;

	public function __construct(private ClientFactory $clientFactory) {}

    /**
     * 发起 HTTP 请求
	 * 
	 * @param string $method 请求方法 GET|POST|PUT|DELETE|PATCH
	 * @param string $url 请求地址
	 * @param array $headers 自定义请求头 ['key' => 'value']
	 * @param array|string|null $body 请求体（GET时作为查询参数）
	 * @param HandlerStack|null $stack 连接池
	 * @return array 返回解析后的数组结果
     */
    public function request(string $method, string $url, array $headers = [], mixed $body = null, ?HandlerStack $stack = null): array
    {
		$config = [
			// 总超时时间（秒）
            'timeout' => 10.0,
			// 连接超时（秒）
            'connect_timeout' => 5.0,
			// 是否使用连接池
			'use_pool' => true,
			// 连接池最大连接数
			'max_connections' => 50
        ];

		if ($stack !== null) {
            $config['handler'] = $stack;
        }

		$client = $this->clientFactory->create($config);

		$options = [
            'headers' => $headers,
			// 重试次数（0表示不重试）
			'times' => 2,
			// 毫秒，重试间隔
			'sleep' => 100,
			// 遇到这些状态码重试
			'retry_on_status' => [429, 500, 502, 503, 504]
        ];

        // GET 请求：body 作为查询参数
        if (strtoupper($method) === 'GET' && $body !== null) {
            $options['query'] = $body;
        }
        // 其他请求：body 作为请求体
        else if ($body !== null) {
            $options['body'] = $body;
        }

		try {
			$response = $client->request($method, $url, $options);

			// 状态码
			$statusCode = $response->getStatusCode();
			// 内容
			$body = (string) $response->getBody();
			// 内容类型
			$contentType = $response->getHeaderLine('Content-Type');

			// 检查状态码
			if ($statusCode < 200 || $statusCode >= 300) {
				throw new \Exception("HTTP状态码异常: {$statusCode}, 响应: {$body}");
			}

			// 尝试解析 JSON
			if (strpos($contentType, 'application/json') !== false) {
				$decoded = json_decode($body, true);
				if (json_last_error() === JSON_ERROR_NONE) {
					return $decoded;
				}
			}

			return [
				'status_code' => $statusCode,
				'content_type' => $contentType,
				'body' => $body
			];
		} catch (\Throwable $e) {
            $this->loggerService->error('HTTP请求失败', [
                'url' => $url,
                'method' => $method,
                'error' => $e->getMessage()
            ]);
            throw new \Exception("HTTP请求失败: " . $e->getMessage(), 0, $e);
        }
    }
}
