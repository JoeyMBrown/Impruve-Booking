<?php

use App\Enums\ProposalAction;
use App\Enums\ProposalStatus;
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
        Schema::create('booking_proposals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained();
            // No foreign key: pruning old conversations must not delete proposals.
            $table->string('conversation_id', 36)->index();
            $table->enum('action', array_column(ProposalAction::cases(), 'value'));
            $table->foreignId('booking_id')->nullable()->constrained();
            $table->jsonb('payload');
            $table->enum('status', array_column(ProposalStatus::cases(), 'value'))
                ->default(ProposalStatus::Pending->value);
            $table->jsonb('failure')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampsTz();

            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_proposals');
    }
};
