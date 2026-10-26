<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\BookingDraft;
use App\Domain\Booking\BookingRuleException;
use App\Domain\Booking\BookingService;
use App\Domain\Booking\RuleCode;
use App\Enums\BookingSource;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Equipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\BooksEquipment;
use Tests\TestCase;

/**
 * BookingService create / update / cancel. Item rows must always match the
 * booking's range and active flag through the cascading foreign key.
 */
class BookingWritesTest extends TestCase
{
    use BooksEquipment, RefreshDatabase;

    private BookingService $service;

    private User $ana;

    private Equipment $camera;

    private Equipment $light;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeAt();
        $this->service = app(BookingService::class);
        $this->ana = User::factory()->create();
        $this->camera = Equipment::factory()->create();
        $this->light = Equipment::factory()->create();
    }

    public function test_create_saves_the_booking_and_its_items_with_a_copied_range()
    {
        $booking = $this->service->create(
            $this->draft([$this->camera, $this->light], '2026-10-29 09:00', '2026-10-29 17:00', 'Client interview', 'Lav mics'),
            $this->ana,
            BookingSource::Chat,
        );

        $booking->refresh();
        $this->assertSame($this->ana->id, $booking->user_id);
        $this->assertSame('Client interview', $booking->title);
        $this->assertSame('Lav mics', $booking->notes);
        $this->assertSame(BookingSource::Chat, $booking->source);
        $this->assertTrue($booking->starts_at->equalTo($this->at('2026-10-29 09:00')));
        $this->assertItemsMatch($booking, [$this->camera->id, $this->light->id]);
    }

    public function test_create_refuses_a_booking_that_breaks_the_rules()
    {
        $this->book($this->camera, '2026-10-29 10:00', '2026-10-29 12:00');

        try {
            $this->service->create($this->draft($this->camera, '2026-10-29 09:00', '2026-10-29 11:00'), $this->ana, BookingSource::Manual);
            $this->fail('Expected a BookingRuleException.');
        } catch (BookingRuleException $exception) {
            $this->assertSame([RuleCode::ItemConflict], $exception->result->codes());
        }

        $this->assertSame(1, Booking::count());
    }

    public function test_rescheduling_moves_every_item_row()
    {
        $booking = $this->service->create($this->draft([$this->camera, $this->light], '2026-10-29 09:00', '2026-10-29 12:00'), $this->ana, BookingSource::Manual);

        $this->service->update($booking, BookingDraft::fromBooking($booking)->with(
            startsAt: $this->at('2026-10-30 13:00'),
            endsAt: $this->at('2026-10-30 16:00'),
        ), $this->ana);

        $this->assertItemsMatch($booking->refresh(), [$this->camera->id, $this->light->id]);
        $this->assertTrue($booking->starts_at->equalTo($this->at('2026-10-30 13:00')));
    }

    public function test_update_can_swap_items()
    {
        $booking = $this->service->create($this->draft($this->camera, '2026-10-29 09:00', '2026-10-29 12:00'), $this->ana, BookingSource::Manual);

        $this->service->update($booking, BookingDraft::fromBooking($booking)->with(equipmentIds: [$this->light->id]), $this->ana);

        $this->assertItemsMatch($booking->refresh(), [$this->light->id]);
    }

    public function test_rescheduling_into_a_conflict_is_refused()
    {
        $this->book($this->camera, '2026-10-30 09:00', '2026-10-30 12:00');
        $booking = $this->service->create($this->draft($this->camera, '2026-10-29 09:00', '2026-10-29 12:00'), $this->ana, BookingSource::Manual);

        $this->expectException(BookingRuleException::class);

        $this->service->update($booking, BookingDraft::fromBooking($booking)->with(
            startsAt: $this->at('2026-10-30 10:00'),
            endsAt: $this->at('2026-10-30 11:00'),
        ), $this->ana);
    }

    public function test_cancelling_an_upcoming_booking_deactivates_it_and_its_items()
    {
        $booking = $this->service->create($this->draft([$this->camera, $this->light], '2026-10-29 09:00', '2026-10-29 12:00'), $this->ana, BookingSource::Manual);

        $this->service->cancel($booking, $this->ana);

        $booking->refresh();
        $this->assertFalse($booking->is_active);
        $this->assertTrue($booking->cancelled_at?->equalTo(now()));
        $this->assertItemsMatch($booking, [$this->camera->id, $this->light->id]);
        $this->assertSame(0, BookingItem::where('is_active', true)->count());
    }

    public function test_cancelling_an_in_progress_booking_ends_it_at_the_next_slot()
    {
        $booking = $this->book([$this->camera, $this->light], '2026-10-28 10:00', '2026-10-28 17:00', $this->ana);
        $this->travelTo($this->at('2026-10-28 12:10'));

        $this->service->cancel($booking, $this->ana);

        $booking->refresh();
        $this->assertTrue($booking->is_active, 'Ending early keeps the booking in history as active.');
        $this->assertTrue($booking->ends_at->equalTo($this->at('2026-10-28 12:30')));
        $this->assertItemsMatch($booking, [$this->camera->id, $this->light->id]);
    }

    public function test_ending_now_on_a_boundary_rounds_to_the_next_slot()
    {
        $booking = $this->book($this->camera, '2026-10-28 12:00', '2026-10-28 17:00', $this->ana);

        $this->service->cancel($booking, $this->ana);

        $this->assertTrue($booking->refresh()->ends_at->equalTo($this->at('2026-10-28 12:30')));
    }

    public function test_ending_now_never_extends_a_booking()
    {
        $booking = $this->book($this->camera, '2026-10-28 11:00', '2026-10-28 12:30', $this->ana);
        $this->travelTo($this->at('2026-10-28 12:20'));

        $this->service->cancel($booking, $this->ana);

        $this->assertTrue($booking->refresh()->ends_at->equalTo($this->at('2026-10-28 12:30')));
    }

    public function test_shortening_an_in_progress_booking_moves_its_items()
    {
        $booking = $this->book($this->camera, '2026-10-28 10:00', '2026-10-28 17:00', $this->ana);

        $this->service->update($booking, BookingDraft::fromBooking($booking)->with(endsAt: $this->at('2026-10-28 14:00')), $this->ana);

        $this->assertItemsMatch($booking->refresh(), [$this->camera->id]);
        $this->assertTrue($booking->ends_at->equalTo($this->at('2026-10-28 14:00')));
    }

    public function test_ended_and_already_cancelled_bookings_cannot_be_cancelled()
    {
        $ended = $this->book($this->camera, '2026-10-27 09:00', '2026-10-27 12:00', $this->ana);
        $cancelled = $this->book($this->light, '2026-10-29 09:00', '2026-10-29 12:00', $this->ana, cancelled: true);

        foreach ([$ended, $cancelled] as $booking) {
            try {
                $this->service->cancel($booking, $this->ana);
                $this->fail('Expected a BookingRuleException.');
            } catch (BookingRuleException $exception) {
                $this->assertSame([RuleCode::ImmutablePhase], $exception->result->codes());
            }
        }
    }

    public function test_only_the_owner_can_cancel()
    {
        $booking = $this->book($this->camera, '2026-10-29 09:00', '2026-10-29 12:00');

        try {
            $this->service->cancel($booking, $this->ana);
            $this->fail('Expected a BookingRuleException.');
        } catch (BookingRuleException $exception) {
            $this->assertSame([RuleCode::OwnerOnly], $exception->result->codes());
        }

        $this->assertTrue($booking->refresh()->is_active);
    }

    public function test_policy_lets_anyone_view_but_only_the_owner_change()
    {
        $booking = $this->book($this->camera, '2026-10-29 09:00', '2026-10-29 12:00', $this->ana);
        $sam = User::factory()->create();

        $this->assertTrue(Gate::forUser($sam)->allows('view', $booking));
        $this->assertFalse(Gate::forUser($sam)->allows('update', $booking));
        $this->assertFalse(Gate::forUser($sam)->allows('cancel', $booking));
        $this->assertTrue(Gate::forUser($this->ana)->allows('update', $booking));
        $this->assertTrue(Gate::forUser($this->ana)->allows('cancel', $booking));
    }

    /**
     * @param  list<int>  $equipmentIds
     */
    private function assertItemsMatch(Booking $booking, array $equipmentIds): void
    {
        $items = BookingItem::where('booking_id', $booking->id)->get();

        $this->assertEqualsCanonicalizing($equipmentIds, $items->pluck('equipment_id')->all());

        foreach ($items as $item) {
            $this->assertTrue($item->starts_at->equalTo($booking->starts_at));
            $this->assertTrue($item->ends_at->equalTo($booking->ends_at));
            $this->assertSame($booking->is_active, $item->is_active);
        }
    }
}
