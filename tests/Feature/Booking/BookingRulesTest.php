<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\BookingDraft;
use App\Domain\Booking\BookingService;
use App\Domain\Booking\CheckResult;
use App\Domain\Booking\RuleCode;
use App\Models\Equipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BooksEquipment;
use Tests\TestCase;

/**
 * BookingService::check: one test per rule code, plus phase rules, the 96 h
 * boundary, the 15-minute grace and DST. "Now" is Wed Oct 28, 12:00 CDT.
 */
class BookingRulesTest extends TestCase
{
    use BooksEquipment, RefreshDatabase;

    private BookingService $service;

    private User $ana;

    private Equipment $camera;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeAt();
        $this->service = app(BookingService::class);
        $this->ana = User::factory()->create(['name' => 'Ana']);
        $this->camera = Equipment::factory()->create(['name' => 'Sony FX3 #1']);
    }

    public function test_a_valid_booking_passes()
    {
        $result = $this->service->check($this->draft($this->camera, '2026-10-29 09:00', '2026-10-29 17:00'), $this->ana);

        $this->assertTrue($result->ok());
    }

    // owner_only

    public function test_only_the_owner_can_change_a_booking()
    {
        $booking = $this->book($this->camera, '2026-10-29 09:00', '2026-10-29 12:00');

        $result = $this->service->check(BookingDraft::fromBooking($booking), $this->ana, $booking);

        $this->assertSame([RuleCode::OwnerOnly], $result->codes());
    }

    // immutable_phase

    public function test_ended_bookings_cannot_change()
    {
        $booking = $this->book($this->camera, '2026-10-27 09:00', '2026-10-27 12:00', $this->ana);

        $result = $this->service->check(BookingDraft::fromBooking($booking)->with(title: 'Renamed'), $this->ana, $booking);

        $this->assertSame([RuleCode::ImmutablePhase], $result->codes());
    }

    public function test_cancelled_bookings_cannot_change()
    {
        $booking = $this->book($this->camera, '2026-10-29 09:00', '2026-10-29 12:00', $this->ana, cancelled: true);

        $result = $this->service->check(BookingDraft::fromBooking($booking)->with(title: 'Renamed'), $this->ana, $booking);

        $this->assertSame([RuleCode::ImmutablePhase], $result->codes());
    }

    public function test_in_progress_bookings_can_be_shortened_to_a_later_slot()
    {
        $booking = $this->book($this->camera, '2026-10-28 10:00', '2026-10-28 17:00', $this->ana);
        $draft = BookingDraft::fromBooking($booking);

        $this->assertTrue($this->service->check($draft->with(endsAt: $this->at('2026-10-28 15:00')), $this->ana, $booking)->ok());
        $this->assertTrue($this->service->check($draft->with(endsAt: $this->at('2026-10-28 12:30')), $this->ana, $booking)->ok());
        $this->assertTrue($this->service->check($draft, $this->ana, $booking)->ok(), 'No change is allowed.');
    }

    public function test_in_progress_bookings_cannot_change_anything_but_shortening_the_end()
    {
        $booking = $this->book($this->camera, '2026-10-28 10:00', '2026-10-28 17:00', $this->ana);
        $other = Equipment::factory()->create();
        $draft = BookingDraft::fromBooking($booking);

        $refused = [
            'title' => $draft->with(title: 'Renamed'),
            'notes' => $draft->with(notes: 'New notes'),
            'start' => $draft->with(startsAt: $this->at('2026-10-28 10:30')),
            'items' => $draft->with(equipmentIds: [$this->camera->id, $other->id]),
            'extend' => $draft->with(endsAt: $this->at('2026-10-28 18:00')),
            'end in past' => $draft->with(endsAt: $this->at('2026-10-28 11:00')),
            'end now-ish' => $draft->with(endsAt: $this->at('2026-10-28 12:00')),
        ];

        foreach ($refused as $label => $change) {
            $this->assertSame([RuleCode::ImmutablePhase], $this->service->check($change, $this->ana, $booking)->codes(), $label);
        }
    }

    public function test_shortening_ignores_unavailable_items()
    {
        $booking = $this->book($this->camera, '2026-10-28 10:00', '2026-10-28 17:00', $this->ana);
        $this->camera->update(['status' => 'out_of_service']);

        $result = $this->service->check(BookingDraft::fromBooking($booking)->with(endsAt: $this->at('2026-10-28 14:00')), $this->ana, $booking);

        $this->assertTrue($result->ok());
    }

    // invalid_range

    public function test_start_and_end_must_be_on_30_minute_steps()
    {
        $this->assertSame([RuleCode::InvalidRange], $this->check('2026-10-29 09:15', '2026-10-29 12:00')->codes());
        $this->assertSame([RuleCode::InvalidRange], $this->check('2026-10-29 09:00', '2026-10-29 12:10')->codes());
    }

    public function test_end_must_be_after_start()
    {
        $this->assertSame([RuleCode::InvalidRange], $this->check('2026-10-29 12:00', '2026-10-29 12:00')->codes());
        $this->assertSame([RuleCode::InvalidRange], $this->check('2026-10-29 12:00', '2026-10-29 09:00')->codes());
    }

    // too_long

    public function test_bookings_can_be_96_hours_but_not_longer()
    {
        $this->assertTrue($this->check('2026-11-06 09:00', '2026-11-10 09:00')->ok(), 'Fri → Tue 09:00 is 96 h (no DST change).');
        $this->assertSame([RuleCode::TooLong], $this->check('2026-11-06 09:00', '2026-11-10 09:30')->codes());
    }

    public function test_the_96_hour_cap_counts_real_time_across_dst()
    {
        // Clocks fall back on Sun Nov 1, so Fri 09:00 → Tue 09:00 is 97 real hours.
        $this->assertSame([RuleCode::TooLong], $this->check('2026-10-30 09:00', '2026-11-03 09:00')->codes());
        $this->assertTrue($this->check('2026-10-30 09:00', '2026-11-03 08:00')->ok());
    }

    // starts_in_past

    public function test_bookings_may_start_up_to_15_minutes_in_the_past()
    {
        $this->assertTrue($this->check('2026-10-28 12:00', '2026-10-28 13:00')->ok());

        $this->travelTo($this->at('2026-10-28 12:15'));
        $this->assertTrue($this->check('2026-10-28 12:00', '2026-10-28 13:00')->ok(), 'Exactly at the grace boundary.');

        $this->travelTo($this->at('2026-10-28 12:15:01'));
        $this->assertSame([RuleCode::StartsInPast], $this->check('2026-10-28 12:00', '2026-10-28 13:00')->codes());
    }

    public function test_starts_in_past_only_applies_when_the_start_changes()
    {
        $booking = $this->book($this->camera, '2026-10-29 09:00', '2026-10-29 12:00', $this->ana);

        $result = $this->service->check(BookingDraft::fromBooking($booking)->with(startsAt: $this->at('2026-10-27 09:00')), $this->ana, $booking);
        $this->assertTrue($result->has(RuleCode::StartsInPast));

        $result = $this->service->check(BookingDraft::fromBooking($booking)->with(endsAt: $this->at('2026-10-29 13:00')), $this->ana, $booking);
        $this->assertTrue($result->ok());
    }

    // no_items

    public function test_at_least_one_item_is_required()
    {
        $draft = new BookingDraft([], $this->at('2026-10-29 09:00'), $this->at('2026-10-29 12:00'), 'Shoot');

        $this->assertSame([RuleCode::NoItems], $this->service->check($draft, $this->ana)->codes());
    }

    // item_unbookable

    public function test_out_of_service_retired_and_unknown_items_cannot_be_booked()
    {
        $broken = Equipment::factory()->outOfService('Bulb replacement')->create(['name' => 'Aputure Kit B']);
        $retired = Equipment::factory()->retired()->create();
        $draft = new BookingDraft([$broken->id, $retired->id, 999], $this->at('2026-10-29 09:00'), $this->at('2026-10-29 12:00'), 'Shoot');

        $result = $this->service->check($draft, $this->ana);

        $this->assertSame([RuleCode::ItemUnbookable], $result->codes());
        $details = $result->first(RuleCode::ItemUnbookable)->details ?? [];
        $this->assertSame([$broken->id, $retired->id, 999], array_column($details['items'], 'id'));
        $this->assertSame('Bulb replacement', $details['items'][0]['reason']);
    }

    public function test_an_at_risk_booking_can_still_be_renamed()
    {
        $booking = $this->book($this->camera, '2026-10-29 09:00', '2026-10-29 12:00', $this->ana);
        $this->camera->update(['status' => 'out_of_service']);

        $draft = BookingDraft::fromBooking($booking);

        $this->assertTrue($this->service->check($draft->with(title: 'Renamed'), $this->ana, $booking)->ok());
        $this->assertSame([RuleCode::ItemUnbookable], $this->service->check($draft->with(endsAt: $this->at('2026-10-29 13:00')), $this->ana, $booking)->codes());
    }

    // item_conflict

    public function test_items_cannot_be_in_two_overlapping_active_bookings()
    {
        $sam = User::factory()->create(['name' => 'Sam']);
        $existing = $this->book($this->camera, '2026-10-29 10:00', '2026-10-29 14:00', $sam, title: 'Doc shoot');

        $result = $this->check('2026-10-29 09:00', '2026-10-29 12:00');

        $this->assertSame([RuleCode::ItemConflict], $result->codes());
        $conflict = ($result->first(RuleCode::ItemConflict)->details ?? [])['conflicts'][0];
        $this->assertSame($existing->id, $conflict['booking_id']);
        $this->assertSame('Sam', $conflict['owner']['name']);
        $this->assertSame('Doc shoot', $conflict['title']);
        $this->assertStringContainsString('Sony FX3 #1 is booked by Sam', $result->first(RuleCode::ItemConflict)->message ?? '');
    }

    public function test_back_to_back_bookings_do_not_conflict()
    {
        $this->book($this->camera, '2026-10-29 09:00', '2026-10-29 12:00');

        $this->assertTrue($this->check('2026-10-29 12:00', '2026-10-29 15:00')->ok());
    }

    public function test_bookings_may_overlap_in_time_when_they_share_no_item()
    {
        $this->book(Equipment::factory()->create(), '2026-10-29 09:00', '2026-10-29 12:00');

        $this->assertTrue($this->check('2026-10-29 09:00', '2026-10-29 12:00')->ok());
    }

    public function test_cancelled_bookings_do_not_conflict()
    {
        $this->book($this->camera, '2026-10-29 09:00', '2026-10-29 12:00', cancelled: true);

        $this->assertTrue($this->check('2026-10-29 09:00', '2026-10-29 12:00')->ok());
    }

    public function test_an_update_does_not_conflict_with_itself()
    {
        $booking = $this->book($this->camera, '2026-10-29 09:00', '2026-10-29 12:00', $this->ana);

        $result = $this->service->check(BookingDraft::fromBooking($booking)->with(endsAt: $this->at('2026-10-29 13:00')), $this->ana, $booking);

        $this->assertTrue($result->ok());
    }

    public function test_every_broken_rule_is_reported_together()
    {
        $draft = new BookingDraft([], $this->at('2026-10-27 09:15'), $this->at('2026-10-27 12:00'), 'Shoot');

        $this->assertSame(
            [RuleCode::InvalidRange, RuleCode::StartsInPast, RuleCode::NoItems],
            $this->service->check($draft, $this->ana)->codes(),
        );
    }

    private function check(string $from, string $to): CheckResult
    {
        return $this->service->check($this->draft($this->camera, $from, $to), $this->ana);
    }
}
