<?php

namespace App\Ai;

use App\Enums\EquipmentCategory;
use App\Enums\EquipmentStatus;
use App\Models\Equipment;

/**
 * Three or four suggestion chips for the chat widget, built from real item
 * names (FR-CHAT-3).
 */
final class ChatSuggestions
{
    /**
     * @return list<string>
     */
    public static function make(): array
    {
        $available = Equipment::query()
            ->where('status', EquipmentStatus::Available)
            ->orderBy('name')
            ->get(['id', 'name', 'category']);

        $space = $available->firstWhere('category', EquipmentCategory::Space);
        $camera = $available->firstWhere('category', EquipmentCategory::Camera);

        return array_values(array_filter([
            $space ? "Book the {$space->name} tomorrow 2–3pm" : null,
            "What's free this weekend?",
            $camera ? "When is the {$camera->name} free next?" : null,
            'My bookings this week',
        ]));
    }
}
