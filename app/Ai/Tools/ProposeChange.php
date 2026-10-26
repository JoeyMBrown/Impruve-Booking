<?php

namespace App\Ai\Tools;

use App\Ai\ProposalCard;
use App\Domain\Booking\BookingDraft;
use App\Domain\Booking\BookingService;
use App\Enums\ProposalAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ProposeChange extends ProposeTool
{
    public function description(): Stringable|string
    {
        return "Propose a change to one of the user's own bookings (new items, times, title or notes) for them to confirm on a card. "
            .'Pass only the fields that change. Find the booking id with ListMyBookings. It never saves the change.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'booking_id' => $schema->integer()->required()->description("The user's booking to change"),
            'equipment_ids' => $schema->array()->items($schema->integer())->description('The full new list of item ids'),
            'starts_at' => $schema->string()->description('New local start, YYYY-MM-DDTHH:mm'),
            'ends_at' => $schema->string()->description('New local end, YYYY-MM-DDTHH:mm'),
            'title' => $schema->string()->max(120)->description('New title'),
            'notes' => $schema->string()->description('New notes'),
        ];
    }

    protected function run(Request $request): array
    {
        $request->validate([
            'booking_id' => ['required', 'integer'],
            'equipment_ids' => ['nullable', 'array'],
            'equipment_ids.*' => ['integer'],
            'starts_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'title' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $booking = $this->ownBooking($request['booking_id']);

        if (is_array($booking)) {
            return $booking;
        }

        $current = BookingDraft::fromBooking($booking);
        $draft = new BookingDraft(
            isset($request['equipment_ids']) ? array_map(intval(...), (array) $request['equipment_ids']) : $current->equipmentIds,
            isset($request['starts_at']) ? $this->time($request, 'starts_at') : $current->startsAt,
            isset($request['ends_at']) ? $this->time($request, 'ends_at') : $current->endsAt,
            isset($request['title']) ? trim((string) $request['title']) : $current->title,
            array_key_exists('notes', $request->all()) ? (filled($request['notes']) ? trim((string) $request['notes']) : null) : $current->notes,
        );

        $result = app(BookingService::class)->check($draft, $this->context->user, $booking);

        if (! $result->ok()) {
            return $this->refused($result);
        }

        return $this->propose(ProposalAction::Update, [
            ...ProposalCard::payloadFromBooking($booking),
            'equipment_ids' => $draft->equipmentIds,
            'starts_at' => $draft->startsAt->toIso8601String(),
            'ends_at' => $draft->endsAt->toIso8601String(),
            'title' => $draft->title,
            'notes' => $draft->notes,
        ], $booking);
    }
}
