<?php

namespace App\Ai\Agents;

use App\Ai\BookingAssistantPrompt;
use App\Ai\ProposalCollector;
use App\Ai\Tools\CheckAvailability;
use App\Ai\Tools\FindOpenings;
use App\Ai\Tools\ListMyBookings;
use App\Ai\Tools\ProposeBooking;
use App\Ai\Tools\ProposeCancellation;
use App\Ai\Tools\ProposeChange;
use App\Ai\Tools\ToolContext;
use App\Models\User;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;

/**
 * The chat booking assistant. It can only read and propose: every save goes
 * through a user-clicked Confirm, never the model (FR-CHAT-6). The acting
 * user comes from the session, never from the model (FR-CHAT-7).
 */
class BookingAssistant implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    public function __construct(
        private readonly User $user,
        private readonly ProposalCollector $proposals = new ProposalCollector,
    ) {}

    public function instructions(): string
    {
        return BookingAssistantPrompt::build($this->user);
    }

    /**
     * Read and propose tools only; none of them can save a booking. Each gets
     * the acting user from the session, never as an argument the model sets.
     *
     * @return list<Tool>
     */
    public function tools(): iterable
    {
        $context = new ToolContext($this->user, $this->currentConversation(), $this->proposals);

        return [
            new CheckAvailability($context),
            new FindOpenings($context),
            new ListMyBookings($context),
            new ProposeBooking($context),
            new ProposeChange($context),
            new ProposeCancellation($context),
        ];
    }

    public function provider(): string
    {
        return 'anthropic';
    }

    public function model(): string
    {
        return config()->string('ai.chat_model');
    }

    public function maxSteps(): int
    {
        return config()->integer('booking.chat.max_steps');
    }
}
