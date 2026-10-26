<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BooksEquipment;
use Tests\TestCase;

class BookingShowTest extends TestCase
{
    use BooksEquipment, RefreshDatabase;

    private User $ana;

    private Equipment $camera;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeAt();
        $this->ana = User::factory()->create(['name' => 'Ana']);
        $this->camera = Equipment::factory()->create(['name' => 'Sony FX3 #1']);
        $this->actingAs($this->ana);
    }

    public function test_anyone_can_view_any_booking()
    {
        $sam = User::factory()->create(['name' => 'Sam']);
        $booking = $this->book($this->camera, '2026-10-29 09:00', '2026-10-29 12:00', $sam, title: 'Doc shoot');

        $this->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('bookings/show')
                ->where('booking.id', $booking->id)
                ->where('booking.title', 'Doc shoot')
                ->where('booking.owner.name', 'Sam')
                ->where('booking.is_mine', false)
                ->where('booking.phase', 'upcoming')
                ->where('booking.items.0.name', 'Sony FX3 #1'));
    }

    public function test_cancelled_bookings_can_still_be_viewed()
    {
        $booking = $this->book($this->camera, '2026-10-29 09:00', '2026-10-29 12:00', $this->ana, cancelled: true);

        $this->get(route('bookings.show', $booking))
            ->assertInertia(fn (Assert $page) => $page
                ->where('booking.is_active', false)
                ->where('booking.cancelled_at', '2026-10-28T12:00:00-05:00'));
    }

    public function test_unknown_bookings_are_not_found()
    {
        $this->get('/bookings/999999')->assertNotFound();
    }

    public function test_cancel_returns_to_the_page_it_came_from()
    {
        $booking = $this->book($this->camera, '2026-10-29 09:00', '2026-10-29 12:00', $this->ana);
        $from = route('bookings.show', $booking);

        $this->from($from)->post(route('bookings.cancel', $booking))->assertRedirect($from);
    }

    public function test_cancel_from_the_edit_form_returns_to_the_calendar_week()
    {
        $booking = $this->book($this->camera, '2026-10-28 10:00', '2026-10-28 17:00', $this->ana);

        $this->from(route('bookings.edit', $booking))
            ->post(route('bookings.cancel', $booking))
            ->assertRedirect(route('calendar', ['week' => '2026-W44']));
    }
}
