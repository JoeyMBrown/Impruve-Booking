<?php

namespace App\Ai\Tools;

use App\Ai\ProposalCard;
use App\Domain\Booking\BookingRuleViolation;
use App\Domain\Booking\CheckResult;
use App\Enums\ProposalAction;
use App\Enums\ProposalStatus;
use App\Models\Booking;
use App\Models\BookingProposal;

/**
 * Shared by the propose tools. They never save a booking: they dry-run the
 * rules and store a pending proposal that the user confirms on a card
 * (FR-CHAT-6). Failed checks return the rule codes for the bot to explain.
 */
abstract class ProposeTool extends BookingTool
{
    /**
     * @param  array{equipment_ids: list<int>, starts_at: string, ends_at: string, title: string, notes: string|null}  $payload
     * @return array<string, mixed>
     */
    protected function propose(ProposalAction $action, array $payload, ?Booking $booking = null): array
    {
        $proposal = BookingProposal::create([
            'user_id' => $this->context->user->id,
            'conversation_id' => $this->context->conversationId ?? '',
            'action' => $action,
            'booking_id' => $booking?->id,
            'payload' => $payload,
            'status' => ProposalStatus::Pending,
            'expires_at' => now()->addMinutes(config()->integer('booking.chat.proposal_expiry_minutes')),
        ]);

        $card = ProposalCard::from($proposal);
        $this->context->proposals->add($card);

        return [
            'ok' => true,
            'proposal_id' => $proposal->id,
            'card' => [
                'action' => $card['action'],
                'title' => $card['title'],
                'items' => array_column($card['items'], 'name'),
                'when' => $card['display']['when'],
            ],
            'note' => 'A confirm card is now shown to the user. Nothing is saved until they press Confirm. Tell them to review the card.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function refused(CheckResult $result): array
    {
        return [
            'ok' => false,
            'error' => $result->violations[0]->code->value,
            'problems' => array_map(fn (BookingRuleViolation $violation) => [
                'code' => $violation->code->value,
                'message' => $violation->message,
                'details' => $violation->details,
            ], $result->violations),
            'note' => 'Nothing was proposed. Explain the problem in plain language and suggest alternatives.',
        ];
    }

    /**
     * The user's own active booking, or an error result: tools refuse other
     * people's bookings (FR-CHAT-7).
     *
     * @return Booking|array<string, mixed>
     */
    protected function ownBooking(mixed $id): Booking|array
    {
        $booking = Booking::query()->with('items')->find((int) $id);

        if ($booking === null) {
            return ['ok' => false, 'error' => 'not_found', 'message' => 'There is no booking with that id. Use ListMyBookings to find it.'];
        }

        if ($booking->user_id !== $this->context->user->id) {
            return ['ok' => false, 'error' => 'owner_only', 'message' => 'That booking belongs to someone else. Only its owner can change or cancel it.'];
        }

        return $booking;
    }
}
