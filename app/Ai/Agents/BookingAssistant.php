<?php

namespace App\Ai\Agents;

use App\Ai\BookingAssistantPrompt;
use App\Models\User;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

/**
 * The chat booking assistant. It can only read and propose: every save goes
 * through a user-clicked Confirm, never the model (FR-CHAT-6). The acting
 * user comes from the session, never from the model (FR-CHAT-7).
 */
class BookingAssistant implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    public function __construct(private readonly User $user) {}

    public function instructions(): string
    {
        return BookingAssistantPrompt::build($this->user);
    }

    /**
     * @return list<object>
     */
    public function tools(): iterable
    {
        return [];
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
