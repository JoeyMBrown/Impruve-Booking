<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\SlotClock;
use Carbon\CarbonImmutable;
use Tests\Concerns\BooksEquipment;
use Tests\TestCase;

class SlotClockTest extends TestCase
{
    use BooksEquipment;

    public function test_slot_boundaries_are_whole_and_half_hours_with_no_seconds()
    {
        $this->assertTrue(SlotClock::isOnSlot($this->at('2026-10-28 09:00')));
        $this->assertTrue(SlotClock::isOnSlot($this->at('2026-10-28 09:30')));
        $this->assertFalse(SlotClock::isOnSlot($this->at('2026-10-28 09:15')));
        $this->assertFalse(SlotClock::isOnSlot($this->at('2026-10-28 09:00:30')));
        $this->assertTrue(SlotClock::isOnSlot($this->at('2026-10-28 09:00')->utc()));
    }

    public function test_floor_and_ceil()
    {
        $this->assertTrue(SlotClock::floor($this->at('2026-10-28 09:29:59'))->equalTo($this->at('2026-10-28 09:00')));
        $this->assertTrue(SlotClock::ceil($this->at('2026-10-28 09:00'))->equalTo($this->at('2026-10-28 09:00')));
        $this->assertTrue(SlotClock::ceil($this->at('2026-10-28 09:01'))->equalTo($this->at('2026-10-28 09:30')));
    }

    public function test_next_slot_after_is_strictly_later()
    {
        $this->assertTrue(SlotClock::nextSlotAfter($this->at('2026-10-28 10:00'))->equalTo($this->at('2026-10-28 10:30')));
        $this->assertTrue(SlotClock::nextSlotAfter($this->at('2026-10-28 10:10'))->equalTo($this->at('2026-10-28 10:30')));
        $this->assertTrue(SlotClock::nextSlotAfter($this->at('2026-10-28 23:45'))->equalTo($this->at('2026-10-29 00:00')));
    }

    public function test_rounding_is_correct_across_the_fall_back_dst_change()
    {
        // 01:45 CDT (06:45 UTC); half an hour after 01:30 CDT is 01:00 CST.
        $time = CarbonImmutable::parse('2026-11-01 06:45', 'UTC');

        $next = SlotClock::nextSlotAfter($time);

        $this->assertTrue($next->equalTo(CarbonImmutable::parse('2026-11-01 07:00', 'UTC')));
        $this->assertSame('01:00 CST', $next->format('H:i T'));
        $this->assertTrue(SlotClock::isOnSlot($next));
    }
}
