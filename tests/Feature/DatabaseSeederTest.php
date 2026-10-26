<?php

namespace Tests\Feature;

use App\Enums\EquipmentCategory;
use App\Enums\EquipmentStatus;
use App\Models\Equipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_placeholder_users_and_equipment_into_an_empty_database()
    {
        $this->seed();

        $this->assertSame(14, User::count());
        $this->assertSame(10, Equipment::count());
        $this->assertSame(0, Equipment::where('status', '!=', EquipmentStatus::Available)->count());

        foreach (EquipmentCategory::cases() as $category) {
            $this->assertTrue(Equipment::where('category', $category)->exists(), "No {$category->value} seeded.");
        }
    }

    public function test_it_skips_tables_that_already_have_rows()
    {
        $this->seed();
        $this->seed();

        $this->assertSame(14, User::count());
        $this->assertSame(10, Equipment::count());
    }
}
