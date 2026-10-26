<?php

namespace App\Ai\Tools;

use App\Models\User;

/**
 * What every tool knows about the request: who is asking (always from the
 * session, never from the model) and which conversation it belongs to.
 */
final readonly class ToolContext
{
    public function __construct(
        public User $user,
        public ?string $conversationId,
    ) {}
}
