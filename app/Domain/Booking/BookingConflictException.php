<?php

namespace App\Domain\Booking;

use RuntimeException;

/**
 * Thrown when Postgres rejects a save because an item is already in an
 * overlapping active booking (exclusion constraint, SQLSTATE 23P01). This
 * happens when two saves race past the service's own conflict check.
 */
class BookingConflictException extends RuntimeException
{
    /**
     * @param  list<array<string, mixed>>  $conflicts  As returned by AvailabilityService::conflicts().
     */
    public function __construct(public readonly array $conflicts)
    {
        parent::__construct('One or more items were just booked by someone else.');
    }
}
