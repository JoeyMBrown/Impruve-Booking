<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WhoTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_who_from_protected_pages()
    {
        $this->get(route('calendar'))->assertRedirect(route('who.index'));
    }

    public function test_home_redirects_guests_to_who()
    {
        $this->get('/')->assertRedirect(route('who.index'));
    }

    public function test_home_redirects_acting_users_to_the_calendar()
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertRedirect(route('calendar'));
    }

    public function test_who_lists_all_users_by_name()
    {
        User::factory()->create(['name' => 'Ben Ito']);
        User::factory()->create(['name' => 'Ana Ruiz']);

        $this->get(route('who.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('who/index')
                ->has('users', 2)
                ->where('users.0.name', 'Ana Ruiz')
                ->where('users.1.name', 'Ben Ito')
                ->missing('users.0.email'));
    }

    public function test_acting_users_visiting_who_are_redirected_to_the_calendar()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('who.index'))
            ->assertRedirect(route('calendar'));
    }

    public function test_choosing_a_user_logs_them_in_and_regenerates_the_session()
    {
        $user = User::factory()->create();

        $this->startSession();
        $sessionId = session()->getId();

        $this->post(route('who.store'), ['user_id' => $user->id])
            ->assertRedirect(route('calendar'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($sessionId, session()->getId());
    }

    public function test_choosing_an_unknown_user_fails_validation()
    {
        $this->post(route('who.store'), ['user_id' => 999])
            ->assertSessionHasErrors('user_id');

        $this->assertGuest();
    }

    public function test_switching_user_logs_out_and_clears_the_session()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->withSession(['marker' => 'kept?']);

        $this->post(route('who.destroy'))
            ->assertRedirect(route('who.index'));

        $this->assertGuest();
        $this->assertFalse(session()->has('marker'));
    }

    public function test_guests_cannot_switch_user()
    {
        $this->post(route('who.destroy'))->assertRedirect(route('who.index'));
    }
}
