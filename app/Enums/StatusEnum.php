<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum StatusEnum: string
{
    use EnumTrait;

    case ACTIVE = 'active';
    case CANCELLED = 'cancelled';
    case PENDING = 'pending';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case PAUSED = 'paused';
}
