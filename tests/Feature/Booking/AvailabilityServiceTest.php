<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\AvailabilityService;
use App\Domain\Booking\AvailabilityState;
use App\Domain\Booking\ItemAvailability;
use App\Enums\EquipmentCategory;
use App\Models\Equipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BooksEquipment;
use Tests\TestCase;

class AvailabilityServiceTest extends TestCase
{
    use BooksEquipment, RefreshDatabase;

    private AvailabilityService $availability;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeAt();
        $this->availability = app(AvailabilityService::class);
    }

    public function test_each_non_retired_item_is_free_busy_or_unbookable()
    {
        $free = Equipment::factory()->create(['name' => 'Canon C70', 'category' => EquipmentCategory::Camera]);
        $busy = Equipment::factory()->create(['name' => 'Sony FX3 #1', 'category' => EquipmentCategory::Camera]);
        $broken = Equipment::factory()->outOfService('Bulb replacement')->create(['name' => 'Aputure Kit B', 'category' => EquipmentCategory::Lighting]);
        Equipment::factory()->retired()->create(['name' => 'Old cam']);
        $sam = User::factory()->create(['name' => 'Sam']);
        $this->book($busy, '2026-10-29 10:00', '2026-10-29 14:00', $sam, title: 'Doc shoot');

        $rows = $this->availability->forRange($this->at('2026-10-29 09:00'), $this->at('2026-10-29 12:00'))
            ->keyBy(fn (ItemAvailability $row) => $row->equipment->name);

        $this->assertSame(['Canon C70', 'Sony FX3 #1', 'Aputure Kit B'], $rows->keys()->all(), 'Ordered by category then name; retired excluded.');
        $this->assertSame(AvailabilityState::Free, $rows['Canon C70']->state);
        $this->assertSame(AvailabilityState::Busy, $rows['Sony FX3 #1']->state);
        $this->assertSame('Sam', $rows['Sony FX3 #1']->conflicts[0]['owner']['name']);
        $this->assertSame('Doc shoot', $rows['Sony FX3 #1']->conflicts[0]['title']);
        $this->assertSame(AvailabilityState::Unbookable, $rows['Aputure Kit B']->state);
        $this->assertSame('Bulb replacement', $rows['Aputure Kit B']->toArray()['reason']);
        $this->assertSame($free->id, $rows['Canon C70']->toArray()['equipment_id']);
        $this->assertSame($broken->id, $rows['Aputure Kit B']->toArray()['equipment_id']);
    }

    public function test_for_range_can_be_limited_to_items_and_ignore_the_booking_being_edited()
    {
        $camera = Equipment::factory()->create();
        Equipment::factory()->create();
        $booking = $this->book($camera, '2026-10-29 09:00', '2026-10-29 12:00');

        $rows = $this->availability->forRange($this->at('2026-10-29 09:00'), $this->at('2026-10-29 12:00'), [$camera->id]);
        $this->assertCount(1, $rows);
        $this->assertSame(AvailabilityState::Busy, $rows->first()?->state);

        $rows = $this->availability->forRange($this->at('2026-10-29 09:00'), $this->at('2026-10-29 12:00'), [$camera->id], $booking->id);
        $this->assertSame(AvailabilityState::Free, $rows->first()?->state);
    }

    public function test_retired_items_never_appear_even_when_asked_for()
    {
        $retired = Equipment::factory()->retired()->create();

        $this->assertCount(0, $this->availability->forRange($this->at('2026-10-29 09:00'), $this->at('2026-10-29 12:00'), [$retired->id]));
    }

    public function test_openings_are_the_gaps_where_all_items_are_free_together()
    {
        $camera = Equipment::factory()->create();
        $light = Equipment::factory()->create();
        $this->book($camera, '2026-10-29 09:00', '2026-10-29 11:00');
        $this->book($light, '2026-10-29 10:00', '2026-10-29 13:00');
        $this->book($camera, '2026-10-29 15:00', '2026-10-29 16:00');

        $openings = $this->openings([$camera->id, $light->id], 60, '2026-10-29 08:00', '2026-10-29 18:00');

        $this->assertSame([
            ['08:00', '09:00'],
            ['13:00', '15:00'],
            ['16:00', '18:00'],
        ], $openings);
    }

    public function test_openings_respect_the_minimum_length_and_limit()
    {
        $camera = Equipment::factory()->create();
        $this->book($camera, '2026-10-29 09:00', '2026-10-29 09:30');
        $this->book($camera, '2026-10-29 10:00', '2026-10-29 12:00');

        $this->assertSame([['12:00', '18:00']], $this->openings([$camera->id], 120, '2026-10-29 08:00', '2026-10-29 18:00'));
        $this->assertSame([['08:00', '09:00']], $this->openings([$camera->id], 30, '2026-10-29 08:00', '2026-10-29 18:00', limit: 1));
    }

    public function test_openings_never_start_in_the_past_and_align_to_slots()
    {
        $camera = Equipment::factory()->create();
        $this->travelTo($this->at('2026-10-28 12:10'));

        $this->assertSame([['12:30', '17:00']], $this->openings([$camera->id], 60, '2026-10-28 08:00', '2026-10-28 17:15'));
    }

    public function test_there_are_no_openings_when_an_item_cannot_be_booked()
    {
        $camera = Equipment::factory()->create();
        $broken = Equipment::factory()->outOfService()->create();

        $this->assertSame([], $this->openings([$camera->id, $broken->id], 60, '2026-10-29 08:00', '2026-10-29 18:00'));
        $this->assertSame([], $this->openings([$camera->id, 999], 60, '2026-10-29 08:00', '2026-10-29 18:00'));
    }

    /**
     * @param  list<int>  $equipmentIds
     * @return list<array{0: string, 1: string}>
     */
    private function openings(array $equipmentIds, int $minutes, string $from, string $to, int $limit = 5): array
    {
        return array_map(
            fn (array $opening) => [$opening['starts_at']->format('H:i'), $opening['ends_at']->format('H:i')],
            $this->availability->findOpenings($equipmentIds, $minutes, $this->at($from), $this->at($to), $limit),
        );
    }
}
