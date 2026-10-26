<?php

namespace App\Models;

use App\Enums\BookingSource;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property string|null $notes
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property BookingSource $source
 * @property CarbonImmutable|null $cancelled_at
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 */
#[Table(dateFormat: 'Y-m-d H:i:sP')]
#[Fillable(['user_id', 'title', 'notes', 'starts_at', 'ends_at', 'source', 'cancelled_at', 'is_active'])]
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'source' => BookingSource::class,
            'cancelled_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Bookings that have not been cancelled. Columns are qualified because
     * booking_items (joined by equipment relations) has the same names.
     *
     * @param  Builder<Booking>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('bookings.is_active', true);
    }

    /**
     * Bookings whose half-open range [starts_at, ends_at) overlaps [from, to).
     * Bounds are converted to UTC because bound dates are sent without an offset.
     *
     * @param  Builder<Booking>  $query
     */
    public function scopeOverlapping(Builder $query, CarbonInterface $from, CarbonInterface $to): void
    {
        $query->where('bookings.starts_at', '<', CarbonImmutable::instance($to)->utc())
            ->where('bookings.ends_at', '>', CarbonImmutable::instance($from)->utc());
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The item rows. Their range and active flag are kept in sync with the
     * booking by a cascading foreign key, so never update them directly.
     *
     * @return HasMany<BookingItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(BookingItem::class);
    }

    /**
     * @return BelongsToMany<Equipment, $this>
     */
    public function equipment(): BelongsToMany
    {
        return $this->belongsToMany(Equipment::class, 'booking_items');
    }
}
