<?php

declare(strict_types=1);

use function Hyperf\Support\env;

return [
    'consumers' => [
        [
            // The service name, this name should as same as with the name of service provider.
            'name' => 'UnicdService',
            // The service registry, if `nodes` is missing below, then you should provide this configs.
            'registry' => [
                'protocol' => 'consul',
                'address' => 'Enter the address of service registry',
            ],
            // If `registry` is missing, then you should provide the nodes configs.
            'nodes' => [
                // Provide the host and port of the service provider.
                // ['host' => 'The host of the service provider', 'port' => 9502]
            ],
        ],
    ],
    // WaveSpeedAI
    'wavespeed' => [
        'webhook_secret' => env('WAVESPEED_WEBHOOK_SECRET', ''),
    ]
];
