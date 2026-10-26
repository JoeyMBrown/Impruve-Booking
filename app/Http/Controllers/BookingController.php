<?php

namespace App\Http\Controllers;

use App\Domain\Booking\AvailabilityService;
use App\Domain\Booking\BookingDraft;
use App\Domain\Booking\BookingConflictException;
use App\Domain\Booking\BookingRuleException;
use App\Domain\Booking\BookingService;
use App\Domain\Booking\ItemAvailability;
use App\Domain\Booking\SlotClock;
use App\Enums\BookingPhase;
use App\Enums\BookingSource;
use App\Enums\EquipmentCategory;
use App\Enums\EquipmentStatus;
use App\Http\Requests\BookingRequest;
use App\Models\Booking;
use App\Models\Equipment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class BookingController extends Controller
{
    /**
     * The format the form's date and time pickers use, in company time.
     */
    private const string FORM_TIME_FORMAT = 'Y-m-d\TH:i';

    public function __construct(
        private readonly BookingService $bookings,
        private readonly AvailabilityService $availability,
    ) {}

    /**
     * The booking form, optionally prefilled from a calendar slot or an
     * equipment page: ?starts_at=&ends_at=&equipment[]=
     */
    public function create(Request $request): Response
    {
        $startsAt = $this->parseOrNull($request->string('starts_at')->toString())
            ?? SlotClock::ceil(SlotClock::now());
        $endsAt = $this->parseOrNull($request->string('ends_at')->toString());

        if ($endsAt === null || $endsAt->lessThanOrEqualTo($startsAt)) {
            $endsAt = $startsAt->addHour();
        }

        /** @var list<int|string> $requested */
        $requested = (array) $request->query('equipment', []);
        $equipmentIds = Equipment::query()
            ->whereIn('id', array_map(intval(...), $requested))
            ->where('status', '!=', EquipmentStatus::Retired)
            ->pluck('id')
            ->all();

        return Inertia::render('bookings/form', [
            'booking' => null,
            'values' => [
                'starts_at' => SlotClock::local($startsAt)->format(self::FORM_TIME_FORMAT),
                'ends_at' => SlotClock::local($endsAt)->format(self::FORM_TIME_FORMAT),
                'equipment_ids' => $equipmentIds,
                'title' => '',
                'notes' => '',
            ],
            'availability' => $this->availabilityFor($startsAt, $endsAt),
            ...$this->formOptions(),
        ]);
    }

    public function store(BookingRequest $request): RedirectResponse
    {
        try {
            $booking = $this->bookings->create($request->toDraft(), $this->actor($request), BookingSource::Manual);
        } catch (BookingRuleException|BookingConflictException $exception) {
            throw $this->validationErrors($exception);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Booked: {$booking->title}."]);

        return $this->redirectToWeekOf($booking);
    }

    /**
     * The same form for the owner's booking. An in-progress booking opens in
     * a restricted mode where only the end can move (or End now).
     */
    public function edit(Booking $booking): Response|RedirectResponse
    {
        Gate::authorize('update', $booking);

        $phase = $booking->phase();

        if (! $booking->is_active || $phase === BookingPhase::Ended) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'This booking can no longer be changed.']);

            return $this->redirectToWeekOf($booking);
        }

        $draft = BookingDraft::fromBooking($booking);

        return Inertia::render('bookings/form', [
            'booking' => [
                'id' => $booking->id,
                'title' => $booking->title,
                'phase' => $phase,
                'ends_now_at' => $booking->ends_at->min(SlotClock::nextSlotAfter(SlotClock::now()))->toIso8601String(),
            ],
            'values' => [
                'starts_at' => SlotClock::local($booking->starts_at)->format(self::FORM_TIME_FORMAT),
                'ends_at' => SlotClock::local($booking->ends_at)->format(self::FORM_TIME_FORMAT),
                'equipment_ids' => $draft->equipmentIds,
                'title' => $booking->title,
                'notes' => $booking->notes ?? '',
            ],
            'availability' => $this->availabilityFor($booking->starts_at, $booking->ends_at, $booking->id),
            ...$this->formOptions(),
        ]);
    }

    public function update(BookingRequest $request, Booking $booking): RedirectResponse
    {
        Gate::authorize('update', $booking);

        try {
            $booking = $this->bookings->update($booking, $request->toDraft(), $this->actor($request));
        } catch (BookingRuleException|BookingConflictException $exception) {
            throw $this->validationErrors($exception);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Saved: {$booking->title}."]);

        return $this->redirectToWeekOf($booking);
    }

    /**
     * Cancel an upcoming booking, or end an in-progress one now.
     */
    public function cancel(Request $request, Booking $booking): RedirectResponse
    {
        Gate::authorize('cancel', $booking);

        $wasInProgress = $booking->phase() === BookingPhase::InProgress;

        try {
            $this->bookings->cancel($booking, $this->actor($request));
        } catch (BookingRuleException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return $this->redirectToWeekOf($booking);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $wasInProgress ? "Ended: {$booking->title}." : "Cancelled: {$booking->title}.",
        ]);

        return $this->redirectToWeekOf($booking);
    }

    /**
     * Send the user to the calendar week that shows the booking.
     */
    private function redirectToWeekOf(Booking $booking): RedirectResponse
    {
        return redirect()->route('calendar', [
            'week' => SlotClock::local($booking->starts_at)->format('o-\WW'),
        ]);
    }

    /**
     * Rule failures become ordinary field errors, keyed by the field each
     * rule belongs to (e.g. too_long under the times, conflicts under items).
     */
    private function validationErrors(BookingRuleException|BookingConflictException $exception): ValidationException
    {
        if ($exception instanceof BookingConflictException) {
            return ValidationException::withMessages(['equipment_ids' => $exception->getMessage()]);
        }

        $messages = [];

        foreach ($exception->result->violations as $violation) {
            $messages[$violation->field][] = $violation->message;
        }

        return ValidationException::withMessages($messages);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function availabilityFor(CarbonImmutable $startsAt, CarbonImmutable $endsAt, ?int $ignoreBookingId = null): array
    {
        return array_values($this->availability
            ->forRange($startsAt, $endsAt, ignoreBookingId: $ignoreBookingId)
            ->map(fn (ItemAvailability $item) => $item->toArray())
            ->all());
    }

    /**
     * @return array{categories: list<array{value: string, label: string}>, limits: array{slot_minutes: int, max_duration_hours: int}}
     */
    private function formOptions(): array
    {
        return [
            'categories' => array_map(
                fn (EquipmentCategory $category) => ['value' => $category->value, 'label' => $category->label()],
                EquipmentCategory::cases(),
            ),
            'limits' => [
                'slot_minutes' => SlotClock::slotMinutes(),
                'max_duration_hours' => config()->integer('booking.max_duration_hours'),
            ],
        ];
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function parseOrNull(string $value): ?CarbonImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            return SlotClock::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
