<?php

namespace App\Http\Controllers;

use App\Domain\Booking\CalendarWeek;
use App\Enums\EquipmentCategory;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The weekly calendar: the home page, and a JSON copy of its data that the
 * page refetches (e.g. after a chat confirm) without a full reload.
 * Query: ?week=2026-W44&category=camera&equipment=3&mine=1
 */
class CalendarController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('calendar/week', $this->payload($request));
    }

    public function week(Request $request): JsonResponse
    {
        return response()->json($this->payload($request));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $viewer = $request->user();
        abort_unless($viewer instanceof User, 401);

        return CalendarWeek::payload(
            $viewer,
            CalendarWeek::start($request->filled('week') ? $request->string('week')->toString() : null),
            EquipmentCategory::tryFrom($request->string('category')->toString()),
            $request->filled('equipment') ? $request->integer('equipment') : null,
            $request->boolean('mine'),
        );
    }
}
