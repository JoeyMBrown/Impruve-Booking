<?php

namespace Tests\Feature;

use App\Enums\EquipmentCategory;
use App\Models\Equipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BooksEquipment;
use Tests\TestCase;

/**
 * The calendar week payload. "Now" is Wed Oct 28, 12:00 CDT (week 2026-W44,
 * Mon Oct 26 → Mon Nov 2; clocks fall back on Sun Nov 1).
 */
class CalendarWeekTest extends TestCase
{
    use BooksEquipment, RefreshDatabase;

    private User $ana;

    private Equipment $camera;

    private Equipment $light;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeAt();
        $this->ana = User::factory()->create(['name' => 'Ana']);
        $this->camera = Equipment::factory()->create(['name' => 'Sony FX3 #1', 'category' => EquipmentCategory::Camera]);
        $this->light = Equipment::factory()->create(['name' => 'Aputure Kit A', 'category' => EquipmentCategory::Lighting]);
        $this->actingAs($this->ana);
    }

    public function test_the_page_and_the_json_endpoint_share_one_payload()
    {
        $this->book($this->camera, '2026-10-29 09:00', '2026-10-29 12:00', $this->ana);

        $json = $this->getJson(route('api.calendar.week'))->assertOk()->json();

        $this->get(route('calendar'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('calendar/week')
                ->where('week', $json['week'])
                ->where('bookings', $json['bookings'])
                ->where('out_now', $json['out_now'])
                ->where('unavailable', $json['unavailable'])
                ->where('my_at_risk', $json['my_at_risk'])
                ->where('equipment', $json['equipment']));
    }

    public function test_defaults_to_the_current_week_in_company_time()
    {
        $this->getJson(route('api.calendar.week'))
            ->assertJsonPath('week.iso', '2026-W44')
            ->assertJsonPath('week.start', '2026-10-26')
            ->assertJsonPath('week.end', '2026-11-01')
            ->assertJsonPath('week.tz', 'America/Chicago')
            ->assertJsonPath('week.prev', '2026-W43')
            ->assertJsonPath('week.next', '2026-W45')
            ->assertJsonPath('week.is_current', true)
            ->assertJsonPath('now', '2026-10-28T12:00:00-05:00');
    }

    public function test_a_week_can_be_chosen_and_invalid_weeks_fall_back()
    {
        $this->getJson(route('api.calendar.week', ['week' => '2026-W45']))
            ->assertJsonPath('week.start', '2026-11-02')
            ->assertJsonPath('week.is_current', false);

        foreach (['2026-W54', 'nope', '2026-44'] as $week) {
            $this->getJson(route('api.calendar.week', ['week' => $week]))->assertJsonPath('week.iso', '2026-W44');
        }
    }

    public function test_includes_every_active_booking_that_overlaps_the_week()
    {
        $this->book($this->camera, '2026-10-25 20:00', '2026-10-26 02:00', title: 'From last week');
        $this->book($this->camera, '2026-10-28 09:00', '2026-10-28 11:00', title: 'Inside');
        $this->book($this->light, '2026-11-01 22:00', '2026-11-02 03:00', title: 'Into next week');
        $this->book($this->light, '2026-10-25 09:00', '2026-10-26 00:00', title: 'Ends at week start');
        $this->book($this->camera, '2026-11-02 00:00', '2026-11-02 09:00', title: 'Starts at week end');
        $this->book($this->light, '2026-10-29 09:00', '2026-10-29 10:00', title: 'Cancelled', cancelled: true);

        $this->getJson(route('api.calendar.week'))
            ->assertJsonPath('bookings.*.title', ['From last week', 'Inside', 'Into next week']);
    }

    public function test_booking_shape()
    {
        $this->light->update(['status' => 'out_of_service', 'status_reason' => 'Bulb']);
        $booking = $this->book([$this->camera, $this->light], '2026-10-29 09:00', '2026-10-29 12:00', $this->ana, title: 'Client interview');

        $this->getJson(route('api.calendar.week'))
            ->assertJsonPath('bookings.0', [
                'id' => $booking->id,
                'title' => 'Client interview',
                'notes' => null,
                'starts_at' => '2026-10-29T09:00:00-05:00',
                'ends_at' => '2026-10-29T12:00:00-05:00',
                'owner' => ['id' => $this->ana->id, 'name' => 'Ana'],
                'is_mine' => true,
                'is_active' => true,
                'cancelled_at' => null,
                'items' => [
                    ['id' => $this->light->id, 'name' => 'Aputure Kit A', 'category' => 'lighting', 'status' => 'out_of_service', 'status_reason' => 'Bulb'],
                    ['id' => $this->camera->id, 'name' => 'Sony FX3 #1', 'category' => 'camera', 'status' => 'available', 'status_reason' => null],
                ],
                'phase' => 'upcoming',
                'at_risk' => true,
                'source' => 'manual',
                'created_at' => '2026-10-28T12:00:00-05:00',
            ]);
    }

    public function test_filters_by_category_item_and_mine()
    {
        $sam = User::factory()->create();
        $this->book($this->camera, '2026-10-29 09:00', '2026-10-29 10:00', $sam, title: 'Camera, Sam');
        $this->book($this->light, '2026-10-29 09:00', '2026-10-29 10:00', $this->ana, title: 'Light, Ana');
        $this->book([$this->camera, $this->light], '2026-10-30 09:00', '2026-10-30 10:00', $sam, title: 'Both, Sam');

        $titles = fn (array $query) => $this->getJson(route('api.calendar.week', $query))->json('bookings.*.title');

        $this->assertSame(['Camera, Sam', 'Both, Sam'], $titles(['category' => 'camera']));
        $this->assertSame(['Light, Ana', 'Both, Sam'], $titles(['equipment' => $this->light->id]));
        $this->assertSame(['Light, Ana'], $titles(['mine' => 1]));
        $this->assertSame(['Both, Sam'], $titles(['category' => 'lighting', 'equipment' => $this->camera->id]));

        $this->getJson(route('api.calendar.week', ['category' => 'camera', 'equipment' => $this->camera->id, 'mine' => 1]))
            ->assertJsonPath('filters', ['category' => 'camera', 'equipment' => $this->camera->id, 'mine' => true]);
    }

    public function test_side_panel_shows_what_is_out_now_unavailable_and_my_at_risk_bookings()
    {
        $sam = User::factory()->create(['name' => 'Sam']);
        $out = $this->book($this->camera, '2026-10-28 10:00', '2026-10-28 16:00', $sam, title: 'Out');
        $broken = Equipment::factory()->outOfService('Sensor cleaning')->create(['name' => 'Canon C70']);
        Equipment::factory()->retired()->create();
        $mine = $this->book($broken, '2026-11-10 09:00', '2026-11-10 10:00', $this->ana, title: 'Next month');
        $this->book($broken, '2026-11-11 09:00', '2026-11-11 10:00', $sam, title: 'Not mine');

        $this->getJson(route('api.calendar.week'))
            ->assertJsonPath('out_now', [[
                'equipment_id' => $this->camera->id,
                'name' => 'Sony FX3 #1',
                'booking_id' => $out->id,
                'owner' => 'Sam',
                'until' => '2026-10-28T16:00:00-05:00',
            ]])
            ->assertJsonPath('unavailable', [[
                'equipment_id' => $broken->id,
                'name' => 'Canon C70',
                'status' => 'out_of_service',
                'reason' => 'Sensor cleaning',
            ]])
            ->assertJsonCount(1, 'my_at_risk')
            ->assertJsonPath('my_at_risk.0.id', $mine->id);
    }

    public function test_the_item_picker_lists_non_retired_equipment()
    {
        Equipment::factory()->retired()->create();

        $this->getJson(route('api.calendar.week'))->assertJsonCount(2, 'equipment');
    }

    public function test_guests_are_refused()
    {
        auth()->logout();

        $this->get(route('calendar'))->assertRedirect(route('who.index'));
        $this->getJson(route('api.calendar.week'))->assertUnauthorized();
    }
}
