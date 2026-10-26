<?php

namespace App\Http\Controllers\Api;

use App\Domain\Booking\AvailabilityService;
use App\Domain\Booking\ItemAvailability;
use App\Domain\Booking\SlotClock;
use App\Http\Controllers\Controller;
use App\Http\Requests\AvailabilityRequest;
use Illuminate\Http\JsonResponse;

/**
 * Live availability for the booking form's item picker: one row per
 * non-retired item for the requested range.
 */
class AvailabilityController extends Controller
{
    public function __invoke(AvailabilityRequest $request, AvailabilityService $availability): JsonResponse
    {
        $items = $availability->forRange(
            SlotClock::parse($request->string('starts_at')->toString()),
            SlotClock::parse($request->string('ends_at')->toString()),
            ignoreBookingId: $request->filled('ignore_booking') ? $request->integer('ignore_booking') : null,
        );

        return response()->json([
            'items' => $items->map(fn (ItemAvailability $item) => $item->toArray())->values(),
        ]);
    }
}
