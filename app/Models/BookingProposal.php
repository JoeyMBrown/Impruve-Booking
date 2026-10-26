<?php

namespace App\Models;

use App\Enums\ProposalAction;
use App\Enums\ProposalStatus;
use Carbon\CarbonImmutable;
use Database\Factories\BookingProposalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property int $user_id
 * @property string $conversation_id
 * @property ProposalAction $action
 * @property int|null $booking_id
 * @property array<string, mixed> $payload
 * @property ProposalStatus $status
 * @property array<string, mixed>|null $failure
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Booking|null $booking
 * @property-read User $user
 */
#[Table(dateFormat: 'Y-m-d H:i:sP')]
#[Fillable(['user_id', 'conversation_id', 'action', 'booking_id', 'payload', 'status', 'failure', 'expires_at'])]
class BookingProposal extends Model
{
    /** @use HasFactory<BookingProposalFactory> */
    use HasFactory, HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => ProposalAction::class,
            'payload' => 'array',
            'status' => ProposalStatus::class,
            'failure' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
