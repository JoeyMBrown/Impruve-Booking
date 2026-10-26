<?php

namespace Database\Factories;

use App\Enums\EquipmentCategory;
use App\Enums\EquipmentStatus;
use App\Models\Equipment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Equipment>
 */
class EquipmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->bothify('??? Kit ###'),
            'category' => fake()->randomElement(EquipmentCategory::cases()),
            'description' => fake()->optional()->sentence(),
            'asset_tag' => fake()->unique()->bothify('IMP-####'),
            'status' => EquipmentStatus::Available,
        ];
    }

    /**
     * Indicate that the equipment is out of service.
     */
    public function outOfService(?string $reason = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => EquipmentStatus::OutOfService,
            'status_reason' => $reason,
            'status_changed_at' => now(),
        ]);
    }

    /**
     * Indicate that the equipment is retired.
     */
    public function retired(?string $reason = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => EquipmentStatus::Retired,
            'status_reason' => $reason,
            'status_changed_at' => now(),
        ]);
    }
}
