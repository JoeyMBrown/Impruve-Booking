<?php

namespace Tests\Feature;

use App\Enums\EquipmentStatus;
use App\Models\Booking;
use App\Models\Equipment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EquipmentStatusTest extends TestCase
{
    use RefreshDatabase;

    private const string TZ = 'America/Chicago';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-28 12:00', self::TZ));
        $this->actingAs(User::factory()->create());
    }

    public function test_status_can_change_with_a_reason_and_records_when()
    {
        $equipment = Equipment::factory()->create();

        $this->put(route('equipment.status', $equipment), [
            'status' => 'out_of_service',
            'reason' => 'Bulb replacement',
        ])->assertRedirect(route('equipment.show', $equipment))
            ->assertInertiaFlash('toast.type', 'success');

        $equipment->refresh();
        $this->assertSame(EquipmentStatus::OutOfService, $equipment->status);
        $this->assertSame('Bulb replacement', $equipment->status_reason);
        $this->assertTrue($equipment->status_changed_at?->equalTo(now()));
    }

    public function test_any_status_can_change_to_any_other_including_un_retiring()
    {
        $equipment = Equipment::factory()->create();

        foreach (['retired', 'out_of_service', 'retired', 'available'] as $status) {
            $this->put(route('equipment.status', $equipment), ['status' => $status])
                ->assertSessionHasNoErrors();

            $this->assertSame($status, $equipment->refresh()->status->value);
        }

        $this->assertNull($equipment->status_reason);
    }

    public function test_re_saving_the_same_status_updates_the_reason()
    {
        $equipment = Equipment::factory()->outOfService('Bulb')->create();

        $this->travel(1)->hours();

        $this->put(route('equipment.status', $equipment), [
            'status' => 'out_of_service',
            'reason' => 'Bulb, back ~Nov 3',
        ])->assertSessionHasNoErrors();

        $equipment->refresh();
        $this->assertSame('Bulb, back ~Nov 3', $equipment->status_reason);
        $this->assertTrue($equipment->status_changed_at?->equalTo(now()));
    }

    public function test_status_must_be_a_known_value()
    {
        $equipment = Equipment::factory()->create();

        $this->put(route('equipment.status', $equipment), ['status' => 'broken'])
            ->assertSessionHasErrors('status');
    }

    public function test_status_change_never_cancels_bookings_and_flags_them_at_risk()
    {
        $equipment = Equipment::factory()->create();
        $upcoming = $this->book($equipment, 'B-roll', '2026-10-29 13:00', '2026-10-29 17:00');
        $inProgress = $this->book($equipment, 'Interview', '2026-10-28 11:00', '2026-10-28 14:00');

        $this->assertSame(0, Booking::atRisk()->count());

        $this->put(route('equipment.status', $equipment), ['status' => 'out_of_service']);

        foreach ([$upcoming, $inProgress] as $booking) {
            $booking->refresh();
            $this->assertTrue($booking->is_active);
            $this->assertNull($booking->cancelled_at);
        }

        $this->assertEqualsCanonicalizing(
            [$upcoming->id, $inProgress->id],
            Booking::atRisk()->pluck('id')->all(),
        );
    }

    public function test_impact_lists_upcoming_and_in_progress_bookings_that_use_the_item()
    {
        $equipment = Equipment::factory()->create();
        $other = Equipment::factory()->create();

        $this->book($equipment, 'In progress', '2026-10-28 11:00', '2026-10-28 14:00');
        $this->book($equipment, 'Upcoming', '2026-10-29 13:00', '2026-10-29 17:00');
        $this->book($equipment, 'Ended', '2026-10-27 09:00', '2026-10-27 10:00');
        $this->book($equipment, 'Cancelled', '2026-10-30 09:00', '2026-10-30 10:00', cancelled: true);
        $this->book($other, 'Other item', '2026-10-29 13:00', '2026-10-29 17:00');

        $this->getJson(route('api.equipment.impact', ['equipment' => $equipment, 'status' => 'out_of_service']))
            ->assertOk()
            ->assertJsonCount(2, 'bookings')
            ->assertJsonPath('bookings.0.title', 'In progress')
            ->assertJsonPath('bookings.0.phase', 'in_progress')
            ->assertJsonPath('bookings.1.title', 'Upcoming')
            ->assertJsonPath('bookings.1.phase', 'upcoming')
            ->assertJsonPath('bookings.1.starts_at', '2026-10-29T18:00:00+00:00')
            ->assertJsonStructure(['bookings' => [['id', 'title', 'starts_at', 'ends_at', 'phase', 'owner' => ['id', 'name']]]]);
    }

    public function test_impact_of_moving_to_available_is_empty()
    {
        $equipment = Equipment::factory()->outOfService()->create();
        $this->book($equipment, 'Upcoming', '2026-10-29 13:00', '2026-10-29 17:00');

        $this->getJson(route('api.equipment.impact', ['equipment' => $equipment, 'status' => 'available']))
            ->assertOk()
            ->assertJsonCount(0, 'bookings');
    }

    public function test_impact_requires_a_valid_status()
    {
        $equipment = Equipment::factory()->create();

        $this->getJson(route('api.equipment.impact', ['equipment' => $equipment, 'status' => 'nope']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
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
