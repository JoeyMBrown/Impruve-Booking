<?php

namespace Database\Factories;

use App\Enums\BookingSource;
use App\Models\Booking;
use App\Models\Equipment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slotMinutes = config()->integer('booking.slot_minutes');

        $startsAt = CarbonImmutable::now(config()->string('booking.timezone'))
            ->addDays(fake()->numberBetween(1, 14))
            ->setTime(fake()->numberBetween(6, 18), 0)
            ->addMinutes($slotMinutes * fake()->numberBetween(0, 1));

        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(3),
            'notes' => null,
            'starts_at' => $startsAt->utc(),
            'ends_at' => $startsAt->addMinutes($slotMinutes * fake()->numberBetween(2, 16))->utc(),
            'source' => BookingSource::Manual,
            'cancelled_at' => null,
            'is_active' => true,
        ];
    }

    /**
     * Attach equipment by inserting item rows that copy the booking's range
     * and active flag. Creates one item when none are given.
     */
    public function withEquipment(Equipment ...$equipment): static
    {
        return $this->afterCreating(function (Booking $booking) use ($equipment) {
            $items = $equipment ?: [Equipment::factory()->create()];

            foreach ($items as $item) {
                $booking->items()->create([
                    'equipment_id' => $item->id,
                    'starts_at' => $booking->starts_at,
                    'ends_at' => $booking->ends_at,
                    'is_active' => $booking->is_active,
                ]);
            }
        });
    }
}
