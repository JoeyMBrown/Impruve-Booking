<?php

namespace App\Http\Controllers;

use App\Enums\EquipmentCategory;
use App\Enums\EquipmentStatus;
use App\Http\Requests\StoreEquipmentRequest;
use App\Http\Requests\UpdateEquipmentRequest;
use App\Models\Booking;
use App\Models\Equipment;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EquipmentController extends Controller
{
    /**
     * The default "active" filter shows available and out-of-service items.
     */
    private const string ACTIVE_FILTER = 'active';

    public function index(Request $request): Response
    {
        $category = EquipmentCategory::tryFrom($request->string('category')->toString());
        $status = EquipmentStatus::tryFrom($request->string('status')->toString());

        $equipment = Equipment::query()
            ->when($category, fn ($query, EquipmentCategory $category) => $query->where('category', $category))
            ->when(
                $status,
                fn ($query, EquipmentStatus $status) => $query->where('status', $status),
                fn ($query) => $query->where('status', '!=', EquipmentStatus::Retired),
            )
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        return Inertia::render('equipment/index', [
            'equipment' => $equipment,
            'filters' => [
                'category' => $category?->value,
                'status' => $status->value ?? self::ACTIVE_FILTER,
            ],
            ...$this->options(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('equipment/form', [
            'equipment' => null,
            ...$this->options(),
        ]);
    }

    public function store(StoreEquipmentRequest $request): RedirectResponse
    {
        $equipment = Equipment::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$equipment->name} added."]);

        return redirect()->route('equipment.show', $equipment);
    }

    public function show(Equipment $equipment): Response
    {
        // This week (from Monday, company time) and next week.
        $from = CarbonImmutable::now(config()->string('booking.timezone'))->startOfWeek(CarbonInterface::MONDAY);
        $to = $from->addWeeks(2);

        $bookings = $equipment->bookings()
            ->active()
            ->overlapping($from, $to)
            ->with('user:id,name')
            ->orderBy('bookings.starts_at')
            ->get()
            ->map(fn (Booking $booking) => [
                'id' => $booking->id,
                'title' => $booking->title,
                'starts_at' => $booking->starts_at->toIso8601String(),
                'ends_at' => $booking->ends_at->toIso8601String(),
                'owner' => ['id' => $booking->user->id, 'name' => $booking->user->name],
            ]);

        return Inertia::render('equipment/show', [
            'equipment' => $equipment,
            'bookings' => $bookings,
            'window' => [
                'starts_at' => $from->toIso8601String(),
                'ends_at' => $to->toIso8601String(),
            ],
            'statuses' => $this->options()['statuses'],
        ]);
    }

    public function edit(Equipment $equipment): Response
    {
        return Inertia::render('equipment/form', [
            'equipment' => $equipment,
            ...$this->options(),
        ]);
    }

    public function update(UpdateEquipmentRequest $request, Equipment $equipment): RedirectResponse
    {
        $equipment->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$equipment->name} saved."]);

        return redirect()->route('equipment.show', $equipment);
    }

    /**
     * Category and status choices, labelled by the enums so the UI never
     * hard-codes them.
     *
     * @return array{categories: list<array{value: string, label: string}>, statuses: list<array{value: string, label: string}>}
     */
    private function options(): array
    {
        return [
            'categories' => array_map(
                fn (EquipmentCategory $category) => ['value' => $category->value, 'label' => $category->label()],
                EquipmentCategory::cases(),
            ),
            'statuses' => array_map(
                fn (EquipmentStatus $status) => ['value' => $status->value, 'label' => $status->label()],
                EquipmentStatus::cases(),
            ),
        ];
    }
}
