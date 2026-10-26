<?php

namespace Database\Seeders;

use App\Enums\EquipmentCategory;
use App\Models\Equipment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class EquipmentSeeder extends Seeder
{
    /**
     * Seed the placeholder equipment. Runs on a fresh install only.
     */
    public function run(): void
    {
        if (Equipment::query()->exists()) {
            $this->command->warn('Equipment already exists; skipping. Seeders run on a fresh install only.');

            return;
        }

        /** @var list<array{name: string, category: string, description?: string|null, asset_tag?: string|null}> $items */
        $items = File::json(database_path('seeders/data/equipment.json'), JSON_THROW_ON_ERROR);

        foreach ($items as $item) {
            Equipment::create([
                'name' => $item['name'],
                'category' => EquipmentCategory::from($item['category']),
                'description' => $item['description'] ?? null,
                'asset_tag' => $item['asset_tag'] ?? null,
            ]);
        }
    }
}
