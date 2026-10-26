<?php

namespace App\Ai\Tools;

use App\Domain\Booking\AvailabilityService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The next windows where all the given items are free together for at least
 * the given length. Used for "when is X free?" and to suggest alternatives.
 */
class FindOpenings extends BookingTool
{
    public function description(): Stringable|string
    {
        return 'Find the next time windows (up to 5) between search_from and search_to where ALL the given items are free together '
            .'for at least duration_minutes. Use it for "when is X free?" and to suggest alternatives when something is taken.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'equipment_ids' => $schema->array()->items($schema->integer())->required()->description('Item ids that must all be free'),
            'duration_minutes' => $schema->integer()->required()->description('Minimum window length in minutes'),
            'search_from' => $schema->string()->required()->description('Local start of the search, YYYY-MM-DDTHH:mm'),
            'search_to' => $schema->string()->required()->description('Local end of the search, YYYY-MM-DDTHH:mm'),
        ];
    }

    protected function run(Request $request): array
    {
        $request->validate([
            'equipment_ids' => ['required', 'array', 'min:1'],
            'equipment_ids.*' => ['integer'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:'.(config()->integer('booking.max_duration_hours') * 60)],
            'search_from' => self::timeRules(),
            'search_to' => [...self::timeRules(), 'after:search_from'],
        ]);

        $ids = array_values(array_map(intval(...), (array) $request['equipment_ids']));

        $openings = app(AvailabilityService::class)->findOpenings(
            $ids,
            (int) $request['duration_minutes'],
            $this->time($request, 'search_from'),
            $this->time($request, 'search_to'),
        );

        return [
            'ok' => true,
            'openings' => array_map(fn (array $opening) => [
                'starts' => self::when($opening['starts_at']),
                'ends' => self::when($opening['ends_at']),
            ], $openings),
            'note' => $openings === []
                ? 'No window found. An item may be out of service or busy the whole time; check with CheckAvailability.'
                : 'Each window is free for all items from starts to ends; a booking can use any part of it.',
        ];
    }
}
