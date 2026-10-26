<?php

namespace App\Domain\Booking;

use App\Enums\BookingPhase;
use App\Enums\BookingSource;
use App\Enums\EquipmentStatus;
use App\Models\Booking;
use App\Models\Equipment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The single source of booking rules. The manual form, the confirm endpoint
 * and the chat tools (as a dry run) all go through this class, so the bot
 * can never book anything the form wouldn't allow.
 */
class BookingService
{
    public function __construct(private readonly AvailabilityService $availability) {}

    /**
     * Dry run: every rule the draft breaks, without saving anything. Pass
     * the existing booking when checking an update.
     */
    public function check(BookingDraft $draft, User $actor, ?Booking $existing = null): CheckResult
    {
        $now = SlotClock::now();

        if ($existing !== null) {
            if ($existing->user_id !== $actor->id) {
                return CheckResult::fail($this->ownerOnly());
            }

            if (! $existing->is_active) {
                return CheckResult::fail($this->immutable('This booking was cancelled and can no longer be changed.'));
            }

            $phase = $existing->phase($now);

            if ($phase === BookingPhase::Ended) {
                return CheckResult::fail($this->immutable('This booking has ended and can no longer be changed.'));
            }

            if ($phase === BookingPhase::InProgress) {
                return $this->checkInProgressChange($draft, $existing, $now);
            }
        }

        $currentItems = $existing ? $this->itemIds($existing) : null;
        $timesChanged = $existing === null
            || ! $draft->startsAt->equalTo($existing->starts_at)
            || ! $draft->endsAt->equalTo($existing->ends_at);
        $itemsChanged = $currentItems === null || $draft->hasDifferentItemsThan($currentItems);
        $rangeIsValid = $draft->endsAt->greaterThan($draft->startsAt);

        $violations = [];

        if (! SlotClock::isOnSlot($draft->startsAt) || ! SlotClock::isOnSlot($draft->endsAt) || ! $rangeIsValid) {
            $violations[] = $this->invalidRange($draft);
        }

        if ($rangeIsValid && $this->durationMinutes($draft) > config()->integer('booking.max_duration_hours') * 60) {
            $violations[] = new BookingRuleViolation(
                RuleCode::TooLong,
                sprintf('Bookings can be at most %d hours (4 days) long.', config()->integer('booking.max_duration_hours')),
                'ends_at',
                ['max_hours' => config()->integer('booking.max_duration_hours')],
            );
        }

        $startChanged = $existing === null || ! $draft->startsAt->equalTo($existing->starts_at);
        $earliestStart = $now->subMinutes(config()->integer('booking.past_start_grace_minutes'));

        if ($startChanged && $draft->startsAt->lessThan($earliestStart)) {
            $violations[] = new BookingRuleViolation(
                RuleCode::StartsInPast,
                'Bookings can’t start in the past.',
                'starts_at',
                ['earliest_start' => SlotClock::ceil($earliestStart)->toIso8601String()],
            );
        }

        if ($draft->equipmentIds === []) {
            $violations[] = new BookingRuleViolation(RuleCode::NoItems, 'Choose at least one item.', 'equipment_ids');
        }

        if ($draft->equipmentIds !== [] && ($timesChanged || $itemsChanged)) {
            $unbookable = $this->unbookableItems($draft->equipmentIds);

            if ($unbookable !== []) {
                $violations[] = new BookingRuleViolation(
                    RuleCode::ItemUnbookable,
                    implode(' ', array_map(fn (array $item) => "{$item['name']} can’t be booked ({$item['status_label']}).", $unbookable)),
                    'equipment_ids',
                    ['items' => $unbookable],
                );
            }
        }

        if ($draft->equipmentIds !== [] && $rangeIsValid) {
            $conflicts = $this->availability->conflicts($draft->equipmentIds, $draft->startsAt, $draft->endsAt, $existing?->id);

            if ($conflicts !== []) {
                $violations[] = $this->itemConflict($conflicts);
            }
        }

        return new CheckResult($violations);
    }

