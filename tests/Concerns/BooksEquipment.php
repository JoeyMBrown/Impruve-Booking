<?php

namespace Tests\Concerns;

use App\Domain\Booking\BookingDraft;
use App\Models\Booking;
use App\Models\Equipment;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Helpers for booking tests. Times are written as company-local strings
 * ("2026-10-28 09:00") and parsed in America/Chicago.
 */
trait BooksEquipment
{
    protected function at(string $localTime): CarbonImmutable
    {
        return CarbonImmutable::parse($localTime, 'America/Chicago');
    }

    /**
     * Freeze "now" at a company-local time. Defaults to Wed Oct 28, 12:00.
     */
    protected function freezeAt(string $localTime = '2026-10-28 12:00'): void
    {
        $this->travelTo($this->at($localTime));
    }

    /**
     * Insert a booking directly (no rules), with item rows copying its range.
     *
     * @param  Equipment|list<Equipment>  $equipment
     */
    protected function book(
        Equipment|array $equipment,
        string $from,
        string $to,
        ?User $owner = null,
        bool $cancelled = false,
        string $title = 'Shoot',
    ): Booking {
        $items = is_array($equipment) ? $equipment : [$equipment];

        return Booking::factory()
            ->withEquipment(...$items)
            ->for($owner ?? User::factory())
            ->create([
                'title' => $title,
                'starts_at' => $this->at($from),
                'ends_at' => $this->at($to),
                'cancelled_at' => $cancelled ? now() : null,
                'is_active' => ! $cancelled,
            ]);
    }

    /**
     * @param  Equipment|list<Equipment>  $equipment
     */
    protected function draft(
        Equipment|array $equipment,
        string $from,
        string $to,
        string $title = 'Client interview',
        ?string $notes = null,
    ): BookingDraft {
        $items = is_array($equipment) ? $equipment : [$equipment];

        return new BookingDraft(
            array_map(fn (Equipment $item) => $item->id, $items),
            $this->at($from),
            $this->at($to),
            $title,
            $notes,
        );
    }
}
