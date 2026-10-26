<?php

namespace Tests\Feature;

use App\Enums\EquipmentCategory;
use App\Enums\EquipmentStatus;
use App\Models\Equipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EquipmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_guests_cannot_see_equipment()
    {
        auth()->logout();

        $this->get(route('equipment.index'))->assertRedirect(route('who.index'));
    }

    public function test_list_defaults_to_active_items_and_hides_retired()
    {
        Equipment::factory()->create(['name' => 'Available cam']);
        Equipment::factory()->outOfService()->create(['name' => 'Broken cam']);
        Equipment::factory()->retired()->create(['name' => 'Old cam']);

        $this->get(route('equipment.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('equipment/index')
                ->has('equipment', 2)
                ->where('filters.status', 'active')
                ->where('filters.category', null)
                ->has('categories', 3)
                ->has('statuses', 3));
    }

    public function test_list_can_show_only_retired_items()
    {
        Equipment::factory()->create();
        Equipment::factory()->retired()->create(['name' => 'Old cam']);

        $this->get(route('equipment.index', ['status' => 'retired']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('equipment', 1)
                ->where('equipment.0.name', 'Old cam')
                ->where('filters.status', 'retired'));
    }

    public function test_list_can_filter_by_category()
    {
        Equipment::factory()->create(['category' => EquipmentCategory::Camera]);
        Equipment::factory()->create(['category' => EquipmentCategory::Space, 'name' => 'Podcast Room']);

        $this->get(route('equipment.index', ['category' => 'space']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('equipment', 1)
                ->where('equipment.0.name', 'Podcast Room')
                ->where('filters.category', 'space'));
    }

    public function test_unknown_filter_values_fall_back_to_defaults()
    {
        Equipment::factory()->create();

        $this->get(route('equipment.index', ['category' => 'boats', 'status' => 'nope']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('equipment', 1)
                ->where('filters.category', null)
                ->where('filters.status', 'active'));
    }

    public function test_create_form_renders()
    {
        $this->get(route('equipment.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('equipment/form')
                ->where('equipment', null)
                ->has('categories', 3));
    }

    public function test_equipment_can_be_created()
    {
        $this->post(route('equipment.store'), [
            'name' => 'Sony FX3 #3',
            'category' => 'camera',
            'description' => 'Cage and 2 batteries',
            'asset_tag' => 'IMP-099',
        ])->assertRedirect();

        $equipment = Equipment::where('name', 'Sony FX3 #3')->firstOrFail();

        $this->assertSame(EquipmentCategory::Camera, $equipment->category);
        $this->assertSame(EquipmentStatus::Available, $equipment->status);
        $this->assertSame('IMP-099', $equipment->asset_tag);
    }

    public function test_blank_asset_tags_are_stored_as_null_so_several_items_can_have_none()
    {
        Equipment::factory()->create(['asset_tag' => null]);

        $this->post(route('equipment.store'), [
            'name' => 'Studio C',
            'category' => 'space',
            'asset_tag' => '',
        ])->assertSessionHasNoErrors();

        $this->assertNull(Equipment::where('name', 'Studio C')->value('asset_tag'));
    }

    public function test_create_validates_required_and_unique_fields()
    {
        Equipment::factory()->create(['name' => 'Canon C70', 'asset_tag' => 'IMP-003']);

        $this->post(route('equipment.store'), [
            'name' => 'Canon C70',
            'category' => 'boat',
            'asset_tag' => 'IMP-003',
        ])->assertSessionHasErrors(['name', 'category', 'asset_tag']);

        $this->post(route('equipment.store'), [])
            ->assertSessionHasErrors(['name', 'category']);
    }

    public function test_equipment_can_be_updated_keeping_its_own_name_and_tag()
    {
        $equipment = Equipment::factory()->create(['name' => 'Godox Kit', 'asset_tag' => 'IMP-007']);

        $this->get(route('equipment.edit', $equipment))
            ->assertInertia(fn (Assert $page) => $page
                ->component('equipment/form')
                ->where('equipment.id', $equipment->id));

        $this->put(route('equipment.update', $equipment), [
            'name' => 'Godox Kit',
            'category' => 'lighting',
            'description' => 'Now with umbrellas',
            'asset_tag' => 'IMP-007',
        ])->assertSessionHasNoErrors()->assertRedirect(route('equipment.show', $equipment));

        $this->assertSame('Now with umbrellas', $equipment->fresh()?->description);
    }

    public function test_update_cannot_change_status()
    {
        $equipment = Equipment::factory()->create();

        $this->put(route('equipment.update', $equipment), [
            'name' => $equipment->name,
            'category' => $equipment->category->value,
            'status' => 'retired',
        ]);

        $this->assertSame(EquipmentStatus::Available, $equipment->fresh()?->status);
    }

    public function test_equipment_cannot_be_deleted()
    {
        $equipment = Equipment::factory()->create();

        $this->delete("/equipment/{$equipment->id}")->assertMethodNotAllowed();

        $this->assertModelExists($equipment);
    }
}
