<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Equipment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EquipmentShowTest extends TestCase
{
    use RefreshDatabase;

    private const string TZ = 'America/Chicago';

    protected function setUp(): void
    {
        parent::setUp();

        // Wednesday. The window is Mon Oct 26 → Mon Nov 9 (DST ends Nov 1).
        $this->travelTo(CarbonImmutable::parse('2026-10-28 12:00', self::TZ));
        $this->actingAs(User::factory()->create());
    }

    public function test_detail_page_shows_the_item_and_its_bookings_for_this_week_and_next()
    {
        $equipment = Equipment::factory()->create(['name' => 'Sony FX3 #1']);
        $other = Equipment::factory()->create();

        $this->book($equipment, 'Started last week', '2026-10-25 22:00', '2026-10-26 02:00');
        $this->book($equipment, 'Thursday shoot', '2026-10-29 09:00', '2026-10-29 12:00');
        $this->book($equipment, 'Next week', '2026-11-03 13:00', '2026-11-03 15:00');
        $this->book($equipment, 'Week after', '2026-11-09 09:00', '2026-11-09 10:00');
        $this->book($equipment, 'Ended last week', '2026-10-20 09:00', '2026-10-20 10:00');
        $this->book($other, 'Other item', '2026-10-29 09:00', '2026-10-29 12:00');
        $this->book($equipment, 'Cancelled', '2026-10-30 09:00', '2026-10-30 10:00', cancelled: true);

        $this->get(route('equipment.show', $equipment))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('equipment/show')
                ->where('equipment.name', 'Sony FX3 #1')
                ->where('timezone', self::TZ)
                ->where('window.starts_at', '2026-10-26T00:00:00-05:00')
                ->where('window.ends_at', '2026-11-09T00:00:00-06:00')
                ->has('bookings', 3)
                ->where('bookings.0.title', 'Started last week')
                ->where('bookings.1.title', 'Thursday shoot')
                ->where('bookings.2.title', 'Next week')
                ->has('bookings.1', fn (Assert $booking) => $booking
                    ->where('starts_at', '2026-10-29T14:00:00+00:00')
                    ->where('ends_at', '2026-10-29T17:00:00+00:00')
                    ->has('owner', fn (Assert $owner) => $owner->hasAll(['id', 'name']))
                    ->etc()));
    }

    private function book(Equipment $equipment, string $title, string $from, string $to, bool $cancelled = false): Booking
    {
        return Booking::factory()->withEquipment($equipment)->create([
            'title' => $title,
            'starts_at' => CarbonImmutable::parse($from, self::TZ),
            'ends_at' => CarbonImmutable::parse($to, self::TZ),
            'cancelled_at' => $cancelled ? now() : null,
            'is_active' => ! $cancelled,
        ]);
    }
}
