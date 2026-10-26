<?php

namespace App\Domain\Booking;

use App\Models\BookingItem;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Read-only answers about which items are free when.
 */
class AvailabilityService
{
    /**
     * Active bookings that hold any of the items during [from, to).
     *
     * @param  list<int>  $equipmentIds
     * @return list<array{equipment_id: int, equipment_name: string, booking_id: int, title: string, owner: array{id: int, name: string}, starts_at: string, ends_at: string}>
     */
    public function conflicts(array $equipmentIds, CarbonInterface $from, CarbonInterface $to, ?int $ignoreBookingId = null): array
    {
        if ($equipmentIds === []) {
            return [];
        }

        return BookingItem::query()
            ->with(['booking.user:id,name', 'equipment:id,name'])
            ->where('is_active', true)
            ->whereIn('equipment_id', $equipmentIds)
            ->where('starts_at', '<', CarbonImmutable::instance($to)->utc())
            ->where('ends_at', '>', CarbonImmutable::instance($from)->utc())
            ->when($ignoreBookingId, fn ($query, int $id) => $query->where('booking_id', '!=', $id))
            ->orderBy('starts_at')
            ->get()
            ->map(fn (BookingItem $item) => [
                'equipment_id' => $item->equipment_id,
                'equipment_name' => $item->equipment->name,
                'booking_id' => $item->booking_id,
                'title' => $item->booking->title,
                'owner' => ['id' => $item->booking->user->id, 'name' => $item->booking->user->name],
                'starts_at' => $item->starts_at->toIso8601String(),
                'ends_at' => $item->ends_at->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
