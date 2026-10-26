<?php

namespace App\Domain\Booking;

use App\Enums\BookingPhase;
use App\Enums\EquipmentStatus;
use App\Models\Booking;
use App\Models\Equipment;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * The booking shape the calendar, drawer, detail page and My bookings use.
 * Times are ISO 8601 in the company timezone (e.g. 2026-10-28T09:00:00-05:00).
 * Expects the booking's user and equipment to be loaded.
 */
final class BookingPresenter
{
    /**
     * @return array{id: int, title: string, notes: string|null, starts_at: string, ends_at: string, owner: array{id: int, name: string}, is_mine: bool, is_active: bool, cancelled_at: string|null, items: list<array{id: int, name: string, category: string, status: string, status_reason: string|null}>, phase: string, at_risk: bool, source: string, created_at: string|null}
     */
    public static function toArray(Booking $booking, User $viewer, ?CarbonInterface $now = null): array
    {
        $phase = $booking->phase($now ?? SlotClock::now());

        return [
            'id' => $booking->id,
            'title' => $booking->title,
            'notes' => $booking->notes,
            'starts_at' => SlotClock::local($booking->starts_at)->toIso8601String(),
            'ends_at' => SlotClock::local($booking->ends_at)->toIso8601String(),
            'owner' => ['id' => $booking->user->id, 'name' => $booking->user->name],
            'is_mine' => $booking->user_id === $viewer->id,
            'is_active' => $booking->is_active,
            'cancelled_at' => $booking->cancelled_at ? SlotClock::local($booking->cancelled_at)->toIso8601String() : null,
            'items' => array_values($booking->equipment
                ->sortBy('name')
                ->map(fn (Equipment $item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'category' => $item->category->value,
                    'status' => $item->status->value,
                    'status_reason' => $item->status_reason,
                ])
                ->all()),
            'phase' => $phase->value,
            'at_risk' => self::isAtRisk($booking, $phase),
            'source' => $booking->source->value,
            'created_at' => $booking->created_at ? SlotClock::local($booking->created_at)->toIso8601String() : null,
        ];
    }

    /**
     * Active, not ended, and any item is not available (FR-BK-12).
     */
    public static function isAtRisk(Booking $booking, BookingPhase $phase): bool
    {
        return $booking->is_active
            && $phase !== BookingPhase::Ended
            && $booking->equipment->contains(fn (Equipment $item) => $item->status !== EquipmentStatus::Available);
    }
}
