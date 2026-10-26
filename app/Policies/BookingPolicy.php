<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;

/**
 * Everyone can see every booking; only the owner can change or cancel it.
 * Phase rules (e.g. ended bookings) are enforced by BookingService.
 */
class BookingPolicy
{
    public function view(User $user, Booking $booking): bool
    {
        return true;
    }

    public function update(User $user, Booking $booking): bool
    {
        return $user->id === $booking->user_id;
    }

    public function cancel(User $user, Booking $booking): bool
    {
        return $user->id === $booking->user_id;
    }
}
