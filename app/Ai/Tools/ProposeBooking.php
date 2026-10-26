<?php

namespace App\Ai\Tools;

use App\Domain\Booking\BookingDraft;
use App\Domain\Booking\BookingService;
use App\Enums\ProposalAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ProposeBooking extends ProposeTool
{
    public function description(): Stringable|string
    {
        return 'Propose a new booking for the user to confirm on a card. Only call this after CheckAvailability, '
            .'once the exact items and the exact start and end are known. It never saves the booking.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'equipment_ids' => $schema->array()->items($schema->integer())->required()->description('Item ids to book'),
            'starts_at' => $schema->string()->required()->description('Local start, YYYY-MM-DDTHH:mm'),
            'ends_at' => $schema->string()->required()->description('Local end, YYYY-MM-DDTHH:mm'),
            'title' => $schema->string()->required()->max(120)->description('Short title shown on the calendar'),
            'notes' => $schema->string()->description('Optional notes'),
        ];
    }

    protected function run(Request $request): array
    {
        $request->validate([
            'equipment_ids' => ['present', 'array'],
            'equipment_ids.*' => ['integer'],
            'starts_at' => self::timeRules(),
            'ends_at' => self::timeRules(),
            'title' => ['required', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $draft = new BookingDraft(
            array_map(intval(...), (array) $request['equipment_ids']),
            $this->time($request, 'starts_at'),
            $this->time($request, 'ends_at'),
            trim((string) $request['title']),
            filled($request['notes'] ?? null) ? trim((string) $request['notes']) : null,
        );

        $result = app(BookingService::class)->check($draft, $this->context->user);

        if (! $result->ok()) {
            return $this->refused($result);
        }

        return $this->propose(ProposalAction::Create, [
            'equipment_ids' => $draft->equipmentIds,
            'starts_at' => $draft->startsAt->toIso8601String(),
            'ends_at' => $draft->endsAt->toIso8601String(),
            'title' => $draft->title,
            'notes' => $draft->notes,
        ]);
    }
}
