<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BooksEquipment;
use Tests\TestCase;

/**
 * My bookings. "Now" is Wed Oct 28, 12:00 CDT.
 */
class MyBookingsTest extends TestCase
{
    use BooksEquipment, RefreshDatabase;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeAt();
        $this->ana = User::factory()->create();
        $this->actingAs($this->ana);
    }

    public function test_bookings_are_split_into_tabs_with_at_risk_first()
    {
        $camera = Equipment::factory()->create();
        $broken = Equipment::factory()->outOfService()->create();

        $this->book($camera, '2026-10-29 09:00', '2026-10-29 10:00', $this->ana, title: 'Soon');
        $this->book($broken, '2026-11-05 09:00', '2026-11-05 10:00', $this->ana, title: 'Later but at risk');
        $this->book($camera, '2026-10-30 09:00', '2026-10-30 10:00', $this->ana, title: 'Then');
        $this->book($camera, '2026-10-28 11:00', '2026-10-28 14:00', $this->ana, title: 'Now');
        $this->book($camera, '2026-10-26 09:00', '2026-10-26 10:00', $this->ana, title: 'Monday');
        $this->book($camera, '2026-10-27 09:00', '2026-10-27 10:00', $this->ana, title: 'Tuesday');
        $this->book($camera, '2026-10-31 09:00', '2026-10-31 10:00', $this->ana, title: 'Dropped', cancelled: true);
        $this->book($camera, '2026-11-02 09:00', '2026-11-02 10:00', title: 'Someone else');

        $this->get(route('bookings.mine'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('bookings/mine')
                ->where('tab', 'upcoming')
                ->where('tabs.upcoming.0.title', 'Later but at risk')
                ->where('tabs.upcoming.0.at_risk', true)
                ->where('tabs.upcoming.1.title', 'Soon')
                ->where('tabs.upcoming.2.title', 'Then')
                ->has('tabs.upcoming', 3)
                ->has('tabs.in_progress', 1)
                ->where('tabs.in_progress.0.title', 'Now')
                ->has('tabs.past', 2)
                ->where('tabs.past.0.title', 'Tuesday')
                ->where('tabs.past.1.title', 'Monday')
                ->has('tabs.cancelled', 1)
                ->where('tabs.cancelled.0.title', 'Dropped'));
    }

    public function test_the_tab_comes_from_the_query_string()
    {
        $this->get(route('bookings.mine', ['tab' => 'past']))
            ->assertInertia(fn (Assert $page) => $page->where('tab', 'past'));

        $this->get(route('bookings.mine', ['tab' => 'nope']))
            ->assertInertia(fn (Assert $page) => $page->where('tab', 'upcoming'));
    }

    public function test_cancelling_from_my_bookings_returns_there()
    {
        $booking = $this->book(Equipment::factory()->create(), '2026-10-29 09:00', '2026-10-29 10:00', $this->ana);

        $this->from(route('bookings.mine'))
            ->post(route('bookings.cancel', $booking))
            ->assertRedirect(route('bookings.mine'));
    }

    public function test_guests_are_redirected()
    {
        auth()->logout();

        $this->get(route('bookings.mine'))->assertRedirect(route('who.index'));
    }
}
