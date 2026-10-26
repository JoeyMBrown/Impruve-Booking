<?php

use App\Enums\EquipmentCategory;
use App\Enums\EquipmentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('equipment', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->enum('category', array_column(EquipmentCategory::cases(), 'value'));
            $table->text('description')->nullable();
            $table->string('asset_tag')->nullable()->unique();
            $table->enum('status', array_column(EquipmentStatus::cases(), 'value'))
                ->default(EquipmentStatus::Available->value);
            $table->text('status_reason')->nullable();
            $table->timestampTz('status_changed_at')->nullable();
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('equipment');
    }
};
