<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BooksEquipment;
use Tests\TestCase;

class AvailabilityApiTest extends TestCase
{
    use BooksEquipment, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeAt();
        $this->actingAs(User::factory()->create());
    }

    public function test_returns_one_row_per_non_retired_item_with_its_state()
    {
        $free = Equipment::factory()->create(['name' => 'Canon C70']);
        $busy = Equipment::factory()->create(['name' => 'Sony FX3 #1']);
        Equipment::factory()->outOfService('Bulb replacement')->create();
        Equipment::factory()->retired()->create();
        $sam = User::factory()->create(['name' => 'Sam']);
        $this->book($busy, '2026-10-29 10:00', '2026-10-29 14:00', $sam, title: 'Doc shoot');

        $response = $this->getJson(route('api.availability', [
            'starts_at' => '2026-10-29 09:00',
            'ends_at' => '2026-10-29 12:00',
        ]))->assertOk()->assertJsonCount(3, 'items');

        $rows = collect($response->json('items'))->keyBy('equipment_id');
        $this->assertSame('free', $rows[$free->id]['state']);
        $this->assertSame('busy', $rows[$busy->id]['state']);
        $this->assertSame('Sam', $rows[$busy->id]['conflicts'][0]['owner']['name']);
        $this->assertSame('Doc shoot', $rows[$busy->id]['conflicts'][0]['title']);
        $this->assertSame('2026-10-29T15:00:00+00:00', $rows[$busy->id]['conflicts'][0]['starts_at']);

        $response->assertJsonStructure(['items' => [['equipment_id', 'name', 'category', 'state', 'status', 'reason', 'conflicts']]]);
    }

    public function test_local_times_are_read_in_the_company_timezone()
    {
        $camera = Equipment::factory()->create();
        $this->book($camera, '2026-10-29 09:00', '2026-10-29 10:00');

        // 14:00–15:00 UTC would overlap if the times were read as UTC.
        $this->getJson(route('api.availability', ['starts_at' => '2026-10-29 14:00', 'ends_at' => '2026-10-29 15:00']))
            ->assertJsonPath('items.0.state', 'free');
    }

    public function test_the_booking_being_edited_can_be_ignored()
    {
        $camera = Equipment::factory()->create();
        $booking = $this->book($camera, '2026-10-29 09:00', '2026-10-29 12:00');

        $this->getJson(route('api.availability', [
            'starts_at' => '2026-10-29 09:00',
            'ends_at' => '2026-10-29 12:00',
            'ignore_booking' => $booking->id,
        ]))->assertJsonPath('items.0.state', 'free');
    }

    public function test_parameters_are_validated()
    {
        $this->getJson(route('api.availability'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at', 'ends_at']);

        $this->getJson(route('api.availability', ['starts_at' => '2026-10-29 12:00', 'ends_at' => '2026-10-29 09:00']))
            ->assertJsonValidationErrors('ends_at');
    }

    public function test_guests_are_refused()
    {
        auth()->logout();

        $this->getJson(route('api.availability', ['starts_at' => '2026-10-29 09:00', 'ends_at' => '2026-10-29 12:00']))
            ->assertUnauthorized();
    }
}
