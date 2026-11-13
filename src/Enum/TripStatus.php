<?php

declare(strict_types=1);

namespace App\Enum;

enum TripStatus: string
{
    case PUBLISHED = 'PUBLISHED';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';
}
