<?php

namespace Tests\Feature;

use App\Enums\BookingPhase;
use App\Models\Booking;
use App\Models\Equipment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingScopesTest extends TestCase
{
    use RefreshDatabase;

    private const string TZ = 'America/Chicago';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-28 12:00', self::TZ));
    }

    public function test_phase_boundaries_use_the_half_open_range()
    {
        $now = now();

        $startsNow = $this->book(Equipment::factory()->create(), $now, $now->addHour());
        $endsNow = $this->book(Equipment::factory()->create(), $now->subHour(), $now);
        $later = $this->book(Equipment::factory()->create(), $now->addMinutes(30), $now->addHour());

        $this->assertSame(BookingPhase::InProgress, $startsNow->phase());
        $this->assertSame(BookingPhase::Ended, $endsNow->phase());
        $this->assertSame(BookingPhase::Upcoming, $later->phase());

        $this->assertSame([$startsNow->id], Booking::inProgress()->pluck('id')->all());
        $this->assertSame([$later->id], Booking::upcoming()->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$startsNow->id, $later->id], Booking::notEnded()->pluck('id')->all());
    }

    public function test_at_risk_needs_an_active_not_ended_booking_with_an_unavailable_item()
    {
        $broken = Equipment::factory()->outOfService()->create();
        $retired = Equipment::factory()->retired()->create();
        $fine = Equipment::factory()->create();
        $now = now();

        $risky = Booking::factory()->withEquipment($fine, $broken)
            ->create(['starts_at' => $now->addDay(), 'ends_at' => $now->addDay()->addHour()]);
        $retiredItem = $this->book($retired, $now->addDays(2), $now->addDays(2)->addHour());
        $this->book($fine, $now->addDays(3), $now->addDays(3)->addHour());
        $this->book($broken, $now->subDay(), $now->subDay()->addHour());
        Booking::factory()->withEquipment($broken)->create([
            'starts_at' => $now->addDays(4),
            'ends_at' => $now->addDays(4)->addHour(),
            'cancelled_at' => $now,
            'is_active' => false,
        ]);

        $this->assertEqualsCanonicalizing(
            [$risky->id, $retiredItem->id],
            Booking::atRisk()->pluck('id')->all(),
        );
    }

    public function test_using_equipment_matches_any_item_on_the_booking()
    {
        $camera = Equipment::factory()->create();
        $light = Equipment::factory()->create();
        $booking = Booking::factory()->withEquipment($camera, $light)->create();
        Booking::factory()->withEquipment()->create();

        $this->assertSame([$booking->id], Booking::usingEquipment($light->id)->pluck('id')->all());
    }

    private function book(Equipment $equipment, CarbonImmutable $from, CarbonImmutable $to): Booking
    {
        return Booking::factory()->withEquipment($equipment)->create(['starts_at' => $from, 'ends_at' => $to]);
    }
}
