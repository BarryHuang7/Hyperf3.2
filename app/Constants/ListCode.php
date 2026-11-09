<?php

declare(strict_types=1);

namespace App\Constants;

use Hyperf\Constants\AbstractConstants;
use Hyperf\Constants\Annotation\Constants;

#[Constants]
class ListCode extends AbstractConstants
{
    /**
     * 默认开始页
     */
    public const DEFAULT_PAGE = 1;

    /**
     * 默认每页数量
     */
    public const DEFAULT_PAGE_SIZE = 10;

    /**
     * 最大每页数量
     */
    public const MAX_PAGE_SIZE = 50;
}
