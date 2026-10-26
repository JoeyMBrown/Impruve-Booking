<?php

namespace App\Http\Requests;

use App\Domain\Booking\BookingDraft;
use App\Domain\Booking\SlotClock;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Types and formats only. The booking rules (slots, length, availability,
 * phase) are BookingService's job, so the form and the chat share them.
 */
class BookingRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date'],
            'equipment_ids' => ['present', 'array'],
            'equipment_ids.*' => ['integer', 'distinct'],
            'title' => ['required', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * The submitted booking. Times without an offset are company-local.
     */
    public function toDraft(): BookingDraft
    {
        /** @var list<int|string> $equipmentIds */
        $equipmentIds = $this->validated('equipment_ids');

        return new BookingDraft(
            array_map(intval(...), $equipmentIds),
            SlotClock::parse($this->string('starts_at')->toString()),
            SlotClock::parse($this->string('ends_at')->toString()),
            $this->string('title')->trim()->toString(),
            $this->filled('notes') ? $this->string('notes')->trim()->toString() : null,
        );
    }
}
