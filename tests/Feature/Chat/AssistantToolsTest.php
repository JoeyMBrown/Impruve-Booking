<?php

namespace Tests\Feature\Chat;

use App\Ai\Agents\BookingAssistant;
use App\Ai\ProposalCollector;
use App\Ai\Tools\BookingTool;
use App\Ai\Tools\CheckAvailability;
use App\Ai\Tools\FindOpenings;
use App\Ai\Tools\ListMyBookings;
use App\Ai\Tools\ProposeBooking;
use App\Ai\Tools\ProposeCancellation;
use App\Ai\Tools\ProposeChange;
use App\Ai\Tools\ToolContext;
use App\Enums\EquipmentCategory;
use App\Enums\ProposalAction;
use App\Enums\ProposalStatus;
use App\Models\BookingProposal;
use App\Models\Equipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Tools\Request;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\Concerns\BooksEquipment;
use Tests\TestCase;

/**
 * The assistant's tools, called directly. "Now" is Wed Oct 28, 12:00 CDT.
 */
class AssistantToolsTest extends TestCase
{
    use BooksEquipment, RefreshDatabase;

    private User $ana;

    private Equipment $camera;

    private ProposalCollector $proposals;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeAt();
        $this->ana = User::factory()->create(['name' => 'Ana']);
        $this->camera = Equipment::factory()->create(['name' => 'Sony FX3 #1', 'category' => EquipmentCategory::Camera]);
        $this->proposals = new ProposalCollector;
    }

    public function test_no_tool_accepts_a_user_id()
    {
        $schema = new JsonSchemaTypeFactory;

        foreach ((new BookingAssistant($this->ana))->tools() as $tool) {
            $arguments = array_keys($tool->schema($schema));

            $this->assertNotContains('user_id', $arguments, $tool::class);
            $this->assertNotContains('owner_id', $arguments, $tool::class);
        }
    }

    public function test_check_availability_reports_free_busy_and_unbookable()
    {
        $sam = User::factory()->create(['name' => 'Sam']);
        $this->book($this->camera, '2026-10-29 10:00', '2026-10-29 14:00', $sam, title: 'Doc shoot');
        $free = Equipment::factory()->create(['name' => 'Canon C70', 'category' => EquipmentCategory::Camera]);
        $broken = Equipment::factory()->outOfService('Bulb')->create(['name' => 'Kit B', 'category' => EquipmentCategory::Lighting]);

        $result = $this->callTool(CheckAvailability::class, ['starts_at' => '2026-10-29T09:00', 'ends_at' => '2026-10-29T12:00']);

        $items = collect($result['items'])->keyBy('id');
        $this->assertTrue($result['ok']);
        $this->assertSame('free', $items[$free->id]['state']);
        $this->assertSame('busy', $items[$this->camera->id]['state']);
        $this->assertSame('Sam', $items[$this->camera->id]['busy_with'][0]['owner']);
        $this->assertSame('2026-10-29T10:00', $items[$this->camera->id]['busy_with'][0]['starts']['local']);
        $this->assertSame('Thu Oct 29, 10:00 AM', $items[$this->camera->id]['busy_with'][0]['starts']['display']);
        $this->assertSame('unbookable', $items[$broken->id]['state']);
        $this->assertSame('Bulb', $items[$broken->id]['reason']);

        $lighting = $this->callTool(CheckAvailability::class, ['starts_at' => '2026-10-29T09:00', 'ends_at' => '2026-10-29T12:00', 'category' => 'lighting']);
        $this->assertSame([$broken->id], array_column($lighting['items'], 'id'));
    }

    public function test_bad_arguments_come_back_as_an_error_not_an_exception()
    {
        $result = $this->callTool(CheckAvailability::class, ['starts_at' => 'Tuesday', 'ends_at' => '2026-10-29T12:00']);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_arguments', $result['error']);
        $this->assertArrayHasKey('starts_at', $result['messages']);
    }

    public function test_find_openings_returns_windows_where_items_are_free()
    {
        $this->book($this->camera, '2026-10-29 09:00', '2026-10-29 12:00');

        $result = $this->callTool(FindOpenings::class, [
            'equipment_ids' => [$this->camera->id],
            'duration_minutes' => 60,
            'search_from' => '2026-10-29T08:00',
            'search_to' => '2026-10-29T18:00',
        ]);

        $this->assertSame(
            [['2026-10-29T08:00', '2026-10-29T09:00'], ['2026-10-29T12:00', '2026-10-29T18:00']],
            array_map(fn (array $opening) => [$opening['starts']['local'], $opening['ends']['local']], $result['openings']),
        );
    }

    public function test_list_my_bookings_only_lists_the_acting_users_current_bookings()
    {
        $this->book($this->camera, '2026-10-29 09:00', '2026-10-29 12:00', $this->ana, title: 'Mine');
        $this->book($this->camera, '2026-10-27 09:00', '2026-10-27 12:00', $this->ana, title: 'Mine, ended');
        $this->book($this->camera, '2026-10-30 09:00', '2026-10-30 12:00', title: 'Someone else');
        $this->book($this->camera, '2026-10-31 09:00', '2026-10-31 12:00', $this->ana, cancelled: true, title: 'Mine, cancelled');

        $this->assertSame(['Mine'], array_column($this->callTool(ListMyBookings::class, [])['bookings'], 'title'));
        $this->assertSame(['Mine, ended', 'Mine'], array_column($this->callTool(ListMyBookings::class, ['include_past' => true])['bookings'], 'title'));
    }

    public function test_propose_booking_stores_a_pending_proposal_and_a_card_but_saves_nothing()
    {
        $result = $this->callTool(ProposeBooking::class, [
            'equipment_ids' => [$this->camera->id],
            'starts_at' => '2026-10-29T09:00',
            'ends_at' => '2026-10-29T17:00',
            'title' => 'Client interview',
        ]);

        $this->assertTrue($result['ok']);
        $proposal = BookingProposal::sole();
        $this->assertSame($result['proposal_id'], $proposal->id);
        $this->assertSame($this->ana->id, $proposal->user_id);
        $this->assertSame('conversation-1', $proposal->conversation_id);
        $this->assertSame(ProposalAction::Create, $proposal->action);
        $this->assertSame(ProposalStatus::Pending, $proposal->status);
        $this->assertTrue($proposal->expires_at->equalTo(now()->addMinutes(15)));
        $this->assertDatabaseCount('bookings', 0);

        [$card] = $this->proposals->drain();
        $this->assertSame($proposal->id, $card['id']);
        $this->assertSame('Thu Oct 29 · 9:00 AM → 5:00 PM', $card['display']['when']);
        $this->assertSame('8 h', $card['display']['duration']);
        $this->assertSame([['id' => $this->camera->id, 'name' => 'Sony FX3 #1']], $card['items']);
        $this->assertNull($card['before']);
    }

    public function test_propose_booking_returns_rule_codes_and_proposes_nothing()
    {
        $this->book($this->camera, '2026-10-29 10:00', '2026-10-29 12:00');
        $broken = Equipment::factory()->outOfService()->create();

        $cases = [
            'item_conflict' => ['starts_at' => '2026-10-29T09:00', 'ends_at' => '2026-10-29T11:00'],
            'too_long' => ['starts_at' => '2026-11-03T09:00', 'ends_at' => '2026-11-07T09:30'],
            'starts_in_past' => ['starts_at' => '2026-10-27T09:00', 'ends_at' => '2026-10-27T10:00'],
            'invalid_range' => ['starts_at' => '2026-11-03T09:15', 'ends_at' => '2026-11-03T10:00'],
            'item_unbookable' => ['equipment_ids' => [$broken->id], 'starts_at' => '2026-11-03T09:00', 'ends_at' => '2026-11-03T10:00'],
            'no_items' => ['equipment_ids' => [], 'starts_at' => '2026-11-03T09:00', 'ends_at' => '2026-11-03T10:00'],
        ];

        foreach ($cases as $code => $arguments) {
            $result = $this->callTool(ProposeBooking::class, ['equipment_ids' => [$this->camera->id], 'title' => 'Shoot', ...$arguments]);

            $this->assertFalse($result['ok'], $code);
            $this->assertSame($code, $result['error'], $code);
            $this->assertNotEmpty($result['problems'][0]['message']);
        }

        $this->assertDatabaseCount('booking_proposals', 0);
    }

    public function test_change_and_cancel_refuse_other_peoples_bookings()
    {
        $samsBooking = $this->book($this->camera, '2026-10-29 09:00', '2026-10-29 12:00', title: 'Sam’s shoot');

        $this->assertSame('owner_only', $this->callTool(ProposeChange::class, ['booking_id' => $samsBooking->id, 'title' => 'Mine now'])['error']);
        $this->assertSame('owner_only', $this->callTool(ProposeCancellation::class, ['booking_id' => $samsBooking->id])['error']);
        $this->assertSame('not_found', $this->callTool(ProposeCancellation::class, ['booking_id' => 999999])['error']);
        $this->assertDatabaseCount('booking_proposals', 0);
    }

    public function test_propose_change_shows_old_and_new_values()
    {
        $booking = $this->book($this->camera, '2026-10-29 09:00', '2026-10-29 12:00', $this->ana, title: 'B-roll');

        $result = $this->callTool(ProposeChange::class, [
            'booking_id' => $booking->id,
            'starts_at' => '2026-10-30T09:00',
            'ends_at' => '2026-10-30T12:00',
        ]);

        $this->assertTrue($result['ok']);
        [$card] = $this->proposals->drain();
        $this->assertSame('update', $card['action']);
        $this->assertSame($booking->id, $card['booking_id']);
        $this->assertSame('B-roll', $card['title']);
        $this->assertSame('Fri Oct 30 · 9:00 AM → 12:00 PM', $card['display']['when']);
        $this->assertSame('Thu Oct 29 · 9:00 AM → 12:00 PM', $card['before']['display']['when']);
    }

    public function test_propose_cancellation_says_when_it_ends_early_and_refuses_ended_bookings()
    {
        $inProgress = $this->book($this->camera, '2026-10-28 10:00', '2026-10-28 17:00', $this->ana);
        $ended = $this->book($this->camera, '2026-10-27 09:00', '2026-10-27 10:00', $this->ana);

        $this->assertTrue($this->callTool(ProposeCancellation::class, ['booking_id' => $inProgress->id])['ends_early']);
        $this->assertSame('immutable_phase', $this->callTool(ProposeCancellation::class, ['booking_id' => $ended->id])['error']);
    }

    public function test_every_tool_call_is_logged_to_the_ai_channel()
    {
        $logger = Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->with('ai')->andReturn($logger);

        $this->callTool(ProposeCancellation::class, ['booking_id' => 999999]);

        $logger->shouldHaveReceived('info')->with('tool_call', Mockery::on(fn (array $context) => $context['tool'] === 'ProposeCancellation'
            && $context['arguments'] === ['booking_id' => 999999]
            && $context['ok'] === false
            && $context['error'] === 'not_found'
            && $context['user_id'] === $this->ana->id
            && $context['conversation_id'] === 'conversation-1'
            && isset($context['at'])));
    }

    /**
     * @param  class-string<BookingTool>  $tool
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function callTool(string $tool, array $arguments): array
    {
        $instance = new $tool(new ToolContext($this->ana, 'conversation-1', $this->proposals));

        return json_decode((string) $instance->handle(new Request($arguments)), true, flags: JSON_THROW_ON_ERROR);
    }
}
