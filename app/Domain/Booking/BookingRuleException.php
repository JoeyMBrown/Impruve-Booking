<?php

namespace App\Domain\Booking;

use RuntimeException;

/**
 * Thrown by BookingService when a save breaks one or more booking rules.
 */
class BookingRuleException extends RuntimeException
{
    public function __construct(public readonly CheckResult $result)
    {
        parent::__construct(implode(' ', array_map(
            fn (BookingRuleViolation $violation) => $violation->message,
            $result->violations,
        )));
    }
}
