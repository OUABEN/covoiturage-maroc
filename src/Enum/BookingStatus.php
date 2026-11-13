<?php

declare(strict_types=1);

namespace App\Enum;

enum BookingStatus: string
{
    case CONFIRMED = 'CONFIRMED';
    case CANCELLED_BY_PASSENGER = 'CANCELLED_BY_PASSENGER';
    case CANCELLED_BY_DRIVER = 'CANCELLED_BY_DRIVER';
}
