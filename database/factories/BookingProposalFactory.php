<?php

namespace Database\Factories;

use App\Enums\ProposalAction;
use App\Enums\ProposalStatus;
use App\Models\BookingProposal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingProposal>
 */
class BookingProposalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = CarbonImmutable::now(config()->string('booking.timezone'))->addDay()->setTime(9, 0);

        return [
            'user_id' => User::factory(),
            'conversation_id' => fake()->uuid(),
            'action' => ProposalAction::Create,
            'booking_id' => null,
            'payload' => [
                'equipment_ids' => [],
                'starts_at' => $startsAt->toIso8601String(),
                'ends_at' => $startsAt->addHours(3)->toIso8601String(),
                'title' => 'Proposed shoot',
                'notes' => null,
            ],
            'status' => ProposalStatus::Pending,
            'expires_at' => now()->addMinutes(15),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function withPayload(array $payload): static
    {
        return $this->state(fn (array $attributes) => [
            'payload' => [...$attributes['payload'], ...$payload],
        ]);
    }
}