    /**
     * Save a new booking. The exclusion constraint is the final guard if
     * another save wins a race after the check.
     *
     * @throws BookingRuleException
     * @throws BookingConflictException
     */
    public function create(BookingDraft $draft, User $actor, BookingSource $source): Booking
    {
        $this->assertPasses($this->check($draft, $actor));

        return $this->guardConflicts($draft, null, function () use ($draft, $actor, $source) {
            $booking = Booking::create([
                'user_id' => $actor->id,
                'title' => $draft->title,
                'notes' => $draft->notes,
                'starts_at' => $draft->startsAt,
                'ends_at' => $draft->endsAt,
                'source' => $source,
                'is_active' => true,
            ]);

            $this->addItems($booking, $draft->equipmentIds);

            return $booking;
        });
    }

    /**
     * Save changes to a booking. Only the bookings row is written for the
     * range; the cascading foreign key updates every item row's copy.
     *
     * @throws BookingRuleException
     * @throws BookingConflictException
     */
    public function update(Booking $booking, BookingDraft $draft, User $actor): Booking
    {
        $this->assertPasses($this->check($draft, $actor, $booking));

        return $this->guardConflicts($draft, $booking->id, function () use ($booking, $draft) {
            $booking->update([
                'title' => $draft->title,
                'notes' => $draft->notes,
                'starts_at' => $draft->startsAt,
                'ends_at' => $draft->endsAt,
            ]);

            $current = $this->itemIds($booking);
            $removed = array_values(array_diff($current, $draft->equipmentIds));
            $added = array_values(array_diff($draft->equipmentIds, $current));

            if ($removed !== []) {
                $booking->items()->whereIn('equipment_id', $removed)->delete();
            }

            $this->addItems($booking, $added);

            return $booking->refresh();
        });
    }

    /**
     * Cancel an upcoming booking, or end an in-progress one now (its end
     * moves to the next slot boundary). Ended bookings can't be cancelled.
     *
     * @throws BookingRuleException
     */
    public function cancel(Booking $booking, User $actor): Booking
    {
        if ($booking->user_id !== $actor->id) {
            throw new BookingRuleException(CheckResult::fail($this->ownerOnly()));
        }

        if (! $booking->is_active) {
            throw new BookingRuleException(CheckResult::fail($this->immutable('This booking is already cancelled.')));
        }

        $now = SlotClock::now();

        $changes = match ($booking->phase($now)) {
            BookingPhase::Upcoming => ['cancelled_at' => $now, 'is_active' => false],
            BookingPhase::InProgress => ['ends_at' => $booking->ends_at->min(SlotClock::nextSlotAfter($now))],
            BookingPhase::Ended => throw new BookingRuleException(CheckResult::fail($this->immutable('This booking has ended and can’t be cancelled.'))),
        };

        // The cascading foreign key carries the change to every item row.
        $booking->update($changes);

        return $booking;
    }

    /**
     * @throws BookingRuleException
     */
    private function assertPasses(CheckResult $result): void
    {
        if (! $result->ok()) {
            throw new BookingRuleException($result);
        }
    }

