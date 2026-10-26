<?php

namespace Tests\Feature;

use App\Enums\BookingSource;
use App\Models\Booking;
use App\Models\Equipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BooksEquipment;
use Tests\TestCase;

/**
 * The manual booking form. "Now" is Wed Oct 28, 12:00 CDT.
 */
class BookingCreateTest extends TestCase
{
    use BooksEquipment, RefreshDatabase;

    private User $ana;

    private Equipment $camera;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeAt();
        $this->ana = User::factory()->create();
        $this->camera = Equipment::factory()->create(['name' => 'Sony FX3 #1']);
        $this->actingAs($this->ana);
    }

    public function test_guests_are_redirected_to_who()
    {
        auth()->logout();

        $this->get(route('bookings.create'))->assertRedirect(route('who.index'));
    }

    public function test_blank_form_defaults_to_the_next_slot_for_an_hour()
    {
        $this->travelTo($this->at('2026-10-28 12:10'));

        $this->get(route('bookings.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('bookings/form')
                ->where('booking', null)
                ->where('values.starts_at', '2026-10-28T12:30')
                ->where('values.ends_at', '2026-10-28T13:30')
                ->where('values.equipment_ids', [])
                ->has('availability', 1)
                ->where('availability.0.state', 'free')
                ->has('categories', 3)
                ->where('limits.slot_minutes', 30)
                ->where('limits.max_duration_hours', 96));
    }

    public function test_form_can_be_prefilled_from_a_slot_and_equipment()
    {
        $retired = Equipment::factory()->retired()->create();

        $this->get(route('bookings.create', [
            'starts_at' => '2026-10-29T09:00',
            'ends_at' => '2026-10-29T17:00',
            'equipment' => [$this->camera->id, $retired->id, 999],
        ]))->assertInertia(fn (Assert $page) => $page
            ->where('values.starts_at', '2026-10-29T09:00')
            ->where('values.ends_at', '2026-10-29T17:00')
            ->where('values.equipment_ids', [$this->camera->id]));
    }

    public function test_prefilled_times_with_an_offset_are_shown_in_company_time()
    {
        $this->get(route('bookings.create', ['starts_at' => '2026-10-29T14:00:00Z']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('values.starts_at', '2026-10-29T09:00')
                ->where('values.ends_at', '2026-10-29T10:00'));
    }

    public function test_a_prefilled_end_before_the_start_resets_to_one_hour()
    {
        $this->get(route('bookings.create', ['starts_at' => '2026-10-29T09:00', 'ends_at' => '2026-10-29T08:00']))
            ->assertInertia(fn (Assert $page) => $page->where('values.ends_at', '2026-10-29T10:00'));
    }

    public function test_a_booking_can_be_made_and_returns_to_its_calendar_week()
    {
        $this->post(route('bookings.store'), $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('calendar', ['week' => '2026-W44']))
            ->assertInertiaFlash('toast.type', 'success');

        $booking = Booking::sole();
        $this->assertSame($this->ana->id, $booking->user_id);
        $this->assertSame(BookingSource::Manual, $booking->source);
        $this->assertSame('Client interview', $booking->title);
        $this->assertTrue($booking->starts_at->equalTo($this->at('2026-10-29 09:00')));
        $this->assertSame([$this->camera->id], $booking->items()->pluck('equipment_id')->all());
    }

    public function test_rule_failures_come_back_as_field_errors()
    {
        $this->book($this->camera, '2026-10-29 10:00', '2026-10-29 12:00');
        $broken = Equipment::factory()->outOfService()->create();

        $cases = [
            'too_long' => [['ends_at' => '2026-11-03T09:30'], 'ends_at'],
            'invalid_range' => [['starts_at' => '2026-10-29T09:15'], 'ends_at'],
            'starts_in_past' => [['starts_at' => '2026-10-27T09:00', 'ends_at' => '2026-10-27T10:00'], 'starts_at'],
            'no_items' => [['equipment_ids' => []], 'equipment_ids'],
            'item_unbookable' => [['equipment_ids' => [$broken->id], 'starts_at' => '2026-10-30T09:00', 'ends_at' => '2026-10-30T10:00'], 'equipment_ids'],
            'item_conflict' => [[], 'equipment_ids'],
        ];

        foreach ($cases as $code => [$overrides, $field]) {
            $this->post(route('bookings.store'), $this->payload($overrides))
                ->assertSessionHasErrors($field, null, 'default');
        }

        $this->assertSame(1, Booking::count(), 'Nothing new was saved.');
    }

    public function test_the_title_is_required_and_at_most_120_characters()
    {
        $this->post(route('bookings.store'), $this->payload(['title' => '']))->assertSessionHasErrors('title');
        $this->post(route('bookings.store'), $this->payload(['title' => str_repeat('a', 121)]))->assertSessionHasErrors('title');

        $this->assertSame(0, Booking::count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'starts_at' => '2026-10-29T09:00',
            'ends_at' => '2026-10-29T17:00',
            'equipment_ids' => [$this->camera->id],
            'title' => 'Client interview',
            'notes' => '',
            ...$overrides,
        ];
    }
}
