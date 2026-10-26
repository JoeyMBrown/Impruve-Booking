<?php

namespace App\Http\Controllers;

use App\Domain\Booking\BookingPresenter;
use App\Domain\Booking\SlotClock;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The acting user's bookings in four tabs: Upcoming (at-risk first),
 * In progress, Past and Cancelled.
 */
class MyBookingsController extends Controller
{
    private const array TABS = ['upcoming', 'in_progress', 'past', 'cancelled'];

    public function __invoke(Request $request): Response
    {
        $viewer = $request->user();
        abort_unless($viewer instanceof User, 401);

        $now = SlotClock::now();
        $bookings = Booking::query()
            ->where('user_id', $viewer->id)
            ->with(['user:id,name', 'equipment'])
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Booking $booking) => BookingPresenter::toArray($booking, $viewer, $now));

        $active = $bookings->where('is_active', true);

        $tab = $request->string('tab')->toString();

        return Inertia::render('bookings/mine', [
            'tab' => in_array($tab, self::TABS, true) ? $tab : 'upcoming',
            'tabs' => [
                // At-risk first; the stable sort keeps soonest first within each.
                'upcoming' => $active->where('phase', 'upcoming')->sortByDesc('at_risk')->values(),
                'in_progress' => $active->where('phase', 'in_progress')->values(),
                'past' => $active->where('phase', 'ended')->reverse()->values(),
                'cancelled' => $bookings->where('is_active', false)->sortByDesc('cancelled_at')->values(),
            ],
        ]);
    }
}
