<?php

namespace App\Enums;

/**
 * Where a booking is in time. Derived from its range, never stored.
 */
enum BookingPhase: string
{
    case Upcoming = 'upcoming';
    case InProgress = 'in_progress';
    case Ended = 'ended';
}
