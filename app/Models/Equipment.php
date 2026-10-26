<?php

namespace App\Models;

use App\Enums\EquipmentCategory;
use App\Enums\EquipmentStatus;
use Carbon\CarbonImmutable;
use Database\Factories\EquipmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property EquipmentCategory $category
 * @property string|null $description
 * @property string|null $asset_tag
 * @property EquipmentStatus $status
 * @property string|null $status_reason
 * @property CarbonImmutable|null $status_changed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Table(name: 'equipment', dateFormat: 'Y-m-d H:i:sP')]
#[Fillable(['name', 'category', 'description', 'asset_tag', 'status', 'status_reason', 'status_changed_at'])]
class Equipment extends Model
{
    /** @use HasFactory<EquipmentFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => EquipmentCategory::class,
            'status' => EquipmentStatus::class,
            'status_changed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<BookingItem, $this>
     */
    public function bookingItems(): HasMany
    {
        return $this->hasMany(BookingItem::class);
    }

    /**
     * @return BelongsToMany<Booking, $this>
     */
    public function bookings(): BelongsToMany
    {
        return $this->belongsToMany(Booking::class, 'booking_items');
    }
}
