<?php

declare(strict_types=1);

use function Hyperf\Support\env;

return [
    'secret_key' => env('STRIPE_SECRET_KEY', ''),
    'publishable_key' => env('STRIPE_PUBLISHABLE_KEY', ''),
    'webhook_secret' => env('STRIPE_WEBHOOK_SECRET', ''),
];
