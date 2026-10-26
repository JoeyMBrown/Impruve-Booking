<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AppShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_share_the_acting_user_for_the_header()
    {
        $user = User::factory()->create(['name' => 'Ana Ruiz']);

        $this->actingAs($user)
            ->get(route('calendar'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('calendar/week')
                ->where('auth.user.id', $user->id)
                ->where('auth.user.name', 'Ana Ruiz')
                ->missing('auth.user.password')
                ->missing('auth.user.remember_token'));
    }

    public function test_switching_user_flashes_a_toast()
    {
        $this->actingAs(User::factory()->create())
            ->post(route('who.destroy'))
            ->assertInertiaFlash('toast.type', 'info');
    }
}
