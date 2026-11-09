<?php

declare(strict_types=1);

namespace App\Constants;

use Hyperf\Constants\AbstractConstants;
use Hyperf\Constants\Annotation\Constants;

#[Constants]
class WebSocketCode extends AbstractConstants
{
    /**
     * redis的用户fd key
     */
    public const WEBSOCKET_FDS = 'websocket:fds';

    /**
     * redis的用户id key
     */
    public const WEBSOCKET_USERS = 'websocket:users';

    /**
     * redis的在线用户 key
     */
    public const WEBSOCKET_ONLINE_USERS = 'websocket:onlineUsers';
}
