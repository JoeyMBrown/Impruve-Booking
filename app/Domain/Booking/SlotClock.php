<?php

namespace App\Domain\Booking;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Time helpers for the booking rules. Slots are measured in the company
 * timezone; instants compare the same in any timezone.
 */
final class SlotClock
{
    public static function timezone(): string
    {
        return config()->string('booking.timezone');
    }

    public static function slotMinutes(): int
    {
        return config()->integer('booking.slot_minutes');
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone());
    }

    public static function local(CarbonInterface $time): CarbonImmutable
    {
        return CarbonImmutable::instance($time)->setTimezone(self::timezone());
    }

    /**
     * True when the time falls exactly on a slot boundary (e.g. 09:00, 09:30).
     */
    public static function isOnSlot(CarbonInterface $time): bool
    {
        return self::secondsPastSlot($time) === 0 && $time->microsecond === 0;
    }

    /**
     * The slot boundary at or before the time.
     */
    public static function floor(CarbonInterface $time): CarbonImmutable
    {
        $timestamp = $time->getTimestamp() - self::secondsPastSlot($time);

        return CarbonImmutable::createFromTimestamp($timestamp, self::timezone());
    }

    /**
     * The slot boundary at or after the time.
     */
    public static function ceil(CarbonInterface $time): CarbonImmutable
    {
        return self::isOnSlot($time) ? self::local($time) : self::nextSlotAfter($time);
    }

    /**
     * The first slot boundary strictly after the time (10:00:00 → 10:30,
     * 10:10 → 10:30). Used to end a booking "now".
     */
    public static function nextSlotAfter(CarbonInterface $time): CarbonImmutable
    {
        return self::floor($time)->addMinutes(self::slotMinutes());
    }

    /**
     * Seconds since the last slot boundary in the company timezone. Uses the
     * UTC offset at that instant, so it stays correct across DST changes.
     */
    private static function secondsPastSlot(CarbonInterface $time): int
    {
        $local = self::local($time);
        $slotSeconds = self::slotMinutes() * 60;
        $localSeconds = $local->getTimestamp() + $local->getOffset();

        return (($localSeconds % $slotSeconds) + $slotSeconds) % $slotSeconds;
    }
}
