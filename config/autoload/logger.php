<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
use Monolog\Formatter\LineFormatter;
use Monolog\Level;

return [
    'default' => 'default',
    'channels' => [
        'default' => [
            'handler' => [
                'class' => Monolog\Handler\RotatingFileHandler::class,
                'constructor' => [
                    'filename' => BASE_PATH . '/runtime/logs/hyperf.log',
                    'maxFiles' => 30,
                    'level' => Monolog\Logger::DEBUG,
                    'bubble' => true,
                    'filePermission' => null,
                    'useLocking' => false,
                ],
            ],
            'formatter' => [
                'class' => LineFormatter::class,
                'constructor' => [
                    'format' => null,
                    'dateFormat' => 'Y-m-d H:i:s',
                    'allowInlineLineBreaks' => true,
                ],
            ],
        ],
        // http Info 级别日志通道
        'info' => [
            'handlers' => [
                [
                    'class' => Monolog\Handler\RotatingFileHandler::class,
                    'constructor' => [
                        // 基础文件名，实际生成的文件名会包含日期
                        'filename' => BASE_PATH . '/runtime/logs/info.log',
                        // 关键：按月份格式化，Y-m 表示 2026-10
                        'filenameFormat' => 'info-{date}',
                        'level' => Monolog\Logger::INFO, // 只处理 INFO 及以上级别
                        'maxFiles' => 12, // 保留最近 12 个月的文件
                        'bubble' => false, // 关键：防止日志传递给其他通道
                    ],
                ],
            ],
        ],
        // Error 级别日志通道
        'error' => [
            'handlers' => [
                [
                    'class' => Monolog\Handler\RotatingFileHandler::class,
                    'constructor' => [
                        'filename' => BASE_PATH . '/runtime/logs/error.log',
                        'filenameFormat' => 'error-{date}',
                        'level' => Monolog\Logger::ERROR, // 只处理 ERROR 及以上级别
                        'maxFiles' => 12,
                        'bubble' => false,
                    ],
                ],
            ],
        ],
        // websocket Info 级别日志通道
        'wsinfo' => [
            'handlers' => [
                [
                    'class' => Monolog\Handler\RotatingFileHandler::class,
                    'constructor' => [
                        // 基础文件名，实际生成的文件名会包含日期
                        'filename' => BASE_PATH . '/runtime/logs/websocket-info.log',
                        // 关键：按月份格式化，Y-m 表示 2026-10
                        'filenameFormat' => 'websocket-info-{date}',
                        'level' => Monolog\Logger::INFO, // 只处理 INFO 及以上级别
                        'maxFiles' => 12, // 保留最近 12 个月的文件
                        'bubble' => false, // 关键：防止日志传递给其他通道
                    ],
                ],
            ],
        ],
    ],
];
