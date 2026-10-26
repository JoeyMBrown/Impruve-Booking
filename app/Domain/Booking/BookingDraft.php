<?php

namespace App\Domain\Booking;

use App\Models\Booking;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The values a booking would have after a create or update. Validated by
 * BookingService; never trusted on its own.
 */
final readonly class BookingDraft
{
    public CarbonImmutable $startsAt;

    public CarbonImmutable $endsAt;

    /** @var list<int> */
    public array $equipmentIds;

    /**
     * @param  iterable<int>  $equipmentIds
     */
    public function __construct(
        iterable $equipmentIds,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        public string $title,
        public ?string $notes = null,
    ) {
        $ids = [];

        foreach ($equipmentIds as $id) {
            $ids[] = (int) $id;
        }

        $this->equipmentIds = array_values(array_unique($ids));
        $this->startsAt = CarbonImmutable::instance($startsAt);
        $this->endsAt = CarbonImmutable::instance($endsAt);
    }

    /**
     * The booking's current values, as a starting point for an update.
     */
    public static function fromBooking(Booking $booking): self
    {
        return new self(
            $booking->items()->pluck('equipment_id')->all(),
            $booking->starts_at,
            $booking->ends_at,
            $booking->title,
            $booking->notes,
        );
    }

    /**
     * @param  iterable<int>|null  $equipmentIds
     */
    public function with(
        ?iterable $equipmentIds = null,
        ?CarbonInterface $startsAt = null,
        ?CarbonInterface $endsAt = null,
        ?string $title = null,
        ?string $notes = null,
    ): self {
        return new self(
            $equipmentIds ?? $this->equipmentIds,
            $startsAt ?? $this->startsAt,
            $endsAt ?? $this->endsAt,
            $title ?? $this->title,
            $notes ?? $this->notes,
        );
    }

    /**
     * Whether the item list differs from the given ids, ignoring order.
     *
     * @param  list<int>  $equipmentIds
     */
    public function hasDifferentItemsThan(array $equipmentIds): bool
    {
        $current = $this->equipmentIds;
        sort($current);
        sort($equipmentIds);

        return $current !== $equipmentIds;
    }
}
