<?php

namespace Tests\Feature\Booking;

use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Equipment;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BooksEquipment;
use Tests\TestCase;

/**
 * The database guards on their own, bypassing BookingService: the exclusion
 * constraint, the cascading composite foreign key and the CHECK constraints.
 */
class BookingConstraintsTest extends TestCase
{
    use BooksEquipment, RefreshDatabase;

    private Equipment $camera;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeAt();
        $this->camera = Equipment::factory()->create();
    }

    public function test_an_item_cannot_be_in_two_overlapping_active_bookings()
    {
        $this->book($this->camera, '2026-10-29 09:00', '2026-10-29 12:00');

        $this->assertSqlState('23P01', fn () => $this->book($this->camera, '2026-10-29 11:00', '2026-10-29 13:00'));
    }

    public function test_back_to_back_ranges_are_allowed()
    {
        $this->book($this->camera, '2026-10-29 09:00', '2026-10-29 12:00');
        $this->book($this->camera, '2026-10-29 12:00', '2026-10-29 15:00');

        $this->assertSame(2, BookingItem::where('equipment_id', $this->camera->id)->count());
    }

    public function test_cancelled_bookings_do_not_block_the_item()
    {
        $this->book($this->camera, '2026-10-29 09:00', '2026-10-29 12:00', cancelled: true);
        $this->book($this->camera, '2026-10-29 09:00', '2026-10-29 12:00');

        $this->assertSame(1, BookingItem::where('is_active', true)->count());
    }

    public function test_reschedule_end_early_and_cancel_cascade_to_every_item_row()
    {
        $light = Equipment::factory()->create();
        $booking = $this->book([$this->camera, $light], '2026-10-29 09:00', '2026-10-29 12:00');

        $booking->update(['starts_at' => $this->at('2026-10-30 09:00'), 'ends_at' => $this->at('2026-10-30 17:00')]);
        $this->assertItemRows($booking, '2026-10-30 09:00', '2026-10-30 17:00', true);

        $booking->update(['ends_at' => $this->at('2026-10-30 12:30')]);
        $this->assertItemRows($booking, '2026-10-30 09:00', '2026-10-30 12:30', true);

        $booking->update(['cancelled_at' => now(), 'is_active' => false]);
        $this->assertItemRows($booking, '2026-10-30 09:00', '2026-10-30 12:30', false);
    }

    public function test_rescheduling_into_a_conflict_fails_in_the_cascade()
    {
        $this->book($this->camera, '2026-10-30 09:00', '2026-10-30 12:00');
        $booking = $this->book($this->camera, '2026-10-29 09:00', '2026-10-29 12:00');

        $this->assertSqlState('23P01', fn () => $booking->update([
            'starts_at' => $this->at('2026-10-30 10:00'),
            'ends_at' => $this->at('2026-10-30 11:00'),
        ]));
    }

    public function test_reactivating_a_cancelled_booking_into_a_conflict_fails()
    {
        $cancelled = $this->book($this->camera, '2026-10-29 09:00', '2026-10-29 12:00', cancelled: true);
        $this->book($this->camera, '2026-10-29 09:00', '2026-10-29 12:00');

        $this->assertSqlState('23P01', fn () => $cancelled->update(['cancelled_at' => null, 'is_active' => true]));
    }

    public function test_an_item_row_whose_copy_does_not_match_its_booking_is_rejected()
    {
        $booking = $this->book(Equipment::factory()->create(), '2026-10-29 09:00', '2026-10-29 12:00');

        $this->assertSqlState('23503', fn () => BookingItem::create([
            'booking_id' => $booking->id,
            'equipment_id' => $this->camera->id,
            'starts_at' => $this->at('2026-10-29 09:00'),
            'ends_at' => $this->at('2026-10-29 13:00'),
            'is_active' => true,
        ]));
    }

    public function test_check_constraints_on_bookings()
    {
        $this->assertSqlState('23514', fn () => Booking::factory()->create([
            'starts_at' => $this->at('2026-10-29 12:00'),
            'ends_at' => $this->at('2026-10-29 12:00'),
        ]), 'end after start');

        $this->assertSqlState('23514', fn () => Booking::factory()->create([
            'starts_at' => $this->at('2026-11-06 09:00'),
            'ends_at' => $this->at('2026-11-10 09:30'),
        ]), 'at most 96 hours');

        $this->assertSqlState('23514', fn () => Booking::factory()->create([
            'cancelled_at' => now(),
            'is_active' => true,
        ]), 'is_active matches cancelled_at');

        Booking::factory()->create([
            'starts_at' => $this->at('2026-11-06 09:00'),
            'ends_at' => $this->at('2026-11-10 09:00'),
        ]);

        $this->assertSame(1, Booking::count(), 'Exactly 96 hours is allowed.');
    }

    /**
     * Run the statement in a savepoint so the failure doesn't abort the
     * test's own transaction, and assert its SQLSTATE.
     */
    private function assertSqlState(string $expected, Closure $statement, string $message = ''): void
    {
        try {
            DB::transaction($statement);
        } catch (QueryException $exception) {
            $this->assertSame($expected, (string) $exception->getCode(), $message);

            return;
        }

        $this->fail("Expected SQLSTATE {$expected}. {$message}");
    }

    private function assertItemRows(Booking $booking, string $from, string $to, bool $active): void
    {
        $items = BookingItem::where('booking_id', $booking->id)->get();

        $this->assertCount(2, $items);

        foreach ($items as $item) {
            $this->assertTrue($item->starts_at->equalTo($this->at($from)));
            $this->assertTrue($item->ends_at->equalTo($this->at($to)));
            $this->assertSame($active, $item->is_active);
        }
    }
}
