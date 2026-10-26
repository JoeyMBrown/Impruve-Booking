<?php

namespace App\Http\Controllers;

use App\Domain\Booking\BookingConflictException;
use App\Domain\Booking\BookingDraft;
use App\Domain\Booking\BookingPresenter;
use App\Domain\Booking\BookingRuleException;
use App\Domain\Booking\BookingRuleViolation;
use App\Domain\Booking\BookingService;
use App\Domain\Booking\RuleCode;
use App\Domain\Booking\SlotClock;
use App\Enums\BookingSource;
use App\Enums\ProposalAction;
use App\Enums\ProposalStatus;
use App\Models\Booking;
use App\Models\BookingProposal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Confirm or dismiss a chat proposal. This is an ordinary authorised
 * endpoint: the model is never involved in saving (FR-CHAT-6). Responses:
 * 200 booked · 409 an item was taken · 410 expired · 422 a rule now fails.
 */
class ProposalController extends Controller
{
    public function __construct(private readonly BookingService $bookings) {}

    public function confirm(Request $request, BookingProposal $proposal): JsonResponse
    {
        Gate::authorize('confirm', $proposal);
        $user = $this->actor($request);

        return DB::transaction(function () use ($proposal, $user) {
            // Lock the proposal so a double click can't confirm it twice.
            $proposal = BookingProposal::query()->lockForUpdate()->findOrFail($proposal->id);

            if ($proposal->status === ProposalStatus::Pending && $proposal->expires_at->isPast()) {
                $proposal->update(['status' => ProposalStatus::Expired]);
            }

            if ($proposal->status === ProposalStatus::Expired) {
                return response()->json(['code' => 'expired', 'message' => 'This proposal expired. Ask again.'], 410);
            }

            if ($proposal->status !== ProposalStatus::Pending) {
                return response()->json(['code' => 'not_pending', 'status' => $proposal->status->value], 409);
            }

            try {
                $booking = $this->apply($proposal, $user);
            } catch (BookingConflictException $exception) {
                return $this->failed($proposal, 409, 'item_conflict', ['conflicts' => $exception->conflicts]);
            } catch (BookingRuleException $exception) {
                $conflict = $exception->result->first(RuleCode::ItemConflict);

                if ($conflict !== null) {
                    return $this->failed($proposal, 409, 'item_conflict', ['conflicts' => $conflict->details['conflicts'] ?? []]);
                }

                return $this->failed($proposal, 422, $exception->result->violations[0]->code->value, [
                    'errors' => array_map(fn (BookingRuleViolation $violation) => $violation->toArray(), $exception->result->violations),
                ]);
            }

            $proposal->update(['status' => ProposalStatus::Confirmed, 'booking_id' => $booking->id]);
            $booking->load(['user:id,name', 'equipment']);

            return response()->json([
                'booking' => BookingPresenter::toArray($booking, $user),
                'calendar_url' => route('calendar', ['week' => SlotClock::local($booking->starts_at)->format('o-\WW')]),
            ]);
        });
    }

    public function dismiss(BookingProposal $proposal): Response
    {
        Gate::authorize('dismiss', $proposal);

        if ($proposal->status === ProposalStatus::Pending) {
            $proposal->update(['status' => ProposalStatus::Dismissed]);
        }

        return response()->noContent();
    }

    /**
     * Carry out the proposal through the same rules engine as the form.
     *
     * @throws BookingRuleException
     * @throws BookingConflictException
     */
    private function apply(BookingProposal $proposal, User $user): Booking
    {
        if ($proposal->action === ProposalAction::Create) {
            return $this->bookings->create($this->draft($proposal), $user, BookingSource::Chat);
        }

        $booking = Booking::query()->findOrFail($proposal->booking_id);

        return $proposal->action === ProposalAction::Update
            ? $this->bookings->update($booking, $this->draft($proposal), $user)
            : $this->bookings->cancel($booking, $user);
    }

    private function draft(BookingProposal $proposal): BookingDraft
    {
        /** @var array{equipment_ids: list<int>, starts_at: string, ends_at: string, title: string, notes: string|null} $payload */
        $payload = $proposal->payload;

        return new BookingDraft(
            $payload['equipment_ids'],
            CarbonImmutable::parse($payload['starts_at']),
            CarbonImmutable::parse($payload['ends_at']),
            $payload['title'],
            $payload['notes'],
        );
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function failed(BookingProposal $proposal, int $status, string $code, array $details): JsonResponse
    {
        $proposal->update(['status' => ProposalStatus::Failed, 'failure' => ['code' => $code, ...$details]]);

        return response()->json(['code' => $code, ...$details], $status);
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
