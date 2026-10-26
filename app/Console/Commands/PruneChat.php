<?php

namespace App\Console\Commands;

use App\Enums\ProposalStatus;
use App\Models\BookingProposal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deletes chat conversations with no activity for the retention period (30
 * days, Q6) and marks pending proposals past their expiry as expired
 * (FR-CHAT-18). Proposals are kept: they record what was booked via chat.
 */
class PruneChat extends Command
{
    protected $signature = 'chat:prune';

    protected $description = 'Delete old chat conversations and expire stale booking proposals';

    public function handle(): int
    {
        $cutoff = now()->subDays(config()->integer('booking.chat.retention_days'));
        $conversations = config('ai.conversations.tables.conversations', 'agent_conversations');
        $messages = config('ai.conversations.tables.messages', 'agent_conversation_messages');

        $deleted = DB::transaction(function () use ($cutoff, $conversations, $messages) {
            $old = DB::table($conversations)->where('updated_at', '<', $cutoff)->pluck('id');

            foreach ($old->chunk(500) as $ids) {
                DB::table($messages)->whereIn('conversation_id', $ids)->delete();
                DB::table($conversations)->whereIn('id', $ids)->delete();
            }

            return $old->count();
        });

        $expired = BookingProposal::query()
            ->where('status', ProposalStatus::Pending)
            ->where('expires_at', '<', now())
            ->update(['status' => ProposalStatus::Expired]);

        $this->info("Deleted {$deleted} old conversation(s); expired {$expired} proposal(s).");

        return self::SUCCESS;
    }
}
