<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('booking_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id');
            $table->foreignId('equipment_id')->constrained('equipment');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->boolean('is_active');

            $table->unique(['booking_id', 'equipment_id']);
        });

        // The range and active flag are copies of the booking's; this keeps them in sync.
        DB::statement(<<<'SQL'
            ALTER TABLE booking_items
              ADD CONSTRAINT booking_items_booking_fk
              FOREIGN KEY (booking_id, starts_at, ends_at, is_active)
              REFERENCES bookings (id, starts_at, ends_at, is_active)
              ON UPDATE CASCADE
              ON DELETE CASCADE
            SQL);

        // No item in two active bookings at overlapping times.
        DB::statement(<<<'SQL'
            ALTER TABLE booking_items
              ADD CONSTRAINT booking_items_no_overlap
              EXCLUDE USING gist (
                equipment_id WITH =,
                tstzrange(starts_at, ends_at, '[)') WITH &&
              ) WHERE (is_active)
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_items');
    }
};
