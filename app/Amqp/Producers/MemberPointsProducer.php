<?php

declare(strict_types=1);

namespace App\Amqp\Producers;

use Hyperf\Amqp\Annotation\Producer;
use Hyperf\Amqp\Message\ProducerMessage;

#[Producer(exchange: "hyperf.internal", routingKey: "member.points")]
class MemberPointsProducer extends ProducerMessage
{
    public function __construct(array $payload)
    {
        $this->payload = $payload;
    }
}
