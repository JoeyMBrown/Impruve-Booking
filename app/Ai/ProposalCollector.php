<?php

namespace App\Ai;

/**
 * Proposal cards created by tools during the current request. The chat
 * controller drains it when a propose tool's result arrives in the stream
 * and sends each card as a `proposal` event (spike-streaming.md).
 */
final class ProposalCollector
{
    /** @var list<array<string, mixed>> */
    private array $cards = [];

    /**
     * @param  array<string, mixed>  $card
     */
    public function add(array $card): void
    {
        $this->cards[] = $card;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function drain(): array
    {
        $cards = $this->cards;
        $this->cards = [];

        return $cards;
    }
}
