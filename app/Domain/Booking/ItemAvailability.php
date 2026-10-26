<?php

namespace App\Domain\Booking;

use App\Models\Equipment;

/**
 * One item's availability for a time range: free, busy (with the bookings
 * holding it) or unbookable (with its status and reason).
 */
final readonly class ItemAvailability
{
    /**
     * @param  list<array{equipment_id: int, equipment_name: string, booking_id: int, title: string, owner: array{id: int, name: string}, starts_at: string, ends_at: string}>  $conflicts
     */
    public function __construct(
        public Equipment $equipment,
        public AvailabilityState $state,
        public array $conflicts = [],
    ) {}

    /**
     * @return array{equipment_id: int, name: string, category: string, state: string, status: string, reason: string|null, conflicts: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'equipment_id' => $this->equipment->id,
            'name' => $this->equipment->name,
            'category' => $this->equipment->category->value,
            'state' => $this->state->value,
            'status' => $this->equipment->status->value,
            'reason' => $this->equipment->status_reason,
            'conflicts' => $this->conflicts,
        ];
    }
}
