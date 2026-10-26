<?php

namespace App\Http\Controllers;

use App\Enums\EquipmentStatus;
use App\Http\Requests\UpdateEquipmentStatusRequest;
use App\Models\Booking;
use App\Models\Equipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Status changes (any status to any other). Changing status never cancels
 * bookings: affected ones are flagged at risk for their owners to resolve.
 */
class EquipmentStatusController extends Controller
{
    public function update(UpdateEquipmentStatusRequest $request, Equipment $equipment): RedirectResponse
    {
        $equipment->update([
            'status' => $request->enum('status', EquipmentStatus::class),
            'status_reason' => $request->validated('reason'),
            'status_changed_at' => now(),
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "{$equipment->name} is now {$equipment->status->label()}.",
        ]);

        return redirect()->route('equipment.show', $equipment);
    }

    /**
     * The upcoming and in-progress bookings that would be flagged at risk if
     * the item moved to the given status. Moving to available flags none.
     */
    public function impact(Request $request, Equipment $equipment): JsonResponse
    {
        $request->validate([
            'status' => ['required', Rule::enum(EquipmentStatus::class)],
        ]);

        if ($request->enum('status', EquipmentStatus::class) === EquipmentStatus::Available) {
            return response()->json(['bookings' => []]);
        }

        $bookings = Booking::query()
            ->active()
            ->notEnded()
            ->usingEquipment($equipment->id)
            ->with('user:id,name')
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Booking $booking) => [
                'id' => $booking->id,
                'title' => $booking->title,
                'starts_at' => $booking->starts_at->toIso8601String(),
                'ends_at' => $booking->ends_at->toIso8601String(),
                'phase' => $booking->phase(),
                'owner' => ['id' => $booking->user->id, 'name' => $booking->user->name],
            ]);

        return response()->json(['bookings' => $bookings]);
    }
}
