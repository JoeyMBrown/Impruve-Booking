<?php

namespace App\Ai;

use App\Domain\Booking\SlotClock;
use App\Models\Booking;
use App\Models\BookingProposal;
use App\Models\Equipment;
use Carbon\CarbonImmutable;

/**
 * The confirm card's payload (D§5.3): absolute weekday dates so a wrong
 * "next Tuesday" is caught before confirming (US-7), and old vs new values
 * for a change.
 */
final class ProposalCard
{
    /**
     * @return array<string, mixed>
     */
    public static function from(BookingProposal $proposal): array
    {
        /** @var array{equipment_ids: list<int>, starts_at: string, ends_at: string, title: string, notes: string|null} $payload */
        $payload = $proposal->payload;
        $booking = $proposal->booking;

        return [
            'id' => $proposal->id,
            'action' => $proposal->action->value,
            'status' => $proposal->status->value,
            'booking_id' => $proposal->booking_id,
            ...self::values($payload['equipment_ids'], $payload['starts_at'], $payload['ends_at'], $payload['title'], $payload['notes']),
            'before' => $booking && $proposal->action->value === 'update'
                ? self::values(
                    $booking->items()->pluck('equipment_id')->map(fn ($id) => (int) $id)->all(),
                    $booking->starts_at->toIso8601String(),
                    $booking->ends_at->toIso8601String(),
                    $booking->title,
                    $booking->notes,
                )
                : null,
            'check' => ['ok' => true],
            'expires_at' => SlotClock::local($proposal->expires_at)->toIso8601String(),
        ];
    }

    /**
     * @param  list<int>  $equipmentIds
     * @return array{title: string, notes: string|null, items: list<array{id: int, name: string}>, starts_at: string, ends_at: string, display: array{when: string, duration: string}}
     */
    private static function values(array $equipmentIds, string $startsAt, string $endsAt, string $title, ?string $notes): array
    {
        $from = SlotClock::local(CarbonImmutable::parse($startsAt));
        $to = SlotClock::local(CarbonImmutable::parse($endsAt));
        $names = Equipment::query()->whereIn('id', $equipmentIds)->pluck('name', 'id');

        return [
            'title' => $title,
            'notes' => $notes,
            'items' => array_values(array_map(
                fn (int $id) => ['id' => $id, 'name' => (string) ($names[$id] ?? "Item #{$id}")],
                $equipmentIds,
            )),
            'starts_at' => $from->toIso8601String(),
            'ends_at' => $to->toIso8601String(),
            'display' => [
                'when' => $from->isSameDay($to)
                    ? $from->format('D M j · g:i A').' → '.$to->format('g:i A')
                    : $from->format('D M j · g:i A').' → '.$to->format('D M j · g:i A'),
                'duration' => self::duration((int) round($from->diffInMinutes($to))),
            ],
        ];
    }

    private static function duration(int $minutes): string
    {
        $parts = array_filter([
            intdiv($minutes, 1440) ? intdiv($minutes, 1440).' d' : null,
            intdiv($minutes % 1440, 60) ? intdiv($minutes % 1440, 60).' h' : null,
            $minutes % 60 ? ($minutes % 60).' min' : null,
        ]);

        return $parts === [] ? '0 min' : implode(' ', $parts);
    }

    /**
     * The booking's values as a proposal payload (for change and cancel).
     *
     * @return array{equipment_ids: list<int>, starts_at: string, ends_at: string, title: string, notes: string|null}
     */
    public static function payloadFromBooking(Booking $booking): array
    {
        return [
            'equipment_ids' => array_values($booking->items()->pluck('equipment_id')->map(fn ($id) => (int) $id)->all()),
            'starts_at' => SlotClock::local($booking->starts_at)->toIso8601String(),
            'ends_at' => SlotClock::local($booking->ends_at)->toIso8601String(),
            'title' => $booking->title,
            'notes' => $booking->notes,
        ];
    }
}
