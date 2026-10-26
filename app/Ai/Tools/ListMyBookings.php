<?php

namespace App\Ai\Tools;

use App\Domain\Booking\BookingPresenter;
use App\Domain\Booking\SlotClock;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The acting user's own active bookings, with at-risk flags. By default only
 * bookings that haven't ended.
 */
class ListMyBookings extends BookingTool
{
    public function description(): Stringable|string
    {
        return "List the current user's own active bookings (not cancelled), with ids, times, items and at-risk flags. "
            .'By default only upcoming and in-progress ones; optionally limit to a local date range or include past bookings.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('Local start of the range, YYYY-MM-DDTHH:mm'),
            'to' => $schema->string()->description('Local end of the range, YYYY-MM-DDTHH:mm'),
            'include_past' => $schema->boolean()->description('Also include bookings that have ended'),
        ];
    }

    protected function run(Request $request): array
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'to' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'include_past' => ['nullable', 'boolean'],
        ]);

        $user = $this->context->user;
        $now = SlotClock::now();

        $bookings = Booking::query()
            ->where('user_id', $user->id)
            ->active()
            ->unless((bool) ($request['include_past'] ?? false), fn ($query) => $query->notEnded())
            ->when($request['from'] ?? null, fn ($query) => $query->where('bookings.ends_at', '>', $this->time($request, 'from')->utc()))
            ->when($request['to'] ?? null, fn ($query) => $query->where('bookings.starts_at', '<', $this->time($request, 'to')->utc()))
            ->with(['user:id,name', 'equipment'])
            ->orderBy('starts_at')
            ->limit(25)
            ->get();

        return [
            'ok' => true,
            'bookings' => $bookings->map(function (Booking $booking) use ($user, $now) {
                $data = BookingPresenter::toArray($booking, $user, $now);

                return [
                    'id' => $data['id'],
                    'title' => $data['title'],
                    'starts' => self::when(CarbonImmutable::parse($data['starts_at'])),
                    'ends' => self::when(CarbonImmutable::parse($data['ends_at'])),
                    'phase' => $data['phase'],
                    'at_risk' => $data['at_risk'],
                    'items' => array_map(fn (array $item) => [
                        'id' => $item['id'],
                        'name' => $item['name'],
                        'status' => $item['status'],
                        'reason' => $item['status_reason'],
                    ], $data['items']),
                ];
            })->values()->all(),
        ];
    }
}
