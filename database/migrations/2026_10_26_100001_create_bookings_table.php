<?php

use App\Enums\BookingSource;
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
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('title', 120);
            $table->text('notes')->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->enum('source', array_column(BookingSource::cases(), 'value'));
            $table->timestampTz('cancelled_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index('user_id');
            $table->index(['starts_at', 'ends_at']);
        });

        DB::statement('ALTER TABLE bookings ADD CONSTRAINT bookings_range_valid CHECK (ends_at > starts_at)');
        DB::statement("ALTER TABLE bookings ADD CONSTRAINT bookings_max_duration CHECK (ends_at - starts_at <= interval '96 hours')");
        DB::statement('ALTER TABLE bookings ADD CONSTRAINT bookings_active_matches_cancelled CHECK (is_active = (cancelled_at IS NULL))');

        // Lets booking_items reference (id, range, active) so the copies cascade.
        DB::statement('ALTER TABLE bookings ADD CONSTRAINT bookings_id_range_active_key UNIQUE (id, starts_at, ends_at, is_active)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
