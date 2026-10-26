<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Equipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BooksEquipment;
use Tests\TestCase;

/**
 * Editing, shortening, ending and cancelling through the form and the
 * cancel endpoint. "Now" is Wed Oct 28, 12:00 CDT.
 */
class BookingEditTest extends TestCase
{
    use BooksEquipment, RefreshDatabase;

    private User $ana;

    private Equipment $camera;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeAt();
        $this->ana = User::factory()->create();
        $this->camera = Equipment::factory()->create();
        $this->actingAs($this->ana);
    }

    public function test_only_the_owner_can_edit_or_cancel()
    {
        $booking = $this->book($this->camera, '2026-10-29 09:00', '2026-10-29 12:00');

        $this->get(route('bookings.edit', $booking))->assertForbidden();
        $this->put(route('bookings.update', $booking), $this->payloadFor($booking))->assertForbidden();
        $this->post(route('bookings.cancel', $booking))->assertForbidden();

        $this->assertTrue($booking->refresh()->is_active);
    }

    public function test_edit_form_is_prefilled_and_ignores_the_booking_in_availability()
    {
        $booking = $this->book($this->camera, '2026-10-29 09:00', '2026-10-29 12:00', $this->ana, title: 'B-roll');

        $this->get(route('bookings.edit', $booking))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('bookings/form')
                ->where('booking.id', $booking->id)
                ->where('booking.phase', 'upcoming')
                ->where('values.starts_at', '2026-10-29T09:00')
                ->where('values.ends_at', '2026-10-29T12:00')
                ->where('values.equipment_ids', [$this->camera->id])
                ->where('values.title', 'B-roll')
                ->where('availability.0.state', 'free'));
    }

    public function test_in_progress_bookings_open_in_the_restricted_mode()
    {
        $booking = $this->book($this->camera, '2026-10-28 10:00', '2026-10-28 17:00', $this->ana);
        $this->travelTo($this->at('2026-10-28 12:10'));

        $this->get(route('bookings.edit', $booking))
            ->assertInertia(fn (Assert $page) => $page
                ->where('booking.phase', 'in_progress')
                ->where('booking.ends_now_at', '2026-10-28T12:30:00-05:00'));
    }

    public function test_ended_and_cancelled_bookings_cannot_be_edited()
    {
        $ended = $this->book($this->camera, '2026-10-27 09:00', '2026-10-27 12:00', $this->ana);
        $cancelled = $this->book($this->camera, '2026-10-29 09:00', '2026-10-29 12:00', $this->ana, cancelled: true);

        $this->get(route('bookings.edit', $ended))
            ->assertRedirect(route('calendar', ['week' => '2026-W44']))
            ->assertInertiaFlash('toast.type', 'error');
        $this->get(route('bookings.edit', $cancelled))->assertRedirect(route('calendar', ['week' => '2026-W44']));
    }

    public function test_an_upcoming_booking_can_be_rescheduled_and_its_items_swapped()
    {
        $light = Equipment::factory()->create();
        $booking = $this->book($this->camera, '2026-10-29 09:00', '2026-10-29 12:00', $this->ana);

        $this->put(route('bookings.update', $booking), $this->payloadFor($booking, [
            'starts_at' => '2026-11-04T13:00',
            'ends_at' => '2026-11-04T15:00',
            'equipment_ids' => [$light->id],
            'title' => 'Moved shoot',
        ]))->assertSessionHasNoErrors()->assertRedirect(route('calendar', ['week' => '2026-W45']));

        $booking->refresh();
        $this->assertSame('Moved shoot', $booking->title);
        $this->assertTrue($booking->starts_at->equalTo($this->at('2026-11-04 13:00')));
        $this->assertSame([$light->id], $booking->items()->pluck('equipment_id')->all());
    }

    public function test_update_rule_failures_come_back_as_field_errors()
    {
        $this->book($this->camera, '2026-10-30 09:00', '2026-10-30 12:00');
        $booking = $this->book($this->camera, '2026-10-29 09:00', '2026-10-29 12:00', $this->ana);

        $this->put(route('bookings.update', $booking), $this->payloadFor($booking, [
            'starts_at' => '2026-10-30T10:00',
            'ends_at' => '2026-10-30T11:00',
        ]))->assertSessionHasErrors('equipment_ids');
    }

    public function test_an_in_progress_booking_can_be_shortened_but_not_otherwise_changed()
    {
        $booking = $this->book($this->camera, '2026-10-28 10:00', '2026-10-28 17:00', $this->ana);

        $this->put(route('bookings.update', $booking), $this->payloadFor($booking, ['ends_at' => '2026-10-28T14:00']))
            ->assertSessionHasNoErrors();
        $this->assertTrue($booking->refresh()->ends_at->equalTo($this->at('2026-10-28 14:00')));

        $this->put(route('bookings.update', $booking), $this->payloadFor($booking, ['title' => 'Renamed']))
            ->assertSessionHasErrors('booking');
    }

    public function test_cancelling_an_upcoming_booking()
    {
        $booking = $this->book($this->camera, '2026-10-29 09:00', '2026-10-29 12:00', $this->ana);

        $this->post(route('bookings.cancel', $booking))
            ->assertRedirect(route('calendar', ['week' => '2026-W44']))
            ->assertInertiaFlash('toast.type', 'success');

        $this->assertFalse($booking->refresh()->is_active);
        $this->assertNotNull($booking->cancelled_at);
    }

    public function test_end_now_ends_an_in_progress_booking_at_the_next_slot()
    {
        $booking = $this->book($this->camera, '2026-10-28 10:00', '2026-10-28 17:00', $this->ana);
        $this->travelTo($this->at('2026-10-28 12:10'));

        $this->post(route('bookings.cancel', $booking))->assertInertiaFlash('toast.message', "Ended: {$booking->title}.");

        $booking->refresh();
        $this->assertTrue($booking->is_active);
        $this->assertTrue($booking->ends_at->equalTo($this->at('2026-10-28 12:30')));
    }

    public function test_ended_bookings_cannot_be_cancelled()
    {
        $booking = $this->book($this->camera, '2026-10-27 09:00', '2026-10-27 12:00', $this->ana);

        $this->post(route('bookings.cancel', $booking))->assertInertiaFlash('toast.type', 'error');

        $this->assertTrue($booking->refresh()->is_active);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payloadFor(Booking $booking, array $overrides = []): array
    {
        return [
            'starts_at' => $booking->starts_at->setTimezone('America/Chicago')->format('Y-m-d\TH:i'),
            'ends_at' => $booking->ends_at->setTimezone('America/Chicago')->format('Y-m-d\TH:i'),
            'equipment_ids' => $booking->items()->pluck('equipment_id')->all(),
            'title' => $booking->title,
            'notes' => $booking->notes ?? '',
            ...$overrides,
        ];
    }
}
