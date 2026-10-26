<?php

namespace App\Domain\Booking;

use App\Enums\EquipmentStatus;
use App\Models\BookingItem;
use App\Models\Equipment;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Read-only answers about which items are free when.
 */
class AvailabilityService
{
    /**
     * Every non-retired item (or the given ones) with its availability for
     * [from, to). Retired items never appear. Pass the booking being edited
     * as $ignoreBookingId so it doesn't conflict with itself.
     *
     * @param  list<int>|null  $equipmentIds
     * @return Collection<int, ItemAvailability>
     */
    public function forRange(CarbonInterface $from, CarbonInterface $to, ?array $equipmentIds = null, ?int $ignoreBookingId = null): Collection
    {
        $equipment = Equipment::query()
            ->where('status', '!=', EquipmentStatus::Retired)
            ->when($equipmentIds !== null, fn ($query) => $query->whereIn('id', $equipmentIds ?? []))
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        $conflicts = collect($this->conflicts($equipment->modelKeys(), $from, $to, $ignoreBookingId))
            ->groupBy('equipment_id');

        return $equipment->map(function (Equipment $item) use ($conflicts) {
            /** @var list<array{equipment_id: int, equipment_name: string, booking_id: int, title: string, owner: array{id: int, name: string}, starts_at: string, ends_at: string}> $itemConflicts */
            $itemConflicts = $conflicts->get($item->id, collect())->values()->all();

            $state = match (true) {
                $item->status !== EquipmentStatus::Available => AvailabilityState::Unbookable,
                $itemConflicts !== [] => AvailabilityState::Busy,
                default => AvailabilityState::Free,
            };

            return new ItemAvailability($item, $state, $itemConflicts);
        })->values();
    }

    /**
     * The first windows within [from, to) where all the items are free
     * together for at least $minutes. Windows are slot-aligned, never start
     * in the past, and are empty if any item can't be booked.
     *
     * @param  list<int>  $equipmentIds
     * @return list<array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}>
     */
    public function findOpenings(array $equipmentIds, int $minutes, CarbonInterface $from, CarbonInterface $to, int $limit = 5): array
    {
        $equipmentIds = array_values(array_unique($equipmentIds));

        if ($equipmentIds === [] || $minutes <= 0 || $limit <= 0) {
            return [];
        }

        $bookable = Equipment::query()
            ->whereIn('id', $equipmentIds)
            ->where('status', EquipmentStatus::Available)
            ->count();

        if ($bookable !== count($equipmentIds)) {
            return [];
        }

        $start = CarbonImmutable::instance(SlotClock::ceil($from)->max(SlotClock::ceil(SlotClock::now())));
        $end = SlotClock::floor($to);

        if ($start->greaterThanOrEqualTo($end)) {
            return [];
        }

        $busy = BookingItem::query()
            ->where('is_active', true)
            ->whereIn('equipment_id', $equipmentIds)
            ->where('starts_at', '<', $end->utc())
            ->where('ends_at', '>', $start->utc())
            ->orderBy('starts_at')
            ->get(['starts_at', 'ends_at']);

        $openings = [];
        $cursor = $start;

        // Busy ranges are sorted by start, so free gaps fall between them.
        foreach ($busy as $item) {
            if ($item->starts_at->greaterThan($cursor)) {
                $this->addOpening($openings, $cursor, $item->starts_at, $minutes);
            }

            $cursor = CarbonImmutable::instance($cursor->max($item->ends_at));
        }

        $this->addOpening($openings, $cursor, $end, $minutes);

        return array_slice($openings, 0, $limit);
    }

    /**
     * @param  list<array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}>  $openings
     */
    private function addOpening(array &$openings, CarbonImmutable $from, CarbonInterface $to, int $minutes): void
    {
        $to = SlotClock::local($to);

        if ($from->diffInMinutes($to) >= $minutes) {
            $openings[] = ['starts_at' => SlotClock::local($from), 'ends_at' => $to];
        }
    }

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
