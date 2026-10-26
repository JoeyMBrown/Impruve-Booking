<?php

namespace Tests\Feature\Chat;

use App\Enums\ProposalStatus;
use App\Models\BookingProposal;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PruneChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletes_conversations_idle_for_30_days_with_their_messages()
    {
        $this->conversation('old', now()->subDays(31));
        $this->conversation('recent', now()->subDays(29));

        $this->artisan('chat:prune')->assertSuccessful();

        $this->assertSame(['recent'], DB::table('agent_conversations')->pluck('id')->all());
        $this->assertSame(['recent'], DB::table('agent_conversation_messages')->pluck('conversation_id')->all());
    }

    public function test_marks_pending_proposals_past_their_expiry_as_expired()
    {
        $stale = BookingProposal::factory()->create(['expires_at' => now()->subMinute()]);
        $fresh = BookingProposal::factory()->create(['expires_at' => now()->addMinutes(5)]);
        $confirmed = BookingProposal::factory()->create(['expires_at' => now()->subMinute(), 'status' => ProposalStatus::Confirmed]);

        $this->artisan('chat:prune')->assertSuccessful();

        $this->assertSame(ProposalStatus::Expired, $stale->refresh()->status);
        $this->assertSame(ProposalStatus::Pending, $fresh->refresh()->status);
        $this->assertSame(ProposalStatus::Confirmed, $confirmed->refresh()->status);
    }

    public function test_it_is_scheduled_hourly()
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'chat:prune'));

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
    }

    private function conversation(string $id, mixed $updatedAt): void
    {
        DB::table('agent_conversations')->insert([
            'id' => $id, 'participant_type' => 'App\\Models\\User', 'participant_id' => 1, 'title' => 'Chat',
            'created_at' => $updatedAt, 'updated_at' => $updatedAt,
        ]);
        DB::table('agent_conversation_messages')->insert([
            'id' => "{$id}-1", 'conversation_id' => $id, 'participant_type' => 'App\\Models\\User', 'participant_id' => 1,
            'agent' => 'App\\Ai\\Agents\\BookingAssistant', 'role' => 'user', 'content' => 'Hi', 'attachments' => '[]',
            'steps' => '[]', 'usage' => '[]', 'meta' => '[]', 'status' => 'completed',
            'created_at' => $updatedAt, 'updated_at' => $updatedAt,
        ]);
    }
}
