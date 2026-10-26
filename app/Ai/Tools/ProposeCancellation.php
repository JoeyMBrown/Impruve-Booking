<?php

namespace App\Ai\Tools;

use App\Ai\ProposalCard;
use App\Domain\Booking\BookingRuleViolation;
use App\Domain\Booking\CheckResult;
use App\Domain\Booking\RuleCode;
use App\Domain\Booking\SlotClock;
use App\Enums\BookingPhase;
use App\Enums\ProposalAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ProposeCancellation extends ProposeTool
{
    public function description(): Stringable|string
    {
        return "Propose cancelling one of the user's own bookings for them to confirm on a card. If the booking is in progress, "
            .'confirming ends it early at the next 30-minute step instead. Find the booking id with ListMyBookings. It never cancels anything itself.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'booking_id' => $schema->integer()->required()->description("The user's booking to cancel"),
        ];
    }

    protected function run(Request $request): array
    {
        $request->validate(['booking_id' => ['required', 'integer']]);

        $booking = $this->ownBooking($request['booking_id']);

        if (is_array($booking)) {
            return $booking;
        }

        $phase = $booking->phase(SlotClock::now());

        if (! $booking->is_active || $phase === BookingPhase::Ended) {
            return $this->refused(CheckResult::fail(new BookingRuleViolation(
                RuleCode::ImmutablePhase,
                $booking->is_active ? 'This booking has already ended.' : 'This booking is already cancelled.',
                'booking',
            )));
        }

        $result = $this->propose(ProposalAction::Cancel, ProposalCard::payloadFromBooking($booking), $booking);
        $result['ends_early'] = $phase === BookingPhase::InProgress;

        return $result;
    }
}