    /**
     * Run the write in a transaction, turning an exclusion-constraint
     * violation (SQLSTATE 23P01) into a conflict listing the items now taken.
     *
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     *
     * @throws BookingConflictException
     */
    private function guardConflicts(BookingDraft $draft, ?int $ignoreBookingId, Closure $write): mixed
    {
        try {
            return DB::transaction($write);
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23P01') {
                throw $exception;
            }

            throw new BookingConflictException(
                $this->availability->conflicts($draft->equipmentIds, $draft->startsAt, $draft->endsAt, $ignoreBookingId),
            );
        }
    }

    /**
     * Insert item rows carrying a copy of the booking's range and active
     * flag. After this, the composite foreign key keeps the copies in sync.
     *
     * @param  list<int>  $equipmentIds
     */
    private function addItems(Booking $booking, array $equipmentIds): void
    {
        foreach ($equipmentIds as $equipmentId) {
            $booking->items()->create([
                'equipment_id' => $equipmentId,
                'starts_at' => $booking->starts_at,
                'ends_at' => $booking->ends_at,
                'is_active' => $booking->is_active,
            ]);
        }
    }

    /**
     * An in-progress booking may only be shortened: its end moved to a slot
     * after now and before the current end. Nothing else can change.
     */
    private function checkInProgressChange(BookingDraft $draft, Booking $existing, CarbonImmutable $now): CheckResult
    {
        $onlyEndChanged = $draft->startsAt->equalTo($existing->starts_at)
            && ! $draft->hasDifferentItemsThan($this->itemIds($existing))
            && $draft->title === $existing->title
            && $draft->notes === $existing->notes;

        if (! $onlyEndChanged) {
            return CheckResult::fail($this->immutable('This booking is in progress. You can only shorten it or end it now.'));
        }

        if ($draft->endsAt->equalTo($existing->ends_at)) {
            return CheckResult::pass();
        }

        if (! SlotClock::isOnSlot($draft->endsAt)) {
            return CheckResult::fail($this->invalidRange($draft));
        }

        if ($draft->endsAt->greaterThan($existing->ends_at)) {
            return CheckResult::fail($this->immutable('An in-progress booking can’t be extended. Make a new booking for the extra time.'));
        }

        if ($draft->endsAt->lessThanOrEqualTo($now)) {
            return CheckResult::fail($this->immutable('The new end must be later than now. Use End now to finish the booking.'));
        }

        return CheckResult::pass();
    }

    private function ownerOnly(): BookingRuleViolation
    {
        return new BookingRuleViolation(RuleCode::OwnerOnly, 'Only the person who made this booking can change or cancel it.', 'booking');
    }

    private function immutable(string $message): BookingRuleViolation
    {
        return new BookingRuleViolation(RuleCode::ImmutablePhase, $message, 'booking');
    }

    private function invalidRange(BookingDraft $draft): BookingRuleViolation
    {
        $minutes = SlotClock::slotMinutes();

        return new BookingRuleViolation(
            RuleCode::InvalidRange,
            $draft->endsAt->greaterThan($draft->startsAt)
                ? "Start and end times must be on {$minutes}-minute steps (e.g. 9:00 or 9:30)."
                : 'The end must be after the start.',
            'ends_at',
            ['slot_minutes' => $minutes],
        );
    }

    /**
     * @param  list<array{equipment_id: int, equipment_name: string, booking_id: int, title: string, owner: array{id: int, name: string}, starts_at: string, ends_at: string}>  $conflicts
     */
    private function itemConflict(array $conflicts): BookingRuleViolation
    {
        $messages = array_map(fn (array $conflict) => sprintf(
            '%s is booked by %s (%s) %s.',
            $conflict['equipment_name'],
            $conflict['owner']['name'],
            $conflict['title'],
            $this->describeRange(CarbonImmutable::parse($conflict['starts_at']), CarbonImmutable::parse($conflict['ends_at'])),
        ), $conflicts);

        return new BookingRuleViolation(RuleCode::ItemConflict, implode(' ', $messages), 'equipment_ids', ['conflicts' => $conflicts]);
    }

    /**
     * Requested items that aren't available, including unknown and retired ones.
     *
     * @param  list<int>  $equipmentIds
     * @return list<array{id: int, name: string, status: string, status_label: string, reason: string|null}>
     */
    private function unbookableItems(array $equipmentIds): array
    {
        $equipment = Equipment::query()->whereIn('id', $equipmentIds)->get()->keyBy('id');
        $unbookable = [];

        foreach ($equipmentIds as $id) {
            $item = $equipment->get($id);

            if ($item === null) {
                $unbookable[] = ['id' => $id, 'name' => "Item #{$id}", 'status' => 'missing', 'status_label' => 'not found', 'reason' => null];
            } elseif ($item->status !== EquipmentStatus::Available) {
                $unbookable[] = [
                    'id' => $item->id,
                    'name' => $item->name,
                    'status' => $item->status->value,
                    'status_label' => mb_strtolower($item->status->label()),
                    'reason' => $item->status_reason,
                ];
            }
        }

        return $unbookable;
    }

    /**
     * @return list<int>
     */
    private function itemIds(Booking $booking): array
    {
        return $booking->items()->pluck('equipment_id')->map(fn ($id) => (int) $id)->values()->all();
    }

    private function durationMinutes(BookingDraft $draft): float
    {
        // Real elapsed time (matches the DB CHECK), so DST changes count.
        return $draft->startsAt->diffInMinutes($draft->endsAt);
    }

    private function describeRange(CarbonInterface $from, CarbonInterface $to): string
    {
        $from = SlotClock::local($from);
        $to = SlotClock::local($to);

        return $from->isSameDay($to)
            ? $from->format('D M j, g:i A').' – '.$to->format('g:i A')
            : $from->format('D M j, g:i A').' – '.$to->format('D M j, g:i A');
    }
}
