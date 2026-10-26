<?php

namespace App\Http\Controllers;

use App\Domain\Booking\AvailabilityService;
use App\Domain\Booking\BookingConflictException;
use App\Domain\Booking\BookingDraft;
use App\Domain\Booking\BookingPresenter;
use App\Domain\Booking\BookingRuleException;
use App\Domain\Booking\BookingService;
use App\Domain\Booking\ItemAvailability;
use App\Domain\Booking\SlotClock;
use App\Enums\BookingPhase;
use App\Enums\BookingSource;
use App\Enums\EquipmentCategory;
use App\Enums\EquipmentStatus;
use App\Enums\ProposalAction;
use App\Http\Requests\BookingRequest;
use App\Models\Booking;
use App\Models\BookingProposal;
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
     * equipment page (?starts_at=&ends_at=&equipment[]=), or from a chat
     * proposal's "Edit in form" (?proposal={uuid}).
     */
    public function create(Request $request): Response
    {
        $proposal = $this->proposalFor($request, ProposalAction::Create);

        if ($proposal !== null) {
            return $this->renderForm(null, $this->valuesFromProposal($proposal), fromProposal: true);
        }

        $startsAt = $this->parseOrNull($request->string('starts_at')->toString())
            ?? SlotClock::ceil(SlotClock::now());
        $endsAt = $this->parseOrNull($request->string('ends_at')->toString());

        if ($endsAt === null || $endsAt->lessThanOrEqualTo($startsAt)) {
            $endsAt = $startsAt->addHour();
        }

        /** @var list<int|string> $requested */
        $requested = (array) $request->query('equipment', []);
        $equipmentIds = array_values(Equipment::query()
            ->whereIn('id', array_map(intval(...), $requested))
            ->where('status', '!=', EquipmentStatus::Retired)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all());

        return $this->renderForm(null, [
            'starts_at' => SlotClock::local($startsAt)->format(self::FORM_TIME_FORMAT),
            'ends_at' => SlotClock::local($endsAt)->format(self::FORM_TIME_FORMAT),
            'equipment_ids' => $equipmentIds,
            'title' => '',
            'notes' => '',
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
    public function edit(Request $request, Booking $booking): Response|RedirectResponse
    {
        Gate::authorize('update', $booking);

        $phase = $booking->phase();

        if (! $booking->is_active || $phase === BookingPhase::Ended) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'This booking can no longer be changed.']);

            return $this->redirectToWeekOf($booking);
        }

        $draft = BookingDraft::fromBooking($booking);
        $proposal = $this->proposalFor($request, ProposalAction::Update, $booking);

        return $this->renderForm(
            [
                'id' => $booking->id,
                'title' => $booking->title,
                'phase' => $phase,
                'ends_now_at' => $booking->ends_at->min(SlotClock::nextSlotAfter(SlotClock::now()))->toIso8601String(),
            ],
            $proposal !== null ? $this->valuesFromProposal($proposal) : [
                'starts_at' => SlotClock::local($booking->starts_at)->format(self::FORM_TIME_FORMAT),
                'ends_at' => SlotClock::local($booking->ends_at)->format(self::FORM_TIME_FORMAT),
                'equipment_ids' => $draft->equipmentIds,
                'title' => $booking->title,
                'notes' => $booking->notes ?? '',
            ],
            ignoreBookingId: $booking->id,
            fromProposal: $proposal !== null,
        );
    }

    /**
     * @param  array<string, mixed>|null  $booking
     * @param  array{starts_at: string, ends_at: string, equipment_ids: list<int>, title: string, notes: string}  $values
     */
    private function renderForm(?array $booking, array $values, ?int $ignoreBookingId = null, bool $fromProposal = false): Response
    {
        return Inertia::render('bookings/form', [
            'booking' => $booking,
            'values' => $values,
            'from_proposal' => $fromProposal,
            'availability' => $this->availabilityFor(
                SlotClock::parse($values['starts_at']),
                SlotClock::parse($values['ends_at']),
                $ignoreBookingId,
            ),
            ...$this->formOptions(),
        ]);
    }

    /**
     * The acting user's own proposal named in ?proposal=, for "Edit in form"
     * on a chat card. Anyone else's (or an unknown id) is ignored.
     */
    private function proposalFor(Request $request, ProposalAction $action, ?Booking $booking = null): ?BookingProposal
    {
        if (! $request->filled('proposal')) {
            return null;
        }

        return BookingProposal::query()
            ->whereKey($request->string('proposal')->toString())
            ->where('user_id', $this->actor($request)->id)
            ->where('action', $action)
            ->when($booking, fn ($query, Booking $booking) => $query->where('booking_id', $booking->id))
            ->first();
    }

    /**
     * @return array{starts_at: string, ends_at: string, equipment_ids: list<int>, title: string, notes: string}
     */
    private function valuesFromProposal(BookingProposal $proposal): array
    {
        /** @var array{equipment_ids: list<int>, starts_at: string, ends_at: string, title: string, notes: string|null} $payload */
        $payload = $proposal->payload;

        return [
            'starts_at' => SlotClock::local(CarbonImmutable::parse($payload['starts_at']))->format(self::FORM_TIME_FORMAT),
            'ends_at' => SlotClock::local(CarbonImmutable::parse($payload['ends_at']))->format(self::FORM_TIME_FORMAT),
            'equipment_ids' => $payload['equipment_ids'],
            'title' => $payload['title'],
            'notes' => $payload['notes'] ?? '',
        ];
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

            return $this->redirectBackFrom($request, $booking);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $wasInProgress ? "Ended: {$booking->title}." : "Cancelled: {$booking->title}.",
        ]);

        return $this->redirectBackFrom($request, $booking);
    }

    /**
     * The booking detail page. Everyone can see every booking; the page only
     * offers Edit and Cancel to the owner.
     */
    public function show(Request $request, Booking $booking): Response
    {
        Gate::authorize('view', $booking);

        $booking->load(['user:id,name', 'equipment']);

        return Inertia::render('bookings/show', [
            'booking' => BookingPresenter::toArray($booking, $this->actor($request)),
        ]);
    }

    /**
     * Back to the page the cancel came from (calendar, detail page, My
     * bookings), or the booking's calendar week when there isn't one. Never
     * back to the edit form, which no longer applies.
     */
    private function redirectBackFrom(Request $request, Booking $booking): RedirectResponse
    {
        $weekUrl = $this->weekUrl($booking);
        $previous = url()->previous($weekUrl);

        if ($previous === $request->url() || str_starts_with($previous, route('bookings.edit', $booking))) {
            return redirect()->to($weekUrl);
        }

        return redirect()->to($previous);
    }

    /**
     * Send the user to the calendar week that shows the booking.
     */
    private function redirectToWeekOf(Booking $booking): RedirectResponse
    {
        return redirect()->to($this->weekUrl($booking));
    }

    private function weekUrl(Booking $booking): string
    {
        return route('calendar', ['week' => SlotClock::local($booking->starts_at)->format('o-\WW')]);
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
