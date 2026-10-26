<?php

namespace Tests\Feature\Chat;

use App\Ai\Agents\BookingAssistant;
use App\Enums\BookingSource;
use App\Enums\EquipmentCategory;
use App\Enums\ProposalAction;
use App\Enums\ProposalStatus;
use App\Models\Booking;
use App\Models\BookingProposal;
use App\Models\Equipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Responses\Data\ToolCall;
use RuntimeException;
use Tests\Concerns\BooksEquipment;
use Tests\TestCase;

/**
 * The chat endpoints with BookingAssistant::fake(): no model is called.
 * Fakes run our real tools (spike-streaming.md). "Now" is Wed Oct 28, 12:00 CDT.
 */
class ChatEndpointsTest extends TestCase
{
    use BooksEquipment, RefreshDatabase;

    private User $ana;

    private Equipment $camera;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeAt();
        $this->ana = User::factory()->create(['name' => 'Ana']);
        $this->camera = Equipment::factory()->create(['name' => 'Sony FX3 #1', 'category' => EquipmentCategory::Camera]);
        Equipment::factory()->create(['name' => 'Podcast Room', 'category' => EquipmentCategory::Space]);
        $this->actingAs($this->ana);
    }

    // Conversations

    public function test_starting_a_conversation_creates_it_for_the_user_with_suggestions()
    {
        $response = $this->postJson(route('chat.start'))->assertCreated();

        $this->assertDatabaseHas('agent_conversations', [
            'id' => $response->json('conversation_id'),
            'participant_type' => $this->ana->getMorphClass(),
            'participant_id' => $this->ana->id,
        ]);
        $this->assertContains('Book the Podcast Room tomorrow 2–3pm', $response->json('suggestions'));
        $this->assertContains('When is the Sony FX3 #1 free next?', $response->json('suggestions'));
    }

    public function test_only_the_owner_can_continue_a_conversation()
    {
        BookingAssistant::fake()->preventStrayPrompts();
        $conversation = $this->postJson(route('chat.start'))->json('conversation_id');

        $this->actingAs(User::factory()->create());

        $this->postJson(route('chat.message', $conversation), ['message' => 'Hi'])->assertForbidden();
        $this->postJson(route('chat.message', 'not-a-conversation'), ['message' => 'Hi'])->assertForbidden();
        BookingAssistant::assertNeverPrompted();
    }

    // Streaming

    public function test_a_reply_streams_as_text_events_then_done()
    {
        BookingAssistant::fake(['Both light kits are free.'])->preventStrayPrompts();

        $events = $this->events($this->sendMessage('Is a light kit free Wednesday?'));

        $this->assertSame('text', $events[0]['event']);
        $this->assertSame('Both light kits are free.', implode('', array_column(array_column(array_filter($events, fn ($e) => $e['event'] === 'text'), 'data'), 'delta')));
        $this->assertSame('done', end($events)['event']);
        BookingAssistant::assertPrompted('Is a light kit free Wednesday?');
    }

    public function test_a_proposal_card_is_sent_mid_stream_when_a_propose_tool_finishes()
    {
        BookingAssistant::fake([
            new ToolCall('call_1', 'ProposeBooking', [
                'equipment_ids' => [$this->camera->id],
                'starts_at' => '2026-10-29T09:00',
                'ends_at' => '2026-10-29T17:00',
                'title' => 'Client interview',
            ]),
            'Review the card below.',
        ])->preventStrayPrompts();

        $events = $this->events($this->sendMessage('Book the FX3 Thursday 9 to 5'));

        $this->assertSame(['proposal', 'text', 'done'], array_values(array_unique(array_column($events, 'event'))));
        $card = $events[0]['data'];
        $this->assertSame('create', $card['action']);
        $this->assertSame('Thu Oct 29 · 9:00 AM → 5:00 PM', $card['display']['when']);
        $this->assertSame(ProposalStatus::Pending, BookingProposal::findOrFail($card['id'])->status);
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_a_failing_model_ends_in_an_error_event_with_a_manual_fallback()
    {
        BookingAssistant::fake(fn () => throw new RuntimeException('Anthropic is down'));

        $events = $this->events($this->sendMessage('Hi'));

        $this->assertSame('error', end($events)['event']);
        $this->assertSame('The assistant is unavailable right now.', end($events)['data']['message']);
        $this->assertSame(route('bookings.create'), end($events)['data']['fallback_url']);
    }

    public function test_messages_are_rate_limited_per_user()
    {
        config(['booking.chat.rate_limit_per_minute' => 2]);
        BookingAssistant::fake(['One', 'Two', 'Three']);
        $conversation = $this->postJson(route('chat.start'))->json('conversation_id');

        $this->postJson(route('chat.message', $conversation), ['message' => 'a'])->assertOk();
        $this->postJson(route('chat.message', $conversation), ['message' => 'b'])->assertOk();
        $this->postJson(route('chat.message', $conversation), ['message' => 'c'])->assertTooManyRequests();
    }

    public function test_a_message_is_required()
    {
        $conversation = $this->postJson(route('chat.start'))->json('conversation_id');

        $this->postJson(route('chat.message', $conversation), ['message' => ''])->assertJsonValidationErrors('message');
    }

    // Confirm and dismiss

    public function test_confirming_a_create_proposal_books_it_via_chat()
    {
        $proposal = $this->proposal();

        $this->postJson(route('chat.proposals.confirm', $proposal))
            ->assertOk()
            ->assertJsonPath('booking.title', 'Client interview')
            ->assertJsonPath('booking.source', 'chat')
            ->assertJsonPath('calendar_url', route('calendar', ['week' => '2026-W44']));

        $booking = Booking::sole();
        $this->assertSame(BookingSource::Chat, $booking->source);
        $this->assertSame($this->ana->id, $booking->user_id);
        $this->assertSame(ProposalStatus::Confirmed, $proposal->refresh()->status);
        $this->assertSame($booking->id, $proposal->booking_id);
    }

    public function test_only_the_owner_can_confirm_or_dismiss()
    {
        $proposal = $this->proposal();
        $this->actingAs(User::factory()->create());

        $this->postJson(route('chat.proposals.confirm', $proposal))->assertForbidden();
        $this->postJson(route('chat.proposals.dismiss', $proposal))->assertForbidden();
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_an_item_taken_since_the_proposal_returns_409_with_who_has_it()
    {
        $proposal = $this->proposal();
        $sam = User::factory()->create(['name' => 'Sam']);
        $this->book($this->camera, '2026-10-29 10:00', '2026-10-29 14:00', $sam, title: 'Doc shoot');

        $this->postJson(route('chat.proposals.confirm', $proposal))
            ->assertConflict()
            ->assertJsonPath('code', 'item_conflict')
            ->assertJsonPath('conflicts.0.owner.name', 'Sam')
            ->assertJsonPath('conflicts.0.equipment_name', 'Sony FX3 #1');

        $this->assertSame(ProposalStatus::Failed, $proposal->refresh()->status);
        $this->assertSame('item_conflict', $proposal->failure['code'] ?? null);
        $this->assertSame(1, Booking::count());
    }

    public function test_an_expired_proposal_returns_410()
    {
        $proposal = $this->proposal();
        $this->travel(16)->minutes();

        $this->postJson(route('chat.proposals.confirm', $proposal))->assertStatus(410);

        $this->assertSame(ProposalStatus::Expired, $proposal->refresh()->status);
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_a_rule_that_now_fails_returns_422()
    {
        $proposal = $this->proposal();
        $this->camera->update(['status' => 'out_of_service']);

        $this->postJson(route('chat.proposals.confirm', $proposal))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'item_unbookable');
    }

    public function test_a_proposal_cannot_be_confirmed_twice()
    {
        $proposal = $this->proposal();

        $this->postJson(route('chat.proposals.confirm', $proposal))->assertOk();
        $this->postJson(route('chat.proposals.confirm', $proposal))->assertConflict()->assertJsonPath('code', 'not_pending');

        $this->assertSame(1, Booking::count());
    }

    public function test_dismissing_a_proposal()
    {
        $proposal = $this->proposal();

        $this->postJson(route('chat.proposals.dismiss', $proposal))->assertNoContent();

        $this->assertSame(ProposalStatus::Dismissed, $proposal->refresh()->status);
        $this->postJson(route('chat.proposals.confirm', $proposal))->assertConflict();
    }

    public function test_confirming_change_and_cancel_proposals()
    {
        $booking = $this->book($this->camera, '2026-10-29 09:00', '2026-10-29 12:00', $this->ana, title: 'B-roll');

        $change = BookingProposal::factory()->for($this->ana)->create([
            'action' => ProposalAction::Update,
            'booking_id' => $booking->id,
            'payload' => [
                'equipment_ids' => [$this->camera->id],
                'starts_at' => '2026-10-30T09:00:00-05:00',
                'ends_at' => '2026-10-30T12:00:00-05:00',
                'title' => 'B-roll',
                'notes' => null,
            ],
        ]);
        $this->postJson(route('chat.proposals.confirm', $change))->assertOk();
        $this->assertTrue($booking->refresh()->starts_at->equalTo($this->at('2026-10-30 09:00')));

        $cancel = BookingProposal::factory()->for($this->ana)->create([
            'action' => ProposalAction::Cancel,
            'booking_id' => $booking->id,
        ]);
        $this->postJson(route('chat.proposals.confirm', $cancel))->assertOk()->assertJsonPath('booking.is_active', false);
    }

    private function proposal(): BookingProposal
    {
        return BookingProposal::factory()->for($this->ana)->withPayload([
            'equipment_ids' => [$this->camera->id],
            'starts_at' => '2026-10-29T09:00:00-05:00',
            'ends_at' => '2026-10-29T17:00:00-05:00',
            'title' => 'Client interview',
        ])->create();
    }

    private function sendMessage(string $message): TestResponse
    {
        $conversation = $this->postJson(route('chat.start'))->json('conversation_id');

        $response = $this->post(route('chat.message', $conversation), ['message' => $message])->assertOk();
        $this->assertStringStartsWith('text/event-stream', (string) $response->headers->get('Content-Type'));

        return $response;
    }

    /**
     * @return list<array{event: string, data: mixed}>
     */
    private function events(TestResponse $response): array
    {
        $events = [];

        foreach (explode("\n\n", trim($response->streamedContent())) as $block) {
            preg_match('/^event: (.+)$/m', $block, $event);
            preg_match('/^data: (.+)$/m', $block, $data);
            $events[] = ['event' => $event[1], 'data' => json_decode($data[1], true)];
        }

        return $events;
    }
}
