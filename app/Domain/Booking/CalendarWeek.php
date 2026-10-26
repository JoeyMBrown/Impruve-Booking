<?php

namespace App\Domain\Booking;

use App\Enums\EquipmentCategory;
use App\Enums\EquipmentStatus;
use App\Models\Booking;
use App\Models\Equipment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Builds the weekly calendar payload (D§4.2). The /calendar page and the
 * JSON endpoint share it, so a refetch shows exactly what a reload would.
 */
final class CalendarWeek
{
    /**
     * Monday 00:00 (company time) of an ISO week like "2026-W44", or of the
     * current week when the value is missing or invalid.
     */
    public static function start(?string $isoWeek): CarbonImmutable
    {
        $now = SlotClock::now();

        if ($isoWeek !== null && preg_match('/^(\d{4})-W(\d{2})$/', $isoWeek, $matches) === 1) {
            $start = $now->setISODate((int) $matches[1], (int) $matches[2], 1)->startOfDay();

            // Reject weeks that don't exist (e.g. W54), which Carbon would roll over.
            if ($start->format('o-\WW') === $isoWeek) {
                return $start;
            }
        }

        return $now->startOfWeek(CarbonInterface::MONDAY);
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(
        User $viewer,
        CarbonImmutable $start,
        ?EquipmentCategory $category = null,
        ?int $equipmentId = null,
        bool $mineOnly = false,
    ): array {
        $now = SlotClock::now();
        $end = $start->addWeek();

        $bookings = Booking::query()
            ->active()
            ->overlapping($start, $end)
            ->with(['user:id,name', 'equipment'])
            ->when($category, fn ($query, EquipmentCategory $category) => $query->whereHas(
                'equipment',
                fn ($equipment) => $equipment->where('category', $category),
            ))
            ->when($equipmentId, fn ($query, int $id) => $query->usingEquipment($id))
            ->when($mineOnly, fn ($query) => $query->where('user_id', $viewer->id))
            ->orderBy('starts_at')
            ->get();

        return [
            'week' => [
                'iso' => $start->format('o-\WW'),
                'start' => $start->toDateString(),
                'end' => $start->addDays(6)->toDateString(),
                'tz' => SlotClock::timezone(),
                'prev' => $start->subWeek()->format('o-\WW'),
                'next' => $end->format('o-\WW'),
                'is_current' => $now->greaterThanOrEqualTo($start) && $now->lessThan($end),
            ],
            'now' => $now->toIso8601String(),
            'filters' => [
                'category' => $category?->value,
                'equipment' => $equipmentId,
                'mine' => $mineOnly,
            ],
            'equipment' => Equipment::query()
                ->where('status', '!=', EquipmentStatus::Retired)
                ->orderBy('category')
                ->orderBy('name')
                ->get(['id', 'name', 'category'])
                ->map(fn (Equipment $item) => ['id' => $item->id, 'name' => $item->name, 'category' => $item->category->value])
                ->values(),
            'bookings' => $bookings->map(fn (Booking $booking) => BookingPresenter::toArray($booking, $viewer, $now))->values(),
            ...self::sidePanel($viewer, $now),
        ];
    }

    /**
     * What is true right now, regardless of the week or filters.
     *
     * @return array{out_now: list<array<string, mixed>>, unavailable: list<array<string, mixed>>, my_at_risk: list<array<string, mixed>>}
     */
    private static function sidePanel(User $viewer, CarbonImmutable $now): array
    {
        $outNow = [];

        $inProgress = Booking::query()->active()->inProgress()->with(['user:id,name', 'equipment'])->orderBy('ends_at')->get();

        foreach ($inProgress as $booking) {
            foreach ($booking->equipment->sortBy('name') as $item) {
                $outNow[] = [
                    'equipment_id' => $item->id,
                    'name' => $item->name,
                    'booking_id' => $booking->id,
                    'owner' => $booking->user->name,
                    'until' => SlotClock::local($booking->ends_at)->toIso8601String(),
                ];
            }
        }

        $unavailable = Equipment::query()
            ->where('status', EquipmentStatus::OutOfService)
            ->orderBy('name')
            ->get()
            ->map(fn (Equipment $item) => [
                'equipment_id' => $item->id,
                'name' => $item->name,
                'status' => $item->status->value,
                'reason' => $item->status_reason,
            ])
            ->all();

        $myAtRisk = Booking::query()
            ->where('user_id', $viewer->id)
            ->atRisk()
            ->with(['user:id,name', 'equipment'])
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Booking $booking) => BookingPresenter::toArray($booking, $viewer, $now))
            ->all();

        return [
            'out_now' => $outNow,
            'unavailable' => array_values($unavailable),
            'my_at_risk' => array_values($myAtRisk),
        ];
    }
}
