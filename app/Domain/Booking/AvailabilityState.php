<?php

namespace App\Domain\Booking;

enum AvailabilityState: string
{
    case Free = 'free';
    case Busy = 'busy';
    case Unbookable = 'unbookable';
}
