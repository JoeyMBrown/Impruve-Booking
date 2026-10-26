<?php

namespace Tests\Feature\Chat;

use App\Enums\ProposalAction;
use App\Models\BookingProposal;
use App\Models\Equipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BooksEquipment;
use Tests\TestCase;

/**
 * "Edit in form" on a chat card (FR-FORM-4). "Now" is Wed Oct 28, 12:00 CDT.
 */
class ProposalPrefillTest extends TestCase
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

    public function test_a_create_proposal_prefills_the_new_booking_form()
    {
        $proposal = BookingProposal::factory()->for($this->ana)->withPayload([
            'equipment_ids' => [$this->camera->id],
            'starts_at' => '2026-10-29T09:00:00-05:00',
            'ends_at' => '2026-10-29T17:00:00-05:00',
            'title' => 'Client interview',
            'notes' => 'Lav mics',
        ])->create();

        $this->get(route('bookings.create', ['proposal' => $proposal->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('bookings/form')
                ->where('from_proposal', true)
                ->where('values.starts_at', '2026-10-29T09:00')
                ->where('values.ends_at', '2026-10-29T17:00')
                ->where('values.equipment_ids', [$this->camera->id])
                ->where('values.title', 'Client interview')
                ->where('values.notes', 'Lav mics'));
    }

    public function test_someone_elses_proposal_is_ignored()
    {
        $proposal = BookingProposal::factory()->withPayload(['title' => 'Not yours'])->create();

        $this->get(route('bookings.create', ['proposal' => $proposal->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('from_proposal', false)
                ->where('values.title', ''));
    }

    public function test_a_change_proposal_prefills_the_edit_form()
    {
        $booking = $this->book($this->camera, '2026-10-29 09:00', '2026-10-29 12:00', $this->ana, title: 'Old title');
        $proposal = BookingProposal::factory()->for($this->ana)->create([
            'action' => ProposalAction::Update,
            'booking_id' => $booking->id,
            'payload' => [
                'equipment_ids' => [$this->camera->id],
                'starts_at' => '2026-10-30T09:00:00-05:00',
                'ends_at' => '2026-10-30T12:00:00-05:00',
                'title' => 'Moved',
                'notes' => null,
            ],
        ]);

        $this->get(route('bookings.edit', ['booking' => $booking, 'proposal' => $proposal->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('from_proposal', true)
                ->where('booking.id', $booking->id)
                ->where('values.starts_at', '2026-10-30T09:00')
                ->where('values.title', 'Moved'));

        $this->get(route('bookings.edit', $booking))
            ->assertInertia(fn (Assert $page) => $page
                ->where('from_proposal', false)
                ->where('values.title', 'Old title'));
    }
}
