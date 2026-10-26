<?php

namespace App\Ai\Tools;

use App\Ai\ProposalCollector;
use App\Models\User;

/**
 * What every tool knows about the request: who is asking (always from the
 * session, never from the model), which conversation it belongs to, and
 * where to put proposal cards for the stream.
 */
final readonly class ToolContext
{
    public function __construct(
        public User $user,
        public ?string $conversationId,
        public ProposalCollector $proposals = new ProposalCollector,
    ) {}
}
